<?php
session_start();
require_once '../includes/config.php';

if (!isset($_SESSION['patient_id'])) {
    header('Location: login.php');
    exit();
}

$patient_id = $_SESSION['patient_id'];

if (isset($_GET['cancel_id'])) {
    $cancel_id = $_GET['cancel_id'];
    $check_sql = "SELECT appointment_id FROM appointments WHERE appointment_id = ? AND patient_id = ? AND status IN ('scheduled', 'confirmed')";
    $check_stmt = $pdo->prepare($check_sql);
    $check_stmt->execute([$cancel_id, $patient_id]);
    if ($check_stmt->rowCount() > 0) {
        $cancel_sql = "UPDATE appointments SET status = 'cancelled' WHERE appointment_id = ?";
        $cancel_stmt = $pdo->prepare($cancel_sql);
        if ($cancel_stmt->execute([$cancel_id])) {
            header('Location: dashboard.php?cancel_success=1');
            exit();
        }
    }
}

$appointment_id = isset($_GET['id']) ? $_GET['id'] : null;
if (!$appointment_id) { header('Location: dashboard.php'); exit(); }

$sql = "SELECT a.*, CONCAT(d.first_name, ' ', d.last_name) as doctor_name, d.specialization, d.email as doctor_email, d.phone as doctor_phone, CONCAT(p.first_name, ' ', p.last_name) as patient_name, p.email as patient_email, p.phone as patient_phone, p.date_of_birth FROM appointments a JOIN doctors d ON a.doctor_id = d.doctor_id JOIN patients p ON a.patient_id = p.patient_id WHERE a.appointment_id = ? AND a.patient_id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$appointment_id, $patient_id]);
$appointment = $stmt->fetch();
if (!$appointment) { header('Location: dashboard.php'); exit(); }

// ✅ DYNAMIC LOGIC: Calculate position using appointment_time
$queue_position = null;
$total_waiting = null;
if (($appointment['status'] == 'scheduled' || $appointment['status'] == 'confirmed') && $appointment['queue_number'] !== null) {
    $queue_sql = "SELECT COUNT(*) as ahead FROM appointments 
                  WHERE doctor_id = ? 
                  AND appointment_date = ? 
                  AND status IN ('scheduled', 'confirmed')
                  AND appointment_time < ?";  // ← Dynamic
    $queue_stmt = $pdo->prepare($queue_sql);
    $queue_stmt->execute([
        $appointment['doctor_id'],
        $appointment['appointment_date'],
        $appointment['appointment_time']  // ← Pass time, not queue_number
    ]);
    $queue_position = $queue_stmt->fetch();
    
    $total_sql = "SELECT COUNT(*) as total FROM appointments WHERE doctor_id = ? AND appointment_date = ? AND status IN ('scheduled', 'confirmed')";
    $total_stmt = $pdo->prepare($total_sql);
    $total_stmt->execute([$appointment['doctor_id'], $appointment['appointment_date']]);
    $total_waiting = $total_stmt->fetch();
}

$medical_sql = "SELECT * FROM medical_records WHERE patient_id = ? AND appointment_id = ? ORDER BY record_date DESC";
$medical_stmt = $pdo->prepare($medical_sql);
$medical_stmt->execute([$patient_id, $appointment_id]);
$medical_records = $medical_stmt->fetchAll();

$prescription_sql = "SELECT p.*, pd.*, m.medication_name FROM prescriptions p LEFT JOIN prescription_details pd ON p.prescription_id = pd.prescription_id LEFT JOIN medications m ON pd.medication_id = m.medication_id WHERE p.patient_id = ? AND p.appointment_id = ? ORDER BY p.prescription_date DESC";
$prescription_stmt = $pdo->prepare($prescription_sql);
$prescription_stmt->execute([$patient_id, $appointment_id]);
$prescriptions = $prescription_stmt->fetchAll();

$lab_sql = "SELECT * FROM lab_tests WHERE patient_id = ? AND appointment_id = ? ORDER BY ordered_at DESC";
$lab_stmt = $pdo->prepare($lab_sql);
$lab_stmt->execute([$patient_id, $appointment_id]);
$lab_tests = $lab_stmt->fetchAll();

$age = null;
if ($appointment['date_of_birth']) {
    $dob = new DateTime($appointment['date_of_birth']);
    $now = new DateTime();
    $age = $now->diff($dob)->y;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Appointment Details | Medical Practice</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* [Your exact original CSS unchanged] */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: linear-gradient(125deg, #e0f0ff 0%, #f5f0fc 100%); min-height: 100vh; color: #1e2a3e; }
        .navbar { background: white; backdrop-filter: blur(10px); padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08); border-bottom: 1px solid rgba(102, 126, 234, 0.15); flex-wrap: wrap; gap: 1rem; }
        .navbar h1 { font-size: 1.5rem; font-weight: 700; background: linear-gradient(120deg, #1e2a3e, #2d3a5e); background-clip: text; -webkit-background-clip: text; color: transparent; }
        .navbar h1 i { background: none; background-clip: unset; -webkit-background-clip: unset; color: #4f46e5; margin-right: 8px; }
        .back-btn { background: #f1f5f9; color: #4f46e5; padding: 0.6rem 1.2rem; text-decoration: none; border-radius: 60px; font-weight: 600; font-size: 0.85rem; transition: all 0.2s ease; display: inline-flex; align-items: center; gap: 8px; }
        .back-btn:hover { background: #e6edf6; transform: translateX(-2px); }
        .container { max-width: 1000px; margin: 2rem auto; padding: 0 1.5rem; }
        .status-banner { background: white; padding: 1.5rem; border-radius: 1.5rem; margin-bottom: 1.5rem; text-align: center; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05); border: 1px solid rgba(102, 126, 234, 0.1); }
        .status-badge { display: inline-flex; align-items: center; gap: 8px; padding: 0.6rem 1.8rem; border-radius: 60px; font-size: 1rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .status-scheduled { background: #e3f2fd; color: #1976d2; } .status-confirmed { background: #e8f5e8; color: #388e3c; } .status-completed { background: #f3e5f5; color: #7b1fa2; } .status-cancelled { background: #ffebee; color: #c62828; }
        .card { background: white; border-radius: 1.5rem; padding: 1.8rem; margin-bottom: 1.5rem; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05); border: 1px solid rgba(102, 126, 234, 0.1); transition: all 0.2s ease; }
        .card:hover { box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08); }
        .card h3 { color: #1e2a3e; margin-bottom: 1.2rem; border-bottom: 3px solid #4f46e5; padding-bottom: 0.6rem; display: inline-block; font-size: 1.3rem; font-weight: 700; }
        .info-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; }
        .info-item { display: flex; padding: 0.8rem; background: #f8fafc; border-radius: 1rem; align-items: center; }
        .info-label { font-weight: 700; width: 120px; color: #4a5568; font-size: 0.85rem; }
        .info-value { color: #1e2a3e; flex: 1; font-weight: 500; font-size: 0.9rem; }
        .queue-info { background: linear-gradient(135deg, #f0f9ff, #eef2ff); padding: 1.2rem; border-radius: 1rem; margin-top: 1rem; border: 1px solid rgba(79, 70, 229, 0.2); }
        .queue-number { font-size: 2rem; font-weight: 800; color: #4f46e5; }
        .next-message { background: #f0fff4; color: #48bb78; padding: 0.8rem; border-radius: 0.75rem; margin-top: 0.8rem; font-weight: 700; display: flex; align-items: center; gap: 8px; }
        hr { margin: 1rem 0; border: none; border-top: 1px solid #e2e8f0; }
        .record-item, .prescription-item, .test-item { background: #f8fafc; padding: 1rem; margin-bottom: 1rem; border-radius: 1rem; border-left: 4px solid #4f46e5; transition: all 0.2s ease; }
        .prescription-item { border-left-color: #48bb78; } .test-item { border-left-color: #f59e0b; }
        .record-date { font-size: 0.75rem; color: #5b6e8c; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 5px; }
        .empty-message { text-align: center; padding: 2rem; color: #94a3b8; background: #f8fafc; border-radius: 1rem; }
        .action-buttons { display: flex; gap: 1rem; margin-top: 0.5rem; justify-content: center; flex-wrap: wrap; }
        .btn { padding: 0.7rem 1.4rem; border: none; border-radius: 60px; cursor: pointer; text-decoration: none; font-size: 0.9rem; font-weight: 600; transition: all 0.2s ease; display: inline-flex; align-items: center; gap: 8px; }
        .btn:hover { transform: translateY(-2px); }
        .btn-cancel { background: #f56565; color: white; } .btn-cancel:hover { background: #e53e3e; }
        .btn-reschedule { background: #f59e0b; color: white; } .btn-reschedule:hover { background: #d97706; }
        .btn-back { background: #4f46e5; color: white; } .btn-back:hover { background: #4338ca; }
        @media (max-width: 768px) { .info-grid { grid-template-columns: 1fr; } .navbar { flex-direction: column; text-align: center; } .action-buttons { flex-direction: column; } .btn { justify-content: center; } .container { padding: 0 1rem; } }
    </style>
</head>
<body>
    <div class="navbar">
        <h1><i class="fas fa-stethoscope"></i> Medical Practice</h1>
        <a href="dashboard.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
    </div>
    <div class="container">
        <div class="status-banner">
            <span class="status-badge status-<?php echo $appointment['status']; ?>">
                <i class="fas <?php echo $appointment['status'] == 'scheduled' ? 'fa-clock' : ($appointment['status'] == 'confirmed' ? 'fa-check-circle' : ($appointment['status'] == 'completed' ? 'fa-flag-checkered' : 'fa-ban')); ?>"></i>
                <?php echo strtoupper($appointment['status']); ?>
            </span>
        </div>
        <div class="card">
            <h3><i class="fas fa-calendar-check" style="color: #4f46e5; margin-right: 8px;"></i> Appointment Information</h3>
            <div class="info-grid">
                <div class="info-item"><div class="info-label"><i class="far fa-calendar-alt"></i> Date:</div><div class="info-value"><?php echo date('l, F j, Y', strtotime($appointment['appointment_date'])); ?></div></div>
                <div class="info-item"><div class="info-label"><i class="far fa-clock"></i> Time:</div><div class="info-value"><?php echo date('g:i A', strtotime($appointment['appointment_time'])); ?></div></div>
                <div class="info-item"><div class="info-label"><i class="fas fa-user-md"></i> Doctor:</div><div class="info-value">Dr. <?php echo htmlspecialchars($appointment['doctor_name']); ?></div></div>
                <div class="info-item"><div class="info-label"><i class="fas fa-stethoscope"></i> Specialization:</div><div class="info-value"><?php echo htmlspecialchars($appointment['specialization']); ?></div></div>
                <div class="info-item"><div class="info-label"><i class="fas fa-envelope"></i> Doctor Email:</div><div class="info-value"><?php echo htmlspecialchars($appointment['doctor_email']); ?></div></div>
                <div class="info-item"><div class="info-label"><i class="fas fa-phone"></i> Doctor Phone:</div><div class="info-value"><?php echo htmlspecialchars($appointment['doctor_phone']); ?></div></div>
                <div class="info-item"><div class="info-label"><i class="fas fa-calendar-plus"></i> Booked On:</div><div class="info-value"><?php echo date('F j, Y \a\t g:i A', strtotime($appointment['created_at'])); ?></div></div>
                <?php if ($appointment['queue_number'] !== null): ?>
                <div class="info-item"><div class="info-label"><i class="fas fa-ticket-alt"></i> Queue #:</div><div class="info-value"><strong><?php echo $appointment['queue_number']; ?></strong></div></div>
                <?php endif; ?>
            </div>
            <?php if ($appointment['notes']): ?>
            <hr><div><strong><i class="fas fa-pencil-alt"></i> Notes:</strong><br><?php echo nl2br(htmlspecialchars($appointment['notes'])); ?></div>
            <?php endif; ?>
            <?php if (($appointment['status'] == 'scheduled' || $appointment['status'] == 'confirmed') && $appointment['queue_number'] !== null && $queue_position): ?>
            <div class="queue-info">
                <h4 style="margin-bottom: 0.8rem;"><i class="fas fa-chart-line"></i> Queue Information</h4>
                <div class="info-grid" style="margin-top: 0.5rem;">
                    <div class="info-item">
                        <div class="info-label">Queue Number:</div>
                        <!-- ✅ Display calculated position -->
                        <div class="info-value queue-number">#<?php echo ($queue_position['ahead'] ?? 0) + 1; ?></div>
                    </div>
                    <div class="info-item"><div class="info-label">Your Position:</div><div class="info-value"><strong><?php echo ($queue_position['ahead'] ?? 0) + 1; ?></strong> of <?php echo $total_waiting['total']; ?> waiting</div></div>
                    <div class="info-item"><div class="info-label">People Ahead:</div><div class="info-value"><?php echo $queue_position['ahead']; ?></div></div>
                    <?php if ($queue_position['ahead'] > 0): ?>
                    <div class="info-item"><div class="info-label">Est. Wait Time:</div><div class="info-value">~<?php echo $queue_position['ahead'] * 15; ?> minutes</div></div>
                    <?php endif; ?>
                </div>
                <?php if ($queue_position['ahead'] == 0): ?>
                <div class="next-message"><i class="fas fa-bell"></i> You're NEXT! Please be ready when called.</div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <!-- [Rest of your exact HTML unchanged] -->
        <div class="card"><h3><i class="fas fa-user-circle" style="color: #4f46e5; margin-right: 8px;"></i> Patient Information</h3><div class="info-grid"><div class="info-item"><div class="info-label"><i class="fas fa-user"></i> Name:</div><div class="info-value"><?php echo htmlspecialchars($appointment['patient_name']); ?></div></div><div class="info-item"><div class="info-label"><i class="fas fa-envelope"></i> Email:</div><div class="info-value"><?php echo htmlspecialchars($appointment['patient_email']); ?></div></div><div class="info-item"><div class="info-label"><i class="fas fa-phone"></i> Phone:</div><div class="info-value"><?php echo htmlspecialchars($appointment['patient_phone'] ?? 'N/A'); ?></div></div><div class="info-item"><div class="info-label"><i class="fas fa-birthday-cake"></i> Date of Birth:</div><div class="info-value"><?php echo $appointment['date_of_birth'] ? date('F j, Y', strtotime($appointment['date_of_birth'])) : 'N/A'; ?><?php if ($age): ?> (<?php echo $age; ?> years)<?php endif; ?></div></div></div></div>
        <div class="card"><h3><i class="fas fa-notes-medical" style="color: #4f46e5; margin-right: 8px;"></i> Medical Records</h3><?php if (count($medical_records) > 0): ?><?php foreach($medical_records as $record): ?><div class="record-item"><div class="record-date"><i class="far fa-calendar-alt"></i> <?php echo date('F j, Y', strtotime($record['record_date'])); ?></div><?php if ($record['diagnosis']): ?><strong>Diagnosis:</strong> <?php echo htmlspecialchars($record['diagnosis']); ?><br><?php endif; ?><?php if ($record['symptoms']): ?><strong>Symptoms:</strong> <?php echo htmlspecialchars($record['symptoms']); ?><br><?php endif; ?><?php if ($record['notes']): ?><strong>Notes:</strong> <?php echo htmlspecialchars($record['notes']); ?><?php endif; ?></div><?php endforeach; ?><?php else: ?><div class="empty-message"><i class="fas fa-folder-open"></i> No medical records found for this appointment.</div><?php endif; ?></div>
        <div class="card"><h3><i class="fas fa-prescription-bottle" style="color: #48bb78; margin-right: 8px;"></i> Prescriptions</h3><?php if (count($prescriptions) > 0 && !empty($prescriptions[0]['medication_name'])): ?><?php foreach($prescriptions as $prescription): ?><div class="prescription-item"><div class="record-date"><i class="far fa-calendar-alt"></i> <?php echo date('F j, Y', strtotime($prescription['prescription_date'])); ?></div><strong>Medication:</strong> <?php echo htmlspecialchars($prescription['medication_name']); ?><br><strong>Dosage:</strong> <?php echo htmlspecialchars($prescription['dosage']); ?><br><?php if ($prescription['frequency']): ?><strong>Frequency:</strong> <?php echo htmlspecialchars($prescription['frequency']); ?><br><?php endif; ?><?php if ($prescription['duration']): ?><strong>Duration:</strong> <?php echo htmlspecialchars($prescription['duration']); ?><br><?php endif; ?><?php if ($prescription['instructions']): ?><strong>Instructions:</strong> <?php echo htmlspecialchars($prescription['instructions']); ?><?php endif; ?></div><?php endforeach; ?><?php else: ?><div class="empty-message"><i class="fas fa-pills"></i> No prescriptions for this appointment.</div><?php endif; ?></div>
        <div class="card"><h3><i class="fas fa-flask" style="color: #f59e0b; margin-right: 8px;"></i> Lab Tests</h3><?php if (count($lab_tests) > 0): ?><?php foreach($lab_tests as $test): ?><div class="test-item"><div class="record-date"><i class="far fa-calendar-alt"></i> <?php echo date('F j, Y', strtotime($test['ordered_at'])); ?></div><strong>Test:</strong> <?php echo htmlspecialchars($test['test_name']); ?><br><strong>Status:</strong> <span style="color: <?php echo $test['status'] == 'completed' ? '#48bb78' : '#ed8936'; ?>; font-weight: 600;"><?php echo ucfirst($test['status']); ?></span><br><?php if ($test['notes']): ?><strong>Instructions:</strong> <?php echo htmlspecialchars($test['notes']); ?><br><?php endif; ?><?php if ($test['results']): ?><strong>Results:</strong> <?php echo htmlspecialchars($test['results']); ?><?php endif; ?></div><?php endforeach; ?><?php else: ?><div class="empty-message"><i class="fas fa-microscope"></i> No lab tests requested for this appointment.</div><?php endif; ?></div>
        <?php if ($appointment['status'] == 'scheduled' || $appointment['status'] == 'confirmed'): ?>
        <div class="card"><div class="action-buttons"><a href="?cancel_id=<?php echo $appointment['appointment_id']; ?>" class="btn btn-cancel" onclick="return confirm('Are you sure you want to cancel this appointment? This action cannot be undone.');"><i class="fas fa-times-circle"></i> Cancel Appointment</a><a href="reschedule.php?id=<?php echo $appointment['appointment_id']; ?>" class="btn btn-reschedule"><i class="fas fa-calendar-week"></i> Reschedule</a><a href="dashboard.php" class="btn btn-back"><i class="fas fa-arrow-left"></i> Back to Dashboard</a></div></div>
        <?php else: ?>
        <div class="card"><div class="action-buttons"><a href="dashboard.php" class="btn btn-back"><i class="fas fa-arrow-left"></i> Back to Dashboard</a><a href="book_appointment.php" class="btn btn-reschedule"><i class="fas fa-plus-circle"></i> Book New Appointment</a></div></div>
        <?php endif; ?>
    </div>
</body>
</html>
