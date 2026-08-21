-- Sample/test fixtures for the Inventory module (Unit Measurements, Warehouses,
-- Products, Stock In, Stock Out) plus two new Combo rows on the pre-existing,
-- Node-owned `combo` table so the ledger's COMBO item type has something to
-- point at.
--
-- Every id is prefixed 'seed-inv-' so inventory-sample-data-cleanup.sql can
-- remove exactly these rows and nothing else. Every INSERT uses
-- ON CONFLICT DO NOTHING, so this script is safe to run more than once
-- without erroring — re-running it will NOT recompute or top up quantities,
-- it just leaves already-seeded rows alone. The four Unit Measurement rows
-- are the one exception: they conflict-check by uom_short, not id, and
-- every later reference to a UOM resolves it by uom_short too - so a
-- "Piece"/"Box"/"Kilogram"/"Gram" unit created by hand beforehand (e.g.
-- through the Unit Measurement master) is reused instead of colliding.
--
-- Resulting balances/costs below were hand-computed to match exactly what
-- the app's own Stock In / Stock Out code would have produced had these
-- movements been submitted through the API (weighted-average costing,
-- UOM-to-base-unit conversion, per-warehouse balances, InventoryProduct
-- current_stock as the cross-warehouse sum) — see
-- app/Services/Inventory/StockService.php for that logic. Do not edit the
-- numbers below without redoing that math by hand; they will silently go
-- inconsistent otherwise.
--
-- Run with (adjust connection args to match your environment):
--   psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f inventory-sample-data.sql
BEGIN;

-- ─── Unit Measurements ──────────────────────────────────────────────────────
-- Two base-unit families: Piece/Box (count-based) and Kilogram/Gram
-- (weight-based), each with one derived unit, so both stock-in and
-- stock-out can exercise UOM conversion, not just same-unit movements.
-- Base units first, conflict-checked by uom_short rather than id - a
-- pre-existing "Piece"/"Kilogram" row under a different id must be reused,
-- not collided with (see header comment).
INSERT INTO inventory_uoms (id, name, uom_short, base_unit, conversion_factor, is_active, created_at, updated_at)
VALUES
  ('seed-inv-uom-pc', 'Piece',    'PC', NULL, 1, true, now() - interval '3 days', now() - interval '3 days'),
  ('seed-inv-uom-kg', 'Kilogram', 'KG', NULL, 1, true, now() - interval '3 days', now() - interval '3 days')
ON CONFLICT (uom_short) DO NOTHING;

-- Derived units, resolved against whichever PC/KG row actually exists - the
-- one just inserted above, or a pre-existing row under a different id.
INSERT INTO inventory_uoms (id, name, uom_short, base_unit, conversion_factor, is_active, created_at, updated_at)
VALUES
  ('seed-inv-uom-box', 'Box',  'BOX', (SELECT id FROM inventory_uoms WHERE uom_short = 'PC'), 12,    true, now() - interval '3 days', now() - interval '3 days'),
  ('seed-inv-uom-g',   'Gram', 'G',   (SELECT id FROM inventory_uoms WHERE uom_short = 'KG'), 0.001, true, now() - interval '3 days', now() - interval '3 days')
ON CONFLICT (uom_short) DO NOTHING;

-- ─── Warehouses ─────────────────────────────────────────────────────────────
INSERT INTO inventory_warehouses (id, code, name, description, is_active, created_at, updated_at)
VALUES
  ('seed-inv-wh-1', 'WA20269001', 'Main Warehouse',   'Seed data — primary stock location', true, now() - interval '3 days', now() - interval '3 days'),
  ('seed-inv-wh-2', 'WA20269002', 'Branch Warehouse', 'Seed data — secondary stock location', true, now() - interval '3 days', now() - interval '3 days')
ON CONFLICT (id) DO NOTHING;

-- ─── Products (Inventory module's own Product master) ──────────────────────
-- current_stock / average_cost / last_purchase_cost / last_stock_update
-- already reflect every Stock In/Out row below applied in order — see the
-- worked example in this file's header comment.
INSERT INTO inventory_products (
  id, product_code, name, description, uom_id, unit_price, cost_price,
  current_stock, min_stock, low_stock_alert, is_stockable, is_active,
  average_cost, last_purchase_cost, last_stock_update, created_at, updated_at
)
VALUES
  ('seed-inv-prod-1', 'PRD90000001', 'Photo Paper A4 Glossy (240gsm)', 'Seed data — glossy A4 photo paper, stocked by the box', (SELECT id FROM inventory_uoms WHERE uom_short = 'BOX'), 45.00, 30.00, 144.000, 24, 48, true, true, 2.33, 3.00, now() - interval '1 day', now() - interval '3 days', now() - interval '1 day'),
  ('seed-inv-prod-2', 'PRD90000002', 'Ink Cartridge - Black',         'Seed data — black ink cartridge for photo printers',    (SELECT id FROM inventory_uoms WHERE uom_short = 'PC'),  85.00, 60.00, 18.000,  5, 10, true, true, 60.00, 60.00, now() - interval '1 day', now() - interval '3 days', now() - interval '1 day'),
  ('seed-inv-prod-3', 'PRD90000003', 'Lamination Pouch A4',           'Seed data — A4 lamination pouches',                     (SELECT id FROM inventory_uoms WHERE uom_short = 'PC'),  2.50,  1.20, 150.000, 50, 100, true, true, 1.20, 1.20, now() - interval '1 day', now() - interval '3 days', now() - interval '1 day'),
  ('seed-inv-prod-4', 'PRD90000004', 'Photo Chemical Solution',       'Seed data — developing chemical, stocked by weight',    (SELECT id FROM inventory_uoms WHERE uom_short = 'KG'),  40.00, 25.00, 4.500,  2, 5,  true, true, 25.45, 30.00, now() - interval '1 day', now() - interval '3 days', now() - interval '1 day')
ON CONFLICT (id) DO NOTHING;

-- ─── Combo Products ─────────────────────────────────────────────────────────
-- New rows on the pre-existing, Node-owned `combo` table — the Inventory
-- ledger's COMBO item type points here, same as it would for any real combo.
-- Does not touch or alter any existing combo row.
INSERT INTO combo (id, combo_name, price, size, description, img_url, status, created_at, updated_at)
VALUES
  ('seed-inv-combo-1', 'Seed Inventory Combo Print Pack', 30, '4R', 'Seed data — combo used to test inventory stock in/out', 'combos/seed-inv-1.png', true, now() - interval '3 days', now() - interval '3 days'),
  ('seed-inv-combo-2', 'Seed Inventory Combo Deluxe',     55, '5R', 'Seed data — combo used to test inventory stock in/out', 'combos/seed-inv-2.png', true, now() - interval '3 days', now() - interval '3 days')
ON CONFLICT (id) DO NOTHING;

-- ─── Stock In ───────────────────────────────────────────────────────────────
-- Quantity/unit_cost are stored in the item's BASE unit (see
-- StockService::toBaseQuantity / toBaseUnitCost) even when the movement was
-- entered in a derived unit (Box -> Piece, Gram -> Kilogram below).
INSERT INTO inventory_stock_movements (
  id, movement_number, movement_type, movement_subtype, item_type, item_id,
  warehouse_id, quantity, input_uom_id, unit_cost, total_cost, batch_number,
  expiry_date, reference_type, reference_id, notes, created_at
)
VALUES
  -- Photo Paper: opening stock at Main (10 BOX @ RM24/box -> 120 PC @ RM2/PC), then a purchase at Branch (5 BOX @ RM36/box -> 60 PC @ RM3/PC)
  ('seed-inv-mv-in-1', 'STK-IN-'||to_char(now(), 'YYYYMMDD')||'-9001', 'IN', 'OPENING_STOCK', 'INVENTORY_PRODUCT', 'seed-inv-prod-1', 'seed-inv-wh-1', 120.000, (SELECT id FROM inventory_uoms WHERE uom_short = 'BOX'), 2.00, 240.00, NULL, NULL, 'DIRECT', NULL, 'Opening stock at rollout', now() - interval '3 days'),
  ('seed-inv-mv-in-2', 'STK-IN-'||to_char(now(), 'YYYYMMDD')||'-9002', 'IN', 'PURCHASE',      'INVENTORY_PRODUCT', 'seed-inv-prod-1', 'seed-inv-wh-2', 60.000,  (SELECT id FROM inventory_uoms WHERE uom_short = 'BOX'), 3.00, 180.00, NULL, NULL, 'DIRECT', 'GRN-SEED-0001', 'Purchased from vendor, branch restock', now() - interval '2 days'),
  -- Ink Cartridge: opening stock at Main (20 PC @ RM60/pc)
  ('seed-inv-mv-in-3', 'STK-IN-'||to_char(now(), 'YYYYMMDD')||'-9003', 'IN', 'OPENING_STOCK', 'INVENTORY_PRODUCT', 'seed-inv-prod-2', 'seed-inv-wh-1', 20.000,  (SELECT id FROM inventory_uoms WHERE uom_short = 'PC'),  60.00, 1200.00, NULL, NULL, 'DIRECT', NULL, 'Opening stock at rollout', now() - interval '3 days'),
  -- Lamination Pouch: opening stock at Branch (200 PC @ RM1.20/pc)
  ('seed-inv-mv-in-4', 'STK-IN-'||to_char(now(), 'YYYYMMDD')||'-9004', 'IN', 'OPENING_STOCK', 'INVENTORY_PRODUCT', 'seed-inv-prod-3', 'seed-inv-wh-2', 200.000, (SELECT id FROM inventory_uoms WHERE uom_short = 'PC'),  1.20, 240.00, NULL, NULL, 'DIRECT', NULL, 'Opening stock at rollout', now() - interval '3 days'),
  -- Photo Chemical: opening stock at Main (5 KG @ RM25/kg), then a small top-up entered in grams (500 G @ RM0.03/g -> 0.5 KG @ RM30/kg)
  ('seed-inv-mv-in-5', 'STK-IN-'||to_char(now(), 'YYYYMMDD')||'-9005', 'IN', 'OPENING_STOCK', 'INVENTORY_PRODUCT', 'seed-inv-prod-4', 'seed-inv-wh-1', 5.000,   (SELECT id FROM inventory_uoms WHERE uom_short = 'KG'),  25.00, 125.00, 'BATCH-CHEM-0001', (now() + interval '1 year')::date, 'DIRECT', NULL, 'Opening stock at rollout', now() - interval '3 days'),
  ('seed-inv-mv-in-6', 'STK-IN-'||to_char(now(), 'YYYYMMDD')||'-9006', 'IN', 'PURCHASE',      'INVENTORY_PRODUCT', 'seed-inv-prod-4', 'seed-inv-wh-1', 0.500,   (SELECT id FROM inventory_uoms WHERE uom_short = 'G'),   30.00, 15.00, 'BATCH-CHEM-0002', (now() + interval '1 year')::date, 'DIRECT', 'GRN-SEED-0002', 'Small top-up purchase, entered in grams', now() - interval '2 days'),
  -- Combos: opening stock only (Product/Combo carry no unit or cost on the ledger)
  ('seed-inv-mv-in-7', 'STK-IN-'||to_char(now(), 'YYYYMMDD')||'-9007', 'IN', 'OPENING_STOCK', 'COMBO', 'seed-inv-combo-1', 'seed-inv-wh-1', 15.000, NULL, NULL, NULL, NULL, NULL, 'DIRECT', NULL, 'Opening stock at rollout', now() - interval '3 days'),
  ('seed-inv-mv-in-8', 'STK-IN-'||to_char(now(), 'YYYYMMDD')||'-9008', 'IN', 'OPENING_STOCK', 'COMBO', 'seed-inv-combo-2', 'seed-inv-wh-2', 8.000,  NULL, NULL, NULL, NULL, NULL, 'DIRECT', NULL, 'Opening stock at rollout', now() - interval '3 days')
ON CONFLICT (id) DO NOTHING;

-- ─── Stock Out ──────────────────────────────────────────────────────────────
-- unit_cost on an Inventory Product OUT is always its average_cost AT THE
-- TIME of that movement (never user-entered) — see StockOutService. Product/
-- Combo OUT rows always carry unit_cost/total_cost = 0.00 (never NULL).
INSERT INTO inventory_stock_movements (
  id, movement_number, movement_type, movement_subtype, item_type, item_id,
  warehouse_id, quantity, input_uom_id, unit_cost, total_cost, batch_number,
  expiry_date, reference_type, reference_id, notes, created_at
)
VALUES
  -- Photo Paper: 3 BOX sold from Main (36 PC @ avg cost RM2.33/PC after the two receipts above)
  ('seed-inv-mv-out-1', 'STK-OUT-'||to_char(now(), 'YYYYMMDD')||'-9001', 'OUT', 'SALE',    'INVENTORY_PRODUCT', 'seed-inv-prod-1', 'seed-inv-wh-1', 36.000, (SELECT id FROM inventory_uoms WHERE uom_short = 'BOX'), 2.33, 83.88, NULL, NULL, 'SALE',    'SO-SEED-0001',  'Sold at kiosk counter', now() - interval '1 day'),
  -- Ink Cartridge: 2 PC damaged at Main
  ('seed-inv-mv-out-2', 'STK-OUT-'||to_char(now(), 'YYYYMMDD')||'-9002', 'OUT', 'DAMAGED', 'INVENTORY_PRODUCT', 'seed-inv-prod-2', 'seed-inv-wh-1', 2.000,  (SELECT id FROM inventory_uoms WHERE uom_short = 'PC'),  60.00, 120.00, NULL, NULL, 'DAMAGED', 'OUT-SEED-0002', 'Damaged during handling', now() - interval '1 day'),
  -- Lamination Pouch: 50 PC sold from Branch
  ('seed-inv-mv-out-3', 'STK-OUT-'||to_char(now(), 'YYYYMMDD')||'-9003', 'OUT', 'SALE',    'INVENTORY_PRODUCT', 'seed-inv-prod-3', 'seed-inv-wh-2', 50.000, (SELECT id FROM inventory_uoms WHERE uom_short = 'PC'),  1.20,  60.00,  NULL, NULL, 'SALE',    'SO-SEED-0003',  'Sold at kiosk counter', now() - interval '1 day'),
  -- Photo Chemical: 1 KG wastage at Main (avg cost RM25.45/kg after the top-up above)
  ('seed-inv-mv-out-4', 'STK-OUT-'||to_char(now(), 'YYYYMMDD')||'-9004', 'OUT', 'WASTAGE', 'INVENTORY_PRODUCT', 'seed-inv-prod-4', 'seed-inv-wh-1', 1.000,  (SELECT id FROM inventory_uoms WHERE uom_short = 'KG'),  25.45, 25.45,  NULL, NULL, 'WASTAGE', 'OUT-SEED-0004', 'Evaporation / wastage during processing', now() - interval '1 day'),
  -- Combo: 4 sold from Main
  ('seed-inv-mv-out-5', 'STK-OUT-'||to_char(now(), 'YYYYMMDD')||'-9005', 'OUT', 'SALE',    'COMBO', 'seed-inv-combo-1', 'seed-inv-wh-1', 4.000, NULL, 0.00, 0.00, NULL, NULL, 'SALE', 'SO-SEED-0005', 'Sold at kiosk counter', now() - interval '1 day')
ON CONFLICT (id) DO NOTHING;

-- ─── Resulting stock balances (per item, per warehouse) ─────────────────────
-- One row per (item_type, item_id, warehouse_id) touched above, holding the
-- net quantity after every IN/OUT movement for that combination.
INSERT INTO inventory_stock_balances (id, item_type, item_id, warehouse_id, current_quantity, created_at, updated_at)
VALUES
  ('seed-inv-bal-1', 'INVENTORY_PRODUCT', 'seed-inv-prod-1', 'seed-inv-wh-1', 84.000,  now() - interval '1 day', now() - interval '1 day'),
  ('seed-inv-bal-2', 'INVENTORY_PRODUCT', 'seed-inv-prod-1', 'seed-inv-wh-2', 60.000,  now() - interval '2 days', now() - interval '2 days'),
  ('seed-inv-bal-3', 'INVENTORY_PRODUCT', 'seed-inv-prod-2', 'seed-inv-wh-1', 18.000,  now() - interval '1 day', now() - interval '1 day'),
  ('seed-inv-bal-4', 'INVENTORY_PRODUCT', 'seed-inv-prod-3', 'seed-inv-wh-2', 150.000, now() - interval '1 day', now() - interval '1 day'),
  ('seed-inv-bal-5', 'INVENTORY_PRODUCT', 'seed-inv-prod-4', 'seed-inv-wh-1', 4.500,   now() - interval '1 day', now() - interval '1 day'),
  ('seed-inv-bal-6', 'COMBO',             'seed-inv-combo-1', 'seed-inv-wh-1', 11.000, now() - interval '1 day', now() - interval '1 day'),
  ('seed-inv-bal-7', 'COMBO',             'seed-inv-combo-2', 'seed-inv-wh-2', 8.000,  now() - interval '3 days', now() - interval '3 days')
ON CONFLICT (id) DO NOTHING;

COMMIT;
