-- Migration 006: Full LiveMORE Program Preset
-- Creates a new preset based on the Full LiveMORE Program specification
-- Bronze/Silver/Gold tiers with quarterly and monthly payment options
-- $100 deposit option, PIF marked as best value

INSERT INTO pdp_presets (
    name,
    contract_description,
    pdp_from,
    pdp_toward,
    option_1_desc,
    option_1_price_yearly,
    option_1_price_monthly,
    option_1_price_quarterly,
    option_1_sub_options,
    option_2_desc,
    option_2_price_yearly,
    option_2_price_monthly,
    option_2_price_quarterly,
    option_2_sub_options,
    option_3_desc,
    option_3_price_yearly,
    option_3_price_monthly,
    option_3_price_quarterly,
    option_3_sub_options
) VALUES (
    'Full LiveMORE Program',
    '<p><strong>Full LiveMORE Program</strong> — Year-long program</p><ul><li>Training segments in various areas of life over five weekends</li><li>Weekly live lessons and assignments</li><li>Proven research-based curriculum for lasting change</li><li>Assignment, SEI, and Lifestyle Coaching</li></ul><p><em>Pay in full for best value. $100 deposit option available.</em></p><p><small>* Financial aid is available for those who demonstrate need and are committed to fully engaging their growth capacity.<br>** Payment plans available.</small></p>',
    '',
    '',
    -- Option 1: Bronze Level
    '<p><strong>Bronze Level — Full LiveMORE Program</strong></p><p>30 minutes coaching every other week with Dr. Marilyn Pearson*</p><p><small>* Possibly another trained coach</small></p>',
    7000.00,
    642.00,
    1838.00,
    NULL,
    -- Option 2: Silver Level
    '<p><strong>Silver Level — LiveMORE Program Coaching Upgrade</strong></p><p>Coaching with Elizabeth Tuazon, MA, PCC</p>',
    8196.00,
    752.00,
    2152.00,
    NULL,
    -- Option 3: Gold Level
    '<p><strong>Gold Level — LiveMORE Program Coaching Upgrade</strong></p><p>Coaching with Dr. Judith Wright</p>',
    11796.00,
    1082.00,
    3097.00,
    NULL
);
