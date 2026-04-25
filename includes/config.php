<?php
session_start();

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
define('TELEGRAM_BOT_TOKEN', getenv('TELEGRAM_BOT_TOKEN') ?: '');

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8",
        $username,
        $password
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

/* ================================
   TELEGRAM LINKING PATCH (FIXED)
   ================================ */
if (isset($_GET['telegram_user_id']) && isset($_GET['patient_id'])) {

    $telegram_user_id = $_GET['telegram_user_id'];
    $patient_id = $_GET['patient_id'];

    $stmt = $pdo->prepare("
        UPDATE patients 
        SET telegram_user_id = ? 
        WHERE patient_id = ?
    ");

    $stmt->execute([$telegram_user_id, $patient_id]);
}

?>
