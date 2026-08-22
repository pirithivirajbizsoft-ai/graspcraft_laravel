<?php

namespace Tests\Feature;

use App\Models\BomItem;
use App\Models\Order;
use App\Models\ProdCombMap;
use App\Models\Uom;
use App\Services\ComboService;
use App\Services\Inventory\BomValidationError;
use App\Services\Inventory\ProductService;
use App\Services\Inventory\StockMigrationService;
use App\Services\Inventory\StockService;
use App\Services\Inventory\WarehouseService;
use App\Services\ProductsService;
use Tests\Concerns\CreatesInventorySchema;
use Tests\TestCase;

/**
 * Exercises BOM (Bill of Materials) CRUD on Product/Combo and its cascade
 * into the existing Stock Migration deduction, against an in-memory SQLite
 * database — see CreatesInventorySchema and StockTransferAndMigrationTest
 * for the same convention.
 */
class BomTest extends TestCase
{
    use CreatesInventorySchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createInventorySchema();
    }

    private function makeProductDto(array $overrides = []): array
    {
        return array_merge([
            'product_name' => '8x10 Print',
            'price' => 500,
            'size' => '8x10',
            'photo_limit' => 1,
            'product_type' => 'others',
            'img_url' => 'x.jpg',
            'status' => true,
        ], $overrides);
    }

    public function test_bom_items_save_and_reload_on_a_product(): void
    {
        $uom = Uom::create(['name' => 'Piece', 'uom_short' => 'PCS']);
        $paper = app(ProductService::class)->create(['name' => 'Glossy Paper', 'uom_id' => $uom->id, 'unit_price' => 1]);

        $productsService = app(ProductsService::class);
        $product = $productsService->create($this->makeProductDto([
            'bom_items' => [['inventory_product_id' => $paper->id, 'quantity' => 2]],
        ]));

        $fetched = $productsService->findOne($product->id);
        $this->assertCount(1, $fetched->bomItems);
        $this->assertEquals(2, (float) $fetched->bomItems->first()->quantity);
        $this->assertSame($paper->id, $fetched->bomItems->first()->inventory_product_id);

        // Update replaces wholesale — an explicit [] clears every row.
        $productsService->update($product->id, ['bom_items' => []]);
        $this->assertCount(0, $productsService->findOne($product->id)->bomItems);

        // A missing key leaves existing rows untouched.
        $productsService->update($product->id, [
            'bom_items' => [['inventory_product_id' => $paper->id, 'quantity' => 3]],
        ]);
        $productsService->update($product->id, ['product_name' => '8x10 Print (renamed)']);
        $this->assertCount(1, $productsService->findOne($product->id)->bomItems);
    }

    public function test_bom_items_save_and_reload_on_a_combo(): void
    {
        $uom = Uom::create(['name' => 'Piece', 'uom_short' => 'PCS']);
        $ink = app(ProductService::class)->create(['name' => 'Ink Cartridge', 'uom_id' => $uom->id, 'unit_price' => 5]);

        $comboService = app(ComboService::class);
        $combo = $comboService->create([
            'combo_name' => 'Family Bundle',
            'price' => 2000,
            'img_url' => 'x.jpg',
            'status' => true,
            'bom_items' => [['inventory_product_id' => $ink->id, 'quantity' => 1]],
        ]);

        $fetched = $comboService->findOne($combo->id);
        $this->assertCount(1, $fetched->bomItems);
        $this->assertEquals(1, (float) $fetched->bomItems->first()->quantity);
    }

    public function test_bom_items_reject_duplicates_unknown_items_and_bad_quantity(): void
    {
        $uom = Uom::create(['name' => 'Piece', 'uom_short' => 'PCS']);
        $ink = app(ProductService::class)->create(['name' => 'Ink Cartridge', 'uom_id' => $uom->id, 'unit_price' => 5]);
        $productsService = app(ProductsService::class);

        $this->expectException(BomValidationError::class);
        $this->expectExceptionMessage('The same inventory item is listed more than once.');

        $productsService->create($this->makeProductDto([
            'bom_items' => [
                ['inventory_product_id' => $ink->id, 'quantity' => 1],
                ['inventory_product_id' => $ink->id, 'quantity' => 2],
            ],
        ]));
    }

    public function test_bom_items_reject_unknown_inventory_item(): void
    {
        $productsService = app(ProductsService::class);

        $this->expectException(BomValidationError::class);
        $this->expectExceptionMessage('One of the selected inventory items no longer exists.');

        $productsService->create($this->makeProductDto([
            'bom_items' => [['inventory_product_id' => 'does-not-exist', 'quantity' => 1]],
        ]));
    }

    public function test_bom_items_reject_non_positive_quantity(): void
    {
        $uom = Uom::create(['name' => 'Piece', 'uom_short' => 'PCS']);
        $ink = app(ProductService::class)->create(['name' => 'Ink Cartridge', 'uom_id' => $uom->id, 'unit_price' => 5]);
        $productsService = app(ProductsService::class);

        $this->expectException(BomValidationError::class);
        $this->expectExceptionMessage('Quantity must be a number greater than zero.');

        $productsService->create($this->makeProductDto([
            'bom_items' => [['inventory_product_id' => $ink->id, 'quantity' => 0]],
        ]));
    }

    public function test_deleting_an_inventory_item_used_in_a_bom_is_blocked(): void
    {
        $uom = Uom::create(['name' => 'Piece', 'uom_short' => 'PCS']);
        $ink = app(ProductService::class)->create(['name' => 'Ink Cartridge', 'uom_id' => $uom->id, 'unit_price' => 5]);

        app(ProductsService::class)->create($this->makeProductDto([
            'bom_items' => [['inventory_product_id' => $ink->id, 'quantity' => 1]],
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('This inventory item is used in a Bill of Materials and cannot be deleted.');

        app(ProductService::class)->remove($ink->id);
    }

    public function test_completing_an_order_deducts_combo_and_product_bom_summed_per_component(): void
    {
        $uom = Uom::create(['name' => 'Piece', 'uom_short' => 'PCS']);
        $paper = app(ProductService::class)->create(['name' => 'Glossy Paper', 'uom_id' => $uom->id, 'unit_price' => 1]);
        $ink = app(ProductService::class)->create(['name' => 'Ink Cartridge', 'uom_id' => $uom->id, 'unit_price' => 5]);
        $warehouse = app(WarehouseService::class)->create(['name' => 'Main Store']);

        // 100 sheets of paper, 50 ink cartridges on hand.
        $stockService = app(StockService::class);
        $stockService->applyMovement(StockService::ITEM_TYPE_INVENTORY_PRODUCT, $paper->id, $warehouse, 'IN', 100);
        $stockService->applyMovement(StockService::ITEM_TYPE_INVENTORY_PRODUCT, $ink->id, $warehouse, 'IN', 50);

        // Product "8x10 Print" needs 1 sheet of paper per unit.
        $productsService = app(ProductsService::class);
        $product = $productsService->create($this->makeProductDto([
            'bom_items' => [['inventory_product_id' => $paper->id, 'quantity' => 1]],
        ]));

        // Combo "Family Bundle" contains that Product, and ALSO needs its own
        // paper (2 sheets) plus 1 ink cartridge per unit — the paper
        // requirement must be summed across both BOM sources.
        $comboService = app(ComboService::class);
        $combo = $comboService->create([
            'combo_name' => 'Family Bundle',
            'price' => 2000,
            'img_url' => 'x.jpg',
            'status' => true,
            'product_ids' => [$product->id],
            'bom_items' => [
                ['inventory_product_id' => $paper->id, 'quantity' => 2],
                ['inventory_product_id' => $ink->id, 'quantity' => 1],
            ],
        ]);
        $this->assertSame(1, ProdCombMap::query()->where('combo_id', $combo->id)->count());

        // Combo's own stock balance (unrelated to BOM) also needs enough on hand.
        $stockService->applyMovement(StockService::ITEM_TYPE_COMBO, $combo->id, $warehouse, 'IN', 10);

        // Stock Migration only surfaces/acts on an order once it has reached
        // Completed — see StockMigrationService class docblock.
        Order::create(['order_id' => 'ORD-BOM-0001', 'order_status' => 'completed']);

        $migrationService = app(StockMigrationService::class);
        $migrationService->captureOrder('ORD-BOM-0001', 'cust-1', [
            (object) ['combo_id' => $combo->id, 'qty' => 3, 'combo' => $combo],
        ]);
        $migrationService->completeOrder('ORD-BOM-0001');

        $migration = $migrationService->findOne('ORD-BOM-0001');
        $this->assertSame('MIGRATED', $migration->status);

        // 3 units sold: paper = (1 from Product + 2 from Combo) * 3 = 9; ink = 1 * 3 = 3.
        $this->assertEquals(91, $stockService->currentQuantity(StockService::ITEM_TYPE_INVENTORY_PRODUCT, $paper->id, $warehouse->id));
        $this->assertEquals(47, $stockService->currentQuantity(StockService::ITEM_TYPE_INVENTORY_PRODUCT, $ink->id, $warehouse->id));
        $this->assertEquals(7, $stockService->currentQuantity(StockService::ITEM_TYPE_COMBO, $combo->id, $warehouse->id));

        $bomLines = $migration->items->first()->bomItems->keyBy('inventory_product_id');
        $this->assertSame('MIGRATED', $bomLines[$paper->id]->status);
        $this->assertEquals(9, (float) $bomLines[$paper->id]->quantity);
        $this->assertSame('MIGRATED', $bomLines[$ink->id]->status);

        // Retrying an already-completed order must not double-deduct.
        $migrationService->completeOrder('ORD-BOM-0001');
        $this->assertEquals(91, $stockService->currentQuantity(StockService::ITEM_TYPE_INVENTORY_PRODUCT, $paper->id, $warehouse->id));
    }

    public function test_insufficient_bom_component_stock_is_recorded_without_blocking_the_order(): void
    {
        $uom = Uom::create(['name' => 'Piece', 'uom_short' => 'PCS']);
        $ink = app(ProductService::class)->create(['name' => 'Ink Cartridge', 'uom_id' => $uom->id, 'unit_price' => 5]);
        $warehouse = app(WarehouseService::class)->create(['name' => 'Main Store']);

        $stockService = app(StockService::class);
        // Only 1 cartridge on hand — the combo below will need 3.
        $stockService->applyMovement(StockService::ITEM_TYPE_INVENTORY_PRODUCT, $ink->id, $warehouse, 'IN', 1);

        $combo = app(ComboService::class)->create([
            'combo_name' => 'Ink Bundle',
            'price' => 1000,
            'img_url' => 'x.jpg',
            'status' => true,
            'bom_items' => [['inventory_product_id' => $ink->id, 'quantity' => 1]],
        ]);
        $stockService->applyMovement(StockService::ITEM_TYPE_COMBO, $combo->id, $warehouse, 'IN', 10);

        Order::create(['order_id' => 'ORD-BOM-0002', 'order_status' => 'completed']);

        $migrationService = app(StockMigrationService::class);
        $migrationService->captureOrder('ORD-BOM-0002', 'cust-2', [
            (object) ['combo_id' => $combo->id, 'qty' => 3, 'combo' => $combo],
        ]);

        // Never throws — the order/commission update this runs alongside
        // must never be blocked by a stock shortfall.
        $migrationService->completeOrder('ORD-BOM-0002');

        $migration = $migrationService->findOne('ORD-BOM-0002');
        // Combo balance itself had enough (10 >= 3) and deducted fine; only
        // the BOM component was short, and that alone must still flag the
        // migration for back-office attention.
        $this->assertSame('FAILED', $migration->status);

        $item = $migration->items->first();
        $this->assertSame('MIGRATED', $item->status);

        $bomLine = $item->bomItems->first();
        $this->assertSame('FAILED', $bomLine->status);
        $this->assertEquals(2, (float) $bomLine->shortfall_quantity);

        // The ink balance is untouched since it couldn't be fully covered.
        $this->assertEquals(1, $stockService->currentQuantity(StockService::ITEM_TYPE_INVENTORY_PRODUCT, $ink->id, $warehouse->id));

        // Top up and retry via the manual Stock Migration screen — only the
        // still-pending BOM component should move.
        $stockService->applyMovement(StockService::ITEM_TYPE_INVENTORY_PRODUCT, $ink->id, $warehouse, 'IN', 5);
        $migrationService->migrate(['ORD-BOM-0002']);

        $migration->refresh();
        $this->assertSame('MIGRATED', $migration->status);
        $this->assertEquals(3, $stockService->currentQuantity(StockService::ITEM_TYPE_INVENTORY_PRODUCT, $ink->id, $warehouse->id));
    }

    public function test_two_lines_needing_the_same_bom_component_do_not_collide(): void
    {
        $uom = Uom::create(['name' => 'Piece', 'uom_short' => 'PCS']);
        $ink = app(ProductService::class)->create(['name' => 'Ink Cartridge', 'uom_id' => $uom->id, 'unit_price' => 5]);
        $warehouse = app(WarehouseService::class)->create(['name' => 'Main Store']);

        $stockService = app(StockService::class);
        $stockService->applyMovement(StockService::ITEM_TYPE_INVENTORY_PRODUCT, $ink->id, $warehouse, 'IN', 10);

        $comboService = app(ComboService::class);
        $comboA = $comboService->create([
            'combo_name' => 'Bundle A', 'price' => 1000, 'img_url' => 'x.jpg', 'status' => true,
            'bom_items' => [['inventory_product_id' => $ink->id, 'quantity' => 1]],
        ]);
        $comboB = $comboService->create([
            'combo_name' => 'Bundle B', 'price' => 1200, 'img_url' => 'x.jpg', 'status' => true,
            'bom_items' => [['inventory_product_id' => $ink->id, 'quantity' => 1]],
        ]);
        $stockService->applyMovement(StockService::ITEM_TYPE_COMBO, $comboA->id, $warehouse, 'IN', 10);
        $stockService->applyMovement(StockService::ITEM_TYPE_COMBO, $comboB->id, $warehouse, 'IN', 10);

        $migrationService = app(StockMigrationService::class);
        $migrationService->captureOrder('ORD-BOM-0003', 'cust-3', [
            (object) ['combo_id' => $comboA->id, 'qty' => 2, 'combo' => $comboA],
            (object) ['combo_id' => $comboB->id, 'qty' => 3, 'combo' => $comboB],
        ]);
        $migrationService->completeOrder('ORD-BOM-0003');

        // Each line's own requirement (2 and 3) must be deducted independently
        // — 5 total, not 2 (i.e. not treating the second line as "already
        // deducted" just because the first line already moved this component).
        $this->assertEquals(5, $stockService->currentQuantity(StockService::ITEM_TYPE_INVENTORY_PRODUCT, $ink->id, $warehouse->id));
    }

    public function test_reversal_restores_bom_deducted_stock(): void
    {
        $uom = Uom::create(['name' => 'Piece', 'uom_short' => 'PCS']);
        $ink = app(ProductService::class)->create(['name' => 'Ink Cartridge', 'uom_id' => $uom->id, 'unit_price' => 5]);
        $warehouse = app(WarehouseService::class)->create(['name' => 'Main Store']);

        $stockService = app(StockService::class);
        $stockService->applyMovement(StockService::ITEM_TYPE_INVENTORY_PRODUCT, $ink->id, $warehouse, 'IN', 10);

        $combo = app(ComboService::class)->create([
            'combo_name' => 'Ink Bundle', 'price' => 1000, 'img_url' => 'x.jpg', 'status' => true,
            'bom_items' => [['inventory_product_id' => $ink->id, 'quantity' => 1]],
        ]);
        $stockService->applyMovement(StockService::ITEM_TYPE_COMBO, $combo->id, $warehouse, 'IN', 10);

        $migrationService = app(StockMigrationService::class);
        $migrationService->captureOrder('ORD-BOM-0004', 'cust-4', [
            (object) ['combo_id' => $combo->id, 'qty' => 4, 'combo' => $combo],
        ]);
        $migrationService->completeOrder('ORD-BOM-0004');
        $this->assertEquals(6, $stockService->currentQuantity(StockService::ITEM_TYPE_INVENTORY_PRODUCT, $ink->id, $warehouse->id));

        $migrationService->reverse('ORD-BOM-0004');
        $this->assertEquals(10, $stockService->currentQuantity(StockService::ITEM_TYPE_INVENTORY_PRODUCT, $ink->id, $warehouse->id));
        $this->assertEquals(10, $stockService->currentQuantity(StockService::ITEM_TYPE_COMBO, $combo->id, $warehouse->id));

        // Idempotent — reversing twice must not double-credit.
        $migrationService->reverse('ORD-BOM-0004');
        $this->assertEquals(10, $stockService->currentQuantity(StockService::ITEM_TYPE_INVENTORY_PRODUCT, $ink->id, $warehouse->id));
    }

    public function test_deleting_a_product_or_combo_cascades_its_bom_rows(): void
    {
        $uom = Uom::create(['name' => 'Piece', 'uom_short' => 'PCS']);
        $ink = app(ProductService::class)->create(['name' => 'Ink Cartridge', 'uom_id' => $uom->id, 'unit_price' => 5]);

        $productsService = app(ProductsService::class);
        $product = $productsService->create($this->makeProductDto([
            'bom_items' => [['inventory_product_id' => $ink->id, 'quantity' => 1]],
        ]));
        $productsService->remove($product->id);
        $this->assertSame(0, BomItem::query()->where('owner_type', 'PRODUCT')->where('owner_id', $product->id)->count());

        $comboService = app(ComboService::class);
        $combo = $comboService->create([
            'combo_name' => 'Ink Bundle', 'price' => 1000, 'img_url' => 'x.jpg', 'status' => true,
            'bom_items' => [['inventory_product_id' => $ink->id, 'quantity' => 1]],
        ]);
        $comboService->remove($combo->id);
        $this->assertSame(0, BomItem::query()->where('owner_type', 'COMBO')->where('owner_id', $combo->id)->count());
    }
}
