<?php
session_start();
require_once 'config.php';
require_once 'src/ZakApiClient.php';

// Composer autoload (for Guzzle)
if (file_exists('vendor/autoload.php')) {
    require_once 'vendor/autoload.php';
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: index.php");
    exit;
}

$client = new \GuzzleHttp\Client(['base_uri' => ZAK_API_URL]);
$headers = ['x-api-key' => ZAK_API_KEY];

$totalSum = 0;
$totalCount = 0;
$targetMonthLabel = "";
$debugLog = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['target_month'])) {
    $targetMonthRaw = $_POST['target_month']; // Format: YYYY-MM
    $targetMonthObj = new DateTime($targetMonthRaw . '-01');
    
    // For display
    $meses = ["Enero", "Febrero", "Marzo", "Abril", "Mayo", "Junio", "Julio", "Agosto", "Septiembre", "Octubre", "Noviembre", "Diciembre"];
    $mesNombre = $meses[(int)$targetMonthObj->format('n') - 1];
    $targetMonthLabel = $mesNombre . " " . $targetMonthObj->format('Y');
    
    // We search an arrival window spanning a month before to catch long-stayers
    $searchFrom = (clone $targetMonthObj)->modify('-1 month')->format('d/m/Y');
    $searchTo = (clone $targetMonthObj)->modify('last day of this month')->format('d/m/Y');
    
    // The string we need to find in the extra's 'day' property (e.g. '/05/2026')
    $targetMonthWubook = $targetMonthObj->format('/m/Y');
    
    $limit = 8;
    $offset = 0;
    
    while (true) {
        try {
            $resp = $client->post("reservations/fetch_reservations", [
                'headers' => $headers,
                'form_params' => [
                    'filters' => json_encode([
                        'arrival' => ['from' => $searchFrom, 'to' => $searchTo],
                        'pager' => ['limit' => $limit, 'offset' => $offset]
                    ])
                ]
            ]);
            $data = json_decode($resp->getBody(), true);
            $reservations = $data['data']['reservations'] ?? [];
            
            if (count($reservations) === 0) break;
            
            foreach ($reservations as $res) {
                // OPTIMIZATION: Skip this reservation entirely if ZaK reports it has 0 COP in extras.
                // This saves us from making an API call and a 250ms sleep for 90% of reservations!
                if (empty($res['price']['extras']['total']) || $res['price']['extras']['total'] <= 0) {
                    continue;
                }
                
                usleep(250000); // 250ms sleep to stay under 4 req/sec rate limit
                $retry = 3;
                $extras = [];
                
                while ($retry > 0) {
                    try {
                        $extrasResp = $client->get("reservations/get_extras", [
                            'headers' => $headers,
                            'query' => ['rsrvid' => $res['id']]
                        ]);
                        $extrasData = json_decode($extrasResp->getBody(), true);
                        $extras = $extrasData['data'] ?? [];
                        break;
                    } catch (\Exception $e) {
                        if ($e->getCode() == 429 || $e->getCode() >= 500) {
                            $retry--;
                            sleep(2);
                            if ($retry == 0) throw $e;
                        } else {
                            throw $e;
                        }
                    }
                }
                
                foreach ($extras as $ex) {
                    $extraName = $ex['extra_info']['name'] ?? '';
                    $extraDate = $ex['dates']['day'] ?? ''; // Format: DD/MM/YYYY
                    $extraPrice = (float)($ex['price']['total'] ?? 0);
                    
                    // Look for the specific restaurant string AND ensure the date falls in the selected month
                    if (strpos($extraName, 'Restaurante - Comprobante') !== false && strpos($extraDate, $targetMonthWubook) !== false) {
                        $totalSum += $extraPrice;
                        $totalCount++;
                        $debugLog[] = "Comprobante hallado: {$extraName} - Valor: " . number_format($extraPrice, 0, ',', '.') . " COP (Reserva: {$res['id']})";
                    }
                }
            }
            
            if (count($reservations) < $limit) break;
            $offset += $limit;
            
        } catch (\Exception $e) {
            $debugLog[] = "Error API: " . $e->getMessage();
            break;
        }
    }
} else {
    header("Location: index.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reporte Mensual - VectorZak</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f7f6; margin: 0; padding: 2rem; }
        .container { background: #fff; padding: 2.5rem; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); max-width: 700px; margin: 0 auto; text-align: center; }
        h1 { color: #333; margin-bottom: 0.5rem; font-size: 2rem; }
        .subtitle { color: #666; font-size: 1.2rem; margin-bottom: 2rem; }
        
        .metric-box { background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 8px; padding: 2rem; margin-bottom: 2rem; }
        .metric-title { font-size: 1.1rem; color: #555; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 0.5rem; }
        .metric-value { font-size: 3rem; font-weight: bold; color: #28a745; }
        .metric-count { font-size: 1.1rem; color: #6c757d; margin-top: 0.5rem; }
        
        .back-btn { display: inline-block; padding: 0.75rem 2rem; background-color: #0056b3; color: white; text-decoration: none; border-radius: 4px; font-size: 1.1rem; }
        .back-btn:hover { background-color: #004494; }
        
        .details { margin-top: 2rem; text-align: left; background: #fff; border-top: 2px solid #eaeaea; padding-top: 1.5rem; }
        .details summary { cursor: pointer; color: #0056b3; font-weight: bold; margin-bottom: 1rem; }
        .details ul { padding-left: 20px; font-size: 0.9rem; color: #555; max-height: 300px; overflow-y: auto; }
        .details li { margin-bottom: 0.3rem; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Reporte de Restaurante</h1>
        <div class="subtitle">Importaciones de <?= htmlspecialchars($targetMonthLabel) ?></div>
        
        <div class="metric-box">
            <div class="metric-title">Total Generado</div>
            <div class="metric-value">$<?= number_format($totalSum, 0, ',', '.') ?> COP</div>
            <div class="metric-count">Basado en <?= $totalCount ?> comprobantes inyectados.</div>
        </div>
        
        <a href="index.php" class="back-btn">Volver al Inicio</a>
        
        <?php if (!empty($debugLog)): ?>
            <details class="details">
                <summary>Ver desglose de comprobantes</summary>
                <ul>
                    <?php foreach ($debugLog as $log): ?>
                        <li><?= htmlspecialchars($log) ?></li>
                    <?php endforeach; ?>
                </ul>
            </details>
        <?php endif; ?>
    </div>
</body>
</html>
