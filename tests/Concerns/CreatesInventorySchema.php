<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Schema;

/**
 * Builds the Inventory module's tables (plus minimal stand-ins for the
 * pre-existing, Node-owned products/combo tables) directly against the
 * :memory: SQLite test connection. The real tables are hand-maintained
 * Postgres SQL (see CLAUDE.md "Schema / migrations") and are not a Laravel
 * migration, so there is nothing for RefreshDatabase to run — see
 * tests/Feature/ExampleTest.php.
 */
trait CreatesInventorySchema
{
    private function createInventorySchema(): void
    {
        Schema::create('inventory_uoms', function ($table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('uom_short')->unique();
            $table->string('base_unit')->nullable();
            $table->decimal('conversion_factor', 12, 4)->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('inventory_warehouses', function ($table) {
            $table->string('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('inventory_products', function ($table) {
            $table->string('id')->primary();
            $table->string('product_code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('uom_id');
            $table->decimal('unit_price', 30, 2)->default(0);
            $table->decimal('cost_price', 12, 2)->nullable();
            $table->decimal('current_stock', 14, 3)->default(0);
            $table->decimal('min_stock', 14, 3)->nullable();
            $table->decimal('low_stock_alert', 14, 3)->nullable();
            $table->boolean('is_stockable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->decimal('average_cost', 30, 2)->nullable();
            $table->decimal('last_purchase_cost', 30, 2)->nullable();
            $table->timestamp('last_stock_update')->nullable();
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('inventory_stock_balances', function ($table) {
            $table->string('id')->primary();
            $table->string('item_type');
            $table->string('item_id');
            $table->string('warehouse_id');
            $table->decimal('current_quantity', 14, 3)->default(0);
            $table->timestamps();
            $table->unique(['item_type', 'item_id', 'warehouse_id']);
        });

        Schema::create('inventory_stock_movements', function ($table) {
            $table->string('id')->primary();
            $table->string('movement_number')->unique();
            $table->string('movement_type');
            $table->string('movement_subtype');
            $table->string('item_type');
            $table->string('item_id');
            $table->string('warehouse_id');
            $table->decimal('quantity', 14, 3);
            $table->string('input_uom_id')->nullable();
            $table->decimal('unit_cost', 30, 2)->nullable();
            $table->decimal('total_cost', 34, 2)->nullable();
            $table->string('batch_number')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('reference_type')->nullable();
            $table->string('reference_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        // Minimal stand-ins for the pre-existing, Node-owned products/combo
        // tables — only the columns these tests actually exercise.
        Schema::create('products', function ($table) {
            $table->string('id')->primary();
            $table->string('product_name');
            $table->integer('price')->nullable();
            $table->string('size')->nullable();
            $table->text('description')->nullable();
            $table->integer('photo_limit')->nullable();
            $table->string('product_type')->nullable();
            $table->string('img_url')->nullable();
            $table->boolean('status')->nullable();
            $table->string('certificate_name')->nullable();
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('combo', function ($table) {
            $table->string('id')->primary();
            $table->string('combo_name');
            $table->integer('price')->nullable();
            $table->string('size')->nullable();
            $table->text('description')->nullable();
            $table->string('img_url')->nullable();
            $table->boolean('status')->nullable();
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('inventory_order_stock_migrations', function ($table) {
            $table->string('id')->primary();
            $table->string('order_id')->unique();
            $table->string('customer_id')->nullable();
            $table->string('status')->default('PENDING');
            $table->string('warehouse_id')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('migrated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('inventory_order_stock_migration_items', function ($table) {
            $table->string('id')->primary();
            $table->string('migration_id');
            $table->string('combo_id');
            $table->string('combo_name')->nullable();
            $table->integer('quantity');
            $table->string('status')->default('PENDING');
            $table->decimal('shortfall_quantity', 14, 3)->nullable();
            $table->timestamps();
        });
    }
}
