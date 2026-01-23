<?php

require_once('../settings.php');
require_once('../functions.php');
require_once '../vendor/autoload.php';

if (isset($_REQUEST['app'])) {
    $appUrlShort = clean($_REQUEST['app']);
}

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

$infusionsoft->refreshAccessToken();

// Save the serialized token to the current session for subsequent requests
$token = serialize($infusionsoft->getToken());

$access_token = $infusionsoft->getToken()->accessToken;
$refresh_token = $infusionsoft->getToken()->refreshToken;
$end_of_life = $infusionsoft->getToken()->endOfLife;
$scope = $infusionsoft->getToken()->extraInfo['scope'];
$token_type = $infusionsoft->getToken()->extraInfo['token_type'];

$scopeArray = explode('|',$scope);
$appUrl = $scopeArray[1];

$timestamp = date('Y-m-d H:i:s');

$query2 = "INSERT INTO inf_settings (token,updated_on,access_token,refresh_token,end_of_life,scope,token_type,app_url) VALUES(:token,:updated_on,:access_token,:refresh_token,:end_of_life,:scope,:token_type,:app_url)";
$params2 = array('token' => $token, 'updated_on' => $timestamp, 'access_token' => $access_token, 'refresh_token' => $refresh_token, 'end_of_life' => $end_of_life, 'scope' => $scope, 'token_type' => $token_type, 'app_url' => $appUrl);

$stmt2 = $conn->prepare($query2);
$stmt2->execute($params2);

