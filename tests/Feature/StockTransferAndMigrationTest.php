<?php

namespace Tests\Feature;

use App\Models\Combo;
use App\Models\OrderStockMigration;
use App\Models\Product;
use App\Models\Uom;
use App\Models\Warehouse;
use App\Services\Inventory\ProductService;
use App\Services\Inventory\StockInService;
use App\Services\Inventory\StockMigrationService;
use App\Services\Inventory\StockService;
use App\Services\Inventory\StockTransferService;
use App\Services\Inventory\WarehouseService;
use Tests\Concerns\CreatesInventorySchema;
use Tests\TestCase;

class StockTransferAndMigrationTest extends TestCase
{
    use CreatesInventorySchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createInventorySchema();
    }

    public function test_transfer_moves_stock_between_warehouses_with_uom_conversion(): void
    {
        $pcs = Uom::create(['name' => 'Piece', 'uom_short' => 'PCS']);
        $box = Uom::create(['name' => 'Box', 'uom_short' => 'BOX', 'base_unit' => $pcs->id, 'conversion_factor' => 12]);

        $main = app(WarehouseService::class)->create(['name' => 'Main Store']);
        $branch = app(WarehouseService::class)->create(['name' => 'Branch Store']);
        $product = app(ProductService::class)->create(['name' => 'Photo Paper', 'uom_id' => $pcs->id, 'unit_price' => 10]);

        app(StockInService::class)->create([
            'item_type' => StockService::ITEM_TYPE_INVENTORY_PRODUCT,
            'item_id' => $product->id,
            'warehouse_id' => $main->id,
            'quantity' => 10,
            'uom_id' => $box->id,
            'unit_cost' => 24,
            'movement_subtype' => 'OPENING_STOCK',
        ]);

        $stockService = app(StockService::class);
        $this->assertEquals(120, $stockService->currentQuantity(StockService::ITEM_TYPE_INVENTORY_PRODUCT, $product->id, $main->id));
        $this->assertEquals(0, $stockService->currentQuantity(StockService::ITEM_TYPE_INVENTORY_PRODUCT, $product->id, $branch->id));

        // Transfer 2 boxes (24 pcs) from Main to Branch.
        $result = app(StockTransferService::class)->create([
            'item_type' => StockService::ITEM_TYPE_INVENTORY_PRODUCT,
            'item_id' => $product->id,
            'from_warehouse_id' => $main->id,
            'to_warehouse_id' => $branch->id,
            'quantity' => 2,
            'uom_id' => $box->id,
            'transfer_reason' => 'Rebalancing stock',
        ]);

        $this->assertSame('OUT', $result['out']->movement_type);
        $this->assertSame('TRANSFER_OUT', $result['out']->movement_subtype);
        $this->assertSame('IN', $result['in']->movement_type);
        $this->assertSame('TRANSFER_IN', $result['in']->movement_subtype);
        $this->assertSame('TRANSFER', $result['out']->reference_type);
        $this->assertSame($result['out']->reference_id, $result['in']->reference_id);
        $this->assertSame($result['out']->reference_id.'-OUT', $result['out']->movement_number);
        $this->assertSame($result['out']->reference_id.'-IN', $result['in']->movement_number);
        $this->assertEquals(24, (float) $result['out']->quantity, 'quantity persisted in base units (pcs), not the entered boxes');
        $this->assertEquals(24, (float) $result['in']->quantity);
        // Cost-neutral: both legs carry the product's average cost, unchanged.
        $this->assertEquals(2, (float) $result['out']->unit_cost);
        $this->assertEquals(2, (float) $result['in']->unit_cost);

        $this->assertEquals(96, $stockService->currentQuantity(StockService::ITEM_TYPE_INVENTORY_PRODUCT, $product->id, $main->id));
        $this->assertEquals(24, $stockService->currentQuantity(StockService::ITEM_TYPE_INVENTORY_PRODUCT, $product->id, $branch->id));

        // A transfer never changes the item's TOTAL stock, only its per-warehouse split.
        $product->refresh();
        $this->assertEquals(120, (float) $product->current_stock);
    }

    public function test_transfer_rejects_same_source_and_destination_and_insufficient_stock(): void
    {
        $warehouse = app(WarehouseService::class)->create(['name' => 'Main Store']);
        $product = Product::create(['product_name' => '8x10 Print', 'price' => 500, 'status' => true]);

        $stockTransferService = app(StockTransferService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The destination warehouse must be different from the source warehouse.');

        $stockTransferService->create([
            'item_type' => StockService::ITEM_TYPE_PRODUCT,
            'item_id' => $product->id,
            'from_warehouse_id' => $warehouse->id,
            'to_warehouse_id' => $warehouse->id,
            'quantity' => 1,
            'transfer_reason' => 'test',
        ]);
    }

    public function test_transfer_rejects_insufficient_stock_at_source(): void
    {
        $main = app(WarehouseService::class)->create(['name' => 'Main Store']);
        $branch = app(WarehouseService::class)->create(['name' => 'Branch Store']);
        $product = Product::create(['product_name' => '8x10 Print', 'price' => 500, 'status' => true]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Insufficient stock/');

        app(StockTransferService::class)->create([
            'item_type' => StockService::ITEM_TYPE_PRODUCT,
            'item_id' => $product->id,
            'from_warehouse_id' => $main->id,
            'to_warehouse_id' => $branch->id,
            'quantity' => 5,
            'transfer_reason' => 'test',
        ]);
    }

    public function test_migration_captures_cart_deducts_stock_and_is_idempotent_on_retry(): void
    {
        $warehouse = app(WarehouseService::class)->create(['name' => 'Main Store']);
        $combo = Combo::create(['combo_name' => 'Family Bundle', 'price' => 2000, 'status' => true]);

        app(StockInService::class)->create([
            'item_type' => StockService::ITEM_TYPE_COMBO,
            'item_id' => $combo->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 10,
            'movement_subtype' => 'OPENING_STOCK',
        ]);

        $migrationService = app(StockMigrationService::class);

        // Simulate what UsersService::createOrder passes: the customer's cart
        // rows at the moment the order was created (duck-typed, no real `cart`
        // table needed — captureOrder only reads combo_id/qty/combo->combo_name).
        $cartRow = (object) ['combo_id' => $combo->id, 'qty' => 3, 'combo' => $combo];
        $migrationService->captureOrder('ORD-TEST-0001', 'cust-1', [$cartRow]);

        $migration = $migrationService->findOne('ORD-TEST-0001');
        $this->assertNotNull($migration);
        $this->assertSame('PENDING', $migration->status);
        $this->assertCount(1, $migration->items);
        $this->assertSame(3, $migration->items->first()->quantity);

        // Capturing the same order twice must not create a duplicate migration row.
        $migrationService->captureOrder('ORD-TEST-0001', 'cust-1', [$cartRow]);
        $this->assertSame(1, OrderStockMigration::query()->where('order_id', 'ORD-TEST-0001')->count());

        $results = $migrationService->migrate(['ORD-TEST-0001'], $warehouse->id);
        $this->assertSame('migrated', $results[0]['outcome']);

        $stockService = app(StockService::class);
        $this->assertEquals(7, $stockService->currentQuantity(StockService::ITEM_TYPE_COMBO, $combo->id, $warehouse->id));

        $migration->refresh();
        $this->assertSame('MIGRATED', $migration->status);
        $this->assertSame($warehouse->id, $migration->warehouse_id);
        $this->assertNotNull($migration->migrated_at);

        // Retrying an already-migrated order must not double-deduct.
        $results = $migrationService->migrate(['ORD-TEST-0001'], $warehouse->id);
        $this->assertSame('not_eligible', $results[0]['outcome']);
        $this->assertEquals(7, $stockService->currentQuantity(StockService::ITEM_TYPE_COMBO, $combo->id, $warehouse->id));
    }

    public function test_migration_deducts_available_lines_even_when_another_line_is_short(): void
    {
        $warehouse = app(WarehouseService::class)->create(['name' => 'Main Store']);
        $plentiful = Combo::create(['combo_name' => 'Plentiful Bundle', 'price' => 1000, 'status' => true]);
        $scarce = Combo::create(['combo_name' => 'Scarce Bundle', 'price' => 1500, 'status' => true]);

        app(StockInService::class)->create([
            'item_type' => StockService::ITEM_TYPE_COMBO,
            'item_id' => $plentiful->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 10,
            'movement_subtype' => 'OPENING_STOCK',
        ]);
        // Deliberately no stock-in for $scarce — it starts at 0.

        $migrationService = app(StockMigrationService::class);
        $migrationService->captureOrder('ORD-TEST-0002', 'cust-2', [
            (object) ['combo_id' => $plentiful->id, 'qty' => 2, 'combo' => $plentiful],
            (object) ['combo_id' => $scarce->id, 'qty' => 1, 'combo' => $scarce],
        ]);

        $results = $migrationService->migrate(['ORD-TEST-0002'], $warehouse->id);
        $this->assertSame('partial', $results[0]['outcome']);

        $stockService = app(StockService::class);
        // The plentiful line still deducted despite the scarce line failing —
        // partial success is a supported outcome, not a rollback.
        $this->assertEquals(8, $stockService->currentQuantity(StockService::ITEM_TYPE_COMBO, $plentiful->id, $warehouse->id));
        $this->assertEquals(0, $stockService->currentQuantity(StockService::ITEM_TYPE_COMBO, $scarce->id, $warehouse->id));

        $migration = $migrationService->findOne('ORD-TEST-0002');
        $this->assertSame('FAILED', $migration->status);

        $items = $migration->items->keyBy('combo_id');
        $this->assertSame('MIGRATED', $items[$plentiful->id]->status);
        $this->assertSame('FAILED', $items[$scarce->id]->status);
        $this->assertEquals(1, (float) $items[$scarce->id]->shortfall_quantity);

        // Top up the scarce combo and retry — only the still-pending line should move.
        app(StockInService::class)->create([
            'item_type' => StockService::ITEM_TYPE_COMBO,
            'item_id' => $scarce->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 5,
            'movement_subtype' => 'PURCHASE',
        ]);

        $results = $migrationService->migrate(['ORD-TEST-0002'], $warehouse->id);
        $this->assertSame('migrated', $results[0]['outcome']);
        $this->assertEquals(8, $stockService->currentQuantity(StockService::ITEM_TYPE_COMBO, $plentiful->id, $warehouse->id), 'the already-migrated line must not be deducted again');
        $this->assertEquals(4, $stockService->currentQuantity(StockService::ITEM_TYPE_COMBO, $scarce->id, $warehouse->id));
    }

    public function test_migration_reversal_restores_stock_and_is_idempotent(): void
    {
        $warehouse = app(WarehouseService::class)->create(['name' => 'Main Store']);
        $combo = Combo::create(['combo_name' => 'Family Bundle', 'price' => 2000, 'status' => true]);

        app(StockInService::class)->create([
            'item_type' => StockService::ITEM_TYPE_COMBO,
            'item_id' => $combo->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 10,
            'movement_subtype' => 'OPENING_STOCK',
        ]);

        $migrationService = app(StockMigrationService::class);
        $migrationService->captureOrder('ORD-TEST-0003', 'cust-3', [
            (object) ['combo_id' => $combo->id, 'qty' => 4, 'combo' => $combo],
        ]);
        $migrationService->migrate(['ORD-TEST-0003'], $warehouse->id);

        $stockService = app(StockService::class);
        $this->assertEquals(6, $stockService->currentQuantity(StockService::ITEM_TYPE_COMBO, $combo->id, $warehouse->id));

        $migrationService->reverse('ORD-TEST-0003');

        $this->assertEquals(10, $stockService->currentQuantity(StockService::ITEM_TYPE_COMBO, $combo->id, $warehouse->id));
        $migration = $migrationService->findOne('ORD-TEST-0003');
        $this->assertSame('REVERSED', $migration->status);

        // Reversing twice must not double-credit the stock back.
        $migrationService->reverse('ORD-TEST-0003');
        $this->assertEquals(10, $stockService->currentQuantity(StockService::ITEM_TYPE_COMBO, $combo->id, $warehouse->id));
    }

    public function test_migration_reverse_is_a_no_op_when_nothing_was_ever_migrated(): void
    {
        $migrationService = app(StockMigrationService::class);

        // No exception, no-op — an order that was never captured/migrated.
        $migrationService->reverse('ORD-NEVER-EXISTED');

        $this->assertNull($migrationService->findOne('ORD-NEVER-EXISTED'));
    }
}
