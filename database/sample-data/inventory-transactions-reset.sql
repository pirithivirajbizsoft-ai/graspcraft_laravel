-- One-time reset for the Inventory module's transactional data, run when
-- Stock In/Out/Transfer moved to Inventory Items only (Product/Combo stock
-- maintenance removed). Clears every existing Stock In, Stock Out, Stock
-- Transfer and order-driven stock deduction record so the ledger starts
-- fresh — Inventory Item MASTER data (Unit Measurements, Warehouses,
-- inventory_products rows) is left untouched, only its cached stock/cost
-- figures are reset to match the now-empty ledger.
--
-- Safe to run more than once — every statement is idempotent (deleting an
-- already-empty table, or re-applying the same zeroed values, is a no-op).
--
-- Run with (adjust connection args to match your environment):
--   psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f inventory-transactions-reset.sql
BEGIN;

-- Order-driven stock deduction records (children before parents).
DELETE FROM inventory_order_stock_migration_items;
DELETE FROM inventory_order_stock_migrations;

-- Stock In / Stock Out / Stock Transfer ledger, and the per-warehouse
-- balances derived from it.
DELETE FROM inventory_stock_movements;
DELETE FROM inventory_stock_balances;

-- Inventory Item master rows are kept, but their current_stock/average_cost/
-- last_purchase_cost/last_stock_update are cached figures derived from the
-- movements just removed above — reset them to the same "no movement yet"
-- state a brand-new Inventory Item starts in.
UPDATE inventory_products
SET current_stock = 0,
    average_cost = NULL,
    last_purchase_cost = NULL,
    last_stock_update = NULL;

COMMIT;
