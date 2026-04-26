<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---------------------------------------------------------------------------
// Database connection — override any of these via environment variables so
// that secrets are never hard-coded in source control.
// ---------------------------------------------------------------------------
$host     = getenv('DB_HOST')     ?: 'db-mysql-nyc3-10499-do-user-36185384-0.e.db.ondigitalocean.com';
$dbname   = getenv('DB_NAME')     ?: 'defaultdb';
$username = getenv('DB_USERNAME') ?: 'doadmin';
$password = getenv('DB_PASSWORD') ?: 'AVNS_bO2G7PtVCtrA6uXCiYp';

// ---------------------------------------------------------------------------
// Telegram Bot Token
// Set the TELEGRAM_BOT_TOKEN environment variable on your server.
// Example (Linux/Apache):  export TELEGRAM_BOT_TOKEN="your_token_here"
// Example (.env file):     TELEGRAM_BOT_TOKEN=your_token_here
// ---------------------------------------------------------------------------
define('TELEGRAM_BOT_TOKEN', getenv('TELEGRAM_BOT_TOKEN') ?: '8330456846:AAHSmyKZrvCL5yLqpHjynBMqC6tM2u9k6N8');

// ---------------------------------------------------------------------------
// PDO connection options — attach the DigitalOcean CA certificate for SSL
// verification when the file is present in the repository root.
// ---------------------------------------------------------------------------
$pdoOptions = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
$caCert = __DIR__ . '/../ca-certificate.crt';
if (file_exists($caCert)) {
    $pdoOptions[PDO::MYSQL_ATTR_SSL_CA]                = $caCert;
    $pdoOptions[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
}

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8",
        $username,
        $password,
        $pdoOptions
    );
} catch (PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    if (php_sapi_name() !== 'cli') {
        http_response_code(503);
        echo 'Service temporarily unavailable. Please try again later.';
        exit;
    }
    exit(1);
}

/* ================================
   TELEGRAM LINKING PATCH (FIXED)
   ================================ */
if (isset($_GET['telegram_user_id']) && isset($_GET['patient_id'])
    && isset($_SESSION['patient_id'])
    && (int) $_SESSION['patient_id'] === (int) $_GET['patient_id']) {

    $telegram_user_id = $_GET['telegram_user_id'];
    $patient_id = (int) $_GET['patient_id'];

    $stmt = $pdo->prepare("
        UPDATE patients 
        SET telegram_user_id = ? 
        WHERE patient_id = ?
    ");

    $stmt->execute([$telegram_user_id, $patient_id]);
}

?>
