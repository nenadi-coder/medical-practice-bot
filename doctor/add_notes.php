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
    <title>Add Medical Notes | Doctor Portal</title>
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

        /* Card Style */
        .card {
            background: white;
            border-radius: 1.5rem;
            padding: 2rem;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(102, 126, 234, 0.1);
        }

        .card h2 {
            font-size: 1.6rem;
            font-weight: 700;
            color: #1e2a3e;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
            border-bottom: 3px solid #9f7aea;
            padding-bottom: 0.75rem;
        }

        /* Patient Info Box */
        .patient-info {
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
            padding: 1.2rem;
            border-radius: 1rem;
            margin-bottom: 1.5rem;
            border-left: 4px solid #9f7aea;
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

        /* Form Styling */
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
        }

        input, textarea, select {
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
            min-height: 120px;
        }

        input:focus, textarea:focus, select:focus {
            border-color: #9f7aea;
            box-shadow: 0 0 0 3px rgba(159, 122, 234, 0.15);
        }

        input:focus + i {
            color: #9f7aea;
        }

        /* Button */
        .save-btn {
            width: 100%;
            background: linear-gradient(95deg, #9f7aea, #805ad5);
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

        .save-btn:hover {
            background: linear-gradient(95deg, #805ad5, #6b46c1);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(159, 122, 234, 0.35);
        }

        /* Messages */
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

        /* Back Link */
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 1.5rem;
            color: #9f7aea;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.2s;
        }

        .back-link:hover {
            gap: 12px;
            color: #805ad5;
        }

        /* Hint Text */
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
                <i class="fas fa-notes-medical" style="color: #9f7aea;"></i>
                Add Medical Notes
            </h2>
            
            <?php if ($patient): ?>
                <div class="patient-info">
                    <p><i class="fas fa-user-circle" style="color: #9f7aea; width: 20px;"></i> <strong>Patient:</strong> <?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></p>
                    <p><i class="fas fa-envelope" style="color: #9f7aea; width: 20px;"></i> <strong>Email:</strong> <?php echo htmlspecialchars($patient['email']); ?></p>
                    <?php if (!empty($patient['phone'])): ?>
                        <p><i class="fas fa-phone" style="color: #9f7aea; width: 20px;"></i> <strong>Phone:</strong> <?php echo htmlspecialchars($patient['phone']); ?></p>
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
                    <label for="diagnosis"><i class="fas fa-stethoscope"></i> Diagnosis</label>
                    <div class="input-wrapper">
                        <i class="fas fa-stethoscope"></i>
                        <input type="text" id="diagnosis" name="diagnosis" placeholder="e.g., Hypertension, Diabetes, Upper Respiratory Infection">
                    </div>
                    <div class="hint-text"><i class="fas fa-info-circle"></i> Primary diagnosis or medical condition</div>
                </div>
                
                <div class="form-group">
                    <label for="symptoms"><i class="fas fa-head-side-medical"></i> Symptoms</label>
                    <div class="input-wrapper">
                        <i class="fas fa-head-side-medical"></i>
                        <input type="text" id="symptoms" name="symptoms" placeholder="e.g., Fever, Cough, Headache, Fatigue">
                    </div>
                    <div class="hint-text"><i class="fas fa-info-circle"></i> Patient-reported symptoms (comma separated)</div>
                </div>
                
                <div class="form-group">
                    <label for="notes"><i class="fas fa-pencil-alt"></i> Notes / Observations</label>
                    <textarea id="notes" name="notes" placeholder="Enter your medical notes, observations, treatment plan, and recommendations here..."></textarea>
                    <div class="hint-text"><i class="fas fa-info-circle"></i> Include treatment plan, follow-up instructions, or additional observations</div>
                </div>
                
                <button type="submit" class="save-btn">
                    <i class="fas fa-save"></i> Save Medical Notes
                </button>
            </form>
            
            <a href="dashboard.php" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>
    </div>
</body>
</html>
