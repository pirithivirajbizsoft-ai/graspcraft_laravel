-- Removes everything seed.sql inserted, plus the rows the write-path harness
-- created. Deliberately narrow: only ids prefixed 'seed-' and rows whose
-- name/title is one of the harness fixtures.
BEGIN;

-- write-harness leftovers (orders created through both APIs)
DELETE FROM "staff-commission" WHERE order_ref_id IN (SELECT id FROM orders WHERE customer_id = 'seed-cust-1');
DELETE FROM orders WHERE customer_id = 'seed-cust-1';

DELETE FROM cart WHERE customer_id = 'seed-cust-1';

DELETE FROM "combo-user-commission" WHERE combo_id IN (SELECT id FROM combo WHERE combo_name LIKE 'wdiff%');
DELETE FROM "prod-comb-map" WHERE combo_id IN (SELECT id FROM combo WHERE combo_name LIKE 'wdiff%');
DELETE FROM combo WHERE combo_name LIKE 'wdiff%';
DELETE FROM frames WHERE frame_name LIKE 'wdiff%';

-- seeded fixtures, children first
DELETE FROM "commission-payout" WHERE id LIKE 'seed-%';
DELETE FROM "staff-commission" WHERE id LIKE 'seed-%';
DELETE FROM "combo-user-commission" WHERE id LIKE 'seed-%';
DELETE FROM deleted_orders WHERE id LIKE 'seed-%';
DELETE FROM "fram-img-map" WHERE id LIKE 'seed-%';
DELETE FROM "images-uploads" WHERE id LIKE 'seed-%';
DELETE FROM "ultra-object-obj-map" WHERE id LIKE 'seed-%';
DELETE FROM "ultra-object-master" WHERE id LIKE 'seed-%';
DELETE FROM "object-master" WHERE id LIKE 'seed-%';
DELETE FROM discount WHERE id LIKE 'seed-%';
DELETE FROM "pc-amt-master" WHERE id LIKE 'seed-%';
DELETE FROM "product-size" WHERE id LIKE 'seed-%';
DELETE FROM "prod-comb-map" WHERE id LIKE 'seed-%';
DELETE FROM combo WHERE id LIKE 'seed-%';
DELETE FROM products WHERE id LIKE 'seed-%';
DELETE FROM frames WHERE id LIKE 'seed-%';
DELETE FROM customers WHERE id LIKE 'seed-%';
DELETE FROM "role-kiosk-map" WHERE id LIKE 'seed-%';
DELETE FROM roles WHERE id LIKE 'seed-%';
DELETE FROM users WHERE id LIKE 'seed-%';

COMMIT;
