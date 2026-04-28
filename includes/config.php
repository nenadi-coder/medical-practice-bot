<?php
session_start();

// Database configuration - use environment variables for security
$host = getenv('DB_HOST') ?: 'db-mysql-nyc3-10499-do-user-36185384-0.e.db.ondigitalocean.com';
$dbname = getenv('DB_NAME') ?: 'defaultdb';
$username = getenv('DB_USER') ?: 'doadmin';
$password = getenv('DB_PASSWORD') ?: 'AVNS_bO2G7PtVCtrA6uXCiYp';  // ⚠️ SET THIS IN YOUR ENVIRONMENT

// If no environment variable, you MUST set it manually (but NOT in version control)
// $password = 'YOUR_PASSWORD_HERE';

try {
    $pdo = new PDO(
        "mysql:host=$host;port=25060;dbname=$dbname;charset=utf8mb4",
        $username,
        $password
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    // Log error instead of exposing to users
    error_log("Database connection failed: " . $e->getMessage());
    die("Connection failed. Please try again later.");
}

// Note: Telegram linking has been moved to patient/link_telegram.php
?>
