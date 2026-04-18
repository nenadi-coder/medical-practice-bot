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
    <title>Patient Profile | Doctor Portal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(125deg, #e0f0ff 0%, #f5f0fc 100%);
            min-height: 100vh;
            padding: 2rem;
            color: #1e2a3e;
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
        }

        /* Card Style */
        .card {
            background: white;
            border-radius: 1.5rem;
            padding: 1.8rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(245, 101, 101, 0.1);
        }

        .card h2 {
            font-size: 1.4rem;
            font-weight: 700;
            color: #1e2a3e;
            margin-bottom: 1.2rem;
            display: flex;
            align-items: center;
            gap: 10px;
            border-bottom: 3px solid #f56565;
            padding-bottom: 0.6rem;
        }

        .card h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 1rem;
        }

        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        .info-item {
            padding: 0.8rem;
            background: #f8fafc;
            border-radius: 1rem;
            transition: all 0.2s ease;
        }

        .info-item:hover {
            background: #f1f5f9;
        }

        .info-label {
            font-weight: 600;
            color: #5b6e8c;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.3rem;
        }

        .info-value {
            color: #1e2a3e;
            font-weight: 600;
            font-size: 1rem;
        }

        /* Record Items */
        .record-item, .prescription-item, .test-item {
            background: #f8fafc;
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: 1rem;
            border-left: 4px solid #f56565;
            transition: all 0.2s ease;
        }

        .record-item:hover, .prescription-item:hover, .test-item:hover {
            transform: translateX(4px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .prescription-item {
            border-left-color: #ed8936;
        }

        .test-item {
            border-left-color: #667eea;
        }

        .record-date {
            font-size: 0.75rem;
            color: #94a3b8;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .empty-message {
            text-align: center;
            padding: 2rem;
            color: #94a3b8;
            background: #f8fafc;
            border-radius: 1rem;
        }

        /* Back Button */
        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: linear-gradient(95deg, #f56565, #c53030);
            color: white;
            padding: 0.7rem 1.5rem;
            text-decoration: none;
            border-radius: 60px;
            font-weight: 600;
            transition: all 0.2s ease;
            margin-top: 0.5rem;
        }

        .back-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(245, 101, 101, 0.35);
        }

        @media (max-width: 768px) {
            body {
                padding: 1rem;
            }
            .info-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Patient Profile Card -->
        <div class="card">
            <h2>
                <i class="fas fa-user-circle" style="color: #f56565;"></i>
                Patient Profile
            </h2>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label"><i class="fas fa-id-card"></i> Patient ID</div>
                    <div class="info-value">#<?php echo htmlspecialchars($patient['patient_id']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label"><i class="fas fa-user"></i> Full Name</div>
                    <div class="info-value"><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label"><i class="fas fa-envelope"></i> Email</div>
                    <div class="info-value"><?php echo htmlspecialchars($patient['email']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label"><i class="fas fa-phone"></i> Phone</div>
                    <div class="info-value"><?php echo htmlspecialchars($patient['phone'] ?? 'N/A'); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label"><i class="fas fa-birthday-cake"></i> Date of Birth</div>
                    <div class="info-value"><?php echo $patient['date_of_birth'] ? date('F j, Y', strtotime($patient['date_of_birth'])) : 'N/A'; ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label"><i class="fas fa-chart-line"></i> Age</div>
                    <div class="info-value">
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
        
        <!-- Medical History Card -->
        <div class="card">
            <h2>
                <i class="fas fa-notes-medical" style="color: #f56565;"></i>
                Medical History
            </h2>
            <?php if ($records->rowCount() > 0): ?>
                <?php while($record = $records->fetch()): ?>
                <div class="record-item">
                    <div class="record-date">
                        <i class="far fa-calendar-alt"></i> <?php echo date('F j, Y', strtotime($record['record_date'])); ?>
                    </div>
                    <?php if ($record['diagnosis']): ?>
                        <strong><i class="fas fa-stethoscope"></i> Diagnosis:</strong> <?php echo htmlspecialchars($record['diagnosis']); ?><br>
                    <?php endif; ?>
                    <?php if ($record['symptoms']): ?>
                        <strong><i class="fas fa-head-side-medical"></i> Symptoms:</strong> <?php echo htmlspecialchars($record['symptoms']); ?><br>
                    <?php endif; ?>
                    <?php if ($record['notes']): ?>
                        <strong><i class="fas fa-pencil-alt"></i> Notes:</strong> <?php echo htmlspecialchars($record['notes']); ?>
                    <?php endif; ?>
                </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="empty-message">
                    <i class="fas fa-folder-open"></i>
                    <p>No medical records found.</p>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Prescriptions Card -->
        <div class="card">
            <h2>
                <i class="fas fa-prescription-bottle" style="color: #ed8936;"></i>
                Prescriptions
            </h2>
            <?php if ($prescriptions->rowCount() > 0): ?>
                <?php while($pres = $prescriptions->fetch()): ?>
                <div class="prescription-item">
                    <div class="record-date">
                        <i class="far fa-calendar-alt"></i> <?php echo date('F j, Y', strtotime($pres['prescription_date'])); ?>
                    </div>
                    <?php if ($pres['medication_name']): ?>
                        <strong><i class="fas fa-capsules"></i> Medication:</strong> <?php echo htmlspecialchars($pres['medication_name']); ?><br>
                        <strong><i class="fas fa-weight-hanging"></i> Dosage:</strong> <?php echo htmlspecialchars($pres['dosage']); ?><br>
                        <?php if ($pres['frequency']): ?>
                            <strong><i class="fas fa-clock"></i> Frequency:</strong> <?php echo htmlspecialchars($pres['frequency']); ?><br>
                        <?php endif; ?>
                        <?php if ($pres['duration']): ?>
                            <strong><i class="fas fa-calendar-week"></i> Duration:</strong> <?php echo htmlspecialchars($pres['duration']); ?><br>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($pres['instructions']): ?>
                        <strong><i class="fas fa-clipboard-list"></i> Instructions:</strong> <?php echo htmlspecialchars($pres['instructions']); ?>
                    <?php endif; ?>
                </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="empty-message">
                    <i class="fas fa-pills"></i>
                    <p>No prescriptions found.</p>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Lab Tests Card -->
        <div class="card">
            <h2>
                <i class="fas fa-flask" style="color: #667eea;"></i>
                Lab Tests
            </h2>
            <?php if ($lab_tests->rowCount() > 0): ?>
                <?php while($test = $lab_tests->fetch()): ?>
                <div class="test-item">
                    <div class="record-date">
                        <i class="far fa-calendar-alt"></i> <?php echo date('F j, Y', strtotime($test['ordered_at'])); ?>
                    </div>
                    <strong><i class="fas fa-microscope"></i> Test:</strong> <?php echo htmlspecialchars($test['test_name']); ?><br>
                    <strong><i class="fas fa-tag"></i> Status:</strong> 
                    <span style="color: <?php echo $test['status'] == 'completed' ? '#48bb78' : '#ed8936'; ?>; font-weight: 600;">
                        <?php echo ucfirst($test['status']); ?>
                    </span><br>
                    <?php if ($test['notes']): ?>
                        <strong><i class="fas fa-clipboard-list"></i> Notes:</strong> <?php echo htmlspecialchars($test['notes']); ?>
                    <?php endif; ?>
                </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="empty-message">
                    <i class="fas fa-microscope"></i>
                    <p>No lab tests requested.</p>
                </div>
            <?php endif; ?>
        </div>
        
        <a href="dashboard.php" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
    </div>
</body>
</html>
