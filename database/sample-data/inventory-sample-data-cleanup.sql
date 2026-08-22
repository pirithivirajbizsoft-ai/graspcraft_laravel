-- Removes everything inventory-sample-data.sql inserted, and nothing else.
-- Deliberately narrow: only ids prefixed 'seed-inv-'. Children first so no
-- row is ever left referencing an already-deleted parent mid-script.
BEGIN;

DELETE FROM inventory_stock_balances  WHERE id LIKE 'seed-inv-%';
DELETE FROM inventory_stock_movements WHERE id LIKE 'seed-inv-%';
DELETE FROM inventory_products        WHERE id LIKE 'seed-inv-%';
DELETE FROM inventory_warehouses      WHERE id LIKE 'seed-inv-%';
DELETE FROM inventory_uoms            WHERE id LIKE 'seed-inv-%';

COMMIT;
