<?php

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

// 

header('Content-Type: application/json'); // Set the response to JSON format

// Get the values from the request (GET or POST)
$contactId = isset($_REQUEST['contactId']) ? intval($_REQUEST['contactId']) : null;
$invoiceId = isset($_REQUEST['invoiceId']) ? intval($_REQUEST['invoiceId']) : null;
$paymentAmount = isset($_REQUEST['paymentAmount']) ? floatval($_REQUEST['paymentAmount']) : null;

// Check if all parameters are present
if ($contactId == null || $invoiceId == null || $paymentAmount == null) {

	echo json_encode([
        'status' => 'failure',
        'message' => 'Not all parameters received'
    ]);

	exit;
}    

// Add Payment
$orderDateFormatted = new \DateTime('now',new \DateTimeZone('America/New_York'));

$paymentType = 'Credit Card (Manual)';
$paymentDescription = '';

$addPayment = $infusionsoft->invoices('xml')->addManualPayment($invoiceId, $paymentAmount, $orderDateFormatted, $paymentType, $paymentDescription, false);

if ($addPayment) {
	
	echo json_encode([
        'status' => 'success',
		'add_payment' => $addPayment
    ]);
}
