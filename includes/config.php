<?php
session_start();

$host = 'sql207.infinityfree.com';
$dbname = 'if0_41555171_medical_practice';
$username = 'if0_41555171';
$password = 'fkwDocFNbnScb0'; 

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>
