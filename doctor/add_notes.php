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

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $diagnosis = trim($_POST['diagnosis']);
    $symptoms = trim($_POST['symptoms']);
    $notes = trim($_POST['notes']);
    $patient_id = $_POST['patient_id'];
    $appointment_id = $_POST['appointment_id'] ?: null;
    $record_date = date('Y-m-d');
    
    if (!empty($diagnosis) || !empty($notes)) {
        $sql = "INSERT INTO medical_records (patient_id, appointment_id, record_date, diagnosis, symptoms, notes) 
                VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        if ($stmt->execute([$patient_id, $appointment_id, $record_date, $diagnosis, $symptoms, $notes])) {
            $message = "Medical records added successfully!";
        } else {
            $error = "Failed to add medical records";
        }
    } else {
        $error = "Please enter at least diagnosis or notes";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
 <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Add Medical Notes</title>
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
        textarea { min-height: 100px; resize: vertical; }
        button {
            background: #9f7aea;
            color: white;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1rem;
        }
        button:hover { background: #805ad5; }
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
        <h2>📝 Add Medical Notes</h2>
        
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
            
            <label>Diagnosis:</label>
            <input type="text" name="diagnosis" placeholder="e.g., Hypertension, Diabetes, etc.">
            
            <label>Symptoms:</label>
            <input type="text" name="symptoms" placeholder="e.g., Fever, Cough, Headache">
            
            <label>Notes / Observations:</label>
            <textarea name="notes" placeholder="Enter your medical notes, observations, and recommendations here..."></textarea>
            
            <button type="submit">Save Notes</button>
        </form>
        
        <a href="dashboard.php" class="back-link">← Back to Dashboard</a>
    </div>
</body>
</html>
