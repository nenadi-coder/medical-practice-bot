<?php
session_start();

$host = 'db-mysql-nyc3-10499-do-user-36185384-0.e.db.ondigitalocean.com';
$dbname = 'defaultdb';
$username = 'doadmin';
$password = 'AVNS_bO2G7PtVCtrA6uXCiYp';

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
