<?php
session_start();

// Database configuration - use environment variables for security
$host = getenv('DB_HOST') ?: 'db-mysql-nyc3-10499-do-user-36185384-0.e.db.ondigitalocean.com';
$port = getenv('DB_PORT') ?: '25060';  // ✅ ADDED PORT (DigitalOcean requires 25060)
$dbname = getenv('DB_NAME') ?: 'defaultdb';
$username = getenv('DB_USER') ?: 'doadmin';
$password = getenv('DB_PASSWORD') ?: '';  // ⚠️ SET THIS IN YOUR ENVIRONMENT

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_TIMEOUT => 5,              // ✅ ADDED - 5 second connection timeout
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_PERSISTENT => false        // ✅ ADDED - Don't keep connections open
        ]
    );
} catch(PDOException $e) {
    // Log error instead of exposing to users
    error_log("Database connection failed: " . $e->getMessage());
    die("Connection failed. Please try again later.");
}

// Note: Telegram linking has been moved to patient/link_telegram.php
?>
