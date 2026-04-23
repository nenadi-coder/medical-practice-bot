<?php
require_once '../includes/config.php';

echo "<h1>Database Test</h1>";

// Test 1: Show all appointments
$stmt = $pdo->query("SELECT * FROM appointments ORDER BY appointment_id DESC LIMIT 10");
$appointments = $stmt->fetchAll();

echo "<h2>Last 10 Appointments:</h2>";
echo "<table border='1'>";
echo "<tr><th>ID</th><th>Patient ID</th><th>Date</th><th>Time</th><th>Status</th><th>Queue</th></tr>";
foreach ($appointments as $apt) {
    echo "<tr>";
    echo "<td>{$apt['appointment_id']}</td>";
    echo "<td>{$apt['patient_id']}</td>";
    echo "<td>{$apt['appointment_date']}</td>";
    echo "<td>{$apt['appointment_time']}</td>";
    echo "<td>{$apt['status']}</td>";
    echo "<td>{$apt['queue_number']}</td>";
    echo "</tr>";
}
echo "</table>";

// Test 2: Show tomorrow's appointments
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$stmt2 = $pdo->prepare("SELECT * FROM appointments WHERE appointment_date = ?");
$stmt2->execute([$tomorrow]);
$tomorrow_apps = $stmt2->fetchAll();

echo "<h2>Tomorrow's Appointments ($tomorrow):</h2>";
echo "<p>Found: " . count($tomorrow_apps) . " appointments</p>";
?>
