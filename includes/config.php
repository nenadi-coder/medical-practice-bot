<?php
session_start();

// 🔧 Database configuration - matching YOUR environment variable names
$host     = getenv('DB_HOST') ?: '';
$dbname   = getenv('DB_NAME') ?: '';
$username = getenv('DB_USERNAME') ?: '';  // ✅ Matches your env var: DB_USERNAME
$password = getenv('DB_PASSWORD') ?: '';
$port     = getenv('DB_PORT') ?: 25060;   // ✅ Fallback to DigitalOcean MySQL default port

// 🔍 Debug mode: uncomment temporarily to verify env vars are loading
// if (!$host || !$dbname || !$username || !$password) {
//     error_log("Missing DB env vars — HOST:[$host] DB:[$dbname] USER:[$username] PASS:[" . ($password ? 'set' : 'empty') . "]");
//     die("Configuration error. Check environment variables.");
// }

try {
    // Build DSN with proper charset and port
    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
    
    // PDO options
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,      // Throw exceptions on errors
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,            // Return associative arrays
        PDO::ATTR_EMULATE_PREPARES   => false,                       // Use real prepared statements
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",  // Optional: set timezone
        PDO::ATTR_TIMEOUT            => 10,                          // ✅ Increased timeout to prevent 503
    ];

    // 🔐 SSL Configuration for DigitalOcean Managed MySQL
    // DigitalOcean requires SSL connections for Managed Databases
    if (strpos($host, 'ondigitalocean.com') !== false) {
        // ✅ Updated path to match your Dockerfile: ca-certificate.crt
        $caPath = '/etc/ssl/certs/ca-certificate.crt';
        
        // If CA cert exists in container, use it for secure connection
        if (file_exists($caPath)) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $caPath;
            error_log("✅ Using DO CA certificate: $caPath");
        } 
        // ⚠️ Fallback: disable cert verification (FOR TESTING ONLY - remove in production)
        else {
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
            error_log("⚠️ Warning: CA cert not found at $caPath - SSL verification disabled (testing mode)");
        }
    }

    // Create PDO connection
    $pdo = new PDO($dsn, $username, $password, $options);
    
} catch(PDOException $e) {
    // Log error securely (visible in DigitalOcean App Platform logs)
    error_log("🔴 DB Connection Failed: " . $e->getMessage());
    error_log("   Host: $host, Port: $port, DB: $dbname, User: $username");
    
    // ⚠️ TEMPORARY: Show detailed error for debugging (REMOVE AFTER FIXING)
    die("DB Error: " . $e->getMessage());
    
    // ✅ PRODUCTION: Use this instead after confirming it works:
    // die("Database connection failed. Please try again later.");
}
?>
