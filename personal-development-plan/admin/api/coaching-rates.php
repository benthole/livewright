<?php
/**
 * API Endpoint: Get coaching rates for package builder
 *
 * Returns rates for a given coach, duration, and session count.
 * Used by the admin package builder to calculate pricing.
 *
 * GET Parameters:
 * - coach: Coach name (required)
 * - duration: Session duration in minutes (optional)
 * - sessions: Number of sessions (optional)
 *
 * Returns JSON with rates and calculated pricing.
 */

require_once '../../config.php';

// Require login for API access
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

$coach = $_GET['coach'] ?? '';
$duration = (int)($_GET['duration'] ?? 0);
$sessions = (int)($_GET['sessions'] ?? 0);
// Optional: bill at a specific package tier (flat), independent of delivered sessions.
// e.g. deliver 7 sessions (every 3 weeks) but bill the flat 5-session package.
$bill_sessions = (int)($_GET['bill_sessions'] ?? 0);
if ($bill_sessions <= 0) $bill_sessions = $sessions;
// Optional: manual discount percentage applied on top of volume pricing (e.g. 20 = 20% off).
$discount_percent = (float)($_GET['discount'] ?? 0);
if ($discount_percent < 0) $discount_percent = 0;
if ($discount_percent > 100) $discount_percent = 100;

// If no coach specified, return list of all coaches
if (empty($coach)) {
    $stmt = $pdo->query("SELECT DISTINCT coach_name FROM coaching_rates WHERE deleted_at IS NULL ORDER BY coach_name");
    $coaches = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo json_encode(['coaches' => $coaches]);
    exit;
}

// Get all rates for the coach
$stmt = $pdo->prepare("
    SELECT duration_minutes, tier, rate_per_session
    FROM coaching_rates
    WHERE coach_name = ? AND deleted_at IS NULL
    ORDER BY duration_minutes DESC, tier
");
$stmt->execute([$coach]);
$rates = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($rates)) {
    echo json_encode(['error' => 'No rates found for this coach']);
    exit;
}

// Build rate matrix
$rate_matrix = [];
$durations = [];
$tiers = [];

foreach ($rates as $rate) {
    $d = $rate['duration_minutes'];
    $t = $rate['tier'];
    $rate_matrix[$d][$t] = (float)$rate['rate_per_session'];

    if (!in_array($d, $durations)) $durations[] = $d;
    if (!in_array($t, $tiers)) $tiers[] = $t;
}

rsort($durations); // 60, 45, 30
sort($tiers);      // 1, 3, 5, 10, 20

$response = [
    'coach' => $coach,
    'durations' => $durations,
    'tiers' => $tiers,
    'rates' => $rate_matrix
];

// If duration and sessions specified, calculate package pricing
if ($duration > 0 && $sessions > 0) {
    // The billed tier is chosen from bill_sessions (defaults to delivered sessions),
    // so you can, e.g., deliver 7 sessions but bill the flat 5-session package price point.
    $applicable_tier = 1;
    foreach ($tiers as $tier) {
        if ($bill_sessions >= $tier) {
            $applicable_tier = $tier;
        }
    }

    if (isset($rate_matrix[$duration][$applicable_tier])) {
        $rate_per_session = $rate_matrix[$duration][$applicable_tier];
        $single_rate = $rate_matrix[$duration][1] ?? $rate_per_session;

        // Flat package price = tier rate x the billed tier count (not the delivered count).
        $package_price = $rate_per_session * $bill_sessions;
        // Regular price compares against paying the single-session rate for every delivered session.
        $regular_price = $single_rate * $sessions;

        // Manual discount applies on top of the volume (package) price.
        $discount_amount = round($package_price * ($discount_percent / 100), 2);
        $net_price = $package_price - $discount_amount;
        // Total the client saves vs. the regular single-session price (volume + discount).
        $savings = max(0, $regular_price - $net_price);

        $length_label = ($duration == 60 ? 'one-hour' : ($duration == 45 ? '45-minute' : '30-minute'));
        $description = "{$sessions} {$length_label} coaching sessions with {$coach}";
        if ($bill_sessions !== $sessions) {
            $description .= " (billed at the {$bill_sessions}-session package rate)";
        }
        if ($discount_percent > 0) {
            $description .= " — " . rtrim(rtrim(number_format($discount_percent, 1), '0'), '.') . "% discount applied";
        }

        $response['calculation'] = [
            'duration' => $duration,
            'sessions' => $sessions,
            'bill_sessions' => $bill_sessions,
            'applicable_tier' => $applicable_tier,
            'rate_per_session' => $rate_per_session,
            'single_session_rate' => $single_rate,
            'regular_price' => $regular_price,
            'package_price' => $package_price,
            'discount_percent' => $discount_percent,
            'discount_amount' => $discount_amount,
            'net_price' => $net_price,
            'savings' => $savings,
            'savings_percent' => $regular_price > 0 ? round(($savings / $regular_price) * 100) : 0,
            'package_name' => "{$sessions} x {$duration}min Sessions with {$coach}",
            'description' => $description
        ];
    }
}

echo json_encode($response);
