<?php
require 'config.php';
require 'vendor/autoload.php';
require 'src/ZakApiClient.php';

$api = new \VectorZak\ZakApiClient();
$res = $api->findActiveReservation('27', '2026-05-16', '12:00:00');
var_dump($res);
