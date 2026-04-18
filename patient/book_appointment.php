<?php
require_once '../includes/config.php';

// Check if user is logged in
if (!isset($_SESSION['patient_id'])) {
    header('Location: login.php');
    exit();
}

$success = '';
$error = '';
$today = date('Y-m-d');

// Get available doctors
$doctor_sql = "SELECT doctor_id, first_name, last_name FROM doctors";
$doctor_stmt = $pdo->query($doctor_sql);
$doctors = $doctor_stmt->fetchAll();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $doctor_id = $_POST['doctor_id'];
    $appointment_date = $_POST['appointment_date'];
    $appointment_time = $_POST['appointment_time'];
    $notes = $_POST['notes'] ?? '';
    $patient_id = $_SESSION['patient_id'];
    
    // SMS preference - UNCHECKED BY DEFAULT = 0 (only 1 if checked)
    $send_sms = isset($_POST['send_sms']) ? 1 : 0;
    
    if (empty($doctor_id) || empty($appointment_date) || empty($appointment_time)) {
        $error = "Please fill all required fields";
    } else {
        // Check if patient already has an appointment on this day
        $check_sql = "SELECT appointment_id FROM appointments 
                      WHERE patient_id = ? AND appointment_date = ? 
                      AND status IN ('scheduled', 'confirmed')";
        $check_stmt = $pdo->prepare($check_sql);
        $check_stmt->execute([$patient_id, $appointment_date]);
        
        if ($check_stmt->rowCount() > 0) {
            $error = "You already have an appointment on this date";
        } else {
            // Calculate queue position
            $queue_sql = "SELECT COUNT(*) + 1 as next_queue 
                         FROM appointments 
                         WHERE appointment_date = ? 
                         AND appointment_time < ?";
            $queue_stmt = $pdo->prepare($queue_sql);
            $queue_stmt->execute([$appointment_date, $appointment_time]);
            $queue_number = $queue_stmt->fetchColumn();
            
            if (!$queue_number) {
                $queue_number = 1;
            }
            
            // Insert appointment with send_sms preference (status = 'scheduled')
            $sql = "INSERT INTO appointments (patient_id, doctor_id, appointment_date, appointment_time, queue_number, notes, send_sms, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'scheduled')";
            $stmt = $pdo->prepare($sql);
            
            if ($stmt->execute([$patient_id, $doctor_id, $appointment_date, $appointment_time, $queue_number, $notes, $send_sms])) {
                $success = "Appointment request submitted! Waiting for nurse confirmation.";
            } else {
                $error = "Failed to book appointment. Please try again.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Book Appointment | Medical Practice</title>
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
            color: #1e2a3e;
        }

        /* Modern Navbar */
        .navbar {
            background: white;
            backdrop-filter: blur(10px);
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border-bottom: 1px solid rgba(102, 126, 234, 0.15);
            flex-wrap: wrap;
            gap: 1rem;
        }

        .navbar h1 {
            font-size: 1.5rem;
            font-weight: 700;
            background: linear-gradient(120deg, #1e2a3e, #2d3a5e);
            background-clip: text;
            -webkit-background-clip: text;
            color: transparent;
        }

        .navbar h1 i {
            background: none;
            background-clip: unset;
            -webkit-background-clip: unset;
            color: #4f46e5;
            margin-right: 8px;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
            background: #f1f5f9;
            padding: 0.5rem 1rem;
            border-radius: 60px;
        }

        .user-info span {
            font-weight: 600;
            color: #1e2a3e;
        }

        .user-info a {
            color: #4f46e5;
            text-decoration: none;
            font-weight: 600;
            margin: 0 5px;
        }

        .logout {
            background: #f56565;
            padding: 0.5rem 1rem;
            border-radius: 60px;
            color: white;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.85rem;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .logout:hover {
            background: #e53e3e;
            transform: translateY(-1px);
        }

        /* Container */
        .container {
            max-width: 650px;
            margin: 2rem auto;
            padding: 0 1.5rem;
        }

        /* Card */
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
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        /* Info Box */
        .info-box {
            background: #eef2ff;
            border-left: 4px solid #4f46e5;
            padding: 1rem;
            border-radius: 1rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 0.85rem;
            color: #1e2a3e;
        }

        /* Form */
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

        input, select, textarea {
            width: 100%;
            padding: 0.8rem 1rem 0.8rem 2.8rem;
            border: 1.5px solid #e2e8f0;
            border-radius: 1rem;
            font-size: 0.9rem;
            font-family: 'Inter', sans-serif;
            transition: all 0.2s ease;
            background: #fefefe;
            outline: none;
        }

        textarea {
            padding: 0.8rem;
            resize: vertical;
        }

        input:focus, select:focus, textarea:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.15);
        }

        .time-hint {
            display: block;
            margin-top: 0.3rem;
            font-size: 0.7rem;
            color: #94a3b8;
        }

        /* Checkbox styling */
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 1.5rem;
            padding: 0.8rem 1rem;
            background: #f8fafc;
            border-radius: 1rem;
            border: 1px solid #e2e8f0;
        }

        .checkbox-group input {
            width: auto;
            transform: scale(1.2);
            margin: 0;
            padding: 0;
        }

        .checkbox-group label {
            margin-bottom: 0;
            cursor: pointer;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Buttons */
        .book-btn {
            width: 100%;
            background: linear-gradient(95deg, #4f46e5, #7c3aed);
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

        .book-btn:hover {
            background: linear-gradient(95deg, #4338ca, #6d28d9);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(79, 70, 229, 0.35);
        }

        /* Messages */
        .error {
            background: #fff1f0;
            color: #d9534f;
            padding: 0.85rem 1rem;
            border-radius: 1rem;
            margin-bottom: 1.5rem;
            border-left: 4px solid #f56565;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .success {
            background: #e8f5e8;
            color: #2e7d32;
            padding: 1.2rem;
            border-radius: 1rem;
            margin-bottom: 1.5rem;
            border-left: 4px solid #48bb78;
            text-align: center;
        }

        .success a {
            color: #2e7d32;
            font-weight: bold;
            text-decoration: underline;
        }

        /* Back Link */
        .back-link {
            text-align: center;
            margin-top: 1.5rem;
        }

        .back-link a {
            color: #4f46e5;
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .back-link a:hover {
            gap: 12px;
        }

        @media (max-width: 640px) {
            .navbar {
                flex-direction: column;
                text-align: center;
            }
            .card {
                padding: 1.5rem;
            }
            .card h2 {
                font-size: 1.3rem;
            }
            .container {
                padding: 0 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="navbar">
        <h1><i class="fas fa-stethoscope"></i> Shifa Medical Center</h1>
        <div class="user-info">
            <i class="fas fa-user-circle" style="color: #4f46e5;"></i>
            <span>Welcome, <?php echo htmlspecialchars($_SESSION['patient_name']); ?></span>
            <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>
    
    <div class="container">
        <div class="card">
            <h2><i class="fas fa-calendar-plus" style="color: #4f46e5;"></i> Schedule New Appointment</h2>
            
            <?php if ($error): ?>
                <div class="error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="success">
                    <i class="fas fa-check-circle" style="font-size: 1.2rem;"></i>
                    <p><?php echo htmlspecialchars($success); ?></p>
                    <br>
                    <a href="dashboard.php"><i class="fas fa-arrow-right"></i> View My Appointments →</a>
                </div>
            <?php else: ?>
            
              <!--  <div class="info-box">
                    <i class="fas fa-info-circle" style="font-size: 1.2rem; color: #4f46e5;"></i>
                    <span><strong>Note:</strong> Appointments are scheduled based on time order. Earlier appointment times get lower queue numbers.</span>
                </div>-->
                
                <form method="POST" action="">
                    <div class="form-group">
                        <label for="doctor_id"><i class="fas fa-user-md"></i> Select Doctor</label>
                        <div class="input-wrapper">
                            <i class="fas fa-user-md"></i>
                            <select id="doctor_id" name="doctor_id" required>
                                <option value="">-- Choose a doctor --</option>
                                <?php foreach($doctors as $doctor): ?>
                                    <option value="<?php echo $doctor['doctor_id']; ?>">
                                        Dr. <?php echo htmlspecialchars($doctor['first_name'] . ' ' . $doctor['last_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="appointment_date"><i class="far fa-calendar-alt"></i> Appointment Date</label>
                        <div class="input-wrapper">
                            <i class="far fa-calendar-alt"></i>
                            <input type="date" id="appointment_date" name="appointment_date" min="<?php echo $today; ?>" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="appointment_time"><i class="far fa-clock"></i> Appointment Time</label>
                        <div class="input-wrapper">
                            <i class="far fa-clock"></i>
                            <input type="time" id="appointment_time" name="appointment_time" required>
                        </div>
                        <small class="time-hint"><i class="fas fa-briefcase"></i> Working hours: 9:00 AM - 5:00 PM</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="notes"><i class="fas fa-pencil-alt"></i> Notes (optional)</label>
                        <div class="input-wrapper">
                            <i class="fas fa-pencil-alt" style="top: 12px;"></i>
                            <textarea id="notes" name="notes" rows="3" placeholder="Any specific reason for visit?"></textarea>
                        </div>
                    </div>
                    
                    <!-- SMS OPTION - UNCHECKED BY DEFAULT -->
                    <div class="checkbox-group">
                        <input type="checkbox" name="send_sms" id="send_sms" value="1">
                        <label for="send_sms">
                            <i class="fas fa-sms" style="color: #4f46e5;"></i> Send me SMS reminder when appointment is confirmed
                        </label>
                    </div>
                    
                    <button type="submit" class="book-btn">
                        <i class="fas fa-calendar-check"></i> Request Appointment
                    </button>
                </form>
                
                <div class="back-link">
                    <a href="dashboard.php"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
