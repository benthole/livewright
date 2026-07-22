-- Migration 018: Coaching packages as "choose one" options + one-time package checkout
--
-- Two capabilities for coaching-only quotes (e.g. a standalone coaching
-- package that isn't part of the recurring program):
--
-- 1. contracts.coaching_packages_mode lets an admin present the built
--    coaching packages either as optional add-ons (default, existing
--    behaviour) or as mutually-exclusive options the client chooses ONE of.
--
-- 2. A chosen coaching package can be paid online as a one-time charge that
--    is NOT tied to a pricing_options row. So payments.pricing_option_id must
--    allow NULL, and payment_type gains a 'coaching_package' value.
--
-- Run manually on the server:
--   mysql -u app_user_lw -p livewright < migrations/018_coaching_choose_one.sql

ALTER TABLE contracts
    ADD COLUMN coaching_packages_mode VARCHAR(20) NOT NULL DEFAULT 'addons' AFTER support_packages;

ALTER TABLE payments
    MODIFY COLUMN pricing_option_id INT NULL,
    MODIFY COLUMN payment_type ENUM('deposit','subscription_first','subscription_recurring','one_time','coaching_package') NOT NULL;
