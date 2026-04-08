<?php
session_start();
require_once '../includes/config.php';

if (!isset($_SESSION['doctor_id'])) {
    header('Location: login.php');
    exit();
}

$appointment_id = isset($_GET['appointment_id']) ? $_GET['appointment_id'] : null;
$patient_id = isset($_GET['patient_id']) ? $_GET['patient_id'] : null;

if ($appointment_id) {
    $sql = "UPDATE appointments SET status = 'completed' WHERE appointment_id = ? AND doctor_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$appointment_id, $_SESSION['doctor_id']]);
}

// Redirect back to dashboard
header('Location: dashboard.php');
exit();
?>
