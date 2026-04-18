<?php
session_start();
require_once '../includes/config.php';

if (!isset($_SESSION['doctor_id'])) {
    header('Location: login.php');
    exit();
}

$appointment_id = isset($_GET['appointment_id']) ? $_GET['appointment_id'] : null;
$patient_id = isset($_GET['patient_id']) ? $_GET['patient_id'] : null;
$message = '';
$error = '';

if ($appointment_id) {
    // Get appointment details before updating
    $check_sql = "SELECT a.*, CONCAT(p.first_name, ' ', p.last_name) as patient_name 
                  FROM appointments a
                  JOIN patients p ON a.patient_id = p.patient_id
                  WHERE a.appointment_id = ? AND a.doctor_id = ?";
    $check_stmt = $pdo->prepare($check_sql);
    $check_stmt->execute([$appointment_id, $_SESSION['doctor_id']]);
    $appointment = $check_stmt->fetch();
    
    if ($appointment) {
        $sql = "UPDATE appointments SET status = 'completed' WHERE appointment_id = ? AND doctor_id = ?";
        $stmt = $pdo->prepare($sql);
        
        if ($stmt->execute([$appointment_id, $_SESSION['doctor_id']])) {
            $_SESSION['success'] = "Appointment with " . htmlspecialchars($appointment['patient_name']) . " marked as completed!";
        } else {
            $_SESSION['error'] = "Failed to mark appointment as completed.";
        }
    } else {
        $_SESSION['error'] = "Invalid appointment or you don't have permission.";
    }
}

// Redirect back to dashboard
header('Location: dashboard.php');
exit();
?>
