<?php
session_start();
set_time_limit(0);
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

// AJAX handler for syncing a single invoice
if (isset($_GET['action']) && $_GET['action'] === 'sync_single') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    
    $rcode = $input['rcode'] ?? '';
    $internalId = $input['internal_id'] ?? '';
    $guestName = $input['guest_name'] ?? 'Huésped';
    $inv = $input['invoice'] ?? null;
    
    if (empty($rcode) || empty($internalId) || empty($inv)) {
        echo json_encode(['status' => 'error', 'message' => 'Datos de entrada inválidos.']);
        exit;
    }
    
    $zakApi = new \VectorZak\ZakApiClient();
    $resStub = [
        'id' => $internalId,
        'id_human' => $rcode,
        'guest_name' => $guestName
    ];
    
    $row = [
        'room_raw' => $rcode,
        'room_number' => $rcode,
        'invoice' => $inv['facturaId'],
        'amount' => $inv['total'],
        'date' => $inv['fecha'],
        'time' => '',
        'waiter' => $inv['vendedor'],
        'table' => ''
    ];
    
    $res = $zakApi->appendNoteToReservation($resStub, $row);
    echo json_encode($res);
    exit;
}

$errorMsg = '';
$rcode = '';
$cleanInvoices = [];
$internalId = '';
$reservation = null;
$guestName = '';
$dfrom = '';
$dto = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rcode = strtoupper(trim($_POST['rcode'] ?? ''));
    
    if (empty($rcode)) {
        $errorMsg = "Por favor ingrese un código de reserva ZaK válido.";
    } else {
        try {
            $zakApi = new \VectorZak\ZakApiClient();
            $reservation = $zakApi->fetchReservationByCode($rcode);
            $internalId = $reservation['id'];
            $guestName = $reservation['guest_name'] ?? 'Huésped';
            $dfrom = $reservation['dfrom'] ?? '';
            $dto = $reservation['dto'] ?? '';
            
            // Format check-in/out dates as YYYY-MM-DD
            $dfromParts = explode('/', $reservation['dfrom']);
            $dtoParts = explode('/', $reservation['dto']);
            $arrival = "{$dfromParts[2]}-{$dfromParts[1]}-{$dfromParts[0]}";
            $departure = "{$dtoParts[2]}-{$dtoParts[1]}-{$dtoParts[0]}";
            
            // Login and Fetch from VectorPOS
            if (!class_exists('GuzzleHttp\Client')) {
                throw new \Exception("Biblioteca GuzzleHttp no instalada.");
            }
            
            $cookieJar = new \GuzzleHttp\Cookie\CookieJar();
            $client = new \GuzzleHttp\Client([
                'cookies' => $cookieJar,
                'timeout' => 20.0,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0'
                ]
            ]);
            
            // VectorPOS Login
            $loginUrl = "https://pos.vectorpos.com.co/index.php?r=site/login";
            $client->post($loginUrl, [
                'form_params' => [
                    'LoginForm[username]' => VECTOR_USER,
                    'LoginForm[password]' => VECTOR_PASS,
                    'LoginForm[rememberMe]' => '1'
                ]
            ]);
            
            // Fetch relación facturas
            $posUrl = "https://pos.vectorpos.com.co/?r=ventas%2FrelacionFacturas&idSyA=A09874700300001&fechaInicial={$arrival}&fechaFinal={$departure}";
            $relResp = $client->get($posUrl);
            $html = $relResp->getBody()->getContents();
            
            // Parse dynamic data from javascript JSON array using Regex
            if (preg_match('/const\s+datosOriginales_[a-zA-Z0-9]+\s*=\s*(\[.*?\]);/s', $html, $matches)) {
                $data = json_decode($matches[1], true);
                if (is_array($data)) {
                    foreach ($data as $item) {
                        $doc = isset($item['Documento Cliente']) ? trim($item['Documento Cliente']) : '';
                        if ($doc === $rcode) {
                            // Extract and clean factura ID from button HTML string
                            $facturaHtml = $item['Factura'] ?? '';
                            $facturaId = trim(strip_tags($facturaHtml));
                            
                            // Total
                            $totalRaw = $item['Total Pagado'] ?? '0';
                            $total = (float) preg_replace('/[^0-9.]/', '', $totalRaw);
                            
                            // Fecha
                            $fecha = $item['Fecha'] ?? '';
                            
                            // Vendedor / Cajero fallback
                            $vendedor = trim($item['Vendedor'] ?? '');
                            if (empty($vendedor)) {
                                $vendedor = trim($item['Cajero'] ?? '');
                            }
                            
                            $cleanInvoices[] = [
                                'facturaId' => $facturaId,
                                'total' => $total,
                                'fecha' => $fecha,
                                'vendedor' => $vendedor
                            ];
                        }
                    }
                }
            } else {
                throw new \Exception("No se pudo parsear la tabla de facturas de VectorPOS.");
            }
            
        } catch (\Exception $e) {
            $errorMsg = "Excepción: " . $e->getMessage();
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
    <title>Sincronización Directa - VectorZak</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f7f6; margin: 0; padding: 2rem; }
        .container { background: #fff; padding: 2rem; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); max-width: 800px; margin: 0 auto; }
        h1 { color: #333; border-bottom: 2px solid #eaeaea; padding-bottom: 0.5rem; margin-top: 0; }
        .back-btn { display: inline-block; padding: 0.75rem 1.5rem; background-color: #6c757d; color: white; text-decoration: none; border-radius: 4px; margin-top: 1rem; text-align: center; }
        .back-btn:hover { background-color: #5a6268; }
        .confirm-btn { display: inline-block; padding: 0.75rem 1.5rem; background-color: #28a745; color: white; border: none; border-radius: 4px; margin-top: 1rem; cursor: pointer; font-size: 1rem; font-weight: bold; }
        .confirm-btn:hover { background-color: #218838; }
        .error-alert { color: #dc3545; background: #f8d7da; padding: 1rem; border-radius: 4px; border: 1px solid #f5c6cb; margin-bottom: 1.5rem; }
        .warning-alert { color: #856404; background: #fff3cd; padding: 1rem; border-radius: 4px; border: 1px solid #ffeeba; margin-bottom: 1.5rem; text-align: center; font-weight: bold; }
        .info-box { background: #e8f4fd; color: #1d6fa5; padding: 1rem; border-radius: 6px; font-size: 0.9rem; line-height: 1.4; margin-bottom: 1.5rem; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 1.5rem; }
        th, td { padding: 12px; border-bottom: 1px solid #eaeaea; text-align: left; }
        th { background-color: #f8f9fa; font-weight: bold; }
        tr:hover { background-color: #fdfdfd; }
        .btn-container { display: flex; gap: 10px; justify-content: flex-end; align-items: center; }
    </style>
</head>
<body>
    <div class="container">
        
        <?php if (!empty($errorMsg)): ?>
            <h1>Error</h1>
            <div class="error-alert"><?= htmlspecialchars($errorMsg) ?></div>
            <a href="index.php" class="back-btn">Volver al Inicio</a>
            
        <?php else: ?>
            <h1>Previsualización de Facturas</h1>
            
            <div class="reservation-card" style="background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 6px; padding: 1.25rem; margin-bottom: 1.5rem; border-left: 4px solid #00bcd4;">
                <h3 style="margin-top: 0; margin-bottom: 0.75rem; font-size: 1.1rem; color: #495057;">Datos de la Reserva</h3>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; font-size: 0.95rem; color: #333;">
                    <div><strong>Código ZaK:</strong> <span style="font-family: monospace; font-size: 1.05rem; background: #e9ecef; padding: 2px 6px; border-radius: 4px;"><?= htmlspecialchars($rcode) ?></span></div>
                    <div><strong>ID Interno:</strong> <?= htmlspecialchars($internalId) ?></div>
                    <div><strong>Huésped:</strong> <?= htmlspecialchars($guestName) ?></div>
                    <div><strong>Check-in:</strong> <?= htmlspecialchars($dfrom) ?></div>
                    <div><strong>Check-out:</strong> <?= htmlspecialchars($dto) ?></div>
                </div>
            </div>
            
            <?php if (empty($cleanInvoices)): ?>
                <div class="warning-alert">
                    ⚠️ No se encontraron facturas en VectorPOS asociadas a la identificación de cliente "<?= htmlspecialchars($rcode) ?>" en las fechas indicadas.
                </div>
                <a href="index.php" class="back-btn" style="width: 100%; box-sizing: border-box;">Volver al Inicio</a>
            <?php else: ?>
                <div class="info-box">
                    ℹ️ Los precios se redondearán a la unidad de mil inferior (ej: $15.800 COP se registrará como $15.000 COP) y se omitirán las facturas que ya estén cargadas en ZaK para esta reserva.
                </div>
                
                <table>
                    <thead>
                        <tr>
                            <th>Factura</th>
                            <th>Fecha</th>
                            <th>Vendedor</th>
                            <th style="text-align: right;">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cleanInvoices as $inv): ?>
                            <tr>
                                <td style="font-weight: bold; color: #0056b3;">#<?= htmlspecialchars($inv['facturaId']) ?></td>
                                <td><?= htmlspecialchars($inv['fecha']) ?></td>
                                <td><?= htmlspecialchars($inv['vendedor']) ?></td>
                                <td style="text-align: right; font-weight: bold; color: #28a745;">$<?= number_format($inv['total'], 0, ',', '.') ?> COP</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <form id="sync-form" style="margin: 0;">
                    <div class="btn-container">
                        <a href="index.php" class="back-btn" style="margin-top: 0; background-color: #6c757d;">Cancelar</a>
                        <button type="button" id="btn-start-sync" class="confirm-btn" style="margin-top: 0;">Sincronizar y Registrar</button>
                    </div>
                </form>

                <div id="sync-progress-container" style="display: none; margin-top: 1.5rem; border-top: 1px solid #eaeaea; padding-top: 1.5rem;">
                    <h3 style="margin-top: 0; font-size: 1.1rem; color: #333;">Progreso de Sincronización</h3>
                    <div id="sync-log" style="background: #2b2b2b; color: #a9b7c6; font-family: monospace; padding: 1rem; border-radius: 6px; max-height: 250px; overflow-y: auto; line-height: 1.6; font-size: 0.9rem; white-space: pre-wrap;">
                    </div>
                    <div style="margin-top: 1.5rem; text-align: right; display: none;" id="finish-btn-container">
                        <a href="index.php" class="confirm-btn" style="background-color: #0056b3; text-decoration: none; display: inline-block;">Finalizar y Salir</a>
                    </div>
                </div>

                <script>
                document.getElementById('btn-start-sync').addEventListener('click', async function() {
                    const form = document.getElementById('sync-form');
                    const rcode = "<?= addslashes($rcode) ?>";
                    const internalId = "<?= addslashes($internalId) ?>";
                    const guestName = "<?= addslashes($guestName) ?>";
                    const invoices = <?= json_encode($cleanInvoices) ?>;
                    
                    const container = document.getElementById('sync-progress-container');
                    const log = document.getElementById('sync-log');
                    const btnStart = document.getElementById('btn-start-sync');
                    const btnCancel = form.querySelector('.back-btn');
                    
                    // Show progress container
                    container.style.display = 'block';
                    btnStart.disabled = true;
                    btnStart.style.opacity = '0.5';
                    btnCancel.style.pointerEvents = 'none';
                    btnCancel.style.opacity = '0.5';
                    
                    log.innerHTML = '';
                    appendLog('Iniciando sincronización directa...', 'info');
                    
                    let successes = 0;
                    let skipped = 0;
                    let errors = 0;
                    
                    for (let i = 0; i < invoices.length; i++) {
                        const inv = invoices[i];
                        appendLog(`\n[${i+1}/${invoices.length}] Procesando factura #${inv.facturaId} (Monto: $${inv.total.toLocaleString()} COP)...`, 'info');
                        
                        try {
                            const response = await fetch('sync_direct.php?action=sync_single', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json'
                                },
                                body: JSON.stringify({
                                    rcode: rcode,
                                    internal_id: internalId,
                                    guest_name: guestName,
                                    invoice: inv
                                })
                            });
                            
                            const result = await response.json();
                            
                            if (result.status === 'success') {
                                appendLog(`  -> ÉXITO: ${result.message}`, 'success');
                                successes++;
                            } else if (result.status === 'skipped') {
                                appendLog(`  -> OMITIDO: ${result.message}`, 'warning');
                                skipped++;
                            } else {
                                appendLog(`  -> ERROR: ${result.message}`, 'error');
                                errors++;
                            }
                        } catch (err) {
                            appendLog(`  -> ERROR de red: ${err.message}`, 'error');
                            errors++;
                        }
                        
                        // Throttle 250ms
                        await new Promise(resolve => setTimeout(resolve, 250));
                    }
                    
                    appendLog('\n====================================', 'info');
                    appendLog('Sincronización finalizada con éxito.', 'info');
                    appendLog(`Registrados: ${successes} | Omitidos: ${skipped} | Errores: ${errors}`, 'info');
                    
                    document.getElementById('finish-btn-container').style.display = 'block';
                });

                function appendLog(text, type) {
                    const log = document.getElementById('sync-log');
                    const div = document.createElement('div');
                    if (type === 'success') {
                        div.style.color = '#a3e635'; // Lime green
                    } else if (type === 'warning') {
                        div.style.color = '#facc15'; // Yellow
                    } else if (type === 'error') {
                        div.style.color = '#f87171'; // Red
                    } else {
                        div.style.color = '#e2e8f0'; // Light grey
                    }
                    div.textContent = text;
                    log.appendChild(div);
                    log.scrollTop = log.scrollHeight;
                }
                </script>
            <?php endif; ?>
        <?php endif; ?>
        
    </div>
</body>
</html>
