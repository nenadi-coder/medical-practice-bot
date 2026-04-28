<?php
echo "=== DATABASE CONNECTION TEST ===\n\n";

// Test 1: Check if config.php loads
echo "Test 1: Loading config.php...\n";
require_once 'includes/config.php';
echo "✅ config.php loaded\n\n";

// Test 2: Check PDO connection
echo "Test 2: Testing PDO connection...\n";
try {
    $stmt = $pdo->query("SELECT 1 as test");
    $result = $stmt->fetch();
    echo "✅ Database connection WORKS! Result: " . $result['test'] . "\n\n";
} catch (PDOException $e) {
    echo "❌ Database connection FAILED: " . $e->getMessage() . "\n\n";
}

// Test 3: Check if patients table exists
echo "Test 3: Checking patients table...\n";
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM patients");
    $result = $stmt->fetch();
    echo "✅ Patients table exists. Row count: " . $result['count'] . "\n\n";
} catch (PDOException $e) {
    echo "❌ Failed to query patients: " . $e->getMessage() . "\n\n";
}

// Test 4: Show connection parameters (hide password)
echo "Test 4: Connection parameters:\n";
echo "Host: " . getenv('DB_HOST') ?: 'using default' . "\n";
echo "Port: 25060\n";
echo "Database: " . getenv('DB_NAME') ?: 'using default' . "\n";
echo "User: " . getenv('DB_USER') ?: 'using default' . "\n";
echo "Password: " . (getenv('DB_PASSWORD') ? '✅ SET' : '❌ NOT SET') . "\n";

echo "\n=== TEST COMPLETE ===\n";
?>
