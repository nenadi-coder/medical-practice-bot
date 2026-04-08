<?php
echo "<h2>Finding Your Database</h2>";

// Try port 3306 (default)
try {
    $pdo = new PDO("mysql:host=localhost;port=3306", "root", "");
    echo "<span style='color:green'>✓ Connected to MySQL on port 3306</span><br>";
    $result = $pdo->query("SHOW DATABASES");
    echo "<strong>Databases on port 3306:</strong><br>";
    while($row = $result->fetch()) {
        echo "- " . $row[0] . "<br>";
    }
    $pdo = null;
} catch(Exception $e) {
    echo "<span style='color:red'>✗ Cannot connect to port 3306: " . $e->getMessage() . "</span><br><br>";
}

echo "<hr>";

// Try port 3307
try {
    $pdo = new PDO("mysql:host=localhost;port=3307", "root", "");
    echo "<span style='color:green'>✓ Connected to MySQL on port 3307</span><br>";
    $result = $pdo->query("SHOW DATABASES");
    echo "<strong>Databases on port 3307:</strong><br>";
    while($row = $result->fetch()) {
        echo "- " . $row[0] . "<br>";
    }
    $pdo = null;
} catch(Exception $e) {
    echo "<span style='color:red'>✗ Cannot connect to port 3307: " . $e->getMessage() . "</span><br>";
}
?>
