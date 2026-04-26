<?php
session_start();
require_once '../includes/config.php';

if (!isset($_SESSION['doctor_id'])) {<?php
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

// Get medications list for datalist
$medications = $pdo->query("SELECT medication_id, medication_name FROM medications ORDER BY medication_name");

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
                // Clear form after successful submission
                $medication_name = '';
                $dosage = '';
                $frequency = '';
                $duration = '';
                $instructions = '';
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
    <title>Prescribe Medication | Doctor Portal</title>
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
            max-width: 700px;
            margin: 0 auto;
        }

        .card {
            background: white;
            border-radius: 1.5rem;
            padding: 2rem;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(237, 137, 54, 0.2);
        }

        .card h2 {
            font-size: 1.6rem;
            font-weight: 700;
            color: #1e2a3e;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
            border-bottom: 3px solid #ed8936;
            padding-bottom: 0.75rem;
        }

        .patient-info {
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
            padding: 1.2rem;
            border-radius: 1rem;
            margin-bottom: 1.5rem;
            border-left: 4px solid #ed8936;
        }

        .patient-info p {
            margin: 0.5rem 0;
            font-size: 0.9rem;
        }

        .patient-info strong {
            color: #4a5568;
            width: 80px;
            display: inline-block;
        }

        .form-group {
            margin-bottom: 1.2rem;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #1f2a44;
            font-size: 0.85rem;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-wrapper i {
            position: absolute;
            left: 1rem;
            color: #a0b3d9;
            font-size: 1rem;
            z-index: 1;
        }

        input, textarea {
            width: 100%;
            padding: 0.85rem 1rem 0.85rem 2.8rem;
            border: 1.5px solid #e2e8f0;
            border-radius: 1rem;
            font-size: 0.9rem;
            font-family: 'Inter', sans-serif;
            transition: all 0.2s ease;
            background: #fefefe;
            outline: none;
        }

        textarea {
            padding: 0.85rem;
            resize: vertical;
            min-height: 80px;
        }

        input:focus, textarea:focus {
            border-color: #ed8936;
            box-shadow: 0 0 0 3px rgba(237, 137, 54, 0.15);
        }

        /* Datalist styling */
        input[list]::-webkit-calendar-picker-indicator {
            opacity: 0.6;
            cursor: pointer;
        }

        input[list] {
            background-color: #fefefe;
        }

        .prescribe-btn {
            width: 100%;
            background: linear-gradient(95deg, #ed8936, #dd6b20);
            color: white;
            border: none;
            border-radius: 60px;
            padding: 0.9rem;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.25s ease;
            font-family: 'Inter', sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 0.5rem;
        }

        .prescribe-btn:hover {
            background: linear-gradient(95deg, #dd6b20, #c05621);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(237, 137, 54, 0.35);
        }

        .message {
            padding: 0.85rem 1rem;
            border-radius: 1rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.85rem;
        }

        .success {
            background: #e8f5e8;
            color: #2e7d32;
            border-left: 4px solid #48bb78;
        }

        .error {
            background: #fff1f0;
            color: #d9534f;
            border-left: 4px solid #f56565;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 1.5rem;
            color: #ed8936;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.2s;
        }

        .back-link:hover {
            gap: 12px;
            color: #dd6b20;
        }

        .hint-text {
            font-size: 0.7rem;
            color: #94a3b8;
            margin-top: 0.3rem;
        }

        @media (max-width: 640px) {
            body {
                padding: 1rem;
            }
            .card {
                padding: 1.5rem;
            }
            .card h2 {
                font-size: 1.3rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <h2>
                <i class="fas fa-prescription-bottle" style="color: #ed8936;"></i>
                Prescribe Medication
            </h2>
            
            <?php if ($patient): ?>
                <div class="patient-info">
                    <p><i class="fas fa-user-circle" style="color: #ed8936;"></i> <strong>Patient:</strong> <?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></p>
                    <p><i class="fas fa-envelope" style="color: #ed8936;"></i> <strong>Email:</strong> <?php echo htmlspecialchars($patient['email']); ?></p>
                    <?php if (!empty($patient['phone'])): ?>
                        <p><i class="fas fa-phone" style="color: #ed8936;"></i> <strong>Phone:</strong> <?php echo htmlspecialchars($patient['phone']); ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($message): ?>
                <div class="message success">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="message error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST">
                <input type="hidden" name="patient_id" value="<?php echo htmlspecialchars($patient_id); ?>">
                <input type="hidden" name="appointment_id" value="<?php echo htmlspecialchars($appointment_id); ?>">
                
                <div class="form-group">
                    <label for="medication_name"><i class="fas fa-capsules"></i> Medication Name</label>
                    <div class="input-wrapper">
                        <i class="fas fa-capsules"></i>
                        <input type="text" 
                               id="medication_name" 
                               name="medication_name" 
                               list="medications_list"
                               placeholder="Type or select medication..."
                               value="<?php echo isset($medication_name) ? htmlspecialchars($medication_name) : ''; ?>"
                               required>
                        <datalist id="medications_list">
                            <?php while($med = $medications->fetch()): ?>
                                <option value="<?php echo htmlspecialchars($med['medication_name']); ?>">
                                    <?php echo htmlspecialchars($med['medication_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </datalist>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="dosage"><i class="fas fa-weight-hanging"></i> Dosage</label>
                    <div class="input-wrapper">
                        <i class="fas fa-weight-hanging"></i>
                        <input type="text" id="dosage" name="dosage" placeholder="e.g., 500mg, 1 tablet, 10ml" value="<?php echo isset($dosage) ? htmlspecialchars($dosage) : ''; ?>" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="frequency"><i class="fas fa-clock"></i> Frequency</label>
                    <div class="input-wrapper">
                        <i class="fas fa-clock"></i>
                        <input type="text" id="frequency" name="frequency" placeholder="e.g., 3 times daily, twice daily, once daily" value="<?php echo isset($frequency) ? htmlspecialchars($frequency) : ''; ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="duration"><i class="fas fa-calendar-week"></i> Duration</label>
                    <div class="input-wrapper">
                        <i class="fas fa-calendar-week"></i>
                        <input type="text" id="duration" name="duration" placeholder="e.g., 7 days, 2 weeks, 1 month" value="<?php echo isset($duration) ? htmlspecialchars($duration) : ''; ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="instructions"><i class="fas fa-clipboard-list"></i> Instructions</label>
                    <textarea id="instructions" name="instructions" placeholder="e.g., Take with food, after meals, before bedtime, store in cool place"><?php echo isset($instructions) ? htmlspecialchars($instructions) : ''; ?></textarea>
                    <div class="hint-text"><i class="fas fa-info-circle"></i> Any special instructions for the patient</div>
                </div>
                
                <button type="submit" class="prescribe-btn">
                    <i class="fas fa-prescription-bottle"></i> Submit Prescription
                </button>
            </form>
            
            <a href="dashboard.php" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>
    </div>
</body>
</html>
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

// Get medications list for datalist
$medications = $pdo->query("SELECT medication_id, medication_name FROM medications ORDER BY medication_name");

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
                // Clear form after successful submission
                $medication_name = '';
                $dosage = '';
                $frequency = '';
                $duration = '';
                $instructions = '';
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
    <title>Prescribe Medication | Doctor Portal</title>
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
            max-width: 700px;
            margin: 0 auto;
        }

        .card {
            background: white;
            border-radius: 1.5rem;
            padding: 2rem;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(237, 137, 54, 0.2);
        }

        .card h2 {
            font-size: 1.6rem;
            font-weight: 700;
            color: #1e2a3e;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
            border-bottom: 3px solid #ed8936;
            padding-bottom: 0.75rem;
        }

        .patient-info {
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
            padding: 1.2rem;
            border-radius: 1rem;
            margin-bottom: 1.5rem;
            border-left: 4px solid #ed8936;
        }

        .patient-info p {
            margin: 0.5rem 0;
            font-size: 0.9rem;
        }

        .patient-info strong {
            color: #4a5568;
            width: 80px;
            display: inline-block;
        }

        .form-group {
            margin-bottom: 1.2rem;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #1f2a44;
            font-size: 0.85rem;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-wrapper i {
            position: absolute;
            left: 1rem;
            color: #a0b3d9;
            font-size: 1rem;
            z-index: 1;
        }

        input, textarea {
            width: 100%;
            padding: 0.85rem 1rem 0.85rem 2.8rem;
            border: 1.5px solid #e2e8f0;
            border-radius: 1rem;
            font-size: 0.9rem;
            font-family: 'Inter', sans-serif;
            transition: all 0.2s ease;
            background: #fefefe;
            outline: none;
        }

        textarea {
            padding: 0.85rem;
            resize: vertical;
            min-height: 80px;
        }

        input:focus, textarea:focus {
            border-color: #ed8936;
            box-shadow: 0 0 0 3px rgba(237, 137, 54, 0.15);
        }

        /* Datalist styling */
        input[list]::-webkit-calendar-picker-indicator {
            opacity: 0.6;
            cursor: pointer;
        }

        input[list] {
            background-color: #fefefe;
        }

        .prescribe-btn {
            width: 100%;
            background: linear-gradient(95deg, #ed8936, #dd6b20);
            color: white;
            border: none;
            border-radius: 60px;
            padding: 0.9rem;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.25s ease;
            font-family: 'Inter', sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 0.5rem;
        }

        .prescribe-btn:hover {
            background: linear-gradient(95deg, #dd6b20, #c05621);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(237, 137, 54, 0.35);
        }

        .message {
            padding: 0.85rem 1rem;
            border-radius: 1rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.85rem;
        }

        .success {
            background: #e8f5e8;
            color: #2e7d32;
            border-left: 4px solid #48bb78;
        }

        .error {
            background: #fff1f0;
            color: #d9534f;
            border-left: 4px solid #f56565;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 1.5rem;
            color: #ed8936;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.2s;
        }

        .back-link:hover {
            gap: 12px;
            color: #dd6b20;
        }

        .hint-text {
            font-size: 0.7rem;
            color: #94a3b8;
            margin-top: 0.3rem;
        }

        @media (max-width: 640px) {
            body {
                padding: 1rem;
            }
            .card {
                padding: 1.5rem;
            }
            .card h2 {
                font-size: 1.3rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <h2>
                <i class="fas fa-prescription-bottle" style="color: #ed8936;"></i>
                Prescribe Medication
            </h2>
            
            <?php if ($patient): ?>
                <div class="patient-info">
                    <p><i class="fas fa-user-circle" style="color: #ed8936;"></i> <strong>Patient:</strong> <?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></p>
                    <p><i class="fas fa-envelope" style="color: #ed8936;"></i> <strong>Email:</strong> <?php echo htmlspecialchars($patient['email']); ?></p>
                    <?php if (!empty($patient['phone'])): ?>
                        <p><i class="fas fa-phone" style="color: #ed8936;"></i> <strong>Phone:</strong> <?php echo htmlspecialchars($patient['phone']); ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($message): ?>
                <div class="message success">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="message error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST">
                <input type="hidden" name="patient_id" value="<?php echo htmlspecialchars($patient_id); ?>">
                <input type="hidden" name="appointment_id" value="<?php echo htmlspecialchars($appointment_id); ?>">
                
                <div class="form-group">
                    <label for="medication_name"><i class="fas fa-capsules"></i> Medication Name</label>
                    <div class="input-wrapper">
                        <i class="fas fa-capsules"></i>
                        <input type="text" 
                               id="medication_name" 
                               name="medication_name" 
                               list="medications_list"
                               placeholder="Type or select medication..."
                               value="<?php echo isset($medication_name) ? htmlspecialchars($medication_name) : ''; ?>"
                               required>
                        <datalist id="medications_list">
                            <?php while($med = $medications->fetch()): ?>
                                <option value="<?php echo htmlspecialchars($med['medication_name']); ?>">
                                    <?php echo htmlspecialchars($med['medication_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </datalist>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="dosage"><i class="fas fa-weight-hanging"></i> Dosage</label>
                    <div class="input-wrapper">
                        <i class="fas fa-weight-hanging"></i>
                        <input type="text" id="dosage" name="dosage" placeholder="e.g., 500mg, 1 tablet, 10ml" value="<?php echo isset($dosage) ? htmlspecialchars($dosage) : ''; ?>" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="frequency"><i class="fas fa-clock"></i> Frequency</label>
                    <div class="input-wrapper">
                        <i class="fas fa-clock"></i>
                        <input type="text" id="frequency" name="frequency" placeholder="e.g., 3 times daily, twice daily, once daily" value="<?php echo isset($frequency) ? htmlspecialchars($frequency) : ''; ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="duration"><i class="fas fa-calendar-week"></i> Duration</label>
                    <div class="input-wrapper">
                        <i class="fas fa-calendar-week"></i>
                        <input type="text" id="duration" name="duration" placeholder="e.g., 7 days, 2 weeks, 1 month" value="<?php echo isset($duration) ? htmlspecialchars($duration) : ''; ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="instructions"><i class="fas fa-clipboard-list"></i> Instructions</label>
                    <textarea id="instructions" name="instructions" placeholder="e.g., Take with food, after meals, before bedtime, store in cool place"><?php echo isset($instructions) ? htmlspecialchars($instructions) : ''; ?></textarea>
                    <div class="hint-text"><i class="fas fa-info-circle"></i> Any special instructions for the patient</div>
                </div>
                
                <button type="submit" class="prescribe-btn">
                    <i class="fas fa-prescription-bottle"></i> Submit Prescription
                </button>
            </form>
            
            <a href="dashboard.php" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>
    </div>
</body>
</html>
