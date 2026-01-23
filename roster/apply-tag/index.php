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

$contactId = filter_input(INPUT_GET, 'contact_id', FILTER_VALIDATE_INT) 
    ?? filter_input(INPUT_POST, 'contact_id', FILTER_VALIDATE_INT);

$tagId = filter_input(INPUT_GET, 'tag_id', FILTER_VALIDATE_INT) 
    ?? filter_input(INPUT_POST, 'tag_id', FILTER_VALIDATE_INT);

// Check if contactId and tag_name are valid
if ($contactId > 0 && $tagId > 0) {
    // All good
} else {
    exit;
}

$tagResult = $infusionsoft->contacts('xml')->addToGroup($contactId, $tagId);

if ($tagResult) {
    $result = array('message' => 'success');
} else {
    $result = array('message' => 'success');
}

header('Content-Type: application/json');
print json_encode($result);