-- Verification fixtures for the Nest -> Laravel diff.
--
-- Every id is prefixed 'seed-' so cleanup.sql can remove exactly these rows and
-- nothing else. Encrypted columns hold real ciphertext produced by the shared
-- AES-256-CBC key, so the decrypting read paths are genuinely exercised.
--
-- 'Photographer One' -> 4c48be9dd9d0d2c9e1ee1ce7a4a26b32... (computed below via the app)
BEGIN;

-- kiosk + photographer + staff users
INSERT INTO users (id, name, email_id, ph_number, location, description, user_name, password, img, del_acc, user_type, role_id, status, created_at, updated_at)
VALUES
  ('seed-kiosk-1',  :'kiosk_name',  :'kiosk_email',  :'phone', 'Kuala Lumpur', 'seed kiosk',        'seedkiosk',  :'pass', '', false, 'kiosk',        'seed-role-kiosk-1',  true, now(), now()),
  ('seed-photog-1', :'photog_name', :'photog_email', :'phone', 'Kuala Lumpur', 'seed photographer', 'seedphotog', :'pass', '', false, 'photographer', 'seed-role-photog-1', true, now(), now()),
  ('seed-staff-1',  :'staff_name',  :'staff_email',  :'phone', 'Kuala Lumpur', 'seed staff',        'seedstaff',  :'pass', '', false, 'staff',        'Fc999',              true, now(), now());

-- role + kiosk mapping for a scoped (manager) user
INSERT INTO users (id, name, email_id, ph_number, location, description, user_name, password, img, del_acc, user_type, role_id, status, created_at, updated_at)
VALUES ('seed-mgr-1', :'mgr_name', :'mgr_email', :'phone', 'KL', 'seed manager', 'seedmgr', :'pass', '', false, 'manager', 'Fc998', true, now(), now());

INSERT INTO roles (id, role_name, is_active, user_id, created_at, updated_at)
VALUES ('seed-role-1', 'seed manager role', true, 'seed-mgr-1', now(), now());

INSERT INTO "role-kiosk-map" (id, role_id, kiosk_id, created_at, updated_at)
VALUES ('seed-rkm-1', 'seed-role-1', 'seed-kiosk-1', now(), now());

-- masters
INSERT INTO frames (id, frame_name, img_url, status, created_at, updated_at)
VALUES ('seed-frame-1', 'Seed Frame A', 'frames/seed1.png', true, now(), now()),
       ('seed-frame-2', 'Seed Frame B', 'frames/seed2.png', false, now(), now());

INSERT INTO products (id, product_name, price, size, description, photo_limit, product_type, img_url, status, created_at, updated_at)
VALUES ('seed-prod-1', 'Seed Print', 20, '4R', 'seed printed photo', 2, 'others', 'products/seed1.png', true, now(), now()),
       ('seed-prod-2', 'Seed Email', 10, '-',  'seed soft copy',     1, 'email',  'products/seed2.png', true, now(), now());

INSERT INTO combo (id, combo_name, price, size, description, img_url, status, created_at, updated_at)
VALUES ('seed-combo-1', 'Seed Combo', 30, '4R', 'seed combo', 'combos/seed1.png', true, now(), now());

INSERT INTO "prod-comb-map" (id, product_id, combo_id, created_at, updated_at)
VALUES ('seed-pcm-1', 'seed-prod-1', 'seed-combo-1', now(), now()),
       ('seed-pcm-2', 'seed-prod-2', 'seed-combo-1', now(), now());

INSERT INTO "product-size" (id, height, width, created_at, updated_at)
VALUES ('seed-size-1', '6', '4', now(), now());

INSERT INTO "pc-amt-master" (id, photo_count, price, created_at, updated_at)
VALUES ('seed-pcamt-1', 5, 49.5, now(), now());

INSERT INTO discount (id, discount_code, discount_amount, description, created_at, updated_at)
VALUES ('seed-disc-1', 'SEED10', 10, 'seed discount', now(), now());

INSERT INTO "object-master" (id, title, description, img_url, status, created_at, updated_at)
VALUES ('seed-obj-1', 'Seed Object', 'seed object desc', 'objects/seed1.png', true, now(), now());

INSERT INTO "ultra-object-master" (id, title, description, img_url, status, created_at, updated_at)
VALUES ('seed-ultra-1', 'Seed Ultra', 'seed ultra desc', 'ultra-objects/seed1.png', true, now(), now());

INSERT INTO "ultra-object-obj-map" (id, ultra_object_id, object_id, created_at, updated_at)
VALUES ('seed-uoom-1', 'seed-ultra-1', 'seed-obj-1', now(), now());

-- customer + uploads + framed images
INSERT INTO customers (id, name, email_id, ph_number, role_id, user_name, password, status, customer_code, created_at, updated_at)
VALUES ('seed-cust-1', 'Seed Customer', 'seedcustomer@example.com', '0123456789', 'Fc999', 'seedcust', :'pass', true, 'SEEDC1', now(), now());

INSERT INTO "images-uploads" (id, user_id, customer_id, date, "time", img_url, created_at, updated_at)
VALUES ('seed-img-1', 'seed-photog-1', NULL, to_char(now(), 'DD-MM-YYYY'), '09:00 AM', 'seed11111111.jpg', now(), now()),
       ('seed-img-2', 'seed-photog-1', NULL, to_char(now(), 'DD-MM-YYYY'), '10:00 AM', 'seed22222222.jpg', now(), now());

INSERT INTO "fram-img-map" (id, customer_id, org_img_id, org_img_name, frame_id, frame_name, merged_img_name, edited_img_name, is_edited, created_at, updated_at)
VALUES ('seed-fim-1', 'seed-cust-1', 'seed-img-1', 'seed11111111.jpg', 'seed-frame-1', 'Seed Frame A', 'seed-merged-1.jpg', NULL, false, now(), now()),
       ('seed-fim-2', 'seed-cust-1', 'seed-img-2', 'seed22222222.jpg', 'seed-frame-1', 'Seed Frame A', 'seed-merged-2.jpg', 'seed-edited-2.jpg', true, now(), now());

-- basket: one print product and one emailed product
INSERT INTO cart (id, sub_order_id, combo_id, combo_json, customer_id, qty, created_at, updated_at)
VALUES ('seed-cart-1', '1700000000001', 'seed-combo-1',
  '{"combo_id":"seed-combo-1","product_list":[{"product_id":"seed-prod-1","photo_limit":"2","product_type":"others","magnet_img_name":null,"certificate_img_name":null,"frame_img_ids":["seed-fim-1","seed-fim-2"]},{"product_id":"seed-prod-2","photo_limit":"1","product_type":"email","magnet_img_name":null,"certificate_img_name":null,"frame_img_ids":["seed-fim-1"]}]}'::json,
  'seed-cust-1', 1, now(), now());

-- commission configuration for the staff member on that combo
INSERT INTO "combo-user-commission" (id, combo_id, user_id, commission_type, commission_value, status, created_at, updated_at)
VALUES ('seed-cuc-1', 'seed-combo-1', 'seed-staff-1', 'percent', 10.00, true, now(), now());

-- orders in several states / payment types
INSERT INTO orders (id, order_id, kiosk_id, customer_id, date, "time", amount, discount, print_count, order_status, receipt_img, remarks, payment_type, response_json, staff_id, staff_code, staff_resolve_note, created_at, updated_at)
VALUES
  ('seed-order-1', 'ORD-SEED0000001', 'seed-kiosk-1', 'seed-cust-1', to_char(now(), 'DD-MM-YYYY'), '09:30 AM', '30',  '0',  '0', 'completed', NULL, 'seed completed', 'cash', '{"payment":true}'::json, 'seed-staff-1', 'Fc999', NULL, now(), now()),
  ('seed-order-2', 'ORD-SEED0000002', 'seed-kiosk-1', 'seed-cust-1', to_char(now(), 'DD-MM-YYYY'), '11:30 AM', '45.5','5',  '1', 'pending',   NULL, 'seed pending',   'qr',   '{"payment":false}'::json, NULL, NULL, NULL, now(), now()),
  ('seed-order-3', 'ORD-SEED0000003', 'seed-kiosk-1', 'seed-cust-1', to_char(now(), 'DD-MM-YYYY'), '12:30 PM', '15',  '0',  '2', 'cancelled', NULL, 'seed cancelled', 'card', NULL, NULL, NULL, NULL, now(), now());

-- earned commission ledger + a settled payout
INSERT INTO "staff-commission" (id, order_ref_id, order_id, cart_id, staff_id, staff_code, staff_name, combo_id, combo_name, combo_price, qty, commission_type, commission_value, commission_amount, payout_status, payout_id, order_state, order_status_at_entry, created_at, updated_at)
VALUES ('seed-sc-1', 'seed-order-1', 'ORD-SEED0000001', 'seed-cart-1', 'seed-staff-1', 'Fc999', 'Seed Staff', 'seed-combo-1', 'Seed Combo', 30.00, 1, 'percent', 10.00, 3.00, 'pending', NULL, 'active', 'completed', now(), now());

INSERT INTO "commission-payout" (id, staff_id, staff_name, total_amount, entry_count, paid_by, paid_by_name, paid_at, payout_date, payment_mode, released_by, reference_no, reference_date, remarks, created_at, updated_at)
VALUES ('seed-payout-1', 'seed-staff-1', 'Seed Staff', 12.50, 2, 'seed-mgr-1', 'Seed Manager', now(), current_date, 'cash', 'Seed Manager', NULL, NULL, 'seed payout', now(), now());

-- an archived (deleted) order for the deleted-orders report
INSERT INTO deleted_orders (id, order_pk_id, order_id, kiosk_id, customer_id, date, "time", amount, discount, print_count, order_status, receipt_img, remarks, payment_type, response_json, kiosk_name, order_created_at, order_updated_at, order_deleted_at, deleted_by, deleted_by_name, deleted_on, snapshot, created_at, updated_at)
VALUES ('seed-del-1', 'seed-order-old', 'ORD-SEED0000009', 'seed-kiosk-1', 'seed-cust-1', to_char(now(), 'DD-MM-YYYY'), '08:00 AM', '25', '0', '0', 'completed', NULL, 'seed archived', 'cash', NULL, 'Seed Kiosk', now(), now(), NULL, 'seed-mgr-1', 'Seed Manager', now(), '{"id":"seed-order-old"}'::json, now(), now());

COMMIT;
