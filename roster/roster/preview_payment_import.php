<?php
// preview_payment_import.php - Preview payment data parsing before importing to Keap

// Parse the billing info into payment option and frequency
function parseBillingInfo($billingInfo) {
    $billingInfo = trim($billingInfo);

    // Default values
    $paymentOption = $billingInfo;
    $frequency = '';

    // Handle special cases
    $lowerBilling = strtolower($billingInfo);

    if ($lowerBilling === 'comp' || $lowerBilling === '0' || $lowerBilling === '') {
        return [
            'option' => $billingInfo ?: 'N/A',
            'frequency' => $billingInfo === 'COMP' ? 'Complimentary' : ($billingInfo === '0' ? 'No charge' : '')
        ];
    }

    if ($lowerBilling === 'exchange') {
        return [
            'option' => 'Exchange arrangement',
            'frequency' => 'Exchange'
        ];
    }

    if (strpos($lowerBilling, "couldn't find") !== false || $lowerBilling === '??' || strpos($lowerBilling, 'ask ') === 0) {
        return [
            'option' => $billingInfo,
            'frequency' => 'Unknown'
        ];
    }

    // Detect frequency from the billing string
    if (preg_match('/\/month|monthly/i', $billingInfo)) {
        $frequency = 'Monthly';
    } elseif (preg_match('/\/quarter|quarterly/i', $billingInfo)) {
        $frequency = 'Quarterly';
    } elseif (preg_match('/\/year|yearly|annually/i', $billingInfo)) {
        $frequency = 'Yearly';
    }

    // Check for minimum duration
    if (preg_match('/minimum\s+(\d+)\s+months?/i', $billingInfo, $matches)) {
        $frequency .= $frequency ? ", minimum {$matches[1]} months" : "Minimum {$matches[1]} months";
    }

    // Clean up the payment option - capitalize first letter
    $paymentOption = ucfirst($billingInfo);

    return [
        'option' => $paymentOption,
        'frequency' => $frequency ?: 'See notes'
    ];
}

// Read the CSV file
$csvFile = __DIR__ . '/../to-import/payment options and frequency.csv';
if (!file_exists($csvFile)) {
    die("CSV file not found: $csvFile");
}

$handle = fopen($csvFile, 'r');
if (!$handle) {
    die("Could not open CSV file");
}

// Skip header row
$header = fgetcsv($handle);

$rows = [];
while (($row = fgetcsv($handle)) !== false) {
    $firstName = trim($row[0] ?? '');
    $lastName = trim($row[1] ?? '');
    $billingInfo = trim($row[2] ?? '');

    $parsed = parseBillingInfo($billingInfo);

    $rows[] = [
        'name' => "$firstName $lastName",
        'original' => $billingInfo,
        'payment_option' => $parsed['option'],
        'frequency' => $parsed['frequency']
    ];
}

fclose($handle);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Import Preview</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
            background: #f5f5f5;
            padding: 20px;
            margin: 0;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        h1 {
            background: #2c3e50;
            color: white;
            margin: 0;
            padding: 20px 30px;
            font-size: 24px;
        }
        .actions {
            padding: 20px 30px;
            background: #ecf0f1;
            border-bottom: 1px solid #ddd;
        }
        .btn {
            display: inline-block;
            padding: 10px 20px;
            background: #3498db;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-weight: 600;
            border: none;
            cursor: pointer;
        }
        .btn:hover {
            background: #2980b9;
        }
        .btn-success {
            background: #27ae60;
        }
        .btn-success:hover {
            background: #1e8449;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th {
            background: #34495e;
            color: white;
            padding: 15px;
            text-align: left;
            font-size: 14px;
        }
        td {
            padding: 12px 15px;
            border-bottom: 1px solid #ecf0f1;
            font-size: 14px;
        }
        tr:nth-child(even) {
            background: #f9fafb;
        }
        tr:hover {
            background: #f0f3f5;
        }
        .original {
            color: #7f8c8d;
            font-style: italic;
        }
        .parsed {
            color: #27ae60;
            font-weight: 500;
        }
        .summary {
            padding: 20px 30px;
            background: #f8f9fa;
            border-top: 1px solid #ddd;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Payment Import Preview</h1>
        <div class="actions">
            <p style="margin: 0 0 15px 0; color: #7f8c8d;">Review the parsed data below. If it looks correct, click "Run Import" to update Keap.</p>
            <a href="import_payment_data.php" class="btn btn-success" onclick="return confirm('This will update <?php echo count($rows); ?> contacts in Keap. Continue?');">Run Import to Keap</a>
            <a href="./" class="btn" style="margin-left: 10px;">Back to Roster</a>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Original Billing Info</th>
                    <th>Payment Option (Field 107)</th>
                    <th>Frequency (Field 109)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($row['name']); ?></strong></td>
                    <td class="original"><?php echo htmlspecialchars($row['original']); ?></td>
                    <td class="parsed"><?php echo htmlspecialchars($row['payment_option']); ?></td>
                    <td class="parsed"><?php echo htmlspecialchars($row['frequency']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="summary">
            <strong>Total records:</strong> <?php echo count($rows); ?>
        </div>
    </div>
</body>
</html>
