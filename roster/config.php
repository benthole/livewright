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

// ============================================================
// Campaign Report configuration
// ============================================================

// API token for the JSON endpoint (for Make.com / AI workflows).
// Generate a strong value and keep it out of source control if possible.
// Leave empty to disable the JSON endpoint.
$campaign_report_api_token = '';

// Consumer email domains — used for consumer-vs-professional segmentation.
$consumer_email_domains = [
    'gmail.com', 'yahoo.com', 'aol.com', 'hotmail.com', 'outlook.com',
    'icloud.com', 'msn.com', 'live.com', 'me.com', 'mac.com', 'ymail.com',
    'comcast.net', 'verizon.net', 'sbcglobal.net', 'att.net', 'cox.net',
    'earthlink.net', 'protonmail.com', 'proton.me', 'gmx.com', 'rocketmail.com',
];

// VIP callout tag IDs — map a display label to a Keap tag ID.
// Populate these with real Keap tag IDs after creating tags in Keap.
// A tag ID of 0 disables that callout.
$vip_tag_ids = [
    'current_client' => 0,
    'thought_leader' => 0,
    'partner'        => 0,
];

// Test/junk email patterns — regex list. Matches produce a hygiene flag.
$test_email_patterns = [
    '/\+test/i',
    '/^test@/i',
    '/@example\./i',
    '/@livewright\.com$/i',
];

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
