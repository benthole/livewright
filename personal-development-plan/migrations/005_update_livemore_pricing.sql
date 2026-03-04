-- Migration 005: Update LiveMORE Program Pricing
-- Restructures from sub-options model to Bronze/Silver/Gold tiers
-- Adds quarterly pricing to all options
-- Removes scholarship option, replaces with Gold level

UPDATE pdp_presets SET
    -- Option 1: Bronze Level
    option_1_desc = '<p><strong>Bronze Level — Full LiveMORE Program</strong></p><p>30 minutes coaching every other week with Dr. Marilyn Pearson</p>',
    option_1_price_yearly = 7000.00,
    option_1_price_quarterly = 1838.00,
    option_1_price_monthly = 642.00,
    option_1_sub_options = NULL,

    -- Option 2: Silver Level
    option_2_desc = '<p><strong>Silver Level — LiveMORE Program Coaching Upgrade</strong></p><p>Coaching with Elizabeth Tuazon, MA, PCC</p>',
    option_2_price_yearly = 8196.00,
    option_2_price_quarterly = 2152.00,
    option_2_price_monthly = 752.00,
    option_2_sub_options = NULL,

    -- Option 3: Gold Level
    option_3_desc = '<p><strong>Gold Level — LiveMORE Program Coaching Upgrade</strong></p><p>Coaching with Dr. Judith Wright</p>',
    option_3_price_yearly = 11796.00,
    option_3_price_quarterly = 3097.00,
    option_3_price_monthly = 1082.00,
    option_3_sub_options = NULL

WHERE name = 'LiveMORE Program';
