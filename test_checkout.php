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

$data = null;
$error = null;

if (isset($_POST['rsrv_id'])) {
    $rsrvId = $_POST['rsrv_id']; // For example: internal ID or try to search
    
    try {
        // Try fetch_one endpoint if they provided an ID
        $response = $client->post("reservations/fetch_reservations", [
            'form_params' => [
                'filters' => json_encode([
                    // To keep it simple, we fetch by arrival date if they provide a date instead
                ])
            ]
        ]);
        
        // Actually, ZaK reservations/fetch_one uses 'id' parameter. Let's try that.
        $response = $client->post("reservations/fetch_one", [
            'form_params' => [
                'id' => $rsrvId
            ]
        ]);
        
        $data = json_decode($response->getBody(), true);
    } catch (\Exception $e) {
        $error = $e->getMessage();
    }
} elseif (isset($_POST['arrival_date'])) {
    $date = $_POST['arrival_date']; // format DD/MM/YYYY
    try {
        $response = $client->post("reservations/fetch_reservations", [
            'form_params' => [
                'filters' => json_encode([
                    'arrival' => ['from' => $date, 'to' => $date],
                    'pager' => ['limit' => 10, 'offset' => 0]
                ])
            ]
        ]);
        $data = json_decode($response->getBody(), true);
    } catch (\Exception $e) {
        $error = $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Test Check-out Time</title>
    <style>
        body { font-family: sans-serif; padding: 20px; }
        .box { background: #eee; padding: 10px; margin-bottom: 20px; border-radius: 5px; }
        pre { background: #333; color: #fff; padding: 15px; border-radius: 5px; overflow-x: auto; }
    </style>
</head>
<body>
    <h1>Probar datos de Check-in / Check-out</h1>
    
    <div class="box">
        <h3>Opción 1: Buscar por Fecha de Llegada (DD/MM/YYYY)</h3>
        <form method="POST">
            <input type="text" name="arrival_date" placeholder="Ej: 16/05/2026" required>
            <button type="submit">Buscar Reservas</button>
        </form>
    </div>

    <div class="box">
        <h3>Opción 2: Buscar por ID Interno de Reserva</h3>
        <p><small>(Nota: Este es el ID numérico interno, no el código BL-0027)</small></p>
        <form method="POST">
            <input type="number" name="rsrv_id" placeholder="Ej: 123456" required>
            <button type="submit">Buscar Reserva</button>
        </form>
    </div>

    <?php if ($error): ?>
        <h3 style="color: red;">Error:</h3>
        <pre><?= htmlspecialchars($error) ?></pre>
    <?php endif; ?>

    <?php if ($data): ?>
        <h3>Resultados (Datos Crudos de ZaK):</h3>
        <p>Busca campos como <b>checkin</b>, <b>checkout</b>, o dentro de <b>customers</b>.</p>
        <pre><?= htmlspecialchars(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
    <?php endif; ?>
</body>
</html>
