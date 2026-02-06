-- Migration 003: LiveMORE Program Preset
-- Creates the LiveMORE Program preset with all pricing tiers

-- Insert LiveMORE Program preset
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
    'LiveMORE Program',
    '<p><strong>Full LiveMORE Program includes:</strong></p><ul><li>Access to the complete LiveMORE curriculum</li><li>Weekly group lessons and coaching sessions</li><li>Growth resources and materials</li><li>Community support and accountability</li></ul>',
    '',
    '',
    -- Option 1: Full LiveMORE Program (base)
    '<p><strong>Full LiveMORE Program</strong></p><p>30 minutes coaching every other week with Dr. Marilyn Pearson</p>',
    7000.00,
    583.00,
    NULL,
    NULL,
    -- Option 2: Coaching Upgrade Options (sub-options for different coaches)
    '<p><strong>LiveMORE Program Coaching Upgrade Options</strong></p><p>Upgrade your coaching experience with a premium coach.</p>',
    NULL,
    NULL,
    NULL,
    '[{"name":"Elizabeth Tuazon, MA, PCC","yearly":8196,"monthly":683,"quarterly":null},{"name":"Dr. Judith Wright","yearly":11796,"monthly":983,"quarterly":null}]',
    -- Option 3: Scholarship Levels
    '<p><strong>Scholarship Levels</strong></p><p>Ranges from $495 to $295 monthly fee. Requires application with demonstrated need; may need to reapply quarterly.</p><p><strong>Group coaching ONLY</strong> (~60-90 minutes every week)</p><p><strong>Required commitments:</strong></p><ul><li>Attend 80% of every quarter''s weekly lessons and group coaching</li><li>Submit 80% of every quarter''s weekly growth reports, demonstrating what you are learning and how you are growing</li></ul>',
    NULL,
    NULL,
    NULL,
    '[{"name":"Scholarship - Full ($495/mo)","yearly":null,"monthly":495,"quarterly":null},{"name":"Scholarship - Partial ($395/mo)","yearly":null,"monthly":395,"quarterly":null},{"name":"Scholarship - Reduced ($295/mo)","yearly":null,"monthly":295,"quarterly":null}]'
);

-- Add Dr. Marilyn Pearson to coaching_rates table
-- Using Judith's rates as template but adjusted for 30-minute bi-weekly sessions
INSERT INTO coaching_rates (coach_name, duration_minutes, tier, rate_per_session) VALUES
('Dr. Marilyn Pearson', 30, 1, 375.00),
('Dr. Marilyn Pearson', 30, 3, 350.00),
('Dr. Marilyn Pearson', 30, 5, 325.00),
('Dr. Marilyn Pearson', 30, 10, 300.00),
('Dr. Marilyn Pearson', 30, 20, 275.00),
('Dr. Marilyn Pearson', 45, 1, 560.00),
('Dr. Marilyn Pearson', 45, 3, 525.00),
('Dr. Marilyn Pearson', 45, 5, 487.50),
('Dr. Marilyn Pearson', 45, 10, 450.00),
('Dr. Marilyn Pearson', 45, 20, 412.50),
('Dr. Marilyn Pearson', 60, 1, 750.00),
('Dr. Marilyn Pearson', 60, 3, 700.00),
('Dr. Marilyn Pearson', 60, 5, 650.00),
('Dr. Marilyn Pearson', 60, 10, 600.00),
('Dr. Marilyn Pearson', 60, 20, 550.00);

-- Add Elizabeth Tuazon to coaching_rates if not already present
INSERT INTO coaching_rates (coach_name, duration_minutes, tier, rate_per_session)
SELECT 'Elizabeth Tuazon', duration_minutes, tier, rate_per_session
FROM coaching_rates
WHERE coach_name = 'Judith'
AND NOT EXISTS (SELECT 1 FROM coaching_rates WHERE coach_name = 'Elizabeth Tuazon' LIMIT 1);
