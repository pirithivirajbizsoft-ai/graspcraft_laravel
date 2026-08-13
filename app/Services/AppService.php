<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Support\AwsConfig;
use App\Support\Enums\UsersType;
use App\Support\Mail\Mailer;
use Carbon\Carbon;

/** Port of graspcraft_backend/src/app.service.ts. */
class AppService
{
    public function __construct(private readonly UsersService $usersService) {}

    public function getHello(): string
    {
        return 'Hello World!';
    }

    /**
     * The admin landing screen: a row of counters plus the first page of orders.
     *
     * @return array{dashboard_counts: array<string, mixed>, orders: array<string, mixed>}
     */
    public function adminDashboard(array $req): array
    {
        $loginedUserId = $req['logined_userId'] ?? null;

        $staff = null;
        if (! empty($req['staff_id'])) {
            $staff = User::query()
                ->select(['id', 'role_id', 'user_type'])
                ->where('id', $req['staff_id'])
                ->where('user_type', UsersType::STAFF->value)
                ->first();
        }

        /*
         * Kiosk scoping. Super admin, admin, account manager and staff see
         * everything; anyone else is limited to the kiosks their role grants.
         */
        $scopeToKiosks = $loginedUserId
            && ! in_array($req['user_type'] ?? null, [
                UsersType::SUPER_ADMIN->value,
                UsersType::ADMIN->value,
                UsersType::ACCOUNT_MANAGER->value,
                UsersType::STAFF->value,
            ], true);

        $kioskIds = [];
        if ($scopeToKiosks) {
            $role = Role::with('roleKioskMap')->where('user_id', $loginedUserId)->first();
            $kioskIds = $role?->roleKioskMap->pluck('kiosk_id')->all() ?? [];
        }

        /*
         * "Today" for the today_orders counter.
         *
         * The Node version builds this window with `new Date().setHours(0,0,0,0)`,
         * which is midnight in the HOST PROCESS's local timezone — so the counter
         * silently depends on the server's TZ setting rather than on anything in
         * the app. The deployed servers run UTC, which is why this has never been
         * visible in production.
         *
         * Here the window is anchored to config('app.timezone') (UTC by default),
         * which reproduces the deployed behaviour exactly. If a host ever runs on
         * a non-UTC clock, set APP_TIMEZONE to that zone so the two agree.
         *
         * Note this affects ONLY today_orders. The other "today" counters use
         * `DATE(created_at) = CURRENT_DATE` in SQL, which is evaluated in the
         * session timezone — pinned to UTC in config/database.php.
         */
        $today = Carbon::now(config('app.timezone'));
        $start = $today->copy()->startOfDay();
        $end = $today->copy()->endOfDay();

        $query = Order::query()
            ->selectRaw(
                'COUNT(CASE WHEN "orders"."created_at" BETWEEN ? AND ? THEN 1 END) AS today_orders',
                [$start->toIso8601ZuluString('millisecond'), $end->toIso8601ZuluString('millisecond')],
            )
            ->selectRaw(<<<'SQL'
                COUNT(CASE WHEN "orders"."order_status" = 'completed' THEN 1 END) AS completed_orders
            SQL)
            ->selectRaw(<<<'SQL'
                COUNT(CASE
                    WHEN "orders"."order_status" = 'completed'
                    AND DATE("orders"."created_at") = CURRENT_DATE
                    THEN 1
                END) AS completed_orders_today
            SQL)
            ->selectRaw(<<<'SQL'
                COUNT(CASE WHEN "orders"."order_status" = 'pending' THEN 1 END) AS pending_orders
            SQL)
            ->selectRaw(<<<'SQL'
                COUNT(CASE
                    WHEN "orders"."order_status" = 'pending'
                    AND DATE("orders"."created_at") = CURRENT_DATE
                    THEN 1
                END) AS pending_orders_today
            SQL)
            ->selectRaw(<<<'SQL'
                COUNT(CASE
                    WHEN "orders"."order_status" = 'cancelled'
                    AND DATE("orders"."created_at") = CURRENT_DATE
                    THEN 1
                END) AS cancelled_orders_today
            SQL)
            // Subqueries, not joins — they must not be narrowed by the filters below.
            ->selectRaw(<<<'SQL'
                (SELECT COUNT(*) FROM users WHERE user_type = 'photographer' AND deleted_at IS NULL) AS photographer_count
            SQL)
            ->selectRaw(<<<'SQL'
                (SELECT COUNT(*) FROM users WHERE user_type = 'kiosk' AND deleted_at IS NULL) AS kiosk_count
            SQL);

        if ($staff) {
            // Sequelize's include carries a `where`, which makes the join required.
            $query->whereHas('customer', fn ($q) => $q->where('customers.role_id', $staff->role_id ?: ''));
        }

        if ($scopeToKiosks) {
            $query->whereIn('orders.kiosk_id', $kioskIds);
        }

        $counts = (array) $query->first()?->getAttributes();

        /*
         * COUNT() is bigint. node-postgres hands bigint to JS as a STRING (it
         * cannot fit int64 in a JS number), so Sequelize gives the panel
         * "0"/"12" here; PHP 8.1+'s pdo_pgsql returns native ints instead.
         *
         * The panel renders these straight into the dashboard tiles, so either
         * type displays — but the diff harness compares types, and a silent
         * string/number flip is exactly the kind of thing that breaks a strict
         * comparison somewhere downstream. Cast to match.
         */
        $counts = array_map(fn ($v) => $v === null ? null : (string) $v, $counts);

        $orders = $this->usersService->findAllOrders($req);

        $counts['total_amount'] = $orders['total_amount'];
        $counts['counts'] = $orders['count'];

        return ['dashboard_counts' => $counts, 'orders' => $orders];
    }

    /**
     * Send the customer their soft-copy link.
     *
     * Returns the request DTO unchanged — the panel only needs to know the call
     * succeeded, and mail failures are swallowed (see Mailer).
     */
    public function sendPhotoEmail(array $dto): array
    {
        $existOrder = Order::query()
            ->with(['customer' => fn ($q) => $q->select(['id', 'name', 'email_id'])])
            ->where('customer_id', $dto['customer_id'])
            ->where('order_id', $dto['order_id'])
            ->first();

        $link = config('photocraft.emailsent_link');

        (new Mailer)->mailSent($dto, $existOrder?->customer?->name, $link);

        return $dto;
    }

    /** Presigned download URL for a framed image. */
    public function downloadFramedImg(string $img): string
    {
        return (new AwsConfig)->downloadImg($img);
    }
}
