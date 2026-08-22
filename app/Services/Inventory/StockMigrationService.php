<?php

namespace App\Services\Inventory;

use App\Models\Cart;
use App\Models\Combo;
use App\Models\InventoryProduct;
use App\Models\Order;
use App\Models\OrderStockMigration;
use App\Models\OrderStockMigrationBomItem;
use App\Models\OrderStockMigrationItem;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Support\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Stock Migration" — turning what a customer ordered into real stock
 * deductions. New functionality, not a port of anything in
 * graspcraft_backend, adapted from an inventory reference built around a
 * POS/booking system Photocraft doesn't have. Adaptations from that
 * reference, made deliberately:
 *
 *  - Capture is automatic at order creation (captureOrder()), because the
 *    cart it snapshots is cleared by a separate, client-driven call
 *    (CartService::deleteMultipleCart, fired right after checkout)
 *    independent of when/whether the order later reaches Completed — by
 *    completion time the cart is usually already gone. So the snapshot is
 *    still taken at creation, but every READ path (findAll/findOne/
 *    previewRequirements) and the manual migrate() deduction are gated on
 *    the underlying order's status actually being Completed (see
 *    isOrderCompleted()/scopeOnlyCompletedOrders()) — a captured-but-not-
 *    yet-completed order is invisible and non-actionable everywhere this
 *    service is read from, even though the row already exists.
 *  - Deduction is additionally attempted automatically the moment an order
 *    is marked Completed (completeOrder(), called from
 *    UsersService::updateOrder()). A line no active warehouse (pooled) can
 *    fully cover is left PENDING/FAILED rather than blocking completion —
 *    a back-office user resolves it later from the manual migrate()
 *    screen. Checkout (order creation) itself stays unchanged.
 *  - Neither a Combo nor the Products inside it carry stock of their own on
 *    this screen — only inventory_products do. So a captured order line
 *    (a Combo + quantity) never deducts a Combo-level balance; it only
 *    cascades into BOM: the Combo's own bomItems() plus every constituent
 *    Product's bomItems() (resolved live via prod-comb-map at deduction
 *    time, not captured at order creation — see resolveBomRequirements()).
 *    BOM components are deducted from inventory_products (item_type
 *    INVENTORY_PRODUCT), tracked one row per (order line, component) in
 *    inventory_order_stock_migration_bom_items (OrderStockMigrationBomItem)
 *    with the same PENDING/MIGRATED/FAILED lifecycle as the line itself —
 *    which now simply mirrors the aggregate of its own BOM components,
 *    since it has nothing of its own left to deduct. Their StockMovement
 *    rows are tagged reference_type=SALES_ORDER_BOM with reference_id =
 *    the OrderStockMigrationItem's own id (NOT the order id) — two
 *    different lines in the same order can require the same component,
 *    and only per-line tagging keeps their deductions from colliding.
 *    (Combo itself can still be a stock-tracked item_type=COMBO elsewhere
 *    in the Inventory module — reverse() below still knows how to unwind
 *    an older, already-migrated combo-balance deduction from before this
 *    screen stopped creating new ones.)
 *  - Every deduction (combo balance or BOM component) is allocated across
 *    every ACTIVE warehouse, richest-available first, splitting across
 *    several if needed — see allocateAcrossWarehouses(). Still
 *    all-or-nothing per line: if the POOL across every warehouse doesn't
 *    cover the requirement, nothing is deducted from any of them and the
 *    shortfall is required-minus-pool. completeOrder() and the manual
 *    migrate() both use this same allocator (migrate() no longer takes an
 *    operator-chosen warehouse) via the shared processMigration().
 *
 * Orders carry no line items of their own, and `cart` (the only record of
 * what was ordered) is keyed by customer — not order — and is hard-deleted
 * once checkout completes (CartService::deleteMultipleCart). So there is
 * nothing left to migrate for a past order unless something captures it
 * first: captureOrder() does that, called once from
 * UsersService::createOrder() right after the order is created, before its
 * cart can be cleared. It is deliberately fire-and-forget — a snapshot
 * failure must never affect the sale, exactly like the reference's "stock
 * problems never unwind money already collected" principle.
 */
class StockMigrationService
{
    public function __construct(private readonly StockService $stockService) {}

    /**
     * @param  iterable<int, Cart>  $cartRows  the customer's cart at
     *                                         the moment this order was created
     */
    public function captureOrder(string $orderId, ?string $customerId, iterable $cartRows): void
    {
        try {
            if (OrderStockMigration::query()->where('order_id', $orderId)->exists()) {
                return;
            }

            $lines = [];
            foreach ($cartRows as $cartRow) {
                $comboId = $cartRow->combo_id ?? null;
                $qty = (int) ($cartRow->qty ?? 0);

                if (! $comboId || $qty <= 0) {
                    continue;
                }

                $lines[] = [
                    'combo_id' => $comboId,
                    'combo_name' => $cartRow->combo?->combo_name,
                    'quantity' => $qty,
                ];
            }

            if ($lines === []) {
                return;
            }

            DB::transaction(function () use ($orderId, $customerId, $lines) {
                $migration = OrderStockMigration::create([
                    'order_id' => $orderId,
                    'customer_id' => $customerId,
                    'status' => 'PENDING',
                ]);

                foreach ($lines as $line) {
                    OrderStockMigrationItem::create([
                        'migration_id' => $migration->id,
                        'combo_id' => $line['combo_id'],
                        'combo_name' => $line['combo_name'],
                        'quantity' => $line['quantity'],
                        'status' => 'PENDING',
                    ]);
                }
            });
        } catch (\Throwable) {
            // Never let a snapshot failure affect the sale.
        }
    }

    /**
     * Only migrations for orders that have actually reached Completed are
     * visible here — see class docblock. Backs the "Failed Inventory
     * Migrations" screen, so it always shows only what still needs action
     * (PENDING/FAILED) — MIGRATED/REVERSED rows have nothing left to do
     * and are deliberately never returned here.
     *
     * @return array{count: int, rows: Collection}
     */
    public function findAll(array $req): array
    {
        $pageNo = $req['page_no'] ?? null ?: 1;
        $limit = $req['limit'] ?? null ?: 10;
        $offset = ($pageNo - 1) * $limit;

        $query = OrderStockMigration::query()->with(['items.bomItems.inventoryProduct', 'warehouse', 'order.user']);
        $this->scopeOnlyCompletedOrders($query);

        $query->whereIn('status', ['PENDING', 'FAILED']);

        if (! empty($req['search_text'])) {
            $query->where('order_id', 'ILIKE', '%'.$req['search_text'].'%');
        }

        if (! empty($req['from_date'])) {
            $query->whereDate('created_at', '>=', $req['from_date']);
        }

        if (! empty($req['to_date'])) {
            $query->whereDate('created_at', '<=', $req['to_date']);
        }

        $count = (clone $query)->count();
        $rows = $query->orderByDesc('created_at')->offset($offset)->limit($limit)->get();

        return ['count' => $count, 'rows' => $rows];
    }

    public function findOne(string $orderId): ?OrderStockMigration
    {
        $query = OrderStockMigration::query()->with(['items', 'warehouse'])->where('order_id', $orderId);
        $this->scopeOnlyCompletedOrders($query);

        return $query->first();
    }

    /**
     * Read-only preview: for the given orders (only ones already
     * Completed are considered — others are silently skipped, consistent
     * with findAll()/findOne()), aggregates required quantity per
     * Inventory Product component ACROSS all their captured lines' BOM
     * cascade (see resolveBomRequirements()) — Combo/Product themselves
     * carry no stock of their own (see class docblock) and are never
     * shown here — and compares each against current
     * total-across-every-warehouse availability
     * (StockService::totalQuantity()). Returns only components that are
     * short, for the "Stock Requirements" panel shown when staff select
     * one or more orders.
     *
     * @param  array<int, string>  $orderIds
     * @return array<int, array{type: string, id: string, name: string, required: float, available: float, shortfall: float}>
     */
    public function previewRequirements(array $orderIds): array
    {
        $migrations = OrderStockMigration::query()
            ->with('items')
            ->whereIn('order_id', array_unique($orderIds))
            ->get()
            ->filter(fn (OrderStockMigration $m) => $this->isOrderCompleted($m->order_id));

        /** @var array<string, array{id: string, name: string, required: float}> $required */
        $required = [];

        foreach ($migrations as $migration) {
            foreach ($migration->items as $item) {
                foreach ($this->resolveBomRequirements($item) as $inventoryProductId => $quantity) {
                    $required[$inventoryProductId] ??= [
                        'id' => $inventoryProductId,
                        'name' => InventoryProduct::query()->find($inventoryProductId)?->name ?? $inventoryProductId,
                        'required' => 0.0,
                    ];
                    $required[$inventoryProductId]['required'] += $quantity;
                }
            }
        }

        $rows = [];

        foreach ($required as $row) {
            $available = round($this->stockService->totalQuantity(StockService::ITEM_TYPE_INVENTORY_PRODUCT, $row['id']), 3);
            $requiredQty = round($row['required'], 3);

            if ($requiredQty > $available) {
                $rows[] = [
                    'type' => 'INVENTORY_PRODUCT',
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'required' => $requiredQty,
                    'available' => $available,
                    'shortfall' => round($requiredQty - $available, 3),
                ];
            }
        }

        return $rows;
    }

    /**
     * Deduct stock for one or more orders' captured lines, pooling every
     * active warehouse (see allocateAcrossWarehouses()). Each order is
     * processed independently — a line with insufficient stock must not
     * roll back a sibling line that DID have enough; partial success is a
     * supported outcome, not a bug.
     *
     * Idempotent: a line/component already turned into a real deduction is
     * recognised and skipped, so retrying a FAILED order can never
     * double-deduct its already-succeeded lines.
     *
     * Refuses to touch any order that has not actually reached Completed
     * yet — see class docblock.
     *
     * @return array<int, array{order_id: string, outcome: string}>
     */
    public function migrate(array $orderIds): array
    {
        $warehouses = Warehouse::query()->active()->orderBy('id')->get();
        $results = [];

        foreach (array_unique($orderIds) as $orderId) {
            $migration = OrderStockMigration::query()->with('items')->where('order_id', $orderId)->first();

            if (! $migration) {
                $results[] = ['order_id' => $orderId, 'outcome' => 'not_found'];

                continue;
            }

            if (! $this->isOrderCompleted($orderId)) {
                $results[] = ['order_id' => $orderId, 'outcome' => 'order_not_completed'];

                continue;
            }

            if (! in_array($migration->status, ['PENDING', 'FAILED'], true)) {
                $results[] = ['order_id' => $orderId, 'outcome' => 'not_eligible'];

                continue;
            }

            if ($migration->items->isEmpty()) {
                $migration->update(['status' => 'MIGRATED', 'migrated_at' => now()]);
                $results[] = ['order_id' => $orderId, 'outcome' => 'nothing_to_migrate'];

                continue;
            }

            $anyFailed = $this->processMigration($migration, $orderId, $warehouses);

            $migration->update([
                'status' => $anyFailed ? 'FAILED' : 'MIGRATED',
                'migrated_at' => $anyFailed ? null : now(),
                'failure_reason' => $anyFailed ? 'One or more items had insufficient stock.' : null,
            ]);

            $results[] = ['order_id' => $orderId, 'outcome' => $anyFailed ? 'partial' : 'migrated'];
        }

        return $results;
    }

    /**
     * Auto-deduct stock the moment an order is marked Completed — called
     * from UsersService::updateOrder(), wrapped there in try/catch so a
     * stock problem can never block the order/commission update it runs
     * alongside (same principle as reverse() below). Never throws.
     */
    public function completeOrder(string $orderId): void
    {
        $migration = OrderStockMigration::query()->with('items')->where('order_id', $orderId)->first();

        if (! $migration || ! in_array($migration->status, ['PENDING', 'FAILED'], true)) {
            return;
        }

        if ($migration->items->isEmpty()) {
            $migration->update(['status' => 'MIGRATED', 'migrated_at' => now()]);

            return;
        }

        $warehouses = Warehouse::query()->active()->orderBy('id')->get();
        $anyFailed = $this->processMigration($migration, $orderId, $warehouses);

        $migration->update([
            'status' => $anyFailed ? 'FAILED' : 'MIGRATED',
            'migrated_at' => $anyFailed ? null : now(),
            'failure_reason' => $anyFailed ? 'One or more items had insufficient stock in every warehouse.' : null,
        ]);
    }

    /**
     * Processes every captured line for one migration: the line's BOM
     * cascade, against the given candidate warehouses — a line has no
     * stock of its own to deduct (see class docblock), so its own status
     * simply mirrors whether every one of its BOM components fully
     * migrated. Shared by completeOrder() (called once, at the moment of
     * completion) and migrate() (the manual batch retry) — their
     * warehouse-selection logic is identical, only how they're invoked
     * and reported differs.
     *
     * @return bool true if any line/component for this migration is left short
     */
    private function processMigration(OrderStockMigration $migration, string $orderId, Collection $warehouses): bool
    {
        $anyFailed = false;

        foreach ($migration->items as $item) {
            $bomFailed = $this->processBomComponents($item, $warehouses, $orderId);

            $item->update(['status' => $bomFailed ? 'FAILED' : 'MIGRATED', 'shortfall_quantity' => null]);

            if ($bomFailed) {
                $anyFailed = true;
            }
        }

        return $anyFailed;
    }

    /**
     * BOM requirement for one order line: the Combo's own bomItems() plus
     * every constituent Product's bomItems() (via prod-comb-map), each
     * multiplied by the line's quantity and summed per component — a
     * component needed by both the Combo directly and one of its Products
     * is required once, for their combined total. Resolved against the
     * CURRENT Product/Combo BOM definition, same as combo composition
     * itself already is (see class docblock).
     *
     * @return array<string, float> inventory_product_id => required base quantity
     */
    private function resolveBomRequirements(OrderStockMigrationItem $item): array
    {
        $combo = Combo::query()
            ->with(['bomItems', 'prodCombMap.products.bomItems'])
            ->find($item->combo_id);

        if (! $combo) {
            return [];
        }

        $required = [];

        foreach ($combo->bomItems as $line) {
            $required[$line->inventory_product_id] = ($required[$line->inventory_product_id] ?? 0)
                + (float) $line->quantity * $item->quantity;
        }

        foreach ($combo->prodCombMap as $map) {
            foreach ($map->products?->bomItems ?? [] as $line) {
                $required[$line->inventory_product_id] = ($required[$line->inventory_product_id] ?? 0)
                    + (float) $line->quantity * $item->quantity;
            }
        }

        return array_map(fn ($qty) => round($qty, 3), $required);
    }

    /**
     * Deducts every BOM component required by one order line, against the
     * given candidate warehouses. Mirrors the combo-balance line above:
     * each component gets its own OrderStockMigrationBomItem row with the
     * same PENDING/MIGRATED/FAILED lifecycle, is idempotent against a
     * re-run, and a shortfall is recorded rather than thrown — never
     * blocks the caller.
     *
     * @return bool true if any component for this line is left short
     */
    private function processBomComponents(OrderStockMigrationItem $item, Collection $candidateWarehouses, string $orderId): bool
    {
        $required = $this->resolveBomRequirements($item);
        $anyFailed = false;

        foreach ($required as $inventoryProductId => $quantity) {
            $bomLine = OrderStockMigrationBomItem::query()
                ->where('migration_item_id', $item->id)
                ->where('inventory_product_id', $inventoryProductId)
                ->first();

            if (! $bomLine) {
                $bomLine = OrderStockMigrationBomItem::create([
                    'migration_item_id' => $item->id,
                    'inventory_product_id' => $inventoryProductId,
                    'quantity' => $quantity,
                    'status' => 'PENDING',
                ]);
            }

            if ($bomLine->status === 'MIGRATED') {
                continue;
            }

            if ($this->alreadyDeductedBomComponent($bomLine)) {
                $bomLine->update(['status' => 'MIGRATED', 'shortfall_quantity' => null]);

                continue;
            }

            try {
                $result = $this->allocateAcrossWarehouses(
                    StockService::ITEM_TYPE_INVENTORY_PRODUCT,
                    $inventoryProductId,
                    (float) $bomLine->quantity,
                    $candidateWarehouses,
                    $orderId,
                    'SALES_ORDER_BOM',
                    $bomLine->migration_item_id,
                );

                if ($result['fulfilled']) {
                    $bomLine->update(['status' => 'MIGRATED', 'shortfall_quantity' => null]);
                } else {
                    $bomLine->update(['status' => 'FAILED', 'shortfall_quantity' => $result['shortfall']]);
                    $anyFailed = true;
                }
            } catch (\Throwable) {
                $bomLine->update(['status' => 'FAILED', 'shortfall_quantity' => $bomLine->quantity]);
                $anyFailed = true;
            }
        }

        return $anyFailed;
    }

    /**
     * Greedily allocates $quantity of item_type/item_id across every
     * warehouse in $candidateWarehouses (richest-available first),
     * writing one StockMovement per warehouse actually drawn from. Only
     * commits anything if the POOLED total across every candidate covers
     * the full requirement — never a partial deduction (same
     * all-or-nothing-per-line invariant the single-warehouse search
     * always had, now evaluated against the pool instead of one
     * warehouse). All writes happen in one transaction so a failure
     * partway through never leaves a split deduction half-applied.
     *
     * @return array{fulfilled: bool, shortfall: float}
     */
    private function allocateAcrossWarehouses(
        string $itemType,
        string $itemId,
        float $quantity,
        Collection $candidateWarehouses,
        string $orderId,
        string $referenceType,
        string $referenceId,
    ): array {
        $balances = $candidateWarehouses
            ->map(fn (Warehouse $w) => [
                'warehouse' => $w,
                'available' => $this->stockService->currentQuantity($itemType, $itemId, $w->id),
            ])
            ->filter(fn ($row) => $row['available'] > 0)
            ->sortByDesc('available')
            ->values();

        $totalAvailable = round((float) $balances->sum('available'), 3);

        if ($totalAvailable < $quantity) {
            return ['fulfilled' => false, 'shortfall' => round($quantity - $totalAvailable, 3)];
        }

        DB::transaction(function () use ($balances, $quantity, $itemType, $itemId, $orderId, $referenceType, $referenceId) {
            $remaining = $quantity;

            foreach ($balances as $row) {
                if ($remaining <= 0) {
                    break;
                }

                $take = min($row['available'], $remaining);

                $this->writeDeduction($itemType, $itemId, $row['warehouse'], $take, $orderId, $referenceType, $referenceId);

                $remaining = round($remaining - $take, 3);
            }
        });

        return ['fulfilled' => true, 'shortfall' => 0.0];
    }

    /** Records one OUT movement and applies it to the balance. Caller has already confirmed availability. */
    private function writeDeduction(
        string $itemType,
        string $itemId,
        Warehouse $warehouse,
        float $quantity,
        string $orderId,
        string $referenceType,
        string $referenceId,
    ): void {
        StockMovement::create([
            'movement_number' => $this->stockService->generateMovementNumber('OUT'),
            'movement_type' => 'OUT',
            'movement_subtype' => 'SALE',
            'item_type' => $itemType,
            'item_id' => $itemId,
            'warehouse_id' => $warehouse->id,
            'quantity' => $quantity,
            'unit_cost' => 0,
            'total_cost' => 0,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'notes' => 'Stock migration for order '.$orderId,
        ]);

        $this->stockService->applyMovement($itemType, $itemId, $warehouse, 'OUT', $quantity);
    }

    /**
     * Whether a BOM component already has a real deduction — guards
     * processBomComponents() against double-deducting. Scoped to this
     * migration ITEM (order line), not the whole order — see class
     * docblock on why reference_id is the line's id, not the order id.
     */
    private function alreadyDeductedBomComponent(OrderStockMigrationBomItem $bomLine): bool
    {
        return StockMovement::query()
            ->where('reference_type', 'SALES_ORDER_BOM')
            ->where('reference_id', $bomLine->migration_item_id)
            ->where('item_type', StockService::ITEM_TYPE_INVENTORY_PRODUCT)
            ->where('item_id', $bomLine->inventory_product_id)
            ->exists();
    }

    /** Whether the order (matched by the human order_id, not the row id) has actually reached Completed. */
    private function isOrderCompleted(string $orderId): bool
    {
        return Order::query()
            ->where('order_id', $orderId)
            ->where('order_status', OrderStatus::COMPLETED->value)
            ->exists();
    }

    /**
     * Restricts a migration query to orders that have reached Completed —
     * see class docblock. Excludes a soft-deleted order, matching
     * isOrderCompleted()'s implicit exclusion via the Order Eloquent model
     * (this raw subquery bypasses Eloquent, so deleted_at is checked
     * explicitly instead).
     */
    private function scopeOnlyCompletedOrders(Builder $query): void
    {
        $query->whereExists(function ($sub) {
            $sub->select(DB::raw(1))
                ->from('orders')
                ->whereColumn('orders.order_id', 'inventory_order_stock_migrations.order_id')
                ->where('orders.order_status', OrderStatus::COMPLETED->value)
                ->whereNull('orders.deleted_at');
        });
    }

    /**
     * Reverse a migrated order's stock deductions — called (non-blocking,
     * wrapped in try/catch by the caller) when the order is cancelled/
     * failed (UsersService::updateOrder) or deleted
     * (ReportsService::deleteOrders). A no-op if nothing was ever migrated;
     * idempotent if called twice. Reverses every matching StockMovement
     * row regardless of how many warehouses a deduction was split across.
     */
    public function reverse(string $orderId): void
    {
        $migration = OrderStockMigration::query()->where('order_id', $orderId)->first();

        if (! $migration || $migration->status === 'REVERSED') {
            return;
        }

        $deductions = StockMovement::query()
            ->where('reference_type', 'SALES_ORDER')
            ->where('reference_id', $orderId)
            ->where('movement_type', 'OUT')
            ->get();

        /*
         * BOM-component deductions are tagged per order LINE (the
         * OrderStockMigrationItem's own id), not per order — see class
         * docblock — so they can't be found by reference_id = $orderId
         * like the combo-balance deductions above. Look them up via the
         * order's captured lines instead.
         */
        $migrationItemIds = $migration->items()->pluck('id');

        $bomDeductions = $migrationItemIds->isEmpty() ? collect() : StockMovement::query()
            ->where('reference_type', 'SALES_ORDER_BOM')
            ->whereIn('reference_id', $migrationItemIds)
            ->where('movement_type', 'OUT')
            ->get();

        foreach ($deductions->concat($bomDeductions) as $deduction) {
            DB::transaction(function () use ($deduction, $orderId) {
                $warehouse = Warehouse::query()->find($deduction->warehouse_id);

                if (! $warehouse) {
                    return;
                }

                StockMovement::create([
                    'movement_number' => $this->stockService->generateMovementNumber('IN'),
                    'movement_type' => 'IN',
                    'movement_subtype' => 'ORDER_CANCEL_REVERSAL',
                    'item_type' => $deduction->item_type,
                    'item_id' => $deduction->item_id,
                    'warehouse_id' => $deduction->warehouse_id,
                    'quantity' => $deduction->quantity,
                    'unit_cost' => $deduction->unit_cost,
                    'total_cost' => $deduction->total_cost,
                    'reference_type' => $deduction->reference_type === 'SALES_ORDER_BOM'
                        ? 'SALES_ORDER_BOM_CANCEL'
                        : 'SALES_ORDER_CANCEL',
                    'reference_id' => $deduction->reference_id,
                    'notes' => 'Reversal for cancelled/deleted order '.$orderId,
                ]);

                $this->stockService->applyMovement(
                    $deduction->item_type,
                    $deduction->item_id,
                    $warehouse,
                    'IN',
                    (float) $deduction->quantity
                );
            });
        }

        $migration->update(['status' => 'REVERSED']);
    }
}
