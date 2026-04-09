<?php

// Include shared database configuration
require_once(__DIR__ . '/../shared/config.php');

// Team configuration
// Custom field ID for team in Keap
$cohort_field_id = 45;

// Coach configuration
// Custom field IDs for coaches in Keap
$individual_coach_field_id = 137;  // Now supports comma-separated multiple coaches
$individual_coach_field_id_old = 47;  // Legacy single-value field (for migration)
$group_coach_field_id = 49;

// E Team role (blank or "---" = not on E Team; otherwise contains role)
$eteam_role_field_id = 73;

// Coaching Files Link (Google Drive folder)
$coaching_files_field_id = 135;

// Valid coach values
$individual_coaches = ['Dr. Bob', 'Dr. Judith', 'Elizabeth T.', 'Kun Kun', 'Marilyn P.', 'Ana G.', 'Group Only'];
$group_coaches = ['Elizabeth T.', 'Executive Group'];

// Team names grouped by status
$cohorts = [
    'active' => [
        'Purple People Power',
        'The Robust Radiant Otters',
        'The Robusters',
        'The Naked Truth',
    ],
    'functional' => [
        '-dropped',
        '(unassigned)',
        'Leader',
    ],
    'inactive' => [
        'The Cutie Tiernos Pakhi',
        'Phlock of Phoenixes',
    ],
];
