<?php

require_once('../settings.php');
require_once '../vendor/autoload.php';

$infusionsoft = new \Infusionsoft\Infusionsoft(array(
    'clientId'     => $appKey,
    'clientSecret' => $appSecret,
    'redirectUri'  => $redirectUri
));

if (isset($_GET['code']) and !$infusionsoft->getToken()) {
    print serialize($infusionsoft->requestAccessToken($_GET['code']));

} else {
    echo '<a href="';
    echo $infusionsoft->getAuthorizationUrl();
    echo '">';
    echo 'Click here to authorize';
    echo '</a>';
}