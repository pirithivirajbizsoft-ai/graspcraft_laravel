<?php

namespace App\Services;

use App\Models\DeletedOrder;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Support\Crypto;
use App\Support\Enums\PaymentType;
use App\Support\Enums\UsersType;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Port of graspcraft_backend/src/modules/admin/reports/reports.service.ts.
 *
 * Four of the seven endpoints are hand-written SQL strings in the Node service,
 * built by string concatenation. They are carried over as-is: the joins, the
 * DISTINCT ON, the duplicated sales_count/payment_type_count columns and the
 * `+05:30` timestamp suffix all shape numbers the business reads, and rewriting
 * them into the query builder would be a rewrite, not a port.
 *
 * Two things ARE changed, neither of which alters a result:
 *
 *  1. How values reach the SQL. The Node version interpolates request data
 *     straight into the string, which is an injection hole on every filter. Here
 *     the same SQL is built with bound parameters.
 *
 *  2. `::text` on the COUNT and SUM columns. COUNT() is bigint and SUM(numeric)
 *     is numeric; node-postgres hands both to JS as STRINGS (neither fits a JS
 *     number safely), so the panel has always received "0"/"1234.50" here.
 *     PHP's pdo_pgsql returns native int/float instead, which would silently
 *     flip the JSON types. Casting in SQL keeps both back ends identical.
 */
class ReportsService
{
    public function __construct(
        private readonly CommissionEarningService $commissionEarningService,
    ) {}

    /**
     * Builds `col IN (?, ?, ?)` with its bindings, or a fallback predicate when
     * the list is empty.
     *
     * @param  array<int, mixed>  $values
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function inClause(string $column, array $values, string $emptyFallback): array
    {
        if ($values === []) {
            return [$emptyFallback, []];
        }

        $placeholders = implode(', ', array_fill(0, count($values), '?'));

        return ["{$column} IN ({$placeholders})", array_values($values)];
    }

    /**
     * The date window used by the raw report queries.
     *
     * The Node code does `toISOString().replace('Z', '+05:30')`, which takes a
     * UTC instant and RELABELS it as +05:30 without shifting it — so the window
     * is silently moved by 5h30m. It is reproduced exactly, because changing it
     * would change every historical report total.
     *
     * @return array{0: string, 1: array<int, string>}
     */
    private function dateWindow(?string $fromDate, ?string $toDate, string $column): array
    {
        if (! $fromDate || ! $toDate) {
            return ['', []];
        }

        $from = Carbon::parse($fromDate)->utc();
        $to = Carbon::parse($toDate)->setTime(23, 59, 59, 999999)->utc();

        $fromFormatted = $from->format('Y-m-d\TH:i:s.v').'+05:30';
        $toFormatted = $to->format('Y-m-d\TH:i:s.v').'+05:30';

        return ["AND {$column} BETWEEN ? AND ?", [$fromFormatted, $toFormatted]];
    }

    /**
     * Kiosk ids the caller is allowed to see.
     *
     * Super admin and staff see everything; anyone else with a logined_userId is
     * limited to the kiosks their role grants. An account manager with NO kiosk
     * assigned must see nothing, which is why the empty case is `1=0` rather
     * than an absent filter.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function kioskScope(array $req, string $column): array
    {
        $scoped = ! empty($req['logined_userId'])
            && ($req['user_type'] ?? null) !== UsersType::SUPER_ADMIN->value
            && ($req['user_type'] ?? null) !== UsersType::STAFF->value;

        if (! $scoped) {
            return ['', []];
        }

        $role = Role::with('roleKioskMap')->where('user_id', $req['logined_userId'])->first();
        $kioskIds = $role?->roleKioskMap->pluck('kiosk_id')->all() ?? [];

        if ($kioskIds === []) {
            return ['AND 1=0', []];
        }

        $placeholders = implode(', ', array_fill(0, count($kioskIds), '?'));

        return ["AND {$column} IN ({$placeholders})", $kioskIds];
    }

    /**
     * json columns selected through `o.*` / `ca.*` in the raw queries.
     *
     * node-postgres parses a `json` column into a real object before Sequelize
     * ever sees it, so the panel receives `response_json: {payment: true}`.
     * PDO hands PHP the raw text instead, which would serialise back out as a
     * STRING containing JSON — a different type for the same field. Decoding
     * here restores the Node shape.
     */
    private const JSON_COLUMNS = ['response_json', 'combo_json', 'snapshot'];

    /**
     * Postgres timestamptz text, as PDO hands it back:
     * "2026-08-03 14:50:55.542765+08".
     *
     * node-postgres parses these into JS Dates, which JSON.stringify renders as
     * "2026-08-03T06:50:55.542Z" — UTC, milliseconds, trailing Z. Raw rows are
     * not model attributes, so BaseModel::serializeDate never sees them and they
     * have to be normalised here.
     */
    private const TIMESTAMP_PATTERN = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d+)?([+-]\d{2}(:?\d{2})?)?$/';

    /**
     * @param  array<int, mixed>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function decodeJsonColumns(array $rows): array
    {
        foreach ($rows as $i => $row) {
            $row = (array) $row;

            foreach (self::JSON_COLUMNS as $column) {
                if (isset($row[$column]) && is_string($row[$column])) {
                    /*
                     * Decoded to stdClass, NOT to an associative array: these rows
                     * are handed straight to the client, and an empty `{}` decoded
                     * into a PHP array would re-encode as `[]`.
                     */
                    $decoded = json_decode($row[$column]);

                    if (json_last_error() === JSON_ERROR_NONE) {
                        $row[$column] = $decoded;
                    }
                }
            }

            foreach ($row as $key => $value) {
                if (is_string($value) && preg_match(self::TIMESTAMP_PATTERN, $value)) {
                    $row[$key] = Carbon::parse($value)->utc()->format('Y-m-d\TH:i:s.v\Z');
                }
            }

            $rows[$i] = $row;
        }

        return $rows;
    }

    /** Decrypt the aliased _name / _email / _ph_number columns in place. */
    private function decryptAliases(array $rows): array
    {
        foreach ($rows as $i => $row) {
            $row = (array) $row;

            foreach (['_name', '_email', '_ph_number'] as $key) {
                if (! empty($row[$key])) {
                    $row[$key] = Crypto::tryDecrypt($row[$key]);
                }
            }

            $rows[$i] = $row;
        }

        return $rows;
    }

    /**
     * Collapse to the first row per order_id, preserving order.
     *
     * The SQL already does DISTINCT ON, but the joins can still fan out, so the
     * Node service dedupes again in JS.
     */
    private function uniqueByOrderId(array $rows): array
    {
        $seen = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $key = $row['order_id'] ?? null;

            if (! array_key_exists($key, $seen)) {
                $seen[$key] = $row;
            }
        }

        return array_values($seen);
    }

    // ─── kiosk report ────────────────────────────────────────────────────────

    public function kioskReport(array $req): array
    {
        $pageNo = $req['page_no'] ?? null ?: 1;
        $limits = $req['limit'] ?? null ?: 10;
        $offsets = ($pageNo - 1) * $limits;

        // The search term is encrypted before comparison because users.name is
        // stored encrypted — an exact-substring match on ciphertext, which only
        // ever matches a whole encrypted value.
        $search = '';
        $searchBindings = [];
        if (! empty($req['search_text'])) {
            $search = 'AND u.name ILIKE ?';
            $searchBindings = ['%'.Crypto::encrypt($req['search_text']).'%'];
        }

        [$orderStatusCondition, $orderStatusBindings] = $this->inClause(
            'o.order_status', $req['order_status'] ?? [], 'o.order_status IS NOT NULL',
        );

        [$paymentTypeCondition, $paymentTypeBindings] = $this->inClause(
            'o.payment_type', $req['payment_type'] ?? [], 'o.payment_type IS NOT NULL',
        );

        [$kioskCondition, $kioskBindings] = $this->inClause(
            'u.id', $req['kiosk_id'] ?? [], 'u.id IS NOT NULL',
        );

        [$dateFilter, $dateBindings] = $this->dateWindow(
            $req['from_date'] ?? null, $req['to_date'] ?? null, 'o.created_at',
        );

        [$kioskIds, $kioskIdBindings] = $this->kioskScope($req, 'o.kiosk_id');

        $where = "u.user_type = 'kiosk' AND {$orderStatusCondition} AND {$paymentTypeCondition} "
            ."AND {$kioskCondition} {$kioskIds} {$dateFilter} {$search}";

        $bindings = array_merge(
            $orderStatusBindings, $paymentTypeBindings, $kioskBindings,
            $kioskIdBindings, $dateBindings, $searchBindings,
        );

        $counts = DB::select(<<<SQL
            SELECT
               COUNT(DISTINCT order_id)::text AS sales_count,
               COUNT(DISTINCT order_id)::text AS payment_type_count,
               SUM( amount::NUMERIC)::text AS total_amount
             FROM (
                 SELECT DISTINCT ON (o.id) o.id AS order_id, COALESCE(o.amount, '0')::NUMERIC as amount
           FROM orders o
           left join users as u ON u.id = o.kiosk_id
           left join customers as cu ON cu.id = o.customer_id
           left join cart as ca ON ca.customer_id = cu.id
           left join combo as co ON co.id = ca.combo_id
           left join "prod-comb-map" as pc ON co.id = pc.combo_id
           left join products as p ON p.id = pc.product_id
        where {$where}
            ) AS distinct_orders;
        SQL, $bindings);

        $result = DB::select(<<<SQL
          SELECT * FROM (
          SELECT DISTINCT ON (o.order_id)
            o.*,
            o.created_at AS order_created_at,
            u.name AS _name,
            u.email_id AS _email,
            u.*,
            cu.*,
            ca.*,
            co.*,
            pc.*,
            p.*,
            u.name, u.email_id
          FROM orders AS o
          LEFT JOIN users AS u ON u.id = o.kiosk_id
          LEFT JOIN customers AS cu ON cu.id = o.customer_id
          LEFT JOIN cart AS ca ON ca.customer_id = cu.id
          LEFT JOIN combo AS co ON co.id = ca.combo_id
          LEFT JOIN "prod-comb-map" AS pc ON co.id = pc.combo_id
          LEFT JOIN products AS p ON p.id = pc.product_id
         where {$where}
          ORDER BY o.order_id DESC
        ) AS latest_orders
        ORDER BY latest_orders.order_created_at DESC
        limit ? offset ?;
        SQL, array_merge($bindings, [$limits, $offsets]));

        $countResult = DB::select(<<<SQL
              SELECT COUNT(DISTINCT id)::text AS total_orders FROM (
          SELECT o.id as id
        FROM orders AS o
        left join users as u ON u.id = o.kiosk_id
        left join customers as cu ON cu.id = o.customer_id
        left join cart as ca ON ca.customer_id = cu.id
        left join combo as co ON co.id = ca.combo_id
        left join "prod-comb-map" as pc ON co.id = pc.combo_id
        left join products as p ON p.id = pc.product_id
        where {$where}
        ) AS latest_orders
        SQL, $bindings);

        return [
            'counts' => $countResult[0]->total_orders ?? 0,
            'order_counts' => $counts[0] ?? null,
            'orders' => $this->decryptAliases($this->decodeJsonColumns($result)),
        ];
    }

    // ─── overall report ──────────────────────────────────────────────────────

    public function overallReport(array $req): array
    {
        $pageNo = $req['page_no'] ?? null ?: 1;
        $limits = $req['limit'] ?? null ?: 10;
        $offsets = ($pageNo - 1) * $limits;

        // Unlike the kiosk report this searches order_id, which is not encrypted.
        $search = '';
        $searchBindings = [];
        if (! empty($req['search_text'])) {
            $search = 'AND o.order_id ILIKE ?';
            $searchBindings = ['%'.$req['search_text'].'%'];
        }

        [$orderStatusCondition, $orderStatusBindings] = $this->inClause(
            'o.order_status', $req['order_status'] ?? [], 'o.order_status IS NOT NULL',
        );

        [$paymentTypeCondition, $paymentTypeBindings] = $this->inClause(
            'o.payment_type', $req['payment_type'] ?? [], 'o.payment_type IS NOT NULL',
        );

        [$dateFilter, $dateBindings] = $this->dateWindow(
            $req['from_date'] ?? null, $req['to_date'] ?? null, 'o.created_at',
        );

        [$kioskIds, $kioskIdBindings] = $this->kioskScope($req, 'o.kiosk_id');

        $where = "{$orderStatusCondition} AND {$paymentTypeCondition} {$kioskIds} {$dateFilter} {$search}";

        $bindings = array_merge(
            $orderStatusBindings, $paymentTypeBindings,
            $kioskIdBindings, $dateBindings, $searchBindings,
        );

        $counts = DB::select(<<<SQL
            SELECT
               COUNT(DISTINCT order_id)::text AS sales_count,
               COUNT(DISTINCT order_id)::text AS payment_type_count,
               SUM( amount::NUMERIC)::text AS total_amount
             FROM (
                 SELECT DISTINCT ON (o.id) o.id AS order_id, COALESCE(o.amount, '0')::NUMERIC as amount
           FROM orders o
           left join customers as cu ON cu.id = o.customer_id
           left join cart as ca ON ca.customer_id = cu.id
           left join combo as co ON co.id = ca.combo_id
           left join "prod-comb-map" as pc ON co.id = pc.combo_id
           left join products as p ON p.id = pc.product_id
        where {$where}
            ) AS distinct_orders;
        SQL, $bindings);

        $result = DB::select(<<<SQL
         SELECT * FROM (
          SELECT DISTINCT ON (o.order_id)
             o.*,
            o.created_at AS order_created_at,
             cu.*,
             ca.*,
            co.*,
            p.*
           FROM orders AS o
        left join customers as cu ON cu.id = o.customer_id
        left join cart as ca ON ca.customer_id = cu.id
        left join combo as co ON co.id = ca.combo_id
        left join "prod-comb-map" as pc ON co.id = pc.combo_id
        left join products as p ON p.id = pc.product_id
         where {$where}
          ORDER BY o.order_id DESC
        ) AS latest_orders
        ORDER BY latest_orders.order_created_at DESC
        limit ? offset ?;
        SQL, array_merge($bindings, [$limits, $offsets]));

        $countResult = DB::select(<<<SQL
         SELECT COUNT(DISTINCT id)::text AS total_orders FROM (
        SELECT o.id as id
              FROM orders AS o
        left join customers as cu ON cu.id = o.customer_id
        left join cart as ca ON ca.customer_id = cu.id
        left join combo as co ON co.id = ca.combo_id
        left join "prod-comb-map" as pc ON co.id = pc.combo_id
        left join products as p ON p.id = pc.product_id
        where {$where}
        ) AS latest_orders
        SQL, $bindings);

        return [
            'counts' => $countResult[0]->total_orders ?? 0,
            'order_counts' => $counts[0] ?? null,
            'orders' => $this->uniqueByOrderId($this->decodeJsonColumns($result)),
        ];
    }

    // ─── photographer report ─────────────────────────────────────────────────

    public function photographerReport(array $req): array
    {
        $pageNo = $req['page_no'] ?? null ?: 1;
        $limits = $req['limit'] ?? null ?: 10;
        $offsets = ($pageNo - 1) * $limits;

        $search = '';
        $searchBindings = [];
        if (! empty($req['search_text'])) {
            $search = 'AND u.name ILIKE ?';
            $searchBindings = ['%'.Crypto::encrypt($req['search_text']).'%'];
        }

        [$orderStatusCondition, $orderStatusBindings] = $this->inClause(
            'o.order_status', $req['order_status'] ?? [], 'o.order_status IS NOT NULL',
        );

        [$paymentTypeCondition, $paymentTypeBindings] = $this->inClause(
            'o.payment_type', $req['payment_type'] ?? [], 'o.payment_type IS NOT NULL',
        );

        [$photographerCondition, $photographerBindings] = $this->inClause(
            'i.user_id', $req['photographer_id'] ?? [], 'i.user_id IS NOT NULL',
        );

        [$dateFilter, $dateBindings] = $this->dateWindow(
            $req['from_date'] ?? null, $req['to_date'] ?? null, 'o.created_at',
        );

        [$kioskIds, $kioskIdBindings] = $this->kioskScope($req, 'o.kiosk_id');

        $where = "u.user_type = 'photographer' AND {$orderStatusCondition} {$dateFilter} {$search} "
            ."AND {$photographerCondition} AND {$paymentTypeCondition} {$kioskIds}";

        // Binding order follows the order the fragments appear in the SQL above.
        $bindings = array_merge(
            $orderStatusBindings, $dateBindings, $searchBindings,
            $photographerBindings, $paymentTypeBindings, $kioskIdBindings,
        );

        $counts = DB::select(<<<SQL
            SELECT
          COUNT(DISTINCT order_id)::text AS sales_count,
          COUNT(DISTINCT order_id)::text AS payment_type_count,
          SUM( amount::NUMERIC)::text AS total_amount
        FROM (
            SELECT DISTINCT ON (o.id) o.id AS order_id, COALESCE(o.amount, '0')::NUMERIC as amount
               FROM orders o
            LEFT JOIN customers c ON o.customer_id = c.id
            LEFT JOIN "fram-img-map" f ON f.customer_id = c.id
            LEFT JOIN "images-uploads" i ON i.id = f.org_img_id
            LEFT JOIN users u ON u.id = i.user_id
        where {$where}
        ) AS distinct_orders;
        SQL, $bindings);

        $result = DB::select(<<<SQL
        SELECT * FROM (
          SELECT DISTINCT ON (o.order_id)
            o.*,
            o.created_at AS order_created_at,
            u.name AS _name,
            u.email_id AS _email,
            u.*,
            c.*,
            ca.*,
            co.*,
            p.*,
            u.name, u.email_id
          FROM orders AS o
        LEFT JOIN customers AS c ON o.customer_id = c.id
        LEFT JOIN "fram-img-map" AS f ON f.customer_id = c.id
        LEFT JOIN "images-uploads" AS i ON i.id = f.org_img_id
        LEFT JOIN users AS u ON u.id = i.user_id
        LEFT JOIN cart as ca ON ca.customer_id = c.id
        LEFT JOIN combo as co ON ca.combo_id = co.id
        LEFT JOIN "prod-comb-map" as pc ON pc.combo_id = co.id
        LEFT JOIN products  as p ON p.id = pc.product_id
        where {$where}
          ORDER BY o.order_id DESC
        ) AS latest_orders
        ORDER BY latest_orders.order_created_at DESC
        limit ? offset ?;
        SQL, array_merge($bindings, [$limits, $offsets]));

        $countResult = DB::select(<<<SQL
         SELECT COUNT(DISTINCT id)::text AS total_orders FROM (
        SELECT o.id as id
          FROM orders AS o
          LEFT JOIN customers AS c ON o.customer_id = c.id
          LEFT JOIN "fram-img-map" AS f ON f.customer_id = c.id
          LEFT JOIN "images-uploads" AS i ON i.id = f.org_img_id
          LEFT JOIN users AS u ON u.id = i.user_id
        where {$where}
        ) AS latest_orders
        SQL, $bindings);

        /*
         * The upload count is deliberately NOT joined to orders — it counts every
         * image the photographer uploaded in the window, whether or not it was
         * ever ordered.
         */
        $conditions = [];
        $uploadBindings = [];

        if (! empty($req['photographer_id'])) {
            $placeholders = implode(', ', array_fill(0, count($req['photographer_id']), '?'));
            $conditions[] = "user_id IN ({$placeholders})";
            $uploadBindings = array_merge($uploadBindings, $req['photographer_id']);
        }

        if (! empty($req['from_date']) && ! empty($req['to_date'])) {
            [, $windowBindings] = $this->dateWindow($req['from_date'], $req['to_date'], 'created_at');
            $conditions[] = 'created_at BETWEEN ? AND ?';
            $uploadBindings = array_merge($uploadBindings, $windowBindings);
        }

        $whereClause = $conditions === [] ? '' : 'WHERE '.implode(' AND ', $conditions);

        $uploadCount = DB::select(
            "SELECT COUNT(id)::text FROM \"images-uploads\" {$whereClause}",
            $uploadBindings,
        );

        $orderCounts = $counts[0] ?? null;
        if ($orderCounts) {
            $orderCounts->upload_counts = (int) ($uploadCount[0]->count ?? 0);
        }

        return [
            'counts' => $countResult[0]->total_orders ?? 0,
            'order_counts' => $orderCounts,
            'orders' => $this->decryptAliases($this->uniqueByOrderId($this->decodeJsonColumns($result))),
        ];
    }

    // ─── staff report ────────────────────────────────────────────────────────

    /**
     * Unlike the other three this one is built with the ORM, joining
     * orders -> customers -> users through customers.role_id.
     */
    public function staffReport(array $req): array
    {
        $pageNo = $req['page_no'] ?? null ?: 1;
        $limits = $req['limit'] ?? null ?: 10;
        $offsets = ($pageNo - 1) * $limits;

        $orderStatus = $req['order_status'] ?? [];
        $paymentType = $req['payment_type'] ?? [];

        // staff_name is matched against the ENCRYPTED users.name, so the filter
        // values have to be encrypted first.
        $encryptNames = null;
        if (! empty($req['staff_name'])) {
            $encryptNames = array_map(fn ($n) => Crypto::encrypt($n), $req['staff_name']);
        }

        $kioskIds = null;
        $scoped = ! empty($req['logined_userId'])
            && ($req['user_type'] ?? null) !== UsersType::SUPER_ADMIN->value
            && ($req['user_type'] ?? null) !== UsersType::STAFF->value;

        if ($scoped) {
            $role = Role::with('roleKioskMap')->where('user_id', $req['logined_userId'])->first();
            $kioskIds = $role?->roleKioskMap->pluck('kiosk_id')->all() ?? [];
        }

        $build = function () use ($req, $orderStatus, $paymentType, $encryptNames, $kioskIds) {
            $query = Order::query()
                ->join('customers', 'customers.id', '=', 'orders.customer_id')
                ->leftJoin('users', 'users.role_id', '=', 'customers.role_id');

            if (! empty($req['search_text'])) {
                $s = $req['search_text'];
                $query->where(function ($q) use ($s) {
                    $q->where('orders.order_id', 'ILIKE', "%{$s}%")
                        ->orWhere('users.name', 'ILIKE', "%{$s}%")
                        ->orWhere('users.role_id', 'ILIKE', "%{$s}%");
                });
            }

            if (! empty($req['from_date']) && ! empty($req['to_date'])) {
                $from = Carbon::parse($req['from_date']);
                $to = Carbon::parse($req['to_date'])->setTime(23, 59, 59, 999999);
                $query->whereBetween('orders.created_at', [$from, $to]);
            }

            if ($orderStatus !== []) {
                $query->whereIn('orders.order_status', $orderStatus);
            }

            if ($paymentType !== []) {
                $query->whereIn('orders.payment_type', $paymentType);
            }

            if (! empty($req['role_id'])) {
                $query->where('customers.role_id', $req['role_id']);
            }

            if ($encryptNames !== null) {
                $query->whereIn('users.name', $encryptNames);
            }

            if ($kioskIds !== null) {
                $query->whereIn('orders.kiosk_id', $kioskIds);
            }

            return $query;
        };

        $count = (clone $build())->count('orders.id');

        $rows = $build()
            ->select('orders.*')
            ->with(['customer.user'])
            ->orderByDesc('orders.created_at')
            ->offset($offsets)
            ->limit($limits)
            ->get();

        /*
         * The aggregate row. `orderCondition` / `paymentTypeCondition` fall back
         * to `deleted_at IS NULL` when no filter is set, which makes the "status"
         * count equal the total count — preserved from the Node original.
         */
        $orderCondition = $orderStatus === []
            ? '"orders"."deleted_at" IS NULL'
            : 'TRUE';

        $paymentCondition = $paymentType === []
            ? '"orders"."deleted_at" IS NULL'
            : 'TRUE';

        $aggregateQuery = $build();

        if ($orderStatus !== []) {
            $placeholders = implode(', ', array_fill(0, count($orderStatus), '?'));
            $orderCondition = "\"orders\".\"order_status\" IN ({$placeholders})";
        }

        if ($paymentType !== []) {
            $placeholders = implode(', ', array_fill(0, count($paymentType), '?'));
            $paymentCondition = "\"orders\".\"payment_type\" IN ({$placeholders})";
        }

        $counts = $aggregateQuery
            /*
             * Sequelize's Model.count() with a `group` returns rows shaped
             * `{ count, ...customAttributes }` — it always adds its own `count`
             * alongside whatever attributes were asked for, and parses that one
             * to a NUMBER while the custom aggregates stay strings (bigint /
             * numeric come back from node-postgres as text). Both are reproduced
             * here so the payload matches key for key and type for type.
             */
            ->selectRaw('COUNT(*) AS count')
            ->selectRaw('COALESCE(COUNT("orders"."id"), 0)::text AS order_count')
            ->selectRaw(
                "COALESCE(COUNT(CASE WHEN {$orderCondition} THEN 1 END), 0)::text AS order_status_count",
                $orderStatus,
            )
            ->selectRaw(
                "COALESCE(COUNT(CASE WHEN {$paymentCondition} THEN 1 END), 0)::text AS payment_type_count",
                $paymentType,
            )
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN {$orderCondition} THEN CAST(\"orders\".\"amount\" AS NUMERIC) ELSE 0 END), 0)::text AS total_price",
                $orderStatus,
            );

        // `group: role_id ? ['customer.role_id'] : []` — grouping only when a
        // single role is being reported on.
        if (! empty($req['role_id'])) {
            $counts->groupBy('customers.role_id');
        }

        $countsRow = $counts->first();
        $countsAttributes = $countsRow ? $countsRow->getAttributes() : null;

        if ($countsAttributes !== null) {
            // Sequelize parses only its own `count` to an int.
            $countsAttributes['count'] = (int) $countsAttributes['count'];
        }

        return [
            'counts' => $countsAttributes,
            'staffReport' => ['count' => $count, 'rows' => $rows],
        ];
    }

    // ─── cash orders ─────────────────────────────────────────────────────────

    public function getCashOrders(array $req): array
    {
        $pageNo = $req['page_no'] ?? null ?: 1;
        $limits = $req['limit'] ?? null ?: 10;
        $offsets = ($pageNo - 1) * $limits;

        /*
         * Two completely different queries behind one endpoint: with ph_ids the
         * report pivots to the photographer join and returns raw SQL rows; without
         * it, it is a plain kiosk-joined ORM query with a flattened shape.
         */
        if (! empty($req['ph_ids'])) {
            $search = '';
            $searchBindings = [];
            if (! empty($req['search_text'])) {
                $search = 'AND o.order_id ILIKE ?';
                $searchBindings = ['%'.$req['search_text'].'%'];
            }

            [$phFilter, $phBindings] = $this->inClause('u.id', $req['ph_ids'], 'u.id IS NOT NULL');

            [$dateFilter, $dateBindings] = $this->dateWindow(
                $req['from_date'] ?? null, $req['to_date'] ?? null, 'o.created_at',
            );

            $where = "u.user_type = 'photographer' AND o.payment_type = 'cash' AND {$phFilter} {$dateFilter} {$search}";
            $bindings = array_merge($phBindings, $dateBindings, $searchBindings);

            $result = DB::select(<<<SQL
            SELECT * FROM (
              SELECT DISTINCT ON (o.order_id)
                o.*,
                o.created_at AS order_created_at,
                u.name AS _name,
                u.email_id AS _email,
                u.name, u.email_id
              FROM orders AS o
            LEFT JOIN customers AS c ON o.customer_id = c.id
            LEFT JOIN "fram-img-map" AS f ON f.customer_id = c.id
            LEFT JOIN "images-uploads" AS i ON i.id = f.org_img_id
            LEFT JOIN users AS u ON u.id = i.user_id
            LEFT JOIN cart as ca ON ca.customer_id = c.id
            LEFT JOIN combo as co ON ca.combo_id = co.id
            LEFT JOIN "prod-comb-map" as pc ON pc.combo_id = co.id
            LEFT JOIN products  as p ON p.id = pc.product_id
            where {$where}
              ORDER BY o.order_id DESC
            ) AS latest_orders
            ORDER BY latest_orders.order_created_at DESC
            limit ? offset ?;
            SQL, array_merge($bindings, [$limits, $offsets]));

            $countResult = DB::select(<<<SQL
             SELECT COUNT(DISTINCT id)::text AS total_orders FROM (
            SELECT o.id as id
              FROM orders AS o
              LEFT JOIN customers AS c ON o.customer_id = c.id
              LEFT JOIN "fram-img-map" AS f ON f.customer_id = c.id
              LEFT JOIN "images-uploads" AS i ON i.id = f.org_img_id
              LEFT JOIN users AS u ON u.id = i.user_id
            where {$where}
            ) AS latest_orders
            SQL, $bindings);

            /*
             * The Node version decrypts into `uniqueByOrderId` but then returns
             * `result[0]` — the UNDEDUPED, UNDECRYPTED rows. Reproduced: the panel
             * receives the raw rows with encrypted _name/_email.
             */
            return [
                'count' => $countResult[0]->total_orders ?? 0,
                'rows' => $this->decodeJsonColumns($result),
            ];
        }

        $query = Order::query()->where('payment_type', PaymentType::CASH->value);

        if (! empty($req['search_text'])) {
            $query->where('orders.order_id', 'ILIKE', '%'.$req['search_text'].'%');
        }

        if (! empty($req['from_date']) && ! empty($req['to_date'])) {
            $from = Carbon::parse($req['from_date']);
            $to = Carbon::parse($req['to_date'])->setTime(23, 59, 59, 999999);
            $query->whereBetween('orders.created_at', [$from, $to]);
        }

        if (! empty($req['kiosk_ids'])) {
            $query->whereHas('user', fn ($q) => $q->whereIn('users.id', $req['kiosk_ids']));
        }

        $count = (clone $query)->count();

        $cashOrders = $query
            ->with('user')
            ->orderByDesc('created_at')
            ->offset($offsets)
            ->limit($limits)
            ->get();

        /*
         * The order row and its kiosk user are FLATTENED into one object, with
         * the user's keys winning on collision. The exact Node expression is:
         *
         *     let name = decrypt(d.dataValues.user.dataValues.name);
         *     d.dataValues.user.dataValues.name = name;
         *     return { ...d.dataValues, ...d.dataValues.user.dataValues };
         *
         * Note what that produces, because it is not what it looks like:
         *
         *   - The spread copies RAW dataValues, so the flattened top-level
         *     email_id / ph_number / password are the CIPHERTEXT. Only `name` is
         *     plaintext, because it was decrypted and written back first.
         *   - The nested `user` key survives the spread as the Sequelize
         *     INSTANCE, so JSON.stringify runs its getters and that copy comes
         *     out fully decrypted.
         *
         * So the same three fields appear twice, encrypted at the top level and
         * decrypted inside `user`. Reproduced rather than tidied — the panel
         * reads the flattened `name`, and "fixing" this would start exposing
         * plaintext phone numbers and passwords at the top level, which is a
         * bigger change than it looks.
         */
        $rows = $cashOrders->map(function ($order) {
            $orderValues = $order->toArray();
            $user = $order->user;

            if (! $user) {
                return $orderValues;
            }

            // Raw column values, bypassing the decrypting accessors.
            $rawUserValues = $user->getAttributes();
            $rawUserValues['name'] = $user->name;

            // Timestamps still have to serialise the way the model would.
            foreach (['created_at', 'updated_at', 'deleted_at'] as $column) {
                if (! empty($rawUserValues[$column])) {
                    $rawUserValues[$column] = Carbon::parse($rawUserValues[$column])
                        ->utc()->format('Y-m-d\TH:i:s.v\Z');
                }
            }

            $rawUserValues['createdAt'] = $rawUserValues['created_at'] ?? null;
            $rawUserValues['updatedAt'] = $rawUserValues['updated_at'] ?? null;
            $rawUserValues['deletedAt'] = $rawUserValues['deleted_at'] ?? null;
            unset($rawUserValues['created_at'], $rawUserValues['updated_at'], $rawUserValues['deleted_at']);

            // `user` keeps the fully decrypted copy.
            $rawUserValues['user'] = $user->toArray();

            return array_merge($orderValues, $rawUserValues);
        })->all();

        return ['count' => $count, 'rows' => $rows];
    }

    // ─── deleted orders ──────────────────────────────────────────────────────

    public function getDeletedOrders(array $req): array
    {
        $pageNo = $req['page_no'] ?? null ?: 1;
        $limits = $req['limit'] ?? null ?: 10;
        $offsets = ($pageNo - 1) * $limits;

        $query = DeletedOrder::query();

        if (! empty($req['search_text'])) {
            $s = $req['search_text'];
            $query->where(function ($q) use ($s) {
                $q->where('order_id', 'ILIKE', "%{$s}%")
                    ->orWhere('kiosk_name', 'ILIKE', "%{$s}%");
            });
        }

        // The date range filters on when the order was DELETED, not when it was created.
        if (! empty($req['from_date']) && ! empty($req['to_date'])) {
            $from = Carbon::parse($req['from_date']);
            $to = Carbon::parse($req['to_date'])->setTime(23, 59, 59, 999999);
            $query->whereBetween('deleted_on', [$from, $to]);
        }

        if (! empty($req['kiosk_ids'])) {
            $query->whereIn('kiosk_id', $req['kiosk_ids']);
        }

        if (! empty($req['deleted_by_ids'])) {
            $query->whereIn('deleted_by', $req['deleted_by_ids']);
        }

        $count = (clone $query)->count();

        // kiosk_name / deleted_by_name are stored decrypted, so no decrypt here.
        $rows = $query->orderByDesc('deleted_on')->offset($offsets)->limit($limits)->get()
            ->map(fn ($r) => $r->toArray())->all();

        /*
         * Rows archived before deleted_by_name was captured (or whose actor had no
         * `name` at delete time) still carry deleted_by, so resolve the name here.
         */
        $unresolvedIds = [];
        foreach ($rows as $row) {
            if (empty($row['deleted_by_name']) && ! empty($row['deleted_by'])) {
                $unresolvedIds[$row['deleted_by']] = true;
            }
        }

        if ($unresolvedIds !== []) {
            $nameById = User::withTrashed()
                ->whereIn('id', array_keys($unresolvedIds))
                ->get()
                ->mapWithKeys(fn ($a) => [$a->id => $a->displayName()]);

            foreach ($rows as $i => $row) {
                if (empty($row['deleted_by_name']) && ! empty($row['deleted_by'])) {
                    $rows[$i]['deleted_by_name'] = $nameById[$row['deleted_by']] ?? null;
                }
            }
        }

        return ['count' => $count, 'rows' => $rows];
    }

    // ─── delete orders ───────────────────────────────────────────────────────

    /**
     * Archive an order to deleted_orders, flag its commission, then HARD delete
     * it.
     *
     * @return int rows removed, 0 when the order_id matched nothing
     */
    public function deleteOrders(string $orderId, ?string $deletedBy = null): int
    {
        return DB::transaction(function () use ($orderId, $deletedBy) {
            /*
             * withTrashed is required: the force delete below also removes
             * soft-deleted rows, so they must be archived too.
             */
            $orders = Order::withTrashed()
                ->with(['user' => fn ($q) => $q->withTrashed()])
                ->where('order_id', $orderId)
                ->get();

            if ($orders->isEmpty()) {
                return 0;
            }

            $deletedByName = null;
            if ($deletedBy) {
                // withTrashed so a since-removed staff account still resolves.
                $actor = User::withTrashed()->where('id', $deletedBy)->first();
                // admin accounts often have no `name`, only the login `user_name`
                $deletedByName = $actor?->displayName();
            }

            $deletedOn = now();

            foreach ($orders as $order) {
                $values = $order->getAttributes();

                // The kiosk's name is stored DECRYPTED in the archive so the report
                // can search it without touching users.
                $kioskName = $order->user?->name;

                DeletedOrder::create([
                    'order_pk_id' => $values['id'],
                    'order_id' => $values['order_id'] ?? null,
                    'kiosk_id' => $values['kiosk_id'] ?? null,
                    'customer_id' => $values['customer_id'] ?? null,
                    'date' => $values['date'] ?? null,
                    'time' => $values['time'] ?? null,
                    'amount' => $values['amount'] ?? null,
                    'discount' => $values['discount'] ?? null,
                    'print_count' => $values['print_count'] ?? null,
                    'order_status' => $values['order_status'] ?? null,
                    'receipt_img' => $values['receipt_img'] ?? null,
                    'remarks' => $values['remarks'] ?? null,
                    'payment_type' => $values['payment_type'] ?? null,
                    'response_json' => $order->response_json,
                    'kiosk_name' => $kioskName,
                    'order_created_at' => $values['created_at'] ?? null,
                    'order_updated_at' => $values['updated_at'] ?? null,
                    'order_deleted_at' => $values['deleted_at'] ?? null,
                    'deleted_by' => $deletedBy,
                    'deleted_by_name' => $deletedByName,
                    'deleted_on' => $deletedOn,
                    'snapshot' => $values,
                ]);
            }

            /*
             * Flag the commission ledger before the orders rows disappear. Pending
             * commission is reversed; already-paid commission keeps its amount and
             * is only marked, since the staff has been paid.
             *
             * staff-commission deliberately has no FK to orders, so the hard delete
             * below leaves those rows intact — that is what keeps the history
             * available on the Commission report.
             */
            $this->commissionEarningService->markOrderState(
                $orders->pluck('id')->all(),
                'deleted',
                $deletedBy,
            );

            return Order::withTrashed()->where('order_id', $orderId)->forceDelete();
        });
    }
}
