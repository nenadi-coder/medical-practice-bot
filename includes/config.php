<?php
session_start();

$host = 'db-mysql-nyc3-10499-do-user-36185384-0.e.db.ondigitalocean.com';
$port = '25060';
$dbname = 'defaultdb';
$username = 'doadmin';
$password = 'AVNS_xAlHu7MeZoKMxKJ7Esn';

try {
    // This line tells PHP to use the file you just downloaded
    $options = [
        PDO::MYSQL_ATTR_SSL_CA => __DIR__ . '/ca-certificate.crt',
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];

    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8",
        $username,
        $password,
        $options
    );
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

/* =====================================
   TELEGRAM LINKING PATCH
   ===================================== */
if (isset($_GET['telegram_user_id']) && isset($_GET['patient_id'])) {

    $telegram_user_id = $_GET['telegram_user_id'];
    $patient_id = $_GET['patient_id'];

    // Using your existing column name 'telegram_chat_id'
    $stmt = $pdo->prepare("
        UPDATE patients 
        SET telegram_chat_id = ? 
        WHERE patient_id = ?
    ");

    $stmt->execute([$telegram_user_id, $patient_id]);
}
?>
