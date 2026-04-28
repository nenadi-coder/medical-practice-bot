<?php
// diagnostic.php - Run via browser: https://shifacenter.me/diagnostic.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<pre>\n=== BOT DIAGNOSTIC ===\n";

// 1. PHP Info
echo "PHP Version: " . PHP_VERSION . "\n";
echo "Document Root: " . $_SERVER['DOCUMENT_ROOT'] . "\n";
echo "Script Path: " . __FILE__ . "\n";

// 2. Extensions
echo "\n--- Extensions ---\n";
echo "pdo_mysql: " . (extension_loaded('pdo_mysql') ? '✅' : '❌') . "\n";
echo "curl: " . (extension_loaded('curl') ? '✅' : '❌') . "\n";
echo "openssl: " . (extension_loaded('openssl') ? '✅' : '❌') . "\n";

// 3. Config Load Test
echo "\n--- Config Load Test ---\n";
$config_path = __DIR__ . '/includes/config.php';
echo "Config exists: " . (file_exists($config_path) ? '✅ ' . $config_path : '❌ Not found') . "\n";

if (file_exists($config_path)) {
    require_once $config_path;
    echo "\$pdo available: " . (isset($pdo) && $pdo instanceof PDO ? '✅' : '❌') . "\n";
    
    if (isset($pdo) && $pdo instanceof PDO) {
        try {
            $pdo->query("SELECT COUNT(*) FROM patients");
            echo "DB query: ✅ PASS\n";
            echo "Database: " . $pdo->query("SELECT DATABASE()")->fetchColumn() . "\n";
        } catch (Exception $e) {
            echo "DB query: ❌ FAIL - " . $e->getMessage() . "\n";
        }
    }
}

// 4. Telegram API Test
echo "\n--- Telegram API Test ---\n";
$token = getenv('TELEGRAM_BOT_TOKEN') ?: '8330456846:AAFYmkLZFCx1qw4n2sQa5eRCJBO26NV1QYM';
$test_url = "https://api.telegram.org/bot{$token}/getMe";
$ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
$result = @file_get_contents($test_url, false, $ctx);
echo "Telegram API reachable: " . ($result ? '✅' : '❌') . "\n";
if ($result) {
    $data = json_decode($result, true);
    echo "Bot username: @" . ($data['result']['username'] ?? 'unknown') . "\n";
}

echo "\n=== END ===\n</pre>";
