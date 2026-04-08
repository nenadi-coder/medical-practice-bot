<?php
session_start();
require_once '../includes/config.php';

if (!isset($_SESSION['doctor_id'])) {
    header('Location: login.php');
    exit();
}

$patient_id = isset($_GET['patient_id']) ? $_GET['patient_id'] : null;

if (!$patient_id) {
    header('Location: dashboard.php');
    exit();
}

// Get patient details
$stmt = $pdo->prepare("SELECT * FROM patients WHERE patient_id = ?");
$stmt->execute([$patient_id]);
$patient = $stmt->fetch();

if (!$patient) {
    header('Location: dashboard.php');
    exit();
}

// Get medical records
$records = $pdo->prepare("SELECT * FROM medical_records WHERE patient_id = ? ORDER BY record_date DESC");
$records->execute([$patient_id]);

// Get prescriptions
$prescriptions = $pdo->prepare("
    SELECT p.*, pd.*, m.medication_name 
    FROM prescriptions p
    LEFT JOIN prescription_details pd ON p.prescription_id = pd.prescription_id
    LEFT JOIN medications m ON pd.medication_id = m.medication_id
    WHERE p.patient_id = ? 
    ORDER BY p.prescription_date DESC
");
$prescriptions->execute([$patient_id]);

// Get lab tests
$lab_tests = $pdo->prepare("SELECT * FROM lab_tests WHERE patient_id = ? ORDER BY ordered_at DESC");
$lab_tests->execute([$patient_id]);

// Get appointments
$appointments = $pdo->prepare("SELECT * FROM appointments WHERE patient_id = ? ORDER BY appointment_date DESC");
$appointments->execute([$patient_id]);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Patient Profile</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: Arial, sans-serif;
            background: #f7fafc;
            padding: 2rem;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
        }
        .card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        h2 {
            color: #2d3748;
            border-bottom: 2px solid #f56565;
            padding-bottom: 0.5rem;
            margin-bottom: 1rem;
        }
        h3 {
            color: #4a5568;
            margin-bottom: 1rem;
        }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }
        .info-item {
            padding: 0.5rem;
            background: #f7fafc;
            border-radius: 5px;
        }
        .info-label {
            font-weight: bold;
            color: #718096;
        }
        .record-item, .prescription-item, .test-item {
            background: #f7fafc;
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: 5px;
            border-left: 3px solid #f56565;
        }
        .record-date {
            font-size: 0.875rem;
            color: #718096;
            margin-bottom: 0.5rem;
        }
        .back-link {
            display: inline-block;
            margin-top: 1rem;
            background: #4299e1;
            color: white;
            padding: 0.5rem 1rem;
            text-decoration: none;
            border-radius: 5px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <h2>👤 Patient Profile</h2>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Patient ID</div>
                    <div>#<?php echo $patient['patient_id']; ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Full Name</div>
                    <div><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Email</div>
                    <div><?php echo htmlspecialchars($patient['email']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Phone</div>
                    <div><?php echo htmlspecialchars($patient['phone'] ?? 'N/A'); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Date of Birth</div>
                    <div><?php echo $patient['date_of_birth'] ? date('F j, Y', strtotime($patient['date_of_birth'])) : 'N/A'; ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Age</div>
                    <div>
                        <?php 
                        if ($patient['date_of_birth']) {
                            $dob = new DateTime($patient['date_of_birth']);
                            $now = new DateTime();
                            $age = $now->diff($dob);
                            echo $age->y . ' years';
                        } else {
                            echo 'N/A';
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="card">
            <h2>📋 Medical History</h2>
            <?php if ($records->rowCount() > 0): ?>
                <?php while($record = $records->fetch()): ?>
                <div class="record-item">
                    <div class="record-date"><?php echo date('F j, Y', strtotime($record['record_date'])); ?></div>
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
                <?php endwhile; ?>
            <?php else: ?>
                <p>No medical records found.</p>
            <?php endif; ?>
        </div>
        
        <div class="card">
            <h2>💊 Prescriptions</h2>
            <?php if ($prescriptions->rowCount() > 0): ?>
                <?php while($pres = $prescriptions->fetch()): ?>
                <div class="prescription-item">
                    <div class="record-date"><?php echo date('F j, Y', strtotime($pres['prescription_date'])); ?></div>
                    <?php if ($pres['medication_name']): ?>
                        <strong>Medication:</strong> <?php echo htmlspecialchars($pres['medication_name']); ?><br>
                        <strong>Dosage:</strong> <?php echo htmlspecialchars($pres['dosage']); ?><br>
                        <?php if ($pres['frequency']): ?>
                            <strong>Frequency:</strong> <?php echo htmlspecialchars($pres['frequency']); ?><br>
                        <?php endif; ?>
                        <?php if ($pres['duration']): ?>
                            <strong>Duration:</strong> <?php echo htmlspecialchars($pres['duration']); ?><br>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($pres['instructions']): ?>
                        <strong>Instructions:</strong> <?php echo htmlspecialchars($pres['instructions']); ?>
                    <?php endif; ?>
                </div>
                <?php endwhile; ?>
            <?php else: ?>
                <p>No prescriptions found.</p>
            <?php endif; ?>
        </div>
        
        <div class="card">
            <h2>🔬 Lab Tests</h2>
            <?php if ($lab_tests->rowCount() > 0): ?>
                <?php while($test = $lab_tests->fetch()): ?>
                <div class="test-item">
                    <div class="record-date"><?php echo date('F j, Y', strtotime($test['ordered_at'])); ?></div>
                    <strong>Test:</strong> <?php echo htmlspecialchars($test['test_name']); ?><br>
                    <strong>Status:</strong> 
                    <span style="color: <?php echo $test['status'] == 'completed' ? '#48bb78' : '#ed8936'; ?>">
                        <?php echo ucfirst($test['status']); ?>
                    </span><br>
                    <?php if ($test['notes']): ?>
                        <strong>Notes:</strong> <?php echo htmlspecialchars($test['notes']); ?>
                    <?php endif; ?>
                </div>
                <?php endwhile; ?>
            <?php else: ?>
                <p>No lab tests requested.</p>
            <?php endif; ?>
        </div>
        
        <a href="dashboard.php" class="back-link">← Back to Dashboard</a>
    </div>
</body>
</html>
