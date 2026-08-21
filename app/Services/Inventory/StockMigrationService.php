<?php

namespace App\Services\Inventory;

use App\Models\Cart;
use App\Models\OrderStockMigration;
use App\Models\OrderStockMigrationItem;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Stock Migration" — turning what a customer ordered into real stock
 * deductions. New functionality, not a port of anything in
 * graspcraft_backend, adapted from an inventory reference built around a
 * POS/booking system Photocraft doesn't have. Two adaptations from that
 * reference, made deliberately:
 *
 *  - Trigger is MANUAL, from a back-office screen (migrate()) — not
 *    automatic at order creation. Checkout stays completely unchanged.
 *  - A Combo line always deducts the Combo's OWN stock balance — there is
 *    no Bill-of-Materials cascade into its component Products (Combo is
 *    already tracked independently of its components elsewhere in this
 *    module).
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

    /** @return array{count: int, rows: Collection} */
    public function findAll(array $req): array
    {
        $pageNo = $req['page_no'] ?? null ?: 1;
        $limit = $req['limit'] ?? null ?: 10;
        $offset = ($pageNo - 1) * $limit;

        $query = OrderStockMigration::query()->with(['items', 'warehouse']);

        if (! empty($req['status'])) {
            $query->where('status', $req['status']);
        }

        if (! empty($req['search_text'])) {
            $query->where('order_id', 'ILIKE', '%'.$req['search_text'].'%');
        }

        $count = (clone $query)->count();
        $rows = $query->orderByDesc('created_at')->offset($offset)->limit($limit)->get();

        return ['count' => $count, 'rows' => $rows];
    }

    public function findOne(string $orderId): ?OrderStockMigration
    {
        return OrderStockMigration::query()->with(['items', 'warehouse'])->where('order_id', $orderId)->first();
    }

    /**
     * Deduct stock for one or more orders' captured lines from the given
     * warehouse. Each line is its own small transaction, deliberately NOT
     * one transaction over the whole batch — a line with insufficient
     * stock must not roll back a sibling line that DID have enough;
     * partial success is a supported outcome, not a bug (matches the
     * reference this was built from).
     *
     * Idempotent: a line already turned into a real SALES_ORDER-tagged
     * movement is recognised and skipped, so retrying a FAILED order can
     * never double-deduct its already-succeeded lines.
     *
     * @return array<int, array{order_id: string, outcome: string}>
     */
    public function migrate(array $orderIds, string $warehouseId): array
    {
        $warehouse = Warehouse::query()->findOrFail($warehouseId);
        $results = [];

        foreach (array_unique($orderIds) as $orderId) {
            $migration = OrderStockMigration::query()->with('items')->where('order_id', $orderId)->first();

            if (! $migration) {
                $results[] = ['order_id' => $orderId, 'outcome' => 'not_found'];

                continue;
            }

            if (! in_array($migration->status, ['PENDING', 'FAILED'], true)) {
                $results[] = ['order_id' => $orderId, 'outcome' => 'not_eligible'];

                continue;
            }

            if ($migration->items->isEmpty()) {
                $migration->update(['status' => 'MIGRATED', 'warehouse_id' => $warehouseId, 'migrated_at' => now()]);
                $results[] = ['order_id' => $orderId, 'outcome' => 'nothing_to_migrate'];

                continue;
            }

            $anyFailed = false;

            foreach ($migration->items as $item) {
                if ($item->status === 'MIGRATED') {
                    continue;
                }

                try {
                    DB::transaction(function () use ($item, $warehouse, $orderId) {
                        $alreadyDeducted = StockMovement::query()
                            ->where('reference_type', 'SALES_ORDER')
                            ->where('reference_id', $orderId)
                            ->where('item_type', StockService::ITEM_TYPE_COMBO)
                            ->where('item_id', $item->combo_id)
                            ->exists();

                        if ($alreadyDeducted) {
                            $item->update(['status' => 'MIGRATED', 'shortfall_quantity' => null]);

                            return;
                        }

                        $available = $this->stockService->currentQuantity(
                            StockService::ITEM_TYPE_COMBO,
                            $item->combo_id,
                            $warehouse->id
                        );

                        if ((float) $item->quantity > $available) {
                            $item->update([
                                'status' => 'FAILED',
                                'shortfall_quantity' => (float) $item->quantity - $available,
                            ]);

                            return;
                        }

                        StockMovement::create([
                            'movement_number' => $this->stockService->generateMovementNumber('OUT'),
                            'movement_type' => 'OUT',
                            'movement_subtype' => 'SALE',
                            'item_type' => StockService::ITEM_TYPE_COMBO,
                            'item_id' => $item->combo_id,
                            'warehouse_id' => $warehouse->id,
                            'quantity' => $item->quantity,
                            'unit_cost' => 0,
                            'total_cost' => 0,
                            'reference_type' => 'SALES_ORDER',
                            'reference_id' => $orderId,
                            'notes' => 'Stock migration for order '.$orderId,
                        ]);

                        $this->stockService->applyMovement(
                            StockService::ITEM_TYPE_COMBO,
                            $item->combo_id,
                            $warehouse,
                            'OUT',
                            (float) $item->quantity
                        );

                        $item->update(['status' => 'MIGRATED', 'shortfall_quantity' => null]);
                    });
                } catch (\Throwable) {
                    $item->update(['status' => 'FAILED', 'shortfall_quantity' => $item->quantity]);
                }

                if ($item->status === 'FAILED') {
                    $anyFailed = true;
                }
            }

            $migration->update([
                'status' => $anyFailed ? 'FAILED' : 'MIGRATED',
                'warehouse_id' => $warehouseId,
                'migrated_at' => $anyFailed ? null : now(),
                'failure_reason' => $anyFailed ? 'One or more items had insufficient stock.' : null,
            ]);

            $results[] = ['order_id' => $orderId, 'outcome' => $anyFailed ? 'partial' : 'migrated'];
        }

        return $results;
    }

    /**
     * Reverse a migrated order's stock deductions — called (non-blocking,
     * wrapped in try/catch by the caller) when the order is cancelled/
     * failed (UsersService::updateOrder) or deleted
     * (ReportsService::deleteOrders). A no-op if nothing was ever migrated;
     * idempotent if called twice.
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

        foreach ($deductions as $deduction) {
            DB::transaction(function () use ($deduction) {
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
                    'reference_type' => 'SALES_ORDER_CANCEL',
                    'reference_id' => $deduction->reference_id,
                    'notes' => 'Reversal for cancelled/deleted order '.$deduction->reference_id,
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
