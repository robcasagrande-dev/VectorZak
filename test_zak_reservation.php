<?php
require 'config.php';
require 'vendor/autoload.php';

$client = new \GuzzleHttp\Client([
    'base_uri' => ZAK_API_URL,
    'timeout'  => 15.0,
    'headers'  => [
        'x-api-key'   => ZAK_API_KEY,
        'Lcode'       => ZAK_LCODE,
        'Accept'      => 'application/json'
    ]
]);

$response = $client->post("reservations/fetch_reservations", [
    'form_params' => [
        'filters' => json_encode([
            'arrival' => ['from' => '16/05/2026', 'to' => '16/05/2026'], // using a date from the other test
            'pager' => ['limit' => 1, 'offset' => 0]
        ])
    ]
]);

$data = json_decode($response->getBody(), true);
echo json_encode($data['data']['reservations'][0], JSON_PRETTY_PRINT);
