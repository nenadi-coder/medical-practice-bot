<?php
require_once '../includes/config.php';
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$secretKey = 'nadia'; // Must match API_SECRET in Cloudflare

if (!isset($input['secret']) || $input['secret'] !== $secretKey) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$action = $input['action'];

try {
    switch($action) {
        case 'get_patient':
            $email = $input['email'];
            $stmt = $pdo->prepare("SELECT patient_id, first_name, last_name, email FROM patients WHERE email = ?");
            $stmt->execute([$email]);
            $patient = $stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $patient]);
            break;
            
        case 'link_patient':
            $telegram_id = $input['telegram_id'];
            $email = $input['email'];
            $stmt = $pdo->prepare("UPDATE patients SET telegram_id = ? WHERE email = ?");
            $stmt->execute([$telegram_id, $email]);
            echo json_encode(['success' => true]);
            break;
            
        case 'get_appointments':
            $patient_id = $input['patient_id'];
            $stmt = $pdo->prepare("
                SELECT a.appointment_id, a.appointment_date, a.appointment_time, a.queue_number,
                       CONCAT(d.first_name, ' ', d.last_name) as doctor_name
                FROM appointments a
                LEFT JOIN doctors d ON a.doctor_id = d.doctor_id
                WHERE a.patient_id = ? AND a.status IN ('scheduled', 'confirmed')
                AND a.appointment_date >= CURDATE()
                ORDER BY a.appointment_date ASC, a.appointment_time ASC
            ");
            $stmt->execute([$patient_id]);
            $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $appointments]);
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
