<?php

// header('Content-Type: application/json');

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once('../settings.php');
require_once('../functions.php');
require_once '../vendor/autoload.php';

// add task to contact

$token = '';

try {
    $conn = new PDO("mysql:host=$dbHost;dbname=$dbName", $dbUser, $dbPass);
    // set the PDO error mode to exception
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $query = "SELECT token FROM inf_settings,apps WHERE apps.app_url = inf_settings.app_url AND apps.url_short = :app_url ORDER BY updated_on DESC LIMIT 1";
    $params = array('app_url' => $appUrlShort);
    $stmt = $conn->prepare($query);
    $stmt->execute($params);

    while ($row = $stmt->fetch())
    {
        $token = $row['token'];
    }


} catch(PDOException $e) {
    echo "Connection failed: " . $e->getMessage();
    exit;
}

$infusionsoft = new \Infusionsoft\Infusionsoft(array(
    'clientId'     => $appKey,
    'clientSecret' => $appSecret,
    'redirectUri'  => $redirectUri
));

$infusionsoft->setToken(unserialize($token));

$infusionsoft->setDebug(true);

// Define your parameters
$cohorts = 69;


$userID        = 12;

function getAllSavedSearchResults($infusionsoft, $savedSearchID, $userID) {
    $pageNumber = 0;
    $allResults = [];
    
    try {
        do {
            // Retrieve the saved search results for the current page
            $results = $infusionsoft->search()->getSavedSearchResultsAllFields($savedSearchID, $userID, $pageNumber);
            
            // If results are returned, merge them into the allResults array and increment the page number
            if (!empty($results)) {
                $allResults = array_merge($allResults, $results);
                $pageNumber++;
            }
        } while (!empty($results)); // Continue until an empty result set is returned
    } catch (Exception $e) {
        // Handle the error as needed (for example, logging it)
        echo "Error: " . $e->getMessage();
        return [];
    }
    
    return $allResults;
}

function extractContactIds(array $webinarArray): array {
    return array_map(function($entry) {
        return $entry['ContactId'];
    }, $webinarArray);
}


$participants = getAllSavedSearchResults($infusionsoft, $cohorts, $userID);

// print '<pre>';
// print_r($participants);
// exit;

// Helper: Clean & format phone numbers
function formatPhone($rawPhone) {
    // Strip all non-digits
    $digits = preg_replace('/\D+/', '', $rawPhone);
    if (strlen($digits) == 10) {
        return '(' . substr($digits, 0, 3) . ') ' . substr($digits, 3, 3) . '-' . substr($digits, 6);
    }
    return $rawPhone;
}

// Assign a unique tailwind badge color class to each cohort
$cohortColors = [];
$tailwindColors = ['blue', 'green', 'red', 'purple', 'yellow', 'pink', 'indigo', 'orange', 'teal'];
$colorIndex = 0;

foreach ($participants as $p) {
    $cohort = $p['Custom_Cohort'] ?? 'Unknown';
    if (!isset($cohortColors[$cohort])) {
        $color = $tailwindColors[$colorIndex % count($tailwindColors)];
        $cohortColors[$cohort] = "bg-{$color}-100 text-{$color}-800";
        $colorIndex++;
    }
}

function getCARELabel($person) {
    $values = [
        'A' => isset($person['Custom_CAREA']) ? (int)$person['Custom_CAREA'] : 0,
        'C' => isset($person['Custom_CAREC']) ? (int)$person['Custom_CAREC'] : 0,
        'R' => isset($person['Custom_CARER']) ? (int)$person['Custom_CARER'] : 0,
        'E' => isset($person['Custom_CAREE']) ? (int)$person['Custom_CAREE'] : 0,
    ];

    // If all values are 0, skip
    if (array_sum($values) === 0) {
        return '';
    }

    arsort($values); // Descending sort

    $labels = [
        'A' => 'Analyzer',
        'C' => 'Cooperator',
        'R' => 'Regulator',
        'E' => 'Energizer',
    ];

    $keys = array_keys($values);
    $top = $labels[$keys[0]];
    $second = $labels[$keys[1]];

    if ($values[$keys[0]] === $values[$keys[1]]) {
        return "{$top}-{$second} (equal)";
    }

    return "{$top}-{$second}";
}

$coachColors = [];
$tailwindColors = ['blue', 'green', 'red', 'purple', 'yellow', 'pink', 'indigo', 'orange', 'teal'];
$colorIndex = 0;

foreach ($participants as $p) {
    $coach = $p['Custom_CoachIndividual'] ?? null;
    if ($coach && !isset($coachColors[$coach])) {
        $color = $tailwindColors[$colorIndex % count($tailwindColors)];
        $coachColors[$coach] = "bg-{$color}-100 text-{$color}-800";
        $colorIndex++;
    }
}

$statusColors = [];
$tailwindColors = ['blue', 'green', 'red', 'purple', 'yellow', 'pink', 'indigo', 'orange', 'teal'];
$colorIndex = 0;

foreach ($participants as $p) {
    $status = trim($p['Custom_CohortStatus'] ?? '');
    if ($status && !isset($statusColors[$status])) {
        $color = $tailwindColors[$colorIndex % count($tailwindColors)];
        $statusColors[$status] = "bg-{$color}-100 text-{$color}-800";
        $colorIndex++;
    }
}

$timingColors = [];
$tailwindColors = ['blue', 'green', 'red', 'purple', 'yellow', 'pink', 'indigo', 'orange', 'teal'];
$colorIndex = 0;

foreach ($participants as $p) {
    $timing = trim($p['Custom_CohortTiming'] ?? '');
    if ($timing && !isset($timingColors[$timing])) {
        $color = $tailwindColors[$colorIndex % count($tailwindColors)];
        $timingColors[$timing] = "bg-{$color}-100 text-{$color}-800";
        $colorIndex++;
    }
}


function formatPhoneInternational($rawPhone) {
    // Extract digits only
    $digits = preg_replace('/\D+/', '', $rawPhone);

    // Assume US/Canada if 10 digits and prefix with +1
    if (strlen($digits) === 10) {
        return '+1 (' . substr($digits, 0, 3) . ') ' . substr($digits, 3, 3) . '-' . substr($digits, 6);
    }

    // If it starts with country code (e.g. +57 or +971), just format with +
    if (strlen($digits) > 10) {
        // Attempt to preserve country code
        return '+' . $digits;
    }

    // Fallback
    return $rawPhone;
}

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Participant Table</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 p-8">
  <div class="max-w-7xl mx-auto">
    <h1 class="text-3xl font-bold mb-6">Cohort List</h1>
    <div class="overflow-auto rounded-lg shadow">
  <table class="w-full table-auto bg-white">
<thead class="bg-gray-100 text-gray-700 text-xs font-semibold uppercase tracking-wider">
          <tr>
<th class="px-4 py-3 w-1/12">ID</th>
<th class="px-4 py-3 w-2/12">First Name</th>
<th class="px-4 py-3 w-2/12">Last Name</th>
<th class="px-4 py-3 w-1/12">Email</th>
<th class="px-4 py-3 w-2/12">Cohort</th>
<th class="px-4 py-3 w-1/12">Timing</th>
<th class="px-4 py-3 w-2/12">CARE</th>
<th class="px-4 py-3 w-1/12">Phone</th>
<th class="px-4 py-3 w-1/12">Status</th>
<th class="px-4 py-3 w-2/12">Billing Info</th>
<th class="px-4 py-3 w-4/12">Coaches</th>
          </tr>
        </thead>
        <tbody class="text-sm text-gray-900 divide-y divide-gray-200">
          <?php foreach ($participants as $person): 
              $cohort = $person['Custom_Cohort'] ?? 'Unknown';
              $cohortClass = $cohortColors[$cohort] ?? 'bg-gray-100 text-gray-800';
          ?>
            <tr>
              <td class="px-4 py-2"><a href="https://dja794.infusionsoft.com/Contact/manageContact.jsp?view=edit&ID=<?= $person['Id'] ?>" target="_blank" style="text-decoration: underline"><?= htmlspecialchars($person['Id'] ?? '') ?></a></td>
              <td class="px-4 py-2"><?= htmlspecialchars($person['ContactName.firstName'] ?? '') ?></td>
              <td class="px-4 py-2" style="white-space: nowrap"><?= htmlspecialchars($person['ContactName.lastName'] ?? '') ?></td>

<td class="px-4 py-2" align="center">
  <?php if (!empty($person['Email'])): 
    $email = htmlspecialchars($person['Email']);
  ?>
    <div class="relative group inline-block cursor-pointer" data-email="<?= $email ?>">
      <!-- Email Icon -->
      <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-blue-600 hover:text-blue-800 copy-email-icon" viewBox="0 0 20 20" fill="currentColor">
        <path d="M2.003 5.884L10 10.882l7.997-4.998A2 2 0 0016.8 4H3.2a2 2 0 00-1.197.884z" />
        <path d="M18 8.118l-8 5-8-5V14a2 2 0 002 2h12a2 2 0 002-2V8.118z" />
      </svg>

      <!-- Tooltip -->
      <div class="absolute z-10 hidden group-hover:block bg-gray-800 text-white text-xs rounded px-2 py-1 -top-10 left-1/2 -translate-x-1/2 whitespace-nowrap tooltip-text">
        <?= $email ?><br><span class="block text-gray-300 italic">Click icon to copy</span>
      </div>
    </div>
  <?php endif; ?>
</td>

              <td class="px-4 py-2">
                <span class="inline-block px-2 py-1 text-xs font-semibold rounded <?= $cohortClass ?>" style="white-space: nowrap">
                  <?= htmlspecialchars($cohort) ?>
                </span>
              </td>

<td class="px-4 py-2">
  <?php if (!empty($person['Custom_CohortTiming'])):
    $timing = trim($person['Custom_CohortTiming']);
    $timingClass = $timingColors[$timing] ?? 'bg-gray-100 text-gray-800';
  ?>
    <span class="inline-block px-2 py-1 text-xs font-semibold rounded <?= $timingClass ?>">
      <?= htmlspecialchars($timing) ?>
    </span>
  <?php endif; ?>
</td>

				<td class="px-4 py-2" style="white-space: nowrap"><?= htmlspecialchars(getCARELabel($person)) ?></td>

<td class="px-4 py-2" align="center">
  <?php if (!empty($person['PhoneWithExtension1'])): 
    $formattedPhone = formatPhoneInternational($person['PhoneWithExtension1']);
  ?>
    <div class="relative group inline-block cursor-pointer" data-phone="<?= $formattedPhone ?>">
      <!-- Phone Icon -->
      <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-green-600 hover:text-green-800 copy-phone-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.6a1 1 0 011 .76l1.12 4.47a1 1 0 01-.27.95l-2.17 2.17a11.05 11.05 0 005.66 5.66l2.17-2.17a1 1 0 01.95-.27l4.47 1.12a1 1 0 01.76 1V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
      </svg>

      <!-- Tooltip -->
      <div class="absolute z-10 hidden group-hover:block bg-gray-800 text-white text-xs rounded px-2 py-1 -top-10 left-1/2 -translate-x-1/2 whitespace-nowrap tooltip-text-phone">
        <?= $formattedPhone ?><br><span class="block text-gray-300 italic">Click icon to copy</span>
      </div>
    </div>
  <?php endif; ?>
</td>

<td class="px-4 py-2">
  <?php if (!empty($person['Custom_CohortStatus'])):
    $status = trim($person['Custom_CohortStatus']);
    $statusClass = $statusColors[$status] ?? 'bg-gray-100 text-gray-800';
  ?>
    <span class="inline-block px-2 py-1 text-xs font-semibold rounded <?= $statusClass ?>">
      <?= htmlspecialchars($status) ?>
    </span>
  <?php endif; ?>
</td>

<!--              <td class="px-4 py-2"><?= htmlspecialchars($person['Custom_WouldYouLifeToReceiveOurNewsletters'] ?? '') ?></td> -->
              <td class="px-4 py-2" style="white-space: nowrap"><?= htmlspecialchars($person['Custom_BillingInfo'] ?? '') ?></td>
              <td class="px-4 py-2">
  <?php if (!empty($person['Custom_CoachIndividual'])): 
    $coach = $person['Custom_CoachIndividual'];
    $coachClass = $coachColors[$coach] ?? 'bg-gray-100 text-gray-800';
  ?>
    <span class="inline-block px-2 py-1 text-xs font-semibold rounded <?= $coachClass ?>" style="white-space: nowrap">
      <?= htmlspecialchars($coach) ?>
    </span>
  <?php endif; ?>
</td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

<script>
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.copy-email-icon').forEach(icon => {
      icon.addEventListener('click', function () {
        const group = this.closest('.group');
        const email = group.dataset.email;
        const tooltip = group.querySelector('.tooltip-text');

        navigator.clipboard.writeText(email).then(() => {
          tooltip.innerHTML = "Copied!";
          setTimeout(() => {
            tooltip.innerHTML = email + '<br><span class="block text-gray-300 italic">Click icon to copy</span>';
          }, 1500);
        });
      });
    });
  });
</script>

<script>
  document.querySelectorAll('.copy-phone-icon').forEach(icon => {
    icon.addEventListener('click', function () {
      const group = this.closest('.group');
      const phone = group.dataset.phone;
      const tooltip = group.querySelector('.tooltip-text-phone');

      navigator.clipboard.writeText(phone).then(() => {
        tooltip.innerHTML = "Copied!";
        setTimeout(() => {
          tooltip.innerHTML = phone + '<br><span class="block text-gray-300 italic">Click icon to copy</span>';
        }, 1500);
      });
    });
  });
</script>

</body>
</html>
<?php
exit;



// START DISPLAY CODE

function formatDate($scalarDate) {
    $date = DateTime::createFromFormat('Ymd\TH:i:s', $scalarDate);
    return $date ? $date->format('Y-m-d') : '';
}

$webinars = [
    '2025-02-27' => extractContactIds($result20250227),
	'2025-03-06' => extractContactIds($result20250306),
	'2025-03-20' => extractContactIds($result20250320),
	'2025-03-27' => extractContactIds($result20250327),
	'2025-04-03' => extractContactIds($result20250403)
];

ksort($webinars); // make sure webinars are in chronological order

echo "<table border='1' cellpadding='5' cellspacing='0'>";
echo "<tr>";
echo "<th>Name</th><th>ContactId</th><th>Balance</th><th>InvTotal</th><th>AmtPaid</th><th>Date</th><th>Product</th><th>ReferralPartner</th><th>Attributed Webinar</th>";

// Webinar headers
foreach ($webinars as $date => $ids) {
    echo "<th>Webinar $date</th>";
}
echo "</tr>";

// Rows
foreach ($resultAllSales as $row) {

	$attributedWebinar = '-';
	$webinarDates = array_keys($webinars);
    $purchaseDate = DateTime::createFromFormat('Ymd\TH:i:s', $row['Date']->scalar);
    $purchaseTimestamp = $purchaseDate ? $purchaseDate->getTimestamp() : 0;

    echo "<tr>";
    echo "<td>{$row['Name']}</td>";
    echo "<td>{$row['ContactId']}</td>";
    echo "<td>{$row['Balance']}</td>";
    echo "<td>{$row['InvTotal']}</td>";
    echo "<td>{$row['AmtPaid']}</td>";
    echo "<td>" . ($purchaseDate ? $purchaseDate->format('Y-m-d') : '') . "</td>";
    echo "<td>{$row['Products']}</td>";
    echo "<td>{$row['ReferralPartner']}</td>";

echo "<td>";

for ($i = 0; $i < count($webinarDates); $i++) {
    $currentDate = $webinarDates[$i];
    $currentTimestamp = strtotime($currentDate);
	// print 'currentTimestamp: ' . $currentTimestamp;
	// print '<hr />';

    $isRegisteredCurrent = in_array($row['ContactId'], $webinars[$currentDate]);

    // Look ahead to the next webinar (if any)
    $nextDate = $webinarDates[$i + 1] ?? null;
    $nextTimestamp = $nextDate ? strtotime($nextDate) : PHP_INT_MAX;

    $isRegisteredNext = $nextDate && in_array($row['ContactId'], $webinars[$nextDate]);

    if (
        $isRegisteredCurrent &&
        $purchaseTimestamp >= $currentTimestamp &&
        $purchaseTimestamp < $nextTimestamp
    ) {
        $attributedWebinar = $currentDate;
        break; // we found the right one
    }
}


echo $attributedWebinar ?: '-';
echo "</td>";
    // Webinar columns
    foreach ($webinars as $date => $ids) {
        $isAttended = in_array($row['ContactId'], $ids);
        $symbol = '';
        if ($date === $attributedWebinar && $isAttended) {
            $symbol = '⭐️'; // attributed and attended
        } elseif ($isAttended) {
            $symbol = '✔️';
        }
        echo "<td>$symbol</td>";
    }

    echo "</tr>";
}

echo "</table>";