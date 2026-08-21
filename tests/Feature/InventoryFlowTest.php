<?php

namespace Tests\Feature;

use App\Models\Combo;
use App\Models\InventoryProduct;
use App\Models\Product;
use App\Models\Uom;
use App\Models\Warehouse;
use App\Services\Inventory\ProductService;
use App\Services\Inventory\StockInService;
use App\Services\Inventory\StockMovementService;
use App\Services\Inventory\StockOutService;
use App\Services\Inventory\StockService;
use App\Services\Inventory\UomService;
use App\Services\Inventory\WarehouseService;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesInventorySchema;
use Tests\TestCase;

/**
 * Exercises the Inventory module's business logic end to end against an
 * in-memory SQLite database.
 *
 * The real inventory_* tables (plus the pre-existing products/combo, stood
 * in here with a minimal compatible shape) are hand-maintained Postgres SQL
 * (see CLAUDE.md "Schema / migrations") and are not a Laravel migration, so
 * there is nothing for RefreshDatabase to run here (the one existing
 * migration bootstraps the whole pre-existing PhotoCraft schema from a
 * sibling repo checkout and is intentionally not used by tests — see
 * tests/Feature/ExampleTest.php). This test builds the tables it needs
 * directly against the :memory: SQLite connection instead.
 */
class InventoryFlowTest extends TestCase
{
    use CreatesInventorySchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createInventorySchema();
    }

    public function test_stock_in_converts_units_and_computes_weighted_average_cost(): void
    {
        $pcs = Uom::create(['name' => 'Piece', 'uom_short' => 'PCS']);
        $box = Uom::create(['name' => 'Box', 'uom_short' => 'BOX', 'base_unit' => $pcs->id, 'conversion_factor' => 12]);

        $warehouse = app(WarehouseService::class)->create(['name' => 'Main Store']);
        $product = app(ProductService::class)->create(['name' => 'Photo Paper', 'uom_id' => $pcs->id, 'unit_price' => 10]);

        $this->assertSame('PRD00000001', $product->product_code);

        $stockInService = app(StockInService::class);

        // 5 boxes @ 24/box = 60 pcs on hand @ 2/pc.
        $movement1 = $stockInService->create([
            'item_type' => StockService::ITEM_TYPE_INVENTORY_PRODUCT,
            'item_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 5,
            'uom_id' => $box->id,
            'unit_cost' => 24,
            'movement_subtype' => 'PURCHASE',
        ]);

        $this->assertSame('IN', $movement1->movement_type);
        $this->assertEquals(60, (float) $movement1->quantity, 'quantity must be persisted in the base UOM (pcs), not the entered UOM (boxes)');
        $this->assertEquals(2, (float) $movement1->unit_cost, 'per-box cost must be normalised to a per-pc base cost');
        $this->assertEquals(120, (float) $movement1->total_cost);
        $this->assertStringStartsWith('STK-IN-'.now()->format('Ymd').'-', $movement1->movement_number);
        $this->assertSame('Photo Paper', $movement1->item_label);
        // Direct attribute access reads the accessor regardless of $appends;
        // assert against toArray() too, since that's what the real API response
        // (and the frontend) actually serializes.
        $this->assertSame('Photo Paper', $movement1->toArray()['item_label'] ?? null);

        $product->refresh();
        $this->assertEquals(60, (float) $product->current_stock);
        $this->assertEquals(2, (float) $product->average_cost);

        // 10 pcs directly @ 3/pc -> weighted avg = ((60*2)+(10*3))/70 = 2.14.
        $stockInService->create([
            'item_type' => StockService::ITEM_TYPE_INVENTORY_PRODUCT,
            'item_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 10,
            'uom_id' => $pcs->id,
            'unit_cost' => 3,
            'movement_subtype' => 'PURCHASE',
        ]);

        $product->refresh();
        $this->assertEquals(70, (float) $product->current_stock);
        $this->assertEqualsWithDelta(2.14, (float) $product->average_cost, 0.01);
    }

    public function test_stock_out_uses_average_cost_and_blocks_insufficient_stock(): void
    {
        $pcs = Uom::create(['name' => 'Piece', 'uom_short' => 'PCS']);
        $warehouse = app(WarehouseService::class)->create(['name' => 'Main Store']);
        $product = app(ProductService::class)->create(['name' => 'Photo Paper', 'uom_id' => $pcs->id, 'unit_price' => 10]);

        app(StockInService::class)->create([
            'item_type' => StockService::ITEM_TYPE_INVENTORY_PRODUCT,
            'item_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 70,
            'uom_id' => $pcs->id,
            'unit_cost' => 2.14,
            'movement_subtype' => 'OPENING_STOCK',
        ]);

        $stockOutService = app(StockOutService::class);

        $result = $stockOutService->create([
            'item_type' => StockService::ITEM_TYPE_INVENTORY_PRODUCT,
            'item_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 15,
            'reason' => 'sale',
        ]);

        $this->assertSame('OUT', $result['movement']->movement_type);
        $this->assertSame('SALE', $result['movement']->movement_subtype);
        $this->assertSame('SALE', $result['movement']->reference_type);
        $this->assertEquals(15, (float) $result['movement']->quantity);
        $this->assertEqualsWithDelta(2.14, (float) $result['movement']->unit_cost, 0.001);

        $product->refresh();
        $this->assertEquals(55, (float) $product->current_stock);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Insufficient stock/');

        $stockOutService->create([
            'item_type' => StockService::ITEM_TYPE_INVENTORY_PRODUCT,
            'item_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 9999,
            'reason' => 'sale',
        ]);
    }

    public function test_opening_stock_cannot_be_recorded_twice_for_the_same_product_and_warehouse(): void
    {
        $pcs = Uom::create(['name' => 'Piece', 'uom_short' => 'PCS']);
        $warehouse = app(WarehouseService::class)->create(['name' => 'Main Store']);
        $product = app(ProductService::class)->create(['name' => 'Photo Paper', 'uom_id' => $pcs->id, 'unit_price' => 10]);

        $stockInService = app(StockInService::class);
        $stockInService->create([
            'item_type' => StockService::ITEM_TYPE_INVENTORY_PRODUCT,
            'item_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 20,
            'uom_id' => $pcs->id,
            'movement_subtype' => 'OPENING_STOCK',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/[Oo]pening stock/');

        $stockInService->create([
            'item_type' => StockService::ITEM_TYPE_INVENTORY_PRODUCT,
            'item_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 5,
            'uom_id' => $pcs->id,
            'movement_subtype' => 'OPENING_STOCK',
        ]);
    }

    public function test_incompatible_unit_is_rejected(): void
    {
        $pcs = Uom::create(['name' => 'Piece', 'uom_short' => 'PCS']);
        $ltr = Uom::create(['name' => 'Litre', 'uom_short' => 'LTR']);
        $warehouse = Warehouse::create(['name' => 'W2', 'code' => 'WATEST0002']);
        $product = InventoryProduct::create(['name' => 'P2', 'uom_id' => $pcs->id, 'unit_price' => 1, 'product_code' => 'PRDTEST0002']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The selected unit is not compatible with this product\'s unit of measurement.');

        app(StockInService::class)->create([
            'item_type' => StockService::ITEM_TYPE_INVENTORY_PRODUCT,
            'item_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 1,
            'uom_id' => $ltr->id,
            'movement_subtype' => 'PURCHASE',
        ]);
    }

    public function test_uom_delete_is_blocked_while_in_use_by_a_product(): void
    {
        $pcs = Uom::create(['name' => 'Piece', 'uom_short' => 'PCS']);
        InventoryProduct::create(['name' => 'P3', 'uom_id' => $pcs->id, 'unit_price' => 1, 'product_code' => 'PRDTEST0003']);

        $this->expectException(\RuntimeException::class);

        app(UomService::class)->remove($pcs->id);
    }

    public function test_product_delete_is_blocked_while_stock_on_hand(): void
    {
        $pcs = Uom::create(['name' => 'Piece', 'uom_short' => 'PCS']);
        $warehouse = app(WarehouseService::class)->create(['name' => 'Main Store']);
        $product = app(ProductService::class)->create(['name' => 'Photo Paper', 'uom_id' => $pcs->id, 'unit_price' => 10]);

        app(StockInService::class)->create([
            'item_type' => StockService::ITEM_TYPE_INVENTORY_PRODUCT,
            'item_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 1,
            'uom_id' => $pcs->id,
            'movement_subtype' => 'ADJUSTMENT',
        ]);

        $this->expectException(\RuntimeException::class);

        app(ProductService::class)->remove($product->id);
    }

    public function test_warehouse_delete_is_blocked_once_it_has_stock_movements(): void
    {
        $pcs = Uom::create(['name' => 'Piece', 'uom_short' => 'PCS']);
        $warehouse = app(WarehouseService::class)->create(['name' => 'Main Store']);
        $product = app(ProductService::class)->create(['name' => 'Photo Paper', 'uom_id' => $pcs->id, 'unit_price' => 10]);

        app(StockInService::class)->create([
            'item_type' => StockService::ITEM_TYPE_INVENTORY_PRODUCT,
            'item_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 1,
            'uom_id' => $pcs->id,
            'movement_subtype' => 'ADJUSTMENT',
        ]);

        $this->expectException(\RuntimeException::class);

        app(WarehouseService::class)->remove($warehouse->id);
    }

    public function test_product_uom_family_returns_base_and_derived_units_base_first(): void
    {
        $pcs = Uom::create(['name' => 'Piece', 'uom_short' => 'PCS']);
        $box = Uom::create(['name' => 'Box', 'uom_short' => 'BOX', 'base_unit' => $pcs->id, 'conversion_factor' => 12]);
        $carton = Uom::create(['name' => 'Carton', 'uom_short' => 'CTN', 'base_unit' => $pcs->id, 'conversion_factor' => 144]);

        $product = app(ProductService::class)->create(['name' => 'Photo Paper', 'uom_id' => $pcs->id, 'unit_price' => 10]);

        $family = app(ProductService::class)->uomFamily($product->id);

        $this->assertSame([$pcs->id, $box->id, $carton->id], $family->pluck('id')->all());
    }

    public function test_stock_in_and_out_for_an_existing_catalog_product_tracks_plain_counts(): void
    {
        $warehouse = app(WarehouseService::class)->create(['name' => 'Main Store']);
        $catalogProduct = Product::create(['product_name' => '8x10 Print', 'price' => 500, 'status' => true]);

        $stockInService = app(StockInService::class);
        $stockOutService = app(StockOutService::class);

        // No uom_id, no unit_cost — Product has no unit-of-measure or costing concept.
        $movementIn = $stockInService->create([
            'item_type' => StockService::ITEM_TYPE_PRODUCT,
            'item_id' => $catalogProduct->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 25,
            'movement_subtype' => 'OPENING_STOCK',
        ]);

        $this->assertSame(StockService::ITEM_TYPE_PRODUCT, $movementIn->item_type);
        $this->assertEquals(25, (float) $movementIn->quantity);
        $this->assertNull($movementIn->input_uom_id);
        $this->assertSame('8x10 Print', $movementIn->item_label);

        // products.* gained no new column — the balance lives only in inventory_stock_balances.
        $this->assertFalse(Schema::hasColumn('products', 'current_stock'));
        $this->assertEquals(25, app(StockService::class)->totalQuantity(StockService::ITEM_TYPE_PRODUCT, $catalogProduct->id));

        $result = $stockOutService->create([
            'item_type' => StockService::ITEM_TYPE_PRODUCT,
            'item_id' => $catalogProduct->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 9,
            'reason' => 'sale',
        ]);

        $this->assertEquals(0, (float) $result['movement']->unit_cost, 'Product has no average_cost to price a stock-out from');
        $this->assertEquals(16, app(StockService::class)->totalQuantity(StockService::ITEM_TYPE_PRODUCT, $catalogProduct->id));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Insufficient stock/');

        $stockOutService->create([
            'item_type' => StockService::ITEM_TYPE_PRODUCT,
            'item_id' => $catalogProduct->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 999,
            'reason' => 'sale',
        ]);
    }

    public function test_combo_stock_is_tracked_independently_of_its_component_products(): void
    {
        $warehouse = app(WarehouseService::class)->create(['name' => 'Main Store']);
        $combo = Combo::create(['combo_name' => 'Family Bundle', 'price' => 2000, 'status' => true]);
        $componentProduct = Product::create(['product_name' => '8x10 Print', 'price' => 500, 'status' => true]);

        $stockInService = app(StockInService::class);

        // Stocking the component product must not move the combo's own balance.
        $stockInService->create([
            'item_type' => StockService::ITEM_TYPE_PRODUCT,
            'item_id' => $componentProduct->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 100,
            'movement_subtype' => 'OPENING_STOCK',
        ]);

        $movement = $stockInService->create([
            'item_type' => StockService::ITEM_TYPE_COMBO,
            'item_id' => $combo->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 8,
            'movement_subtype' => 'OPENING_STOCK',
        ]);

        $this->assertSame('Family Bundle', $movement->item_label);

        $stockService = app(StockService::class);
        $this->assertEquals(8, $stockService->totalQuantity(StockService::ITEM_TYPE_COMBO, $combo->id));
        $this->assertEquals(100, $stockService->totalQuantity(StockService::ITEM_TYPE_PRODUCT, $componentProduct->id));
    }

    public function test_warehouse_stock_report_lists_balances_across_item_types(): void
    {
        $pcs = Uom::create(['name' => 'Piece', 'uom_short' => 'PCS']);
        $warehouse = app(WarehouseService::class)->create(['name' => 'Main Store']);
        $inventoryProduct = app(ProductService::class)->create(['name' => 'Photo Paper', 'uom_id' => $pcs->id, 'unit_price' => 10]);
        $catalogProduct = Product::create(['product_name' => '8x10 Print', 'price' => 500, 'status' => true]);

        $stockInService = app(StockInService::class);
        $stockInService->create([
            'item_type' => StockService::ITEM_TYPE_INVENTORY_PRODUCT,
            'item_id' => $inventoryProduct->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 5,
            'uom_id' => $pcs->id,
            'movement_subtype' => 'OPENING_STOCK',
        ]);
        $stockInService->create([
            'item_type' => StockService::ITEM_TYPE_PRODUCT,
            'item_id' => $catalogProduct->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 25,
            'movement_subtype' => 'OPENING_STOCK',
        ]);

        $report = app(StockMovementService::class)->warehouseStock(['limit' => 10]);

        $this->assertSame(2, $report['count']);
        $labels = $report['rows']->pluck('item_label')->all();
        $this->assertContains('Photo Paper', $labels);
        $this->assertContains('8x10 Print', $labels);

        // pluck() reads the accessor directly regardless of $appends, so it
        // would pass even if item_label never made it into the JSON the API
        // actually returns. Assert against toArray() too, since that's the
        // serialization path the real HTTP response (and the frontend) uses.
        $serialized = $report['rows']->first()->toArray();
        $this->assertArrayHasKey('item_label', $serialized);
        $this->assertNotNull($serialized['item_label']);
    }
}
