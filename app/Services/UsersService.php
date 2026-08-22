<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\Combo;
use App\Models\Customer;
use App\Models\Frame;
use App\Models\FramImgMap;
use App\Models\ImagesUpload;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Services\Concerns\HidesJoinKeys;
use App\Services\Inventory\StockMigrationService;
use App\Support\ApiResponse;
use App\Support\AwsConfig;
use App\Support\Crypto;
use App\Support\Enums\OrderStatus;
use App\Support\Enums\UsersType;
use App\Support\Jwt;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Port of graspcraft_backend/src/modules/user/users/users.service.ts.
 *
 * Sequelize's findAndCountAll returns `{ count, rows }` and the Angular panel
 * destructures exactly that, so the list methods here return the same array
 * shape rather than a Laravel paginator.
 */
class UsersService
{
    use HidesJoinKeys;

    public function __construct(
        private readonly CommissionEarningService $commissionEarningService,
        private readonly StockMigrationService $stockMigrationService,
    ) {}

    // ─── users CRUD ──────────────────────────────────────────────────────────

    public function create(array $dto): User
    {
        $userName = strtolower($dto['user_name'] ?? '');
        $dto['user_name'] = $userName;

        $existUser = User::query()->where('user_name', $userName)->first();
        if ($existUser) {
            throw new \RuntimeException('User name Already exist...!');
        }

        $rolesRequiringUniqueId = [
            UsersType::STAFF->value,
            UsersType::MANAGER->value,
            UsersType::SUPERVISOR->value,
            UsersType::ACCOUNT_MANAGER->value,
            UsersType::ADMIN->value,
        ];

        if (in_array($dto['user_type'] ?? null, $rolesRequiringUniqueId, true)) {
            $existRoleId = User::query()->where('role_id', $dto['role_id'] ?? null)->first();
            if ($existRoleId) {
                throw new \RuntimeException('User Role id Already exist...!');
            }
        }

        return User::create($dto);
    }

    public function checkExistUser(array $dto): string
    {
        $userName = strtolower($dto['user_name']);

        /*
         * email_id is compared against the CIPHERTEXT column with a plaintext
         * value, so this only ever matches a row whose email was stored
         * unencrypted. That is the Node behaviour (the `encrypt(email_id)` line
         * is commented out there) and the panel depends on the resulting
         * "User does'nt exist" for its flow.
         */
        $existUser = User::query()
            ->where('user_name', $userName)
            ->where('email_id', $dto['email_id'])
            ->first();

        if (! $existUser) {
            throw new \RuntimeException("User does'nt exist...!");
        }

        return $existUser->id;
    }

    /** @return array{count: int, rows: Collection} */
    public function findAll(array $dto): array
    {
        $pageNo = $dto['page_no'] ?: 1;
        $limit = $dto['limit'] ?: 10;
        $offset = ($pageNo - 1) * $limit;

        $query = User::query()->whereIn('user_type', (array) $dto['user_type']);

        if (! empty($dto['search_text'])) {
            /*
             * name / ph_number / email_id are encrypted at rest, so an ILIKE on
             * the plaintext search term only ever matches user_name in practice.
             * Preserved as-is: the Node version has the `encrypt(search_text)`
             * line commented out and behaves the same way.
             */
            $search = $dto['search_text'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ILIKE', "%{$search}%")
                    ->orWhere('user_name', 'ILIKE', "%{$search}%")
                    ->orWhere('ph_number', 'ILIKE', "%{$search}%")
                    ->orWhere('email_id', 'ILIKE', "%{$search}%");
            });
        }

        $count = (clone $query)->count();
        $rows = $query->orderByDesc('created_at')->offset($offset)->limit($limit)->get();

        return ['count' => $count, 'rows' => $rows];
    }

    public function findOne(string $id): ?User
    {
        return User::query()->where('id', $id)->first();
    }

    /**
     * Kiosk accounts, optionally narrowed to the ones a given user's role grants.
     *
     * With no user_id, every kiosk is returned.
     */
    public function getKioskByUserId(?string $userId)
    {
        $kioskIds = null;

        if ($userId) {
            $role = Role::with('roleKioskMap')->where('user_id', $userId)->first();

            if (! $role) {
                throw new \RuntimeException('User not exist...!');
            }

            $kioskIds = $role->roleKioskMap->pluck('kiosk_id')->all();
        }

        $query = User::query()
            ->select(['id', 'name'])
            ->where('user_type', UsersType::KIOSK->value);

        if ($userId) {
            $query->whereIn('id', $kioskIds);
        }

        return $query->get();
    }

    /**
     * @return int number of rows updated — Sequelize's Model.update returns
     *             [affectedCount], and the controller passes it through
     */
    public function update(string $id, array $dto): array
    {
        $user = User::query()->where('id', $id)->first();

        if (! $user) {
            throw new \RuntimeException("id does'nt exist ...!");
        }

        /*
         * Assign-then-save rather than a mass update(): the encrypted columns
         * live behind model mutators, and a query-builder update would write the
         * plaintext straight into the column.
         */
        $user->fill($dto);
        $user->save();

        // Sequelize's Model.update() resolves to [affectedCount]; the
        // controller returns it verbatim, so the panel sees `data: [1]`.
        return [1];
    }

    public function remove(string $id): int
    {
        // Soft delete — Sequelize destroy() on a paranoid model.
        return User::query()->where('id', $id)->delete();
    }

    // ─── customers ───────────────────────────────────────────────────────────

    public function customerRegistor(array $dto): Customer
    {
        $existCustomer = Customer::query()
            ->where('user_name', $dto['user_name'])
            ->where('email_id', $dto['email_id'])
            ->first();

        if ($existCustomer) {
            throw new \RuntimeException('customer already exist...!');
        }

        // Customers has no model-level encryption; the password is encrypted
        // explicitly here, exactly as in the Node service.
        $dto['password'] = Crypto::encrypt($dto['password']);

        return Customer::create($dto);
    }

    /** @return array{count: int, rows: Collection} */
    public function customerImages(array $dto): array
    {
        $pageNo = $dto['page_no'] ?: 1;
        $limit = $dto['limit'] ?: 10;

        /*
         * Note the offset uses the RAW limit, not the defaulted one, so when
         * `limit` is absent the offset becomes NaN in Node and 0 here. Both end
         * up returning the first page; kept simple rather than reproducing NaN.
         */
        $offset = ($pageNo - 1) * ($dto['limit'] ?? $limit);

        $query = ImagesUpload::query()->where('customer_id', $dto['customer_id']);

        $count = (clone $query)->count();
        $rows = $query->orderByDesc('created_at')->offset($offset)->limit($limit)->get();

        return ['count' => $count, 'rows' => $rows];
    }

    /** Hard delete — `force: true` in the Node service. */
    public function deleteCustomerImages(array $dto): int
    {
        return ImagesUpload::query()->whereIn('id', $dto['ids'])->forceDelete();
    }

    public function updateCustomer(string $customerId, array $dto): array|false
    {
        $existStaff = User::query()->where('role_id', $dto['role_id'] ?? null)->first();

        if (! $existStaff) {
            return false;
        }

        $customer = Customer::query()->where('id', $customerId)->first();

        if (! $customer) {
            throw new \RuntimeException("id does't exist ..!");
        }

        $customer->fill($dto);
        $customer->save();

        // Sequelize's Model.update() resolves to [affectedCount]; the
        // controller returns it verbatim, so the panel sees `data: [1]`.
        return [1];
    }

    // ─── auth ────────────────────────────────────────────────────────────────

    /**
     * Login.
     *
     * This is an ENCRYPTED-EQUALITY match, not a hash comparison: the submitted
     * password is encrypted with the deterministic cipher and looked up as a
     * column value. The bcrypt path above it in the Node source is commented out
     * and does not run. See App\Support\Crypto for why the cipher must stay
     * deterministic.
     *
     * @return User|Customer|false false when no row matched
     */
    public function userLogin(array $dto): User|Customer|false
    {
        $username = trim($dto['username'] ?? '');
        $password = trim($dto['password'] ?? '');
        $type = $dto['type'] ?? null;

        $encryptedPassword = Crypto::encrypt($password);

        $validatedUser = null;

        if ($type != UsersType::CUSTOMER->value) {
            $validatedUser = User::query()
                ->where('user_name', $username)
                // Compare against the raw column: the model mutator would
                // re-encrypt an already-encrypted value.
                ->whereRaw('password = ?', [$encryptedPassword])
                ->where('status', true)
                ->first();

            /*
             * Every known user_type is excluded from this check, so the body only
             * runs for a row with an unrecognised user_type. Preserved verbatim
             * from the Node source rather than simplified — the list is what
             * makes the branch dead, and "simplifying" it would start rejecting
             * real logins.
             */
            $knownTypes = [
                UsersType::SUPER_ADMIN->value,
                UsersType::ADMIN->value,
                UsersType::MANAGER->value,
                UsersType::SUPERVISOR->value,
                UsersType::ACCOUNT_MANAGER->value,
                UsersType::STAFF->value,
                UsersType::PHOTOGRAPHER->value,
                UsersType::KIOSK->value,
            ];

            if ($validatedUser?->id && ! in_array($validatedUser->user_type, $knownTypes, true)) {
                $roleUser = Role::query()
                    ->where('user_id', $validatedUser->id)
                    ->where('is_active', true)
                    ->first();

                if (! $roleUser) {
                    throw new \RuntimeException("User does'nt exist (or) inactive");
                }
            }
        }

        if ($type == UsersType::CUSTOMER->value) {
            $validatedUser = Customer::query()
                ->where('user_name', $username)
                ->where('password', $encryptedPassword)
                ->where('status', true)
                ->first();
        }

        // The token is minted before the null check in the Node source too; it
        // is simply discarded when there is no user.
        $token = Jwt::generate([
            'user_name' => $username,
            'password' => $encryptedPassword,
        ]);

        if (! $validatedUser) {
            return false;
        }

        // Sequelize: `validatedUser.dataValues.token = token` — an extra key
        // grafted onto the serialised row, which the panel reads as `data.token`.
        $validatedUser->setAttribute('token', $token);

        return $validatedUser;
    }

    /**
     * `delete req.headers.authorization` — a no-op on a stateless API. Returns
     * true to match, since the controller only checks truthiness.
     */
    public function userLogout(): bool
    {
        return true;
    }

    // ─── face matching ───────────────────────────────────────────────────────

    /**
     * Match a kiosk-captured face against the day's uploads.
     *
     * @return array<string, mixed>|string|false 'noframe' when no active frame
     *                                           exists, false when Rekognition errored
     */
    public function compareImages(array $data, ?string $fileBytes): array|string|false
    {
        $parsed = json_decode($data['photoFrameRequest'] ?? '{}', true) ?: [];
        $date = $parsed['date'] ?? null;
        $fromTime = $parsed['from_time'] ?? null;
        $toTime = $parsed['to_time'] ?? null;

        $images = null;

        if ($fileBytes !== null) {
            try {
                $images = (new AwsConfig)->getMatchedImages($fileBytes);
            } catch (\Throwable) {
                // A Rekognition failure (including "no faces detected") is
                // reported as a plain false, which the controller turns into EM013.
                return false;
            }
        }

        $frames = Frame::query()->where('status', true)->get();

        if ($frames->isEmpty()) {
            return 'noframe';
        }

        if ($fileBytes === null || ($images !== null && $images->isEmpty())) {
            return $this->getAllImages($date, $frames, $fromTime, $toTime);
        }

        return [
            'frames' => $frames,
            'comparedImages' => $images,
            'unmatchedFaces' => false,
        ];
    }

    /**
     * The no-face fallback: every upload in the requested window.
     *
     * `is_filter` tells the kiosk whether it is showing a filtered view (an
     * explicit time range, or any date other than today) or the live feed.
     *
     * @return array<string, mixed>
     */
    public function getAllImages(?string $date, $frames, ?string $fromTime, ?string $toTime): array
    {
        [$day, $month, $year] = array_map('intval', explode('-', (string) $date));

        $startOfDay = Carbon::create($year, $month, $day, 0, 0, 0);
        $endOfDay = Carbon::create($year, $month, $day, 23, 59, 59)->setMicro(999000);

        $query = ImagesUpload::query()->select(['id', 'img_url']);

        if ($date) {
            $query->whereBetween('created_at', [$startOfDay, $endOfDay]);
        }

        if ($fromTime && $toTime) {
            // `time` is a display string ("09:05 PM"), compared as a string.
            $query->whereBetween('time', [strtoupper($fromTime), strtoupper($toTime)]);
        }

        $datas = $query->orderByDesc('created_at')->get();

        $now = Carbon::now();
        $isSameDate = $now->year === $year && $now->month === $month && $now->day === $day;

        if (($fromTime && $toTime) || ! $isSameDate) {
            return [
                'frames' => $datas->isEmpty() ? [] : $frames,
                'comparedImages' => $datas,
                'unmatchedFaces' => true,
                'is_filter' => true,
            ];
        }

        if ($datas->isEmpty()) {
            return [
                'frames' => [],
                'comparedImages' => [],
                'unmatchedFaces' => true,
                'is_filter' => false,
            ];
        }

        return [
            'frames' => $frames,
            'comparedImages' => $datas,
            'unmatchedFaces' => true,
            'is_filter' => false,
        ];
    }

    /**
     * The kiosk's product screen: every active combo with its products, plus the
     * framed photos already built for this customer.
     *
     * @return array<string, mixed>|false false when no combo is configured
     */
    public function getFrameWithImages(string $customerId): array|false
    {
        $comboProduct = Combo::query()
            ->with(['prodCombMap' => function ($q) {
                $q->select(['id', 'combo_id', 'product_id'])
                    ->whereHas('products', fn ($p) => $p->where('status', true))
                    ->with(['products' => fn ($p) => $p->where('status', true)]);
            }])
            ->where('status', true)
            ->orderBy('created_at')
            ->get();

        $getMappedFrames = FramImgMap::query()->where('customer_id', $customerId)->get();

        if ($comboProduct->isEmpty()) {
            return false;
        }

        /*
         * Sequelize's `attributes: ['id']` on the include emits exactly one
         * column. Eloquent has to select combo_id (to match the hasMany) and
         * product_id (to match the belongsTo), so they are hidden again here —
         * otherwise the payload carries two keys the panel never saw.
         */
        foreach ($comboProduct as $combo) {
            $combo->prodCombMap->makeHidden(['combo_id', 'product_id']);
        }

        return [
            'imgFrameMapped' => $getMappedFrames,
            'comboProduct' => $comboProduct,
        ];
    }

    // ─── orders ──────────────────────────────────────────────────────────────

    /**
     * Create an order and, when it is already complete, accrue commission.
     *
     * @throws StaffCodeError when a staff_code was supplied but does not resolve
     */
    public function createOrder(array $dto): ?Order
    {
        ['date' => $date, 'time' => $time] = ApiResponse::formatDateTime();
        $orderId = ApiResponse::generateOrderId();

        /*
         * The staff code is optional: an order can be placed without one. But if
         * one IS supplied it has to be valid — an unknown or deactivated ID throws
         * and no order is written, so the operator can correct the typo rather than
         * completing a sale whose commission silently goes nowhere.
         */
        $staffResolution = ['staff_id' => null, 'staff_name' => null, 'note' => null];

        if (trim($dto['staff_code'] ?? '') !== '') {
            $staffResolution = $this->commissionEarningService
                ->resolveStaffCodeOrThrow($dto['staff_code']);
        }

        $payload = array_merge(
            ['date' => $date, 'time' => $time, 'order_id' => $orderId],
            $dto,
            [
                'staff_id' => $staffResolution['staff_id'],
                'staff_resolve_note' => $staffResolution['note'],
            ],
        );

        $order = DB::transaction(function () use ($payload, $staffResolution, $dto) {
            $created = Order::create($payload);

            if ($staffResolution['staff_id']) {
                $this->commissionEarningService->accrueForOrder([
                    'order_ref_id' => $created->id,
                    'order_id' => $created->order_id,
                    'customer_id' => $created->customer_id,
                    'order_status' => $created->order_status,
                    'staff_id' => $staffResolution['staff_id'],
                    'staff_code' => $dto['staff_code'] ?? null,
                    'staff_name' => $staffResolution['staff_name'],
                ]);
            }

            return $created;
        });

        if (! $order) {
            return null;
        }

        $res = Order::query()
            ->with(['customer.cart.combo'])
            ->where('order_id', $order->order_id)
            ->first();

        /*
         * `sendMail` tells the kiosk whether this order includes an emailed
         * softcopy product, which drives the "send email" prompt.
         */
        $sendMail = false;
        foreach ($res?->customer?->cart ?? [] as $cartItem) {
            foreach ($cartItem->combo_json['product_list'] ?? [] as $product) {
                if (($product['product_type'] ?? null) === 'email') {
                    $sendMail = true;
                    break 2;
                }
            }
        }

        /*
         * The staff member behind this order, resolved through customers.role_id.
         *
         * The Node line is `roleName?.dataValues.name`, and dataValues bypasses
         * Sequelize's decrypting getter — so what the panel actually receives
         * here is the CIPHERTEXT, not the staff member's name. rawAttribute()
         * reproduces that. It looks like a bug, and probably is, but the kiosk
         * only ever tests this field for presence; decrypting it would be a
         * visible behaviour change.
         */
        $roleId = $res?->customer?->role_id;
        $roleName = $roleId ? User::query()->where('role_id', $roleId)->first() : null;

        if ($res?->customer) {
            $res->customer->setAttribute('role_name', $roleName?->rawAttribute('name'));
        }

        $res?->setAttribute('sendMail', $sendMail);

        /*
         * Stock Migration snapshot: cart is keyed by customer (not order) and
         * gets hard-deleted by CartService::deleteMultipleCart right after
         * checkout, so this is the only moment the order's contents can be
         * captured for a later manual migration. Fire-and-forget — swallowed
         * internally by captureOrder(), never allowed to affect the sale.
         */
        $this->stockMigrationService->captureOrder(
            $order->order_id,
            $order->customer_id,
            $res?->customer?->cart ?? [],
        );

        return $res;
    }

    /**
     * Update an order, keeping the commission ledger in step with its status.
     *
     * @return int rows updated
     */
    public function updateOrder(string $orderId, array $dto): array
    {
        $nextStatus = $dto['order_status'] ?? null;

        // Status is unchanged by this call — nothing for commission to do.
        if (! $nextStatus) {
            return [Order::query()->where('order_id', $orderId)->update($dto)];
        }

        return DB::transaction(function () use ($orderId, $dto, $nextStatus) {
            /*
             * order_id is not unique (no unique index, and generateOrderId can
             * repeat), so operate on every matching row the update touches.
             */
            $orders = Order::query()->where('order_id', $orderId)->get();

            $updated = [Order::query()->where('order_id', $orderId)->update($dto)];

            $orderRefIds = $orders->pluck('id')->all();

            if (in_array($nextStatus, [OrderStatus::CANCELLED->value, OrderStatus::FAILED->value], true)) {
                /*
                 * Pending commission is reversed; already-paid commission keeps its
                 * amount and is only flagged, since the staff has been paid.
                 * There is no actor on UpdateOrderDto, so the change is recorded
                 * without one.
                 */
                $this->commissionEarningService->markOrderState($orderRefIds, 'cancelled', null);

                /*
                 * Reverse any Stock Migration deductions for this order. Wrapped
                 * so a stock problem can never roll back the status/commission
                 * update this is nested inside — same "money side and stock side
                 * are allowed to disagree temporarily" principle as capture.
                 */
                try {
                    $this->stockMigrationService->reverse($orderId);
                } catch (\Throwable) {
                    // Never let a reversal failure block the order status update.
                }
            }

            if ($nextStatus === OrderStatus::COMPLETED->value) {
                /*
                 * An order can be created as pending and completed later, so this is
                 * the second place commission can first be earned. accrueForOrder
                 * skips cart rows that already produced an entry, so re-completing
                 * an order cannot double-credit.
                 */
                foreach ($orders as $order) {
                    if (! $order->staff_id) {
                        continue;
                    }

                    $this->commissionEarningService->accrueForOrder([
                        'order_ref_id' => $order->id,
                        'order_id' => $order->order_id,
                        'customer_id' => $order->customer_id,
                        'order_status' => OrderStatus::COMPLETED->value,
                        'staff_id' => $order->staff_id,
                        'staff_code' => $order->staff_code,
                        'staff_name' => null,
                    ]);
                }

                /*
                 * Attempt to deduct stock for whatever was captured at checkout.
                 * Wrapped so a stock problem can never roll back the status/
                 * commission update this is nested inside — same "money side and
                 * stock side are allowed to disagree temporarily" principle as
                 * the reversal above. A line no active warehouse can cover is
                 * left PENDING/FAILED for the manual Stock Migration screen; the
                 * order itself still completes regardless.
                 */
                try {
                    $this->stockMigrationService->completeOrder($orderId);
                } catch (\Throwable) {
                    // Never let a stock problem block the order status update.
                }
            }

            return $updated;
        });
    }

    /**
     * Paginated order list plus the summed amount across the WHOLE filtered set.
     *
     * @return array{count: int, rows: mixed, total_amount: float}
     */
    public function findAllOrders(array $req): array
    {
        $pageNo = $req['page_no'] ?? null ?: 1;
        $limit = $req['limit'] ?? null ?: 10;
        $offset = ($pageNo - 1) * $limit;

        $applyFilters = function ($query) use ($req) {
            if (! empty($req['search_text'])) {
                $s = $req['search_text'];
                $query->where(function ($q) use ($s) {
                    $q->where('orders.order_id', 'ILIKE', "%{$s}%")
                        ->orWhere('orders.date', 'ILIKE', "%{$s}%")
                        ->orWhere('orders.time', 'ILIKE', "%{$s}%")
                        ->orWhere('orders.order_status', 'ILIKE', "%{$s}%")
                        ->orWhere('orders.payment_type', 'ILIKE', "%{$s}%");
                });
            }

            if (! empty($req['from_date']) && ! empty($req['to_date'])) {
                $from = Carbon::parse($req['from_date']);
                $to = Carbon::parse($req['to_date'])->setTime(23, 59, 59, 999999);
                $query->whereBetween('orders.created_at', [$from, $to]);
            }

            // '$user.id$' — a filter on the joined kiosk user, not on orders.
            if (! empty($req['user_id']) && is_array($req['user_id'])) {
                $query->whereHas('user', fn ($q) => $q->whereIn('users.id', $req['user_id']));
            }

            return $query;
        };

        // Staff filter resolves the staff's role_id, then matches customers on it.
        $staff = null;
        if (! empty($req['staff_id'])) {
            $staff = User::query()
                ->select(['id', 'role_id'])
                ->where('id', $req['staff_id'])
                ->where('user_type', UsersType::STAFF->value)
                ->first();
        }

        // Kiosk scoping for a role-limited operator.
        $kioskIds = [];
        $scopeToKiosks = ! empty($req['logined_userId'])
            && ! in_array($req['user_type'] ?? null, [
                UsersType::SUPER_ADMIN->value,
                UsersType::ADMIN->value,
                UsersType::ACCOUNT_MANAGER->value,
                UsersType::STAFF->value,
            ], true);

        if ($scopeToKiosks) {
            $role = Role::with('roleKioskMap')->where('user_id', $req['logined_userId'])->first();
            $kioskIds = $role?->roleKioskMap->pluck('kiosk_id')->all() ?? [];
        }

        $build = function () use ($applyFilters, $staff, $req, $kioskIds) {
            $query = Order::query();
            $applyFilters($query);

            if (! empty($req['staff_id'])) {
                $roleId = $staff?->role_id ?? '';
                $query->whereHas('customer', fn ($q) => $q->where('customers.role_id', $roleId));
            }

            if ($kioskIds !== []) {
                $query->whereIn('orders.kiosk_id', $kioskIds);
            }

            return $query;
        };

        $count = $build()->count();

        $rows = $build()
            ->with([
                'user' => fn ($q) => $q->select(['id', 'name']),
                'customer' => fn ($q) => $q->select(['id', 'role_id']),
            ])
            ->orderByDesc('created_at')
            ->offset($offset)
            ->limit($limit)
            ->get();

        // Sequelize asks for ['name'] / ['role_id'] alone; `id` is only here so
        // Eloquent can match the belongsTo.
        $this->hideJoinKeys($rows, ['user' => ['id'], 'customer' => ['id']]);

        // SUM(CAST(amount AS NUMERIC)) across the filtered set, not just this page.
        $totalAmount = (float) ($build()->sum(DB::raw('CAST(orders.amount AS NUMERIC)')) ?? 0);

        return ['count' => $count, 'rows' => $rows, 'total_amount' => $totalAmount];
    }

    public function findOneOrder(string $id): ?Order
    {
        return Order::query()
            ->with(['user' => fn ($q) => $q->select(['id', 'name'])])
            ->where('id', $id)
            ->first();
    }

    // ─── emailed images / zip ────────────────────────────────────────────────

    /**
     * The framed images attached to an order's emailed products.
     *
     * Also bumps print_count and writes the customer's name/email back — a
     * side effect the panel relies on after an email send.
     *
     * @return string[]
     */
    public function getEmailedImages(array $dto): array
    {
        $order = Order::query()
            ->select(['id', 'print_count', 'customer_id', 'order_id'])
            ->with(['customer' => fn ($q) => $q->select(['id', 'name', 'email_id'])
                ->with(['cart' => fn ($c) => $c->select(['id', 'customer_id', 'combo_json'])]),
            ])
            ->where('order_id', $dto['order_id'])
            ->first();

        $emailFrameImgIds = $this->emailFrameImageIds($order);

        $imagesData = FramImgMap::query()
            ->select(['id', 'merged_img_name', 'edited_img_name'])
            ->whereIn('id', $emailFrameImgIds)
            ->get();

        $images = $imagesData->map(fn ($img) => $img->edited_img_name ?? $img->merged_img_name)->all();

        Order::query()
            ->where('order_id', $dto['order_id'])
            ->update(['print_count' => (int) ($order?->print_count) + 1]);

        Customer::query()
            ->where('id', $dto['customer_id'])
            ->update([
                'name' => $order?->customer?->name,
                'email_id' => $order?->customer?->email_id,
            ]);

        return $images;
    }

    /**
     * The frame-image ids carried by an order's `email` products.
     *
     * The basket is only recorded in cart.combo_json, so this walks
     * order -> customer -> cart[] -> combo_json.product_list[].
     *
     * @return string[]
     */
    public function emailFrameImageIds(?Order $order): array
    {
        $ids = [];

        foreach ($order?->customer?->cart ?? [] as $cartItem) {
            foreach ($cartItem->combo_json['product_list'] ?? [] as $product) {
                if (($product['product_type'] ?? null) === 'email') {
                    $ids = array_merge($ids, $product['frame_img_ids'] ?? []);
                }
            }
        }

        return $ids;
    }

    /**
     * Order data needed to build the download zip.
     *
     * @return array{order: ?Order, imageNames: string[]}
     */
    public function zipImageNames(string $orderId): array
    {
        $order = Order::query()
            ->select(['id', 'print_count', 'customer_id', 'order_id'])
            ->with(['customer' => fn ($q) => $q->select(['id', 'name', 'email_id'])
                ->with(['cart' => fn ($c) => $c->select(['id', 'customer_id', 'combo_json'])]),
            ])
            ->where('order_id', $orderId)
            ->first();

        $imageNames = FramImgMap::query()
            ->whereIn('id', $this->emailFrameImageIds($order))
            ->pluck('merged_img_name')
            ->filter()
            ->values()
            ->all();

        return ['order' => $order, 'imageNames' => $imageNames];
    }
}
