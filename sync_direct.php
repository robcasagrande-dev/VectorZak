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

$errorMsg = '';
$rcode = '';
$step = 1; // 1 = Preview, 2 = Execution Results
$cleanInvoices = [];
$internalId = '';
$reservation = null;

// Helper to clean cell text recursively, skipping buttons, labels, script, and style tags
function getCleanCellText($node) {
    $text = '';
    foreach ($node->childNodes as $child) {
        if ($child->nodeType === XML_ELEMENT_NODE) {
            if (in_array(strtolower($child->nodeName), ['button', 'label', 'script', 'style'])) {
                continue;
            }
            $text .= getCleanCellText($child);
        } else if ($child->nodeType === XML_TEXT_NODE) {
            $text .= $child->nodeValue;
        }
    }
    return trim($text);
}

// Helper to match column headers
function getColIndex($headerMap, $possibleNames) {
    foreach ($possibleNames as $name) {
        if (isset($headerMap[$name])) {
            return $headerMap[$name];
        }
    }
    // Fuzzy match
    foreach ($headerMap as $key => $index) {
        foreach ($possibleNames as $name) {
            if (stripos($key, $name) !== false) {
                return $index;
            }
        }
    }
    return -1;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['confirm']) && $_POST['confirm'] === '1') {
        // Step 2: Confirm and Run Sync Execution
        $step = 2;
        $rcode = $_POST['rcode'] ?? '';
        $internalId = $_POST['internal_id'] ?? '';
        $invoicesJson = $_POST['invoices_json'] ?? '[]';
        $invoices = json_decode($invoicesJson, true);
        
        $successes = [];
        $skipped = [];
        $errors = [];
        
        if (empty($rcode) || empty($internalId) || empty($invoices)) {
            $errorMsg = "Datos de sincronización inválidos.";
        } else {
            $zakApi = new \VectorZak\ZakApiClient();
            
            // Stub reservation object needed by appendNoteToReservation
            $resStub = [
                'id' => $internalId,
                'id_human' => $rcode,
                'guest_name' => $_POST['guest_name'] ?? 'Huésped'
            ];
            
            foreach ($invoices as $inv) {
                // Construct standard invoice row
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
                
                // Append extra (checks duplicate internally)
                $res = $zakApi->appendNoteToReservation($resStub, $row);
                
                if ($res['status'] === 'success') {
                    $successes[] = $res['message'];
                } elseif ($res['status'] === 'skipped') {
                    $skipped[] = $res['message'];
                } else {
                    $errors[] = $res['message'];
                }
                
                // Sleep 250ms to stay within API rate limit (4 req/sec)
                usleep(250000);
            }
        }
    } else {
        // Step 1: Query dates and fetch matching invoices from VectorPOS
        $rcode = strtoupper(trim($_POST['rcode'] ?? ''));
        
        if (empty($rcode)) {
            $errorMsg = "Por favor ingrese un código de reserva ZaK válido.";
        } else {
            try {
                $zakApi = new \VectorZak\ZakApiClient();
                $reservation = $zakApi->fetchReservationByCode($rcode);
                $internalId = $reservation['id'];
                
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
                $loginUrl = "https://app.vectorpos.com.co/index.php?r=site/login";
                $client->post($loginUrl, [
                    'form_params' => [
                        'email' => VECTOR_USER,
                        'pw' => VECTOR_PASS
                    ]
                ]);
                
                // Fetch relación facturas
                $posUrl = "https://pos.vectorpos.com.co/?r=ventas%2FrelacionFacturas&idSyA=A09874700300001&fechaInicial={$arrival}&fechaFinal={$departure}";
                $relResp = $client->get($posUrl);
                $html = $relResp->getBody()->getContents();
                
                // DOM Parse HTML
                $dom = new \DOMDocument();
                libxml_use_internal_errors(true);
                $dom->loadHTML($html);
                libxml_clear_errors();
                $xpath = new \DOMXPath($dom);
                
                $rows = $xpath->query('//table//tr');
                $headerMap = [];
                $headerIndex = -1;
                
                for ($i = 0; $i < $rows->length; $i++) {
                    $row = $rows->item($i);
                    $ths = $row->getElementsByTagName('th');
                    if ($ths->length > 0) {
                        for ($j = 0; $j < $ths->length; $j++) {
                            $headerMap[trim($ths->item($j)->textContent)] = $j;
                        }
                        $headerIndex = $i;
                        break;
                    }
                }
                
                if (count($headerMap) === 0 && $rows->length > 0) {
                    $cells = $rows->item(0)->getElementsByTagName('td');
                    if ($cells->length > 0) {
                        for ($j = 0; $j < $cells->length; $j++) {
                            $headerMap[trim($cells->item($j)->textContent)] = $j;
                        }
                        $headerIndex = 0;
                    }
                }
                
                $docCol = getColIndex($headerMap, ["Documento Cliente", "Doc. Cliente", "Documento", "Identificación", "Identificacion", "Doc"]);
                $facCol = getColIndex($headerMap, ["Factura", "No. Factura", "Nro Factura", "ID Factura", "Consecutivo", "Factura ID"]);
                $totCol = getColIndex($headerMap, ["Total Pagado", "Total", "Valor", "Monto", "Neto", "Pagado"]);
                $dateCol = getColIndex($headerMap, ["Fecha", "Fecha Factura", "Día", "Dia"]);
                $vendCol = getColIndex(headerMap, ["Vendedor", "Mesero", "Usuario", "Atendió", "Atendio", "Operador"]);
                
                $maxIndex = max($docCol, $facCol, $totCol, $dateCol, $vendCol);
                
                for ($i = $headerIndex + 1; $i < $rows->length; $i++) {
                    $row = $rows->item($i);
                    $cells = $row->getElementsByTagName('td');
                    if ($cells->length === 0 || $cells->length <= $maxIndex) {
                        continue;
                    }
                    
                    $clientDoc = $docCol !== -1 ? getCleanCellText($cells->item($docCol)) : "";
                    
                    if (trim($clientDoc) === $rcode) {
                        $facturaId = $facCol !== -1 ? getCleanCellText($cells->item($facCol)) : "";
                        $totalRaw = $totCol !== -1 ? getCleanCellText($cells->item($totCol)) : "0";
                        $fecha = $dateCol !== -1 ? getCleanCellText($cells->item($dateCol)) : "";
                        $vendedor = $vendCol !== -1 ? getCleanCellText($cells->item($vendCol)) : "";
                        
                        $total = (float) preg_replace('/[^0-9.]/', '', $totalRaw);
                        
                        $cleanInvoices[] = [
                            'facturaId' => $facturaId,
                            'total' => $total,
                            'fecha' => $fecha,
                            'vendedor' => $vendedor
                        ];
                    }
                }
                
            } catch (\Exception $e) {
                $errorMsg = "Excepción: " . $e->getMessage();
            }
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
        .section { margin-bottom: 2rem; }
        .section h2 { font-size: 1.25rem; margin-bottom: 1rem; }
        .success h2 { color: #28a745; }
        .skipped h2 { color: #856404; }
        .error h2 { color: #dc3545; }
        ul { list-style-type: disc; padding-left: 20px; }
        li { margin-bottom: 0.5rem; line-height: 1.5; }
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
            
        <?php elseif ($step === 1): ?>
            <h1>Previsualización de Facturas</h1>
            <p>Sincronización para la reserva <strong><?= htmlspecialchars($rcode) ?></strong> (<?= htmlspecialchars($reservation['guest_name']) ?>)</p>
            <p style="color: #666; font-size: 0.9em; margin-bottom: 1.5rem;">Periodo de Estadía: <?= htmlspecialchars($reservation['dfrom']) ?> al <?= htmlspecialchars($reservation['dto']) ?></p>
            
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
                
                <form method="POST" action="sync_direct.php">
                    <input type="hidden" name="confirm" value="1">
                    <input type="hidden" name="rcode" value="<?= htmlspecialchars($rcode) ?>">
                    <input type="hidden" name="internal_id" value="<?= htmlspecialchars($internalId) ?>">
                    <input type="hidden" name="guest_name" value="<?= htmlspecialchars($reservation['guest_name']) ?>">
                    <input type="hidden" name="invoices_json" value="<?= htmlspecialchars(json_encode($cleanInvoices)) ?>">
                    
                    <div class="btn-container">
                        <a href="index.php" class="back-btn" style="margin-top: 0; background-color: #6c757d;">Cancelar</a>
                        <button type="submit" class="confirm-btn" style="margin-top: 0;">Sincronizar y Registrar</button>
                    </div>
                </form>
            <?php endif; ?>
            
        <?php elseif ($step === 2): ?>
            <h1>Resultado de Sincronización Directa</h1>
            
            <div class="section success">
                <h2>Registrados exitosamente (<?= count($successes) ?>)</h2>
                <?php if (empty($successes)): ?>
                    <p>No se realizaron nuevos registros.</p>
                <?php else: ?>
                    <ul>
                        <?php foreach ($successes as $msg): ?>
                            <li><?= htmlspecialchars($msg) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            
            <div class="section skipped">
                <h2>Omitidos por duplicación (<?= count($skipped) ?>)</h2>
                <?php if (empty($skipped)): ?>
                    <p>No se omitió ninguna factura.</p>
                <?php else: ?>
                    <ul>
                        <?php foreach ($skipped as $msg): ?>
                            <li><?= htmlspecialchars($msg) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            
            <div class="section error">
                <h2>Errores (<?= count($errors) ?>)</h2>
                <?php if (empty($errors)): ?>
                    <p>No hubo errores.</p>
                <?php else: ?>
                    <ul>
                        <?php foreach ($errors as $msg): ?>
                            <li><?= htmlspecialchars($msg) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            
            <a href="index.php" class="back-btn">Volver al Inicio</a>
        <?php endif; ?>
        
    </div>
</body>
</html>
