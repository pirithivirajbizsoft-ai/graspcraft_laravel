<?php

namespace Tests\Feature;

use App\Models\Combo;
use App\Models\Order;
use App\Models\StockMovement;
use App\Models\Uom;
use App\Services\ComboService;
use App\Services\Inventory\ProductService;
use App\Services\Inventory\StockMigrationService;
use App\Services\Inventory\StockService;
use App\Services\Inventory\WarehouseService;
use Tests\Concerns\CreatesInventorySchema;
use Tests\TestCase;

/**
 * Covers the Completed-status gate on Stock Migration (an order's stock is
 * never touched, and its migration never surfaces via findAll/findOne,
 * until the order has actually reached Completed) and the multi-warehouse
 * pooled/split allocation that replaced the single operator-chosen
 * warehouse — see StockMigrationService class docblock.
 */
class StockMigrationCompletionGateTest extends TestCase
{
    use CreatesInventorySchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createInventorySchema();
    }

    private function createOrder(string $orderId, string $status): Order
    {
        return Order::create(['order_id' => $orderId, 'order_status' => $status]);
    }

    public function test_migrate_refuses_an_order_that_is_not_completed_yet(): void
    {
        $warehouse = app(WarehouseService::class)->create(['name' => 'Main Store']);
        $combo = Combo::create(['combo_name' => 'Bundle', 'price' => 1000, 'status' => true]);
        $stockService = app(StockService::class);
        $stockService->applyMovement(StockService::ITEM_TYPE_COMBO, $combo->id, $warehouse, 'IN', 10);

        $this->createOrder('ORD-GATE-0001', 'pending');

        $migrationService = app(StockMigrationService::class);
        $migrationService->captureOrder('ORD-GATE-0001', 'cust-1', [
            (object) ['combo_id' => $combo->id, 'qty' => 2, 'combo' => $combo],
        ]);

        $results = $migrationService->migrate(['ORD-GATE-0001']);
        $this->assertSame('order_not_completed', $results[0]['outcome']);
        $this->assertEquals(10, $stockService->currentQuantity(StockService::ITEM_TYPE_COMBO, $combo->id, $warehouse->id));

        // Invisible via every read path while the order is still pending.
        $this->assertNull($migrationService->findOne('ORD-GATE-0001'));
        $this->assertSame(0, $migrationService->findAll(['page_no' => 1, 'limit' => 10])['count']);

        // Completing the order unblocks both deduction and visibility.
        Order::query()->where('order_id', 'ORD-GATE-0001')->update(['order_status' => 'completed']);

        $results = $migrationService->migrate(['ORD-GATE-0001']);
        $this->assertSame('migrated', $results[0]['outcome']);
        $this->assertEquals(8, $stockService->currentQuantity(StockService::ITEM_TYPE_COMBO, $combo->id, $warehouse->id));
        $this->assertNotNull($migrationService->findOne('ORD-GATE-0001'));
    }

    public function test_findall_and_findone_only_return_completed_orders(): void
    {
        $warehouse = app(WarehouseService::class)->create(['name' => 'Main Store']);
        $combo = Combo::create(['combo_name' => 'Bundle', 'price' => 1000, 'status' => true]);
        app(StockService::class)->applyMovement(StockService::ITEM_TYPE_COMBO, $combo->id, $warehouse, 'IN', 10);

        $this->createOrder('ORD-GATE-0007', 'pending');
        $this->createOrder('ORD-GATE-0008', 'completed');

        $migrationService = app(StockMigrationService::class);
        $migrationService->captureOrder('ORD-GATE-0007', 'cust-7', [
            (object) ['combo_id' => $combo->id, 'qty' => 1, 'combo' => $combo],
        ]);
        $migrationService->captureOrder('ORD-GATE-0008', 'cust-8', [
            (object) ['combo_id' => $combo->id, 'qty' => 1, 'combo' => $combo],
        ]);

        $this->assertNull($migrationService->findOne('ORD-GATE-0007'));
        $this->assertNotNull($migrationService->findOne('ORD-GATE-0008'));

        $result = $migrationService->findAll(['page_no' => 1, 'limit' => 10]);
        $this->assertSame(1, $result['count']);
        $this->assertSame('ORD-GATE-0008', $result['rows']->first()->order_id);
    }

    public function test_migrate_splits_allocation_across_multiple_warehouses(): void
    {
        $uom = Uom::create(['name' => 'Piece', 'uom_short' => 'PCS']);
        $ink = app(ProductService::class)->create(['name' => 'Ink Cartridge', 'uom_id' => $uom->id, 'unit_price' => 5]);
        $main = app(WarehouseService::class)->create(['name' => 'Main Store']);
        $branch = app(WarehouseService::class)->create(['name' => 'Branch Store']);

        $stockService = app(StockService::class);
        // 3 at Main, 4 at Branch - pool of 7 covers a requirement of 6, but
        // NEITHER warehouse alone has enough (3 < 6, 4 < 6).
        $stockService->applyMovement(StockService::ITEM_TYPE_INVENTORY_PRODUCT, $ink->id, $main, 'IN', 3);
        $stockService->applyMovement(StockService::ITEM_TYPE_INVENTORY_PRODUCT, $ink->id, $branch, 'IN', 4);

        $combo = app(ComboService::class)->create([
            'combo_name' => 'Ink Bundle', 'price' => 1000, 'img_url' => 'x.jpg', 'status' => true,
            'bom_items' => [['inventory_product_id' => $ink->id, 'quantity' => 1]],
        ]);
        $stockService->applyMovement(StockService::ITEM_TYPE_COMBO, $combo->id, $main, 'IN', 10);

        $this->createOrder('ORD-GATE-0002', 'completed');

        $migrationService = app(StockMigrationService::class);
        $migrationService->captureOrder('ORD-GATE-0002', 'cust-2', [
            (object) ['combo_id' => $combo->id, 'qty' => 6, 'combo' => $combo],
        ]);

        $results = $migrationService->migrate(['ORD-GATE-0002']);
        $this->assertSame('migrated', $results[0]['outcome']);

        // Richest-first: Branch (4) drawn from fully, then Main for the remaining 2.
        $this->assertEquals(1, $stockService->currentQuantity(StockService::ITEM_TYPE_INVENTORY_PRODUCT, $ink->id, $main->id));
        $this->assertEquals(0, $stockService->currentQuantity(StockService::ITEM_TYPE_INVENTORY_PRODUCT, $ink->id, $branch->id));

        $migration = $migrationService->findOne('ORD-GATE-0002');
        $bomLine = $migration->items->first()->bomItems->first();
        $this->assertSame('MIGRATED', $bomLine->status);

        // One StockMovement per warehouse actually drawn from.
        $movements = StockMovement::query()
            ->where('reference_type', 'SALES_ORDER_BOM')
            ->where('item_id', $ink->id)
            ->get();
        $this->assertCount(2, $movements);
    }

    public function test_migrate_leaves_stock_untouched_when_pool_across_every_warehouse_is_still_short(): void
    {
        $uom = Uom::create(['name' => 'Piece', 'uom_short' => 'PCS']);
        $ink = app(ProductService::class)->create(['name' => 'Ink Cartridge', 'uom_id' => $uom->id, 'unit_price' => 5]);
        $main = app(WarehouseService::class)->create(['name' => 'Main Store']);
        $branch = app(WarehouseService::class)->create(['name' => 'Branch Store']);

        $stockService = app(StockService::class);
        $stockService->applyMovement(StockService::ITEM_TYPE_INVENTORY_PRODUCT, $ink->id, $main, 'IN', 2);
        $stockService->applyMovement(StockService::ITEM_TYPE_INVENTORY_PRODUCT, $ink->id, $branch, 'IN', 1);

        $combo = app(ComboService::class)->create([
            'combo_name' => 'Ink Bundle', 'price' => 1000, 'img_url' => 'x.jpg', 'status' => true,
            'bom_items' => [['inventory_product_id' => $ink->id, 'quantity' => 1]],
        ]);
        $stockService->applyMovement(StockService::ITEM_TYPE_COMBO, $combo->id, $main, 'IN', 10);

        $this->createOrder('ORD-GATE-0003', 'completed');

        $migrationService = app(StockMigrationService::class);
        $migrationService->captureOrder('ORD-GATE-0003', 'cust-3', [
            (object) ['combo_id' => $combo->id, 'qty' => 5, 'combo' => $combo], // needs 5, pool only has 3
        ]);

        $migrationService->migrate(['ORD-GATE-0003']);

        // Pool (3) is short of required (5) - neither warehouse is touched.
        $this->assertEquals(2, $stockService->currentQuantity(StockService::ITEM_TYPE_INVENTORY_PRODUCT, $ink->id, $main->id));
        $this->assertEquals(1, $stockService->currentQuantity(StockService::ITEM_TYPE_INVENTORY_PRODUCT, $ink->id, $branch->id));

        $migration = $migrationService->findOne('ORD-GATE-0003');
        $bomLine = $migration->items->first()->bomItems->first();
        $this->assertSame('FAILED', $bomLine->status);
        $this->assertEquals(2, (float) $bomLine->shortfall_quantity);
    }

    public function test_preview_requirements_aggregates_across_selected_orders(): void
    {
        $uom = Uom::create(['name' => 'Piece', 'uom_short' => 'PCS']);
        $ink = app(ProductService::class)->create(['name' => 'Ink Cartridge', 'uom_id' => $uom->id, 'unit_price' => 5]);
        $warehouse = app(WarehouseService::class)->create(['name' => 'Main Store']);

        $stockService = app(StockService::class);
        $stockService->applyMovement(StockService::ITEM_TYPE_INVENTORY_PRODUCT, $ink->id, $warehouse, 'IN', 3);

        $combo = app(ComboService::class)->create([
            'combo_name' => 'Ink Bundle', 'price' => 1000, 'img_url' => 'x.jpg', 'status' => true,
            'bom_items' => [['inventory_product_id' => $ink->id, 'quantity' => 1]],
        ]);
        $stockService->applyMovement(StockService::ITEM_TYPE_COMBO, $combo->id, $warehouse, 'IN', 1);

        $this->createOrder('ORD-GATE-0004', 'completed');
        $this->createOrder('ORD-GATE-0005', 'completed');

        $migrationService = app(StockMigrationService::class);
        $migrationService->captureOrder('ORD-GATE-0004', 'cust-4', [
            (object) ['combo_id' => $combo->id, 'qty' => 2, 'combo' => $combo],
        ]);
        $migrationService->captureOrder('ORD-GATE-0005', 'cust-5', [
            (object) ['combo_id' => $combo->id, 'qty' => 3, 'combo' => $combo],
        ]);

        $rows = collect($migrationService->previewRequirements(['ORD-GATE-0004', 'ORD-GATE-0005']))->keyBy('type');

        // Combo balance: required 2+3=5, available 1 -> short by 4.
        $this->assertEquals(5, $rows['COMBO']['required']);
        $this->assertEquals(1, $rows['COMBO']['available']);
        $this->assertEquals(4, $rows['COMBO']['shortfall']);

        // Ink (BOM component): required 2+3=5, available 3 -> short by 2.
        $this->assertEquals(5, $rows['INVENTORY_PRODUCT']['required']);
        $this->assertEquals(3, $rows['INVENTORY_PRODUCT']['available']);
        $this->assertEquals(2, $rows['INVENTORY_PRODUCT']['shortfall']);
    }

    public function test_reverse_unwinds_a_split_multi_warehouse_deduction(): void
    {
        $uom = Uom::create(['name' => 'Piece', 'uom_short' => 'PCS']);
        $ink = app(ProductService::class)->create(['name' => 'Ink Cartridge', 'uom_id' => $uom->id, 'unit_price' => 5]);
        $main = app(WarehouseService::class)->create(['name' => 'Main Store']);
        $branch = app(WarehouseService::class)->create(['name' => 'Branch Store']);

        $stockService = app(StockService::class);
        $stockService->applyMovement(StockService::ITEM_TYPE_INVENTORY_PRODUCT, $ink->id, $main, 'IN', 3);
        $stockService->applyMovement(StockService::ITEM_TYPE_INVENTORY_PRODUCT, $ink->id, $branch, 'IN', 4);

        $combo = app(ComboService::class)->create([
            'combo_name' => 'Ink Bundle', 'price' => 1000, 'img_url' => 'x.jpg', 'status' => true,
            'bom_items' => [['inventory_product_id' => $ink->id, 'quantity' => 1]],
        ]);
        $stockService->applyMovement(StockService::ITEM_TYPE_COMBO, $combo->id, $main, 'IN', 10);

        $this->createOrder('ORD-GATE-0006', 'completed');

        $migrationService = app(StockMigrationService::class);
        $migrationService->captureOrder('ORD-GATE-0006', 'cust-6', [
            (object) ['combo_id' => $combo->id, 'qty' => 6, 'combo' => $combo],
        ]);
        $migrationService->migrate(['ORD-GATE-0006']);

        $this->assertEquals(1, $stockService->totalQuantity(StockService::ITEM_TYPE_INVENTORY_PRODUCT, $ink->id));

        $migrationService->reverse('ORD-GATE-0006');

        // Each warehouse gets back exactly what it gave up.
        $this->assertEquals(3, $stockService->currentQuantity(StockService::ITEM_TYPE_INVENTORY_PRODUCT, $ink->id, $main->id));
        $this->assertEquals(4, $stockService->currentQuantity(StockService::ITEM_TYPE_INVENTORY_PRODUCT, $ink->id, $branch->id));
    }
}
