-- Migration 014: Wright Coaching Packages — one-off contract for Drs. Bob & Judith Wright
--
-- This contract uses the existing schema's Yearly/Quarterly/Monthly type slots to
-- store custom payment plans:
--   Option 1 (hours-based):   Yearly = Prepaid, Quarterly = 2-pay, Monthly = 4-pay
--   Option 2 (6 mo. coaching): Yearly = Prepaid, Quarterly = 2-pay, Monthly = 3-pay
--   Option 3 (3 mo. coaching): Yearly = Prepaid, Quarterly = 2-pay, Monthly = 3-pay
--
-- Display is handled by views/wright-coaching.php (loaded via ?skin=wright).
-- Stripe subscription billing for these split-pay plans is handled out-of-band.

SET @uid = CONCAT('wright-', LOWER(HEX(RANDOM_BYTES(8))));

INSERT INTO contracts (
    unique_id,
    first_name,
    last_name,
    email,
    contract_description,
    greeting,
    pdp_from,
    pdp_toward,
    pathways,
    option_1_minimum_months,
    option_2_minimum_months,
    option_3_minimum_months
) VALUES (
    @uid,
    'Coaching',
    'Client',
    'client@example.com',
    '<p><strong>Coaching &amp; consulting packages with Drs. Bob and Judith Wright</strong></p>',
    '',
    '',
    '',
    '',
    1, 1, 1
);

SET @cid = LAST_INSERT_ID();

-- =========================================================================
-- Option 1 — Hours to be used and scheduled as needed with Dr. Bob OR Dr. Judith
-- 3 sub-options: 5 hours / 10 hours / 20 hours
-- 3 payment plans: Prepaid (Yearly) / 2-pay (Quarterly) / 4-pay (Monthly)
-- =========================================================================

-- 5 hours
INSERT INTO pricing_options (contract_id, option_number, sub_option_name, description, price, type) VALUES
(@cid, 1, '5 hours',  '<p><strong>Option 1</strong></p><p>Hours to be used and scheduled as needed with either Dr. Bob or Dr. Judith</p>',  4625.00, 'Yearly'),
(@cid, 1, '5 hours',  '<p><strong>Option 1</strong></p><p>Hours to be used and scheduled as needed with either Dr. Bob or Dr. Judith</p>',  2335.00, 'Quarterly'),
(@cid, 1, '5 hours',  '<p><strong>Option 1</strong></p><p>Hours to be used and scheduled as needed with either Dr. Bob or Dr. Judith</p>',  1179.00, 'Monthly');

-- 10 hours
INSERT INTO pricing_options (contract_id, option_number, sub_option_name, description, price, type) VALUES
(@cid, 1, '10 hours', '<p><strong>Option 1</strong></p><p>Hours to be used and scheduled as needed with either Dr. Bob or Dr. Judith</p>',  8250.00, 'Yearly'),
(@cid, 1, '10 hours', '<p><strong>Option 1</strong></p><p>Hours to be used and scheduled as needed with either Dr. Bob or Dr. Judith</p>',  4150.00, 'Quarterly'),
(@cid, 1, '10 hours', '<p><strong>Option 1</strong></p><p>Hours to be used and scheduled as needed with either Dr. Bob or Dr. Judith</p>',  2088.00, 'Monthly');

-- 20 hours
INSERT INTO pricing_options (contract_id, option_number, sub_option_name, description, price, type) VALUES
(@cid, 1, '20 hours', '<p><strong>Option 1</strong></p><p>Hours to be used and scheduled as needed with either Dr. Bob or Dr. Judith</p>', 14500.00, 'Yearly'),
(@cid, 1, '20 hours', '<p><strong>Option 1</strong></p><p>Hours to be used and scheduled as needed with either Dr. Bob or Dr. Judith</p>',  7275.00, 'Quarterly'),
(@cid, 1, '20 hours', '<p><strong>Option 1</strong></p><p>Hours to be used and scheduled as needed with either Dr. Bob or Dr. Judith</p>',  3650.00, 'Monthly');

-- =========================================================================
-- Option 2 (= "Option 2a") — 6 months
-- 45 min coaching weekly with Dr. Bob + 45 min every other week with Dr. Judith
-- Plans: Prepaid / 2-pay / 3-pay
-- =========================================================================
INSERT INTO pricing_options (contract_id, option_number, sub_option_name, description, price, type) VALUES
(@cid, 2, 'Default', '<p><strong>Option 2a</strong></p><p>45 minutes of coaching weekly with Dr. Bob<br>+ 45 minutes of coaching every other week with Dr. Judith</p><p>For 6 months</p>', 21450.00, 'Yearly'),
(@cid, 2, 'Default', '<p><strong>Option 2a</strong></p><p>45 minutes of coaching weekly with Dr. Bob<br>+ 45 minutes of coaching every other week with Dr. Judith</p><p>For 6 months</p>', 11262.00, 'Quarterly'),
(@cid, 2, 'Default', '<p><strong>Option 2a</strong></p><p>45 minutes of coaching weekly with Dr. Bob<br>+ 45 minutes of coaching every other week with Dr. Judith</p><p>For 6 months</p>',  7865.00, 'Monthly');

-- =========================================================================
-- Option 3 (= "Option 2b") — 3 months
-- Plans: Prepaid / 2-pay / 3-pay
-- =========================================================================
INSERT INTO pricing_options (contract_id, option_number, sub_option_name, description, price, type) VALUES
(@cid, 3, 'Default', '<p><strong>Option 2b</strong></p><p>45 minutes of coaching weekly with Dr. Bob<br>+ 45 minutes of coaching every other week with Dr. Judith</p><p>For 3 months</p>', 11375.00, 'Yearly'),
(@cid, 3, 'Default', '<p><strong>Option 2b</strong></p><p>45 minutes of coaching weekly with Dr. Bob<br>+ 45 minutes of coaching every other week with Dr. Judith</p><p>For 3 months</p>',  5972.00, 'Quarterly'),
(@cid, 3, 'Default', '<p><strong>Option 2b</strong></p><p>45 minutes of coaching weekly with Dr. Bob<br>+ 45 minutes of coaching every other week with Dr. Judith</p><p>For 3 months</p>',  4171.00, 'Monthly');

-- Show the unique_id so the link can be constructed:
--   https://checkout.livewright.com/personal-development-plan/?uid=<unique_id>&skin=wright
SELECT @uid AS contract_unique_id, @cid AS contract_id;
