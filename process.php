<?php
session_start();
set_time_limit(0);
ini_set('memory_limit', '1024M'); // Increase memory limit for large files
require_once 'config.php';
require_once 'src/VectorParser.php';
require_once 'src/ZakApiClient.php';

// Composer autoload (for PhpSpreadsheet and Guzzle)
if (file_exists('vendor/autoload.php')) {
    require_once 'vendor/autoload.php';
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: index.php");
    exit;
}

// Release the session lock immediately so the user can open other pages
// while the long background process runs.
session_write_close();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['file'])) {
    header("Location: index.php");
    exit;
}

$file = $_FILES['file'];
$uploadError = '';
$targetDate = $_POST['target_date'] ?? ''; // If missing, we could default to date('Y-m-d') but it should be required

if ($file['error'] !== UPLOAD_ERR_OK) {
    $uploadError = "Error al subir el archivo.";
} else {
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    if (strtolower($ext) !== 'xlsx') {
        $uploadError = "Solo se permiten archivos .xlsx";
    }
}

$successes = [];
$skipped = [];
$errors = [];

if (empty($uploadError)) {
    try {
        $parser = new \VectorZak\VectorParser();
        $parseResult = $parser->parse($file['tmp_name']);
        
        // Add parser errors directly to the errors list
        $errors = $parseResult['errors'];
        $validRows = $parseResult['data'];
        
        $zakApi = new \VectorZak\ZakApiClient();
        
        $skippedDateCount = 0;
        foreach ($validRows as $row) {
            // Check if the row date matches the target date
            if ($targetDate !== '' && $row['date'] !== $targetDate) {
                $skippedDateCount++;
                continue;
            }
            
            $reservation = $zakApi->findActiveReservation($row['room_number'], $row['date'], $row['time']);
            
            if (isset($reservation['api_error'])) {
                $debugOut = isset($reservation['debug']) ? "<br><pre style='font-size: 0.8em; background: #eee; padding: 5px; margin-top: 5px; border-radius: 4px; overflow-x: auto;'>{$reservation['debug']}</pre>" : "";
                $errors[] = "Error de conexión ZaK API (Comprobante #{$row['invoice']}): " . $reservation['api_error'] . $debugOut;
                continue;
            }
            
            if (!isset($reservation['id'])) {
                $debugOut = isset($reservation['debug']) ? "<br><pre style='font-size: 0.8em; background: #eee; padding: 5px; margin-top: 5px; border-radius: 4px; overflow-x: auto;'>{$reservation['debug']}</pre>" : "";
                $errors[] = "No se encontró una reserva activa para la Habitación {$row['room_raw']} en la fecha/hora indicada (Comprobante #{$row['invoice']})." . $debugOut;
                continue;
            }
            
            $result = $zakApi->appendNoteToReservation($reservation, $row);
            
            if ($result['status'] === 'success') {
                $successes[] = $result['message'];
            } elseif ($result['status'] === 'skipped') {
                $skipped[] = $result['message'];
            } else {
                $errors[] = $result['message'];
            }
        }
    } catch (\Exception $e) {
        $uploadError = "Excepción: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resultados - VectorZak</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f7f6; margin: 0; padding: 2rem; }
        .container { background: #fff; padding: 2rem; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); max-width: 800px; margin: 0 auto; }
        h1 { color: #333; border-bottom: 2px solid #eaeaea; padding-bottom: 0.5rem; }
        .section { margin-bottom: 2rem; }
        .section h2 { font-size: 1.25rem; margin-bottom: 1rem; }
        .success h2 { color: #28a745; }
        .skipped h2 { color: #856404; }
        .error h2 { color: #dc3545; }
        ul { list-style-type: disc; padding-left: 20px; }
        li { margin-bottom: 0.5rem; line-height: 1.5; }
        .back-btn { display: inline-block; padding: 0.75rem 1.5rem; background-color: #6c757d; color: white; text-decoration: none; border-radius: 4px; margin-top: 1rem; }
        .back-btn:hover { background-color: #5a6268; }
        .upload-error { color: #dc3545; background: #f8d7da; padding: 1rem; border-radius: 4px; border: 1px solid #f5c6cb; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Resumen de Ejecución</h1>
        
        <?php if (!empty($uploadError)): ?>
            <div class="upload-error"><?= htmlspecialchars($uploadError) ?></div>
        <?php else: ?>
            
            <div class="section success">
                <h2>Éxitos (<?= count($successes) ?>)</h2>
                <?php if (empty($successes)): ?>
                    <p>No hay éxitos que reportar.</p>
                <?php else: ?>
                    <ul>
                        <?php foreach ($successes as $msg): ?>
                            <li><?= $msg ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            
            <div class="section skipped">
                <h2>Omitidos (<?= count($skipped) + $skippedDateCount ?>)</h2>
                <?php if ($skippedDateCount > 0): ?>
                    <p style="color: #666; font-style: italic;">Se ignoraron <?= $skippedDateCount ?> facturas porque no correspondían a la fecha seleccionada (<?= htmlspecialchars($targetDate) ?>).</p>
                <?php endif; ?>
                <?php if (empty($skipped)): ?>
                    <p>No se encontraron duplicados.</p>
                <?php else: ?>
                    <ul>
                        <?php foreach ($skipped as $msg): ?>
                            <li><?= $msg ?></li>
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
                            <li><?= $msg ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            
        <?php endif; ?>
        
        <a href="index.php" class="back-btn">Volver al Inicio</a>
    </div>
</body>
</html>
