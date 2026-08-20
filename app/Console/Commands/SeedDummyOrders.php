<?php

namespace App\Console\Commands;

use App\Models\Combo;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\StaffCommission;
use App\Models\User;
use App\Services\CartService;
use App\Services\ComboService;
use App\Services\StaffCodeError;
use App\Services\UsersService;
use App\Support\Enums\ProductType;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Seeds a deterministic batch of dummy orders (with matching commission
 * ledger entries) into the LOCAL database only, going through the exact same
 * services the kiosk/admin routes use (UsersService::createOrder,
 * CartService::create, ComboService::create) rather than inserting rows
 * directly — so every quirk of the real order/commission logic (the
 * staff_code resolution gate, the cart-row/combo matching in
 * CommissionEarningService::accrueForOrder, the "only completed orders
 * accrue" rule) applies to the dummy data exactly as it would to a real one.
 *
 * The local database (facecraft_local, selected via NODE_ENV) had no
 * combos, customers, or commission-eligible staff to reuse, so this command
 * creates the minimum supporting master data itself (3 staff users, 1 kiosk
 * user, 3 combos + their commission rates) rather than inventing orders
 * against nothing. The 2 pre-existing products are reused as-is, never
 * duplicated. Every row this command creates is tracked in a manifest file
 * so the whole batch can be undone with --wipe, or replaced with --fresh —
 * nothing is ever matched/deleted by guessing at name patterns.
 */
class SeedDummyOrders extends Command
{
    protected $signature = 'demo:seed-orders
        {--count=15 : How many dummy orders to create (clamped to 10-20)}
        {--fresh : Wipe any previously seeded dummy batch, then create a new one}
        {--wipe : Wipe a previously seeded dummy batch and exit (no new data created)}';

    protected $description = 'Create dummy orders (with real commission accrual) in the local database only, for testing the Order and Commission screens.';

    private const MANIFEST_RELATIVE_PATH = 'dummy-seed/orders-manifest.json';

    /**
     * Combo -> eligible-staff commission config. One deliberate mismatch is
     * included (order #7 below attributes to 'staff1' on 'B', where only
     * 'staff2'/'staff3' are configured) to prove accrueForOrder's "no
     * matching config -> skip silently" path still behaves under real data.
     */
    private const COMBO_DEFS = [
        'A' => ['name' => '[DUMMY] Standard Photo Combo', 'price' => 500, 'size' => '4x6'],
        'B' => ['name' => '[DUMMY] Deluxe Photo Combo', 'price' => 1200, 'size' => '8x10'],
        'C' => ['name' => '[DUMMY] Premium Photo Combo', 'price' => 850, 'size' => '5x7'],
    ];

    private const COMMISSION_DEFS = [
        // combo => [staffKey => [type, value]]
        'A' => ['staff1' => ['percent', 10], 'staff2' => ['amount', 50]],
        'B' => ['staff2' => ['percent', 8], 'staff3' => ['amount', 100]],
        'C' => ['staff1' => ['amount', 60], 'staff3' => ['percent', 12]],
    ];

    /**
     * The order batch itself. Rows 1-10 alone already cover every status
     * (pending/completed/cancelled/failed), an unattributed walk-in sale,
     * and the deliberate no-commission-config mismatch, so even the minimum
     * --count=10 exercises the full matrix; rows 11-20 are additional
     * completed, commission-earning volume.
     */
    private const ORDER_SPECS = [
        ['combo' => 'A', 'qty' => 1, 'status' => 'completed', 'payment' => 'cash', 'staff' => 'staff1'],
        ['combo' => 'B', 'qty' => 1, 'status' => 'completed', 'payment' => 'card', 'staff' => 'staff2'],
        ['combo' => 'C', 'qty' => 1, 'status' => 'completed', 'payment' => 'qr', 'staff' => 'staff3'],
        ['combo' => 'A', 'qty' => 2, 'status' => 'pending', 'payment' => 'cash', 'staff' => 'staff2'],
        ['combo' => 'B', 'qty' => 1, 'status' => 'cancelled', 'payment' => 'card', 'staff' => 'staff3'],
        ['combo' => 'C', 'qty' => 1, 'status' => 'failed', 'payment' => 'qr', 'staff' => 'staff1'],
        ['combo' => 'B', 'qty' => 1, 'status' => 'completed', 'payment' => 'cash', 'staff' => 'staff1'], // mismatch: staff1 not configured on B
        ['combo' => 'C', 'qty' => 2, 'status' => 'completed', 'payment' => 'card', 'staff' => null], // walk-in, no attribution
        ['combo' => 'A', 'qty' => 1, 'status' => 'completed', 'payment' => 'qr', 'staff' => 'staff2'],
        ['combo' => 'B', 'qty' => 1, 'status' => 'completed', 'payment' => 'cash', 'staff' => 'staff2'],
        ['combo' => 'C', 'qty' => 1, 'status' => 'completed', 'payment' => 'card', 'staff' => 'staff3'],
        ['combo' => 'A', 'qty' => 2, 'status' => 'completed', 'payment' => 'cash', 'staff' => 'staff1'],
        ['combo' => 'B', 'qty' => 1, 'status' => 'completed', 'payment' => 'qr', 'staff' => 'staff3'],
        ['combo' => 'C', 'qty' => 1, 'status' => 'completed', 'payment' => 'cash', 'staff' => 'staff1'],
        ['combo' => 'A', 'qty' => 1, 'status' => 'completed', 'payment' => 'card', 'staff' => 'staff2'],
        ['combo' => 'B', 'qty' => 1, 'status' => 'completed', 'payment' => 'qr', 'staff' => 'staff2'],
        ['combo' => 'C', 'qty' => 2, 'status' => 'completed', 'payment' => 'cash', 'staff' => 'staff3'],
        ['combo' => 'A', 'qty' => 1, 'status' => 'completed', 'payment' => 'card', 'staff' => 'staff1'],
        ['combo' => 'B', 'qty' => 1, 'status' => 'completed', 'payment' => 'cash', 'staff' => 'staff3'],
        ['combo' => 'C', 'qty' => 1, 'status' => 'completed', 'payment' => 'qr', 'staff' => 'staff1'],
    ];

    public function handle(UsersService $usersService, CartService $cartService, ComboService $comboService): int
    {
        if (! $this->assertLocalDatabase()) {
            return self::FAILURE;
        }

        $manifestPath = storage_path('app/'.self::MANIFEST_RELATIVE_PATH);

        if ($this->option('wipe')) {
            $this->wipe($manifestPath);

            return self::SUCCESS;
        }

        if ($this->option('fresh')) {
            $this->wipe($manifestPath);
        } elseif (File::exists($manifestPath)) {
            $this->error('A dummy batch already exists (see '.$manifestPath.'). Re-run with --fresh to replace it, or --wipe to remove it.');

            return self::FAILURE;
        }

        $count = max(10, min(20, (int) $this->option('count')));
        $specs = array_slice(self::ORDER_SPECS, 0, $count);

        $manifest = ['users' => [], 'combos' => [], 'customers' => [], 'orders' => []];
        $save = function () use (&$manifest, $manifestPath) {
            File::ensureDirectoryExists(dirname($manifestPath));
            File::put($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT));
        };

        // ─── master data: staff, kiosk, combos + commission rates ──────────

        $products = Product::query()->orderBy('created_at')->get();
        if ($products->isEmpty()) {
            $this->error('No products exist in the local database — cannot build combos without at least one.');

            return self::FAILURE;
        }
        $product1 = $products[0];
        $product2 = $products->count() > 1 ? $products[1] : $products[0];
        $this->info("Reusing existing products: \"{$product1->product_name}\"".($product2->id !== $product1->id ? " and \"{$product2->product_name}\"" : '').'.');

        $staff = [];
        foreach ($this->staffDefs() as $key => $def) {
            $user = $usersService->create($def);
            $staff[$key] = $user;
            $manifest['users'][] = $user->id;
        }
        $kiosk = $usersService->create($this->kioskDef());
        $manifest['users'][] = $kiosk->id;
        $save();
        $this->info('Created 3 dummy staff accounts and 1 dummy kiosk account.');

        $combos = [];
        foreach (self::COMBO_DEFS as $key => $def) {
            $productIds = $key === 'C' ? array_values(array_unique([$product1->id, $product2->id])) : [$product1->id];
            $userCommissions = [];
            foreach (self::COMMISSION_DEFS[$key] as $staffKey => [$type, $value]) {
                $userCommissions[] = ['user_id' => $staff[$staffKey]->id, 'commission_type' => $type, 'commission_value' => $value];
            }

            $combo = $comboService->create([
                'combo_name' => $def['name'],
                'price' => $def['price'],
                'size' => $def['size'],
                'description' => 'Seeded test combo for local order/commission testing (demo:seed-orders).',
                'status' => true,
                'product_ids' => $productIds,
                'user_commissions' => $userCommissions,
            ]);
            $combos[$key] = $combo;
            $manifest['combos'][] = $combo->id;
        }
        $save();
        $this->info('Created 3 dummy combos, each with its product mapping and commission rates.');

        // ─── orders ──────────────────────────────────────────────────────

        $rows = [];
        foreach ($specs as $i => $spec) {
            $n = $i + 1;
            $combo = $combos[$spec['combo']];
            $qty = $spec['qty'];

            $customer = $usersService->customerRegistor([
                'name' => "Dummy Customer {$n}",
                'user_name' => 'dummy_customer_'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
                'password' => 'Passw0rd!',
                'email_id' => 'dummy.customer'.str_pad((string) $n, 2, '0', STR_PAD_LEFT).'@example.test',
                'ph_number' => '98000'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
                'role_id' => $spec['staff'] ? $staff[$spec['staff']]->role_id : null,
                'status' => true,
            ]);
            $manifest['customers'][] = $customer->id;
            $save();

            $comboProducts = $combo->prodCombMap()->with('products')->get()->pluck('products')->filter();
            $productList = $comboProducts->map(fn ($p) => [
                'product_id' => $p->id,
                'photo_limit' => $p->photo_limit,
                'product_type' => $p->product_type ?: ProductType::OTHERS->value,
                'magnet_img_name' => null,
                'certificate_img_name' => null,
                'frame_img_ids' => [],
            ])->values()->all();
            $printCount = $comboProducts->sum('photo_limit') * $qty;

            $cartService->create([
                'customer_id' => $customer->id,
                'combo_id' => $combo->id,
                'combo_map' => ['combo_id' => $combo->id, 'product_list' => $productList],
                'qty' => $qty,
            ]);

            $amount = (string) ($combo->price * $qty);

            try {
                $order = $usersService->createOrder([
                    'customer_id' => $customer->id,
                    'kiosk_id' => $kiosk->id,
                    'amount' => $amount,
                    'discount' => '0',
                    'print_count' => (string) $printCount,
                    'payment_type' => $spec['payment'],
                    'order_status' => $spec['status'],
                    'staff_code' => $spec['staff'] ? $staff[$spec['staff']]->role_id : '',
                    'remarks' => 'Seeded dummy order for local testing (demo:seed-orders)',
                ]);
            } catch (StaffCodeError $e) {
                $this->error("Order #{$n}: staff code did not resolve — {$e->getMessage()}");

                continue;
            }

            $manifest['orders'][] = $order->id;
            $save();

            // Real checkout clears the basket once the order is placed.
            $cartService->deleteMultipleCart($customer->id);

            $rows[] = [
                $n, $order->order_id, $combo->combo_name, $qty, $amount,
                $spec['status'], $spec['payment'], $spec['staff'] ? $staff[$spec['staff']]->user_name : '(none)',
            ];
        }

        $this->newLine();
        $this->table(
            ['#', 'order_id', 'combo', 'qty', 'amount', 'status', 'payment', 'staff'],
            $rows,
        );

        $this->verify($manifest);

        $this->newLine();
        $this->info(count($manifest['orders']).' dummy order(s) created. Manifest: '.$manifestPath);
        $this->line('Undo with: php artisan demo:seed-orders --wipe');

        return self::SUCCESS;
    }

    /**
     * Refuses to run against anything but the local database — NODE_ENV is
     * what config/database.php's pgsql switch actually keys off, matching
     * 'dev'/'prod' explicitly and falling back to local for everything else.
     */
    private function assertLocalDatabase(): bool
    {
        $nodeEnv = env('NODE_ENV', 'local');

        if (in_array($nodeEnv, ['dev', 'prod'], true)) {
            $this->error("Refusing to run: NODE_ENV=\"{$nodeEnv}\" resolves to a non-local database. This command only ever runs against the local database.");

            return false;
        }

        $database = config('database.connections.pgsql.database');
        $host = config('database.connections.pgsql.host');
        $this->info("Target database: {$database} on {$host} (NODE_ENV={$nodeEnv}).");

        return true;
    }

    /**
     * Re-reads the commission ledger the same way CommissionController's
     * getCommissions would, and cross-checks it against what the batch's
     * own combo/staff eligibility matrix predicts, so a mismatch here means
     * something is wrong with the seeded data rather than with production
     * commission logic (which this command never touches).
     */
    private function verify(array $manifest): void
    {
        $orderIds = Order::query()->whereIn('id', $manifest['orders'])->pluck('id');
        $expectedCompleted = Order::query()->whereIn('id', $orderIds)->where('order_status', 'completed')->count();

        $ledger = StaffCommission::query()->whereIn('order_ref_id', $orderIds)->get();

        $this->newLine();
        $this->info('Commission ledger produced by this batch (as CommissionController::getCommissions would read it):');
        $this->table(
            ['staff', 'combo', 'type', 'value', 'amount', 'payout_status'],
            $ledger->map(fn ($r) => [$r->staff_name, $r->combo_name, $r->commission_type, $r->commission_value, number_format((float) $r->commission_amount, 2), $r->payout_status])->all(),
        );

        $totalsByStaff = $ledger->groupBy('staff_id')->map(fn ($rows) => $rows->sum('commission_amount'));
        foreach ($totalsByStaff as $staffId => $total) {
            $name = $ledger->firstWhere('staff_id', $staffId)?->staff_name;
            $this->line("  {$name}: total pending commission = ".number_format((float) $total, 2));
        }

        $ordersWithCommission = $ledger->pluck('order_ref_id')->unique()->count();
        $this->newLine();
        $this->line("Orders created: {$orderIds->count()} ({$expectedCompleted} completed).");
        $this->line("Commission ledger rows: {$ledger->count()}, across {$ordersWithCommission} distinct order(s).");
        $this->line('(Order #4 pending, #5 cancelled and #6 failed correctly earn nothing; #7 (config mismatch) and #8 (no staff) correctly earn nothing; every other completed+attributed order should appear above.)');
    }

    private function wipe(string $manifestPath): void
    {
        if (! File::exists($manifestPath)) {
            $this->info('Nothing to wipe — no manifest found.');

            return;
        }

        $manifest = json_decode(File::get($manifestPath), true) ?: [];

        StaffCommission::query()->whereIn('order_ref_id', $manifest['orders'] ?? [])->forceDelete();
        Order::query()->whereIn('id', $manifest['orders'] ?? [])->forceDelete();

        foreach (($manifest['customers'] ?? []) as $customerId) {
            \App\Models\Cart::query()->where('customer_id', $customerId)->forceDelete();
        }
        Customer::query()->whereIn('id', $manifest['customers'] ?? [])->forceDelete();

        \App\Models\ComboUserCommission::query()->whereIn('combo_id', $manifest['combos'] ?? [])->forceDelete();
        \App\Models\ProdCombMap::query()->whereIn('combo_id', $manifest['combos'] ?? [])->forceDelete();
        Combo::query()->whereIn('id', $manifest['combos'] ?? [])->forceDelete();

        User::query()->whereIn('id', $manifest['users'] ?? [])->forceDelete();

        File::delete($manifestPath);

        $this->info('Wiped previously seeded dummy batch: '
            .count($manifest['orders'] ?? []).' order(s), '
            .count($manifest['customers'] ?? []).' customer(s), '
            .count($manifest['combos'] ?? []).' combo(s), '
            .count($manifest['users'] ?? []).' user(s).');
    }

    private function staffDefs(): array
    {
        return [
            'staff1' => [
                'name' => 'Dummy Photographer One', 'user_name' => 'dummy_photographer_1',
                'password' => 'Passw0rd!', 'email_id' => 'dummy.photographer1@example.test',
                'ph_number' => '9990000001', 'user_type' => 'photographer', 'role_id' => 'PHDUMMY01', 'status' => true,
            ],
            'staff2' => [
                'name' => 'Dummy Staff Two', 'user_name' => 'dummy_staff_2',
                'password' => 'Passw0rd!', 'email_id' => 'dummy.staff2@example.test',
                'ph_number' => '9990000002', 'user_type' => 'staff', 'role_id' => 'STDUMMY02', 'status' => true,
            ],
            'staff3' => [
                'name' => 'Dummy Supervisor Three', 'user_name' => 'dummy_supervisor_3',
                'password' => 'Passw0rd!', 'email_id' => 'dummy.supervisor3@example.test',
                'ph_number' => '9990000003', 'user_type' => 'supervisor', 'role_id' => 'SVDUMMY03', 'status' => true,
            ],
        ];
    }

    private function kioskDef(): array
    {
        return [
            'name' => 'Dummy Kiosk 01', 'user_name' => 'dummy_kiosk_1',
            'password' => 'Passw0rd!', 'email_id' => 'dummy.kiosk1@example.test',
            'ph_number' => '9990000000', 'user_type' => 'kiosk', 'role_id' => '', 'status' => true,
        ];
    }
}
