<?php
// Configuration Constants
define('APP_USER', 'recepcion');
define('APP_PASS', 'YOUR_APP_PASSWORD_HERE');

// ZaK API Credentials
define('ZAK_API_KEY', 'YOUR_ZAK_API_KEY_HERE');
define('ZAK_LCODE', 'YOUR_ZAK_LCODE_HERE');
define('ZAK_API_URL', 'https://kapi.wubook.net/kp/'); // Update paths in ZakApiClient.php if needed

// Error reporting for development (disable in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
