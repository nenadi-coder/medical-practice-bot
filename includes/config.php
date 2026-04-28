<?php
session_start();

// Database configuration - all values from environment variables
$host     = getenv('DB_HOST');
$dbname   = getenv('DB_NAME');
$username = getenv('DB_USER');
$password = getenv('DB_PASSWORD');

// Uncomment this block temporarily to debug missing env vars:
// if (!$host || !$dbname || !$username || !$password) {
//     die("Missing env vars — HOST:$host DB:$dbname USER:$username PASS:" . ($password ? 'set' : 'not set'));
// }

try {
    $pdo = new PDO(
        "mysql:host=$host;port=25060;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_TIMEOUT                  => 5,
            PDO::MYSQL_ATTR_SSL_CA             => true,
            PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
        ]
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

} catch(PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());

    // ⚠️ TEMPORARY: show real error for debugging - remove once working
    die("DB Error: " . $e->getMessage());

    // ✅ PRODUCTION: uncomment this and remove the line above once fixed
    // die("Connection failed. Please try again later.");
}
?>
