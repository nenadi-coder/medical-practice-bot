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
    <title>Request Lab Test | Doctor Portal</title>
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
            border-bottom: 3px solid #667eea;
            padding-bottom: 0.75rem;
        }

        /* Patient Info Box */
        .patient-info {
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
            padding: 1.2rem;
            border-radius: 1rem;
            margin-bottom: 1.5rem;
            border-left: 4px solid #667eea;
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

        select, textarea {
            width: 100%;
            padding: 0.85rem 1rem;
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
            min-height: 100px;
        }

        select:focus, textarea:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.15);
        }

        /* Button */
        .request-btn {
            width: 100%;
            background: linear-gradient(95deg, #667eea, #764ba2);
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

        .request-btn:hover {
            background: linear-gradient(95deg, #5a67d8, #6b46c1);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.35);
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
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.2s;
        }

        .back-link:hover {
            gap: 12px;
            color: #5a67d8;
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
                <i class="fas fa-flask" style="color: #667eea;"></i>
                Request Lab Test
            </h2>
            
            <?php if ($patient): ?>
                <div class="patient-info">
                    <p><i class="fas fa-user-circle" style="color: #667eea; width: 20px;"></i> <strong>Patient:</strong> <?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></p>
                    <p><i class="fas fa-envelope" style="color: #667eea; width: 20px;"></i> <strong>Email:</strong> <?php echo htmlspecialchars($patient['email']); ?></p>
                    <?php if (!empty($patient['phone'])): ?>
                        <p><i class="fas fa-phone" style="color: #667eea; width: 20px;"></i> <strong>Phone:</strong> <?php echo htmlspecialchars($patient['phone']); ?></p>
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
                    <label for="test_name"><i class="fas fa-microscope"></i> Test Name</label>
                    <select id="test_name" name="test_name" required>
                        <option value="">-- Select a test --</option>
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
                    <div class="hint-text"><i class="fas fa-info-circle"></i> Select the laboratory test to request</div>
                </div>
                
                <div class="form-group">
                    <label for="instructions"><i class="fas fa-clipboard-list"></i> Special Instructions</label>
                    <textarea id="instructions" name="instructions" placeholder="Any special instructions for the lab or patient preparation (e.g., fasting required, specific time, etc.)"></textarea>
                    <div class="hint-text"><i class="fas fa-info-circle"></i> Include preparation requirements or notes for the lab</div>
                </div>
                
                <button type="submit" class="request-btn">
                    <i class="fas fa-flask"></i> Request Lab Test
                </button>
            </form>
            
            <a href="dashboard.php" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>
    </div>
</body>
</html>
