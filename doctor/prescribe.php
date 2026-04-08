<?php
session_start();
require_once '../includes/config.php';

if (!isset($_SESSION['doctor_id'])) {
    header('Location: login.php');
    exit();
}

$patient_id = isset($_GET['patient_id']) ? $_GET['patient_id'] : null;
$appointment_id = isset($_GET['appointment_id']) ? $_GET['appointment_id'] : null;
$message = '';
$error = '';

// Get patient info
$patient = null;
if ($patient_id) {
    $stmt = $pdo->prepare("SELECT * FROM patients WHERE patient_id = ?");
    $stmt->execute([$patient_id]);
    $patient = $stmt->fetch();
}

// Get medications list for dropdown
$medications = $pdo->query("SELECT * FROM medications ORDER BY medication_name");

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $medication_name = trim($_POST['medication_name']);
    $dosage = trim($_POST['dosage']);
    $frequency = trim($_POST['frequency']);
    $duration = trim($_POST['duration']);
    $instructions = trim($_POST['instructions']);
    $patient_id = $_POST['patient_id'];
    $appointment_id = $_POST['appointment_id'] ?: null;
    $prescription_date = date('Y-m-d');
    
    if (!empty($medication_name) && !empty($dosage)) {
        // Check if medication exists
        $check_med = $pdo->prepare("SELECT medication_id FROM medications WHERE medication_name = ?");
        $check_med->execute([$medication_name]);
        $medication = $check_med->fetch();
        
        if (!$medication) {
            $add_med = $pdo->prepare("INSERT INTO medications (medication_name) VALUES (?)");
            $add_med->execute([$medication_name]);
            $medication_id = $pdo->lastInsertId();
        } else {
            $medication_id = $medication['medication_id'];
        }
        
        // Create prescription
        $sql = "INSERT INTO prescriptions (patient_id, appointment_id, prescription_date, notes) 
                VALUES (?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        if ($stmt->execute([$patient_id, $appointment_id, $prescription_date, $instructions])) {
            $prescription_id = $pdo->lastInsertId();
            
            // Add prescription details
            $sql_detail = "INSERT INTO prescription_details (prescription_id, medication_id, dosage, frequency, duration, instructions) 
                          VALUES (?, ?, ?, ?, ?, ?)";
            $stmt_detail = $pdo->prepare($sql_detail);
            if ($stmt_detail->execute([$prescription_id, $medication_id, $dosage, $frequency, $duration, $instructions])) {
                $message = "Prescription added successfully!";
            } else {
                $error = "Failed to add prescription details";
            }
        } else {
            $error = "Failed to add prescription";
        }
    } else {
        $error = "Medication name and dosage are required";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Prescribe Medication</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: Arial, sans-serif;
            background: #f7fafc;
            padding: 2rem;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h2 { color: #2d3748; margin-bottom: 1rem; }
        .patient-info {
            background: #f7fafc;
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1.5rem;
        }
        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: bold;
            color: #4a5568;
        }
        input, textarea, select {
            width: 100%;
            padding: 0.75rem;
            margin-bottom: 1rem;
            border: 1px solid #e2e8f0;
            border-radius: 5px;
            font-size: 1rem;
        }
        button {
            background: #ed8936;
            color: white;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1rem;
        }
        button:hover { background: #dd6b20; }
        .message {
            padding: 0.75rem;
            border-radius: 5px;
            margin-bottom: 1rem;
        }
        .success { background: #c6f6d5; color: #22543d; }
        .error { background: #fed7d7; color: #742a2a; }
        .back-link {
            display: inline-block;
            margin-top: 1rem;
            color: #4299e1;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>💊 Prescribe Medication</h2>
        
        <?php if ($patient): ?>
            <div class="patient-info">
                <strong>Patient:</strong> <?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?><br>
                <strong>Email:</strong> <?php echo htmlspecialchars($patient['email']); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($message): ?>
            <div class="message success"><?php echo $message; ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="message error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <input type="hidden" name="patient_id" value="<?php echo $patient_id; ?>">
            <input type="hidden" name="appointment_id" value="<?php echo $appointment_id; ?>">
            
            <label>Medication Name:</label>
            <input type="text" name="medication_name" placeholder="e.g., Amoxicillin, Paracetamol" required>
            
            <label>Dosage:</label>
            <input type="text" name="dosage" placeholder="e.g., 500mg, 1 tablet" required>
            
            <label>Frequency:</label>
            <input type="text" name="frequency" placeholder="e.g., 3 times daily, twice daily">
            
            <label>Duration:</label>
            <input type="text" name="duration" placeholder="e.g., 7 days, 2 weeks">
            
            <label>Instructions:</label>
            <textarea name="instructions" placeholder="e.g., Take with food, after meals"></textarea>
            
            <button type="submit">Submit Prescription</button>
        </form>
        
        <a href="dashboard.php" class="back-link">← Back to Dashboard</a>
    </div>
</body>
</html>
