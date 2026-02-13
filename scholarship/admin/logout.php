<?php
require_once __DIR__ . '/../config.php';
logout();
header('Location: login.php');
exit;
