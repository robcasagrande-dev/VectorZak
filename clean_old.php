<?php
require 'config.php';
require 'vendor/autoload.php';

$client = new \GuzzleHttp\Client(['base_uri' => ZAK_API_URL]);
$headers = ['x-api-key' => ZAK_API_KEY];

$targetDate = '2026-04-01';
$targetDateWubook = '01/04/2026';
$firstDay = date('d/m/Y', strtotime($targetDate . ' -30 days'));
$lastDay = date('d/m/Y', strtotime($targetDate . ' +5 days'));

$limit = 8;
$offset = 0;
$deletedCount = 0;

echo "Buscando reservaciones activas cerca de $targetDateWubook...\n";

while (true) {
    $resp = $client->post("reservations/fetch_reservations", [
        'headers' => $headers,
        'form_params' => [
            'filters' => json_encode([
                'arrival' => ['from' => $firstDay, 'to' => $lastDay],
                'pager' => ['limit' => $limit, 'offset' => $offset]
            ])
        ]
    ]);
    $data = json_decode($resp->getBody(), true);
    $reservations = $data['data']['reservations'] ?? [];
    if (count($reservations) === 0) break;
    
    foreach ($reservations as $res) {
        $extrasResp = $client->get("reservations/get_extras", [
            'headers' => $headers,
            'query' => ['rsrvid' => $res['id']]
        ]);
        $extrasData = json_decode($extrasResp->getBody(), true);
        $extras = $extrasData['data'] ?? [];
        foreach ($extras as $ex) {
            $extraName = $ex['extra_info']['name'] ?? '';
            $extraDate = $ex['dates']['day'] ?? '';
            if (strpos($extraName, '[TEST]') !== false && $extraDate === $targetDateWubook) {
                $client->post("reservations/del_extra", [
                    'headers' => $headers,
                    'form_params' => [
                        'rsrvid' => $res['id'],
                        'rexid' => $ex['id']
                    ]
                ]);
                $deletedCount++;
                echo "Eliminado extra '{$extraName}' de la reserva {$res['id']}\n";
            }
        }
    }
    if (count($reservations) < $limit) break;
    $offset += $limit;
}
echo "Total de extras eliminados: $deletedCount\n";
