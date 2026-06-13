<?php
session_start();
require_once 'config.php';

$error = '';

// Handle Login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if ($username === APP_USER && $password === APP_PASS) {
        $_SESSION['logged_in'] = true;
        header("Location: index.php");
        exit;
    } else {
        $error = 'Credenciales inválidas.';
    }
}

$isLoggedIn = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VectorZak - Integración Vector POS a ZaK</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f7f6; margin: 0; padding: 0; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .container { background: #fff; padding: 2rem; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); width: 100%; max-width: 500px; }
        h1 { font-size: 1.5rem; color: #333; margin-bottom: 1.5rem; text-align: center; }
        .form-group { margin-bottom: 1rem; }
        label { display: block; margin-bottom: 0.5rem; color: #666; }
        input[type="text"], input[type="password"], input[type="file"] { width: 100%; padding: 0.75rem; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        button { width: 100%; padding: 0.75rem; background-color: #0056b3; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 1rem; }
        button:hover { background-color: #004494; }
        .error { color: #dc3545; background: #f8d7da; padding: 0.75rem; border-radius: 4px; margin-bottom: 1rem; text-align: center; }
        .warning-box { background-color: #fff3cd; color: #856404; border: 1px solid #ffeeba; padding: 1rem; border-radius: 4px; margin-bottom: 1.5rem; font-weight: bold; line-height: 1.5; text-align: justify; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
        .logout-btn { background-color: #dc3545; width: auto; padding: 0.5rem 1rem; font-size: 0.875rem; }
        .logout-btn:hover { background-color: #c82333; }
        .btn-sync { background-color: #00bcd4; font-weight: bold; }
        .btn-sync:hover { background-color: #0097a7; }
    </style>
</head>
<body>

<div class="container">
    <?php if (!$isLoggedIn): ?>
        <h1>Inicio de Sesión - VectorZak</h1>
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="POST" action="">
            <div class="form-group">
                <label for="username">Usuario</label>
                <input type="text" id="username" name="username" required>
            </div>
            <div class="form-group">
                <label for="password">Contraseña</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit" name="login">Ingresar</button>
        </form>
    <?php else: ?>
        <div class="header">
            <h1>Subir Archivo POS</h1>
            <form action="logout.php" method="POST" style="margin: 0;">
                <button type="submit" class="logout-btn">Salir</button>
            </form>
        </div>
        
        <div class="warning-box">
            ⚠️ ATENCIÓN RECEPCIÓN: Si un cliente ya ha realizado el check-out y se queda a consumir en el restaurante, el consumo NO puede ser cargado a la habitación. Debe ser registrado y tratado obligatoriamente como PASADÍA.
        </div>

        <form action="process.php" method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label for="target_date">Día a procesar (Ignorará otras fechas en el Excel):</label>
                <input type="date" id="target_date" name="target_date" required value="<?= date('Y-m-d') ?>">
            </div>
            <div class="form-group">
                <label for="file">Seleccionar archivo Excel (.xlsx)</label>
                <input type="file" id="file" name="file" accept=".xlsx" required>
            </div>
            <button type="submit" name="upload">Procesar Archivo</button>
        </form>
        
        <div style="margin-top: 2rem; padding-top: 1rem; border-top: 1px solid #eaeaea;">
            <h3 style="font-size: 1.1rem; color: #555; text-align: center; margin-top: 0;">Sincronizar Reserva Específica (Directo)</h3>
            <form action="sync_direct.php" method="POST" style="margin: 0;">
                <div class="form-group">
                    <label for="rcode">Código de Reserva ZaK (ej: SS-0175, LF-0071):</label>
                    <input type="text" id="rcode" name="rcode" placeholder="Escriba el código de reserva..." required style="width: 100%; padding: 0.75rem; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; text-transform: uppercase;">
                </div>
                <button type="submit" class="btn-sync">Sincronizar desde VectorPOS</button>
            </form>
        </div>
        
        <div style="margin-top: 2rem; padding-top: 1rem; border-top: 1px solid #eaeaea;">
            <h3 style="font-size: 1.1rem; color: #555; text-align: center; margin-top: 0;">Mantenimiento</h3>
            <form action="remove_tests.php" method="POST" style="margin: 0;">
                <button type="submit" class="btn" style="background-color: #dc3545; width: 100%; padding: 0.75rem; font-size: 1rem;">Deshacer Importación de Restaurante</button>
            </form>
        </div>

        <div style="margin-top: 2rem; padding-top: 1rem; border-top: 1px solid #eaeaea;">
            <h3 style="font-size: 1.1rem; color: #555; text-align: center; margin-top: 0;">Reportes</h3>
            <form action="report.php" method="POST" style="margin: 0;">
                <div class="form-group">
                    <label for="target_month">Mes a consultar:</label>
                    <input type="month" id="target_month" name="target_month" required value="<?= date('Y-m') ?>">
                </div>
                <button type="submit" class="btn" style="background-color: #28a745; width: 100%; padding: 0.75rem; font-size: 1rem;">Generar Reporte Mensual</button>
            </form>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
