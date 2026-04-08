<?php
require_once '../includes/config.php';

// Check if user is logged in
if (!isset($_SESSION['patient_id'])) {
    header('Location: login.php');
    exit();
}

$success = '';
$error = '';

// Get today's date for min attribute
$today = date('Y-m-d');

// Get available doctors (even if just one)
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
    
    // Basic validation
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
            // Get the next queue number for this date
            $queue_sql = "SELECT COALESCE(MAX(queue_number), 0) + 1 as next_queue 
                         FROM appointments WHERE appointment_date = ?";
            $queue_stmt = $pdo->prepare($queue_sql);
            $queue_stmt->execute([$appointment_date]);
            $queue = $queue_stmt->fetch();
            $queue_number = $queue['next_queue'];
            
            // Insert appointment
            $sql = "INSERT INTO appointments (patient_id, doctor_id, appointment_date, appointment_time, queue_number, notes, status) 
                    VALUES (?, ?, ?, ?, ?, ?, 'scheduled')";
            $stmt = $pdo->prepare($sql);
            
            if ($stmt->execute([$patient_id, $doctor_id, $appointment_date, $appointment_time, $queue_number, $notes])) {
                $success = "Appointment booked successfully! Your queue number is: " . $queue_number;
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
    <title>Book Appointment</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .navbar {
            background: white;
            padding: 1rem 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .container {
            max-width: 600px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        
        h2 {
            color: #333;
            margin-bottom: 30px;
            text-align: center;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 5px;
            color: #555;
            font-weight: bold;
        }
        
        input, select, textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
        }
        
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #667eea;
        }
        
        button {
            width: 100%;
            padding: 12px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: background 0.3s;
        }
        
        button:hover {
            background: #5a67d8;
        }
        
        .error {
            background: #fee;
            color: #c33;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            border: 1px solid #fcc;
        }
        
        .success {
            background: #e8f5e8;
            color: #2e7d32;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border: 1px solid #a5d6a7;
            text-align: center;
        }
        
        .back-link {
            text-align: center;
            margin-top: 20px;
        }
        
        .back-link a {
            color: #667eea;
            text-decoration: none;
        }
        
        .back-link a:hover {
            text-decoration: underline;
        }
        
        .info-box {
            background: #e3f2fd;
            border-left: 4px solid #2196f3;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
        }
        
        .logout {
            background: #f56565;
            padding: 0.5rem 1rem;
            border-radius: 5px;
            color: white;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="navbar">
        <h1>📅 Book Appointment</h1>
        <div>
            <span>Welcome, <?php echo htmlspecialchars($_SESSION['patient_name']); ?></span>
            <a href="dashboard.php" style="margin: 0 10px; color: #667eea;">Dashboard</a>
            <a href="logout.php" class="logout">Logout</a>
        </div>
    </div>
    
    <div class="container">
        <h2>Schedule New Appointment</h2>
        
        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success">
                <?php echo $success; ?>
                <br><br>
                <a href="dashboard.php" style="color: #2e7d32; font-weight: bold;">View My Appointments →</a>
            </div>
        <?php endif; ?>
        
        <div class="info-box">
            <strong>📌 Note:</strong> Appointments are scheduled on a first-come, first-served basis. 
            Your queue number will be assigned automatically.
        </div>
        
        <form method="POST" action="">
            <div class="form-group">
                <label>Select Doctor:</label>
                <select name="doctor_id" required>
                    <option value="">-- Choose a doctor --</option>
                    <?php foreach($doctors as $doctor): ?>
                        <option value="<?php echo $doctor['doctor_id']; ?>">
                            Dr. <?php echo htmlspecialchars($doctor['first_name'] . ' ' . $doctor['last_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label>Appointment Date:</label>
                <input type="date" name="appointment_date" min="<?php echo $today; ?>" required>
            </div>
            
            <div class="form-group">
                <label>Appointment Time:</label>
                <input type="time" name="appointment_time" required>
                <small style="color: #666;">Working hours: 9:00 AM - 5:00 PM</small>
            </div>
            
            <div class="form-group">
                <label>Notes (optional):</label>
                <textarea name="notes" rows="3" placeholder="Any specific reason for visit?"></textarea>
            </div>
            
            <button type="submit">Book Appointment</button>
        </form>
        
        <div class="back-link">
            <a href="dashboard.php">← Back to Dashboard</a>
        </div>
    </div>
</body>
</html>
