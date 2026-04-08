<?php
session_start();
require_once '../includes/config.php';

// Check if patient is logged in
if (!isset($_SESSION['patient_id'])) {
    header('Location: login.php');
    exit();
}

$appointment_id = isset($_GET['id']) ? $_GET['id'] : null;
$patient_id = $_SESSION['patient_id'];

if (!$appointment_id) {
    header('Location: dashboard.php');
    exit();
}

// Get appointment details with full information
$sql = "SELECT a.*, 
        CONCAT(d.first_name, ' ', d.last_name) as doctor_name,
        d.specialization,
        d.email as doctor_email,
        d.phone as doctor_phone,
        CONCAT(p.first_name, ' ', p.last_name) as patient_name,
        p.email as patient_email,
        p.phone as patient_phone,
        p.date_of_birth
        FROM appointments a
        JOIN doctors d ON a.doctor_id = d.doctor_id
        JOIN patients p ON a.patient_id = p.patient_id
        WHERE a.appointment_id = ? AND a.patient_id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$appointment_id, $patient_id]);
$appointment = $stmt->fetch();

if (!$appointment) {
    header('Location: dashboard.php');
    exit();
}

// Get queue position
$queue_position = null;
$total_waiting = null;
if ($appointment['status'] == 'scheduled' || $appointment['status'] == 'confirmed') {
    // Count people ahead
    $queue_sql = "SELECT COUNT(*) as ahead FROM appointments 
                  WHERE doctor_id = ? 
                  AND appointment_date = ? 
                  AND status IN ('scheduled', 'confirmed')
                  AND queue_number < ?";
    $queue_stmt = $pdo->prepare($queue_sql);
    $queue_stmt->execute([
        $appointment['doctor_id'],
        $appointment['appointment_date'],
        $appointment['queue_number']
    ]);
    $queue_position = $queue_stmt->fetch();
    
    // Count total waiting
    $total_sql = "SELECT COUNT(*) as total FROM appointments 
                  WHERE doctor_id = ? 
                  AND appointment_date = ? 
                  AND status IN ('scheduled', 'confirmed')";
    $total_stmt = $pdo->prepare($total_sql);
    $total_stmt->execute([$appointment['doctor_id'], $appointment['appointment_date']]);
    $total_waiting = $total_stmt->fetch();
}

// Get medical records for this appointment
$medical_sql = "SELECT * FROM medical_records 
                WHERE patient_id = ? AND appointment_id = ?
                ORDER BY record_date DESC";
$medical_stmt = $pdo->prepare($medical_sql);
$medical_stmt->execute([$patient_id, $appointment_id]);
$medical_records = $medical_stmt->fetchAll();

// Get prescriptions for this appointment
$prescription_sql = "SELECT p.*, pd.*, m.medication_name 
                     FROM prescriptions p
                     LEFT JOIN prescription_details pd ON p.prescription_id = pd.prescription_id
                     LEFT JOIN medications m ON pd.medication_id = m.medication_id
                     WHERE p.patient_id = ? AND p.appointment_id = ?
                     ORDER BY p.prescription_date DESC";
$prescription_stmt = $pdo->prepare($prescription_sql);
$prescription_stmt->execute([$patient_id, $appointment_id]);
$prescriptions = $prescription_stmt->fetchAll();

// Get lab tests for this appointment
$lab_sql = "SELECT * FROM lab_tests 
            WHERE patient_id = ? AND appointment_id = ?
            ORDER BY ordered_at DESC";
$lab_stmt = $pdo->prepare($lab_sql);
$lab_stmt->execute([$patient_id, $appointment_id]);
$lab_tests = $lab_stmt->fetchAll();

// Calculate age
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
    <title>Appointment Details - Patient Portal</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
        }
        
        .navbar {
            background: #667eea;
            color: white;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .navbar h1 {
            font-size: 1.5rem;
        }
        
        .back-btn {
            background: white;
            color: #667eea;
            padding: 0.5rem 1rem;
            text-decoration: none;
            border-radius: 5px;
            transition: background 0.3s;
        }
        
        .back-btn:hover {
            background: #f0f2f5;
        }
        
        .container {
            max-width: 900px;
            margin: 2rem auto;
            padding: 0 1rem;
        }
        
        /* Status Banner */
        .status-banner {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            margin-bottom: 1.5rem;
            text-align: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .status-badge {
            display: inline-block;
            padding: 0.5rem 1.5rem;
            border-radius: 30px;
            font-size: 1.1rem;
            font-weight: bold;
        }
        
        .status-scheduled { background: #e3f2fd; color: #1976d2; }
        .status-confirmed { background: #e8f5e8; color: #388e3c; }
        .status-completed { background: #f3e5f5; color: #7b1fa2; }
        .status-cancelled { background: #ffebee; color: #c62828; }
        
        /* Cards */
        .card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .card h3 {
            color: #333;
            margin-bottom: 1rem;
            border-bottom: 2px solid #667eea;
            padding-bottom: 0.5rem;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }
        
        .info-item {
            display: flex;
            padding: 0.75rem;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        .info-label {
            font-weight: bold;
            width: 100px;
            color: #555;
        }
        
        .info-value {
            color: #333;
            flex: 1;
        }
        
        .queue-info {
            background: #f0f9ff;
            padding: 1rem;
            border-radius: 8px;
            margin-top: 1rem;
            border-left: 4px solid #667eea;
        }
        
        .queue-number {
            font-size: 2rem;
            font-weight: bold;
            color: #667eea;
        }
        
        .next-message {
            background: #f0fff4;
            color: #48bb78;
            padding: 0.75rem;
            border-radius: 5px;
            margin-top: 0.5rem;
            font-weight: bold;
        }
        
        .record-item {
            background: #f8f9fa;
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: 8px;
            border-left: 3px solid #667eea;
        }
        
        .record-date {
            font-size: 0.8rem;
            color: #666;
            margin-bottom: 0.5rem;
        }
        
        .prescription-item, .test-item {
            background: #f8f9fa;
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: 8px;
            border-left: 3px solid #48bb78;
        }
        
        .empty-message {
            text-align: center;
            padding: 2rem;
            color: #666;
        }
        
        .action-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
            justify-content: center;
        }
        
        .btn {
            padding: 0.6rem 1.2rem;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: bold;
            transition: transform 0.2s;
            display: inline-block;
        }
        
        .btn:hover {
            transform: translateY(-2px);
        }
        
        .btn-cancel {
            background: #e74c3c;
            color: white;
        }
        
        .btn-reschedule {
            background: #f39c12;
            color: white;
        }
        
        .btn-back {
            background: #667eea;
            color: white;
        }
        
        hr {
            margin: 1rem 0;
            border: none;
            border-top: 1px solid #eee;
        }
        
        @media (max-width: 768px) {
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .navbar {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
            
            .action-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="navbar">
        <h1>🏥 Appointment Details</h1>
        <a href="dashboard.php" class="back-btn">← Back to Dashboard</a>
    </div>
    
    <div class="container">
        <!-- Status Banner -->
        <div class="status-banner">
            <span class="status-badge status-<?php echo $appointment['status']; ?>">
                <?php echo strtoupper($appointment['status']); ?>
            </span>
        </div>
        
        <!-- Appointment Information -->
        <div class="card">
            <h3>📋 Appointment Information</h3>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Date:</div>
                    <div class="info-value"><?php echo date('l, F j, Y', strtotime($appointment['appointment_date'])); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Time:</div>
                    <div class="info-value"><?php echo date('g:i A', strtotime($appointment['appointment_time'])); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Doctor:</div>
                    <div class="info-value">Dr. <?php echo htmlspecialchars($appointment['doctor_name']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Specialization:</div>
                    <div class="info-value"><?php echo htmlspecialchars($appointment['specialization']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Doctor Email:</div>
                    <div class="info-value"><?php echo htmlspecialchars($appointment['doctor_email']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Doctor Phone:</div>
                    <div class="info-value"><?php echo htmlspecialchars($appointment['doctor_phone']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Booked On:</div>
                    <div class="info-value"><?php echo date('F j, Y \a\t g:i A', strtotime($appointment['created_at'])); ?></div>
                </div>
                <?php if ($appointment['queue_number']): ?>
                <div class="info-item">
                    <div class="info-label">Queue #:</div>
                    <div class="info-value"><?php echo $appointment['queue_number']; ?></div>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if ($appointment['notes']): ?>
            <hr>
            <div>
                <strong>📝 Notes:</strong><br>
                <?php echo htmlspecialchars($appointment['notes']); ?>
            </div>
            <?php endif; ?>
            
            <!-- Queue Information -->
            <?php if (($appointment['status'] == 'scheduled' || $appointment['status'] == 'confirmed') && $queue_position): ?>
            <div class="queue-info">
                <h4>🎫 Queue Information</h4>
                <div class="info-grid" style="margin-top: 0.5rem;">
                    <div class="info-item">
                        <div class="info-label">Queue Number:</div>
                        <div class="info-value queue-number">#<?php echo $appointment['queue_number']; ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Position:</div>
                        <div class="info-value">
                            <?php echo $queue_position['ahead'] + 1; ?> of <?php echo $total_waiting['total']; ?> waiting
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">People Ahead:</div>
                        <div class="info-value"><?php echo $queue_position['ahead']; ?></div>
                    </div>
                    <?php if ($queue_position['ahead'] > 0): ?>
                    <div class="info-item">
                        <div class="info-label">Est. Wait Time:</div>
                        <div class="info-value">~<?php echo $queue_position['ahead'] * 15; ?> minutes</div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php if ($queue_position['ahead'] == 0): ?>
                <div class="next-message">
                    ✅ You're NEXT! Please be ready when called.
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Patient Information -->
        <div class="card">
            <h3>👤 Patient Information</h3>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Name:</div>
                    <div class="info-value"><?php echo htmlspecialchars($appointment['patient_name']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Email:</div>
                    <div class="info-value"><?php echo htmlspecialchars($appointment['patient_email']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Phone:</div>
                    <div class="info-value"><?php echo htmlspecialchars($appointment['patient_phone'] ?? 'N/A'); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Date of Birth:</div>
                    <div class="info-value">
                        <?php echo $appointment['date_of_birth'] ? date('F j, Y', strtotime($appointment['date_of_birth'])) : 'N/A'; ?>
                        <?php if ($age): ?> (<?php echo $age; ?> years)<?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Medical Records -->
        <div class="card">
            <h3>📝 Medical Records</h3>
            <?php if (count($medical_records) > 0): ?>
                <?php foreach($medical_records as $record): ?>
                <div class="record-item">
                    <div class="record-date">📅 <?php echo date('F j, Y', strtotime($record['record_date'])); ?></div>
                    <?php if ($record['diagnosis']): ?>
                        <strong>Diagnosis:</strong> <?php echo htmlspecialchars($record['diagnosis']); ?><br>
                    <?php endif; ?>
                    <?php if ($record['symptoms']): ?>
                        <strong>Symptoms:</strong> <?php echo htmlspecialchars($record['symptoms']); ?><br>
                    <?php endif; ?>
                    <?php if ($record['notes']): ?>
                        <strong>Notes:</strong> <?php echo htmlspecialchars($record['notes']); ?>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-message">No medical records found for this appointment.</div>
            <?php endif; ?>
        </div>
        
        <!-- Prescriptions -->
        <div class="card">
            <h3>💊 Prescriptions</h3>
            <?php if (count($prescriptions) > 0 && !empty($prescriptions[0]['medication_name'])): ?>
                <?php foreach($prescriptions as $prescription): ?>
                <div class="prescription-item">
                    <div class="record-date">📅 <?php echo date('F j, Y', strtotime($prescription['prescription_date'])); ?></div>
                    <strong>Medication:</strong> <?php echo htmlspecialchars($prescription['medication_name']); ?><br>
                    <strong>Dosage:</strong> <?php echo htmlspecialchars($prescription['dosage']); ?><br>
                    <?php if ($prescription['frequency']): ?>
                        <strong>Frequency:</strong> <?php echo htmlspecialchars($prescription['frequency']); ?><br>
                    <?php endif; ?>
                    <?php if ($prescription['duration']): ?>
                        <strong>Duration:</strong> <?php echo htmlspecialchars($prescription['duration']); ?><br>
                    <?php endif; ?>
                    <?php if ($prescription['instructions']): ?>
                        <strong>Instructions:</strong> <?php echo htmlspecialchars($prescription['instructions']); ?>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-message">No prescriptions for this appointment.</div>
            <?php endif; ?>
        </div>
        
        <!-- Lab Tests -->
        <div class="card">
            <h3>🔬 Lab Tests</h3>
            <?php if (count($lab_tests) > 0): ?>
                <?php foreach($lab_tests as $test): ?>
                <div class="test-item">
                    <div class="record-date">📅 <?php echo date('F j, Y', strtotime($test['ordered_at'])); ?></div>
                    <strong>Test:</strong> <?php echo htmlspecialchars($test['test_name']); ?><br>
                    <strong>Status:</strong> 
                    <span style="color: <?php echo $test['status'] == 'completed' ? '#48bb78' : '#ed8936'; ?>">
                        <?php echo ucfirst($test['status']); ?>
                    </span><br>
                    <?php if ($test['notes']): ?>
                        <strong>Instructions:</strong> <?php echo htmlspecialchars($test['notes']); ?>
                    <?php endif; ?>
                    <?php if ($test['results']): ?>
                        <strong>Results:</strong> <?php echo htmlspecialchars($test['results']); ?>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-message">No lab tests requested for this appointment.</div>
            <?php endif; ?>
        </div>
        
        <!-- Action Buttons -->
        <?php if ($appointment['status'] == 'scheduled' || $appointment['status'] == 'confirmed'): ?>
        <div class="card">
            <div class="action-buttons">
                <a href="?cancel_id=<?php echo $appointment['appointment_id']; ?>" 
                   class="btn btn-cancel"
                   onclick="return confirm('Are you sure you want to cancel this appointment?');">
                    ❌ Cancel Appointment
                </a>
                <a href="reschedule.php?id=<?php echo $appointment['appointment_id']; ?>" 
                   class="btn btn-reschedule">
                    📅 Reschedule
                </a>
                <a href="dashboard.php" class="btn btn-back">
                    ← Back to Dashboard
                </a>
            </div>
        </div>
        <?php else: ?>
        <div class="card">
            <div class="action-buttons">
                <a href="dashboard.php" class="btn btn-back">
                    ← Back to Dashboard
                </a>
                <a href="book_appointment.php" class="btn btn-reschedule">
                    📅 Book New Appointment
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
