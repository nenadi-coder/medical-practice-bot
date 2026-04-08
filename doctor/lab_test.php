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
    $test_name = trim($_POST['test_name']);
    $instructions = trim($_POST['instructions']);
    $patient_id = $_POST['patient_id'];
    $appointment_id = $_POST['appointment_id'] ?: null;
    
    if (!empty($test_name)) {
        $sql = "INSERT INTO lab_tests (patient_id, appointment_id, test_name, notes, status) 
                VALUES (?, ?, ?, ?, 'ordered')";
        $stmt = $pdo->prepare($sql);
        if ($stmt->execute([$patient_id, $appointment_id, $test_name, $instructions])) {
            $message = "Lab test requested successfully!";
        } else {
            $error = "Failed to request lab test";
        }
    } else {
        $error = "Please select a test";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
     <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Request Lab Test</title>
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
        select, textarea {
            width: 100%;
            padding: 0.75rem;
            margin-bottom: 1rem;
            border: 1px solid #e2e8f0;
            border-radius: 5px;
            font-size: 1rem;
        }
        textarea { min-height: 80px; resize: vertical; }
        button {
            background: #667eea;
            color: white;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1rem;
        }
        button:hover { background: #5a67d8; }
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
        <h2>🔬 Request Lab Test</h2>
        
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
            
            <label>Test Name:</label>
            <select name="test_name" required>
                <option value="">Select a test...</option>
                <option value="Complete Blood Count (CBC)">Complete Blood Count (CBC)</option>
                <option value="Blood Sugar Test (Fasting)">Blood Sugar Test (Fasting)</option>
                <option value="Blood Sugar Test (Random)">Blood Sugar Test (Random)</option>
                <option value="Lipid Profile">Lipid Profile</option>
                <option value="Liver Function Test (LFT)">Liver Function Test (LFT)</option>
                <option value="Kidney Function Test (KFT)">Kidney Function Test (KFT)</option>
                <option value="Urinalysis">Urinalysis</option>
                <option value="X-Ray - Chest">X-Ray - Chest</option>
                <option value="X-Ray - Abdomen">X-Ray - Abdomen</option>
                <option value="Ultrasound - Abdomen">Ultrasound - Abdomen</option>
                <option value="ECG">ECG</option>
                <option value="MRI">MRI</option>
                <option value="CT Scan">CT Scan</option>
                <option value="COVID-19 Test">COVID-19 Test</option>
                <option value="Thyroid Function Test">Thyroid Function Test</option>
            </select>
            
            <label>Special Instructions:</label>
            <textarea name="instructions" placeholder="Any special instructions for the lab or patient preparation"></textarea>
            
            <button type="submit">Request Test</button>
        </form>
        
        <a href="dashboard.php" class="back-link">← Back to Dashboard</a>
    </div>
</body>
</html>
