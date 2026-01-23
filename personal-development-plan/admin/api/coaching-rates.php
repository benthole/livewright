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
    // Find the appropriate tier (use the highest tier that doesn't exceed sessions)
    $applicable_tier = 1;
    foreach ($tiers as $tier) {
        if ($sessions >= $tier) {
            $applicable_tier = $tier;
        }
    }

    if (isset($rate_matrix[$duration][$applicable_tier])) {
        $rate_per_session = $rate_matrix[$duration][$applicable_tier];
        $single_rate = $rate_matrix[$duration][1] ?? $rate_per_session;

        $regular_price = $single_rate * $sessions;
        $package_price = $rate_per_session * $sessions;
        $savings = $regular_price - $package_price;

        $response['calculation'] = [
            'duration' => $duration,
            'sessions' => $sessions,
            'applicable_tier' => $applicable_tier,
            'rate_per_session' => $rate_per_session,
            'single_session_rate' => $single_rate,
            'regular_price' => $regular_price,
            'package_price' => $package_price,
            'savings' => $savings,
            'savings_percent' => $regular_price > 0 ? round(($savings / $regular_price) * 100) : 0,
            'package_name' => "{$sessions} x {$duration}min Sessions with {$coach}",
            'description' => "{$sessions} " . ($duration == 60 ? 'one-hour' : ($duration == 45 ? '45-minute' : '30-minute')) . " coaching sessions with {$coach}"
        ];
    }
}

echo json_encode($response);
