<?php
// Guard against calling session_start() when headers have already been sent
// (e.g. telegram_bot.php acks the request via fastcgi_finish_request() before
// including this file).
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

// ---------------------------------------------------------------------------
// Database connection — all values MUST be supplied via environment variables.
// No hard-coded secrets are present in this file.
// ---------------------------------------------------------------------------
$host     = getenv('DB_HOST')     ?: '';
$dbname   = getenv('DB_NAME')     ?: '';
$username = getenv('DB_USERNAME') ?: '';
$password = getenv('DB_PASSWORD') ?: '';
// DigitalOcean managed MySQL uses port 25060 by default.
$port     = (int) (getenv('DB_PORT') ?: 25060);

// ---------------------------------------------------------------------------
// Telegram Bot Token
// Set the TELEGRAM_BOT_TOKEN environment variable on your server/platform.
// ---------------------------------------------------------------------------
define('TELEGRAM_BOT_TOKEN', getenv('TELEGRAM_BOT_TOKEN') ?: '');

// ---------------------------------------------------------------------------
// PDO options: fast-fail timeout, proper error mode, no emulated prepares.
// ---------------------------------------------------------------------------
$pdoOptions = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::ATTR_TIMEOUT            => 5,
    PDO::MYSQL_ATTR_CONNECT_TIMEOUT => 5,
];

// ---------------------------------------------------------------------------
// SSL support: enabled when DB_SSL=true OR when the port is 25060
// (DigitalOcean managed MySQL requires TLS on that port).
// Override the CA path with DB_CA_PATH if needed.
// ---------------------------------------------------------------------------
$dbSslEnv = getenv('DB_SSL');
$useSSL   = ($dbSslEnv !== false && filter_var($dbSslEnv, FILTER_VALIDATE_BOOLEAN))
            || $port === 25060;

if ($useSSL) {
    $caPath = getenv('DB_CA_PATH') ?: __DIR__ . '/../ca-certificate.crt';
    if (file_exists($caPath)) {
        $pdoOptions[PDO::MYSQL_ATTR_SSL_CA] = $caPath;
    }
}

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4",
        $username,
        $password,
        $pdoOptions
    );
} catch (PDOException $e) {
    error_log('DB connection error: ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        echo '<p>We are having trouble connecting to the database. Please try again in a moment.</p>';
    }
    exit();
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
