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

$zakApi = new \VectorZak\ZakApiClient();
$client = new \GuzzleHttp\Client(['base_uri' => ZAK_API_URL]);
$headers = ['x-api-key' => ZAK_API_KEY];
$debugLog = [];
$deletedCount = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clean'])) {
    $targetDate = $_POST['target_date']; // Format: YYYY-MM-DD
    
    // We search an arrival window spanning a month before to catch long-stayers
    $firstDay = date('d/m/Y', strtotime($targetDate . ' -30 days'));
    $lastDay = date('d/m/Y', strtotime($targetDate . ' +5 days'));
    
    // Convert targetDate to DD/MM/YYYY to match the extra's 'day' format
    $targetDateWubook = date('d/m/Y', strtotime($targetDate));
    
    $limit = 8;
    $offset = 0;
    
    while (true) {
        try {
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
                    
                    if (strpos($extraName, 'Restaurante - Comprobante') !== false && $extraDate === $targetDateWubook) {
                        // Delete this extra!
                        $delResp = $client->post("reservations/del_extra", [
                            'headers' => $headers,
                            'form_params' => [
                                'rsrvid' => $res['id'],
                                'rexid' => $ex['id']
                            ]
                        ]);
                        $deletedCount++;
                        $debugLog[] = "Reserva #{$res['id']}: Se eliminó el Extra importado (ID {$ex['id']}, Nombre: {$extraName}).";
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
}

// Default date
$defaultDate = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deshacer Importación</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f7f6; margin: 0; padding: 2rem; }
        .container { background: #fff; padding: 2rem; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); max-width: 600px; margin: 0 auto; }
        h1 { color: #dc3545; border-bottom: 2px solid #eaeaea; padding-bottom: 0.5rem; }
        .form-group { margin-bottom: 1rem; }
        label { display: block; margin-bottom: 0.5rem; font-weight: bold; }
        input[type="text"] { width: 100%; padding: 0.75rem; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        button { width: 100%; padding: 0.75rem; background-color: #dc3545; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 1rem; }
        button:hover { background-color: #c82333; }
        .back-btn { display: inline-block; padding: 0.75rem; background-color: #6c757d; color: white; text-decoration: none; border-radius: 4px; margin-top: 1rem; text-align: center; width: 100%; box-sizing: border-box;}
        .back-btn:hover { background-color: #5a6268; }
        .results { margin-top: 2rem; background: #f8f9fa; padding: 1rem; border-radius: 4px; border: 1px solid #ddd; }
        ul { padding-left: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Deshacer Importación de Restaurante</h1>
        <p>Esta herramienta buscará todas las reservas activas en la fecha seleccionada. Revisará el folio de cada reserva y <strong>eliminará permanentemente</strong> cualquier Extra que comience con <strong>Restaurante - Comprobante</strong> y haya sido cargado para esa fecha específica.</p>
        
        <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clean'])): ?>
            <div class="results">
                <h3 style="margin-top: 0; color: #28a745;">Proceso Completado</h3>
                <p>Se encontraron y eliminaron <strong><?= $deletedCount ?></strong> cargos importados del restaurante.</p>
                <?php if (!empty($debugLog)): ?>
                    <ul>
                        <?php foreach ($debugLog as $log): ?>
                            <li><?= htmlspecialchars($log) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            <a href="index.php" class="back-btn" style="background-color: #0056b3;">Volver al Inicio</a>
        <?php else: ?>
            <form method="POST">
                <div class="form-group">
                    <label for="target_date">Selecciona el Día a Limpiar:</label>
                    <input type="date" id="target_date" name="target_date" value="<?= $defaultDate ?>" required>
                </div>
                <button type="submit" name="clean" onclick="return confirm('¿Estás seguro de que deseas buscar y eliminar todos los Extras de restaurante para este día específico?');">Ejecutar Limpieza</button>
            </form>
            <a href="index.php" class="back-btn">Cancelar y Volver</a>
        <?php endif; ?>
    </div>
</body>
</html>
