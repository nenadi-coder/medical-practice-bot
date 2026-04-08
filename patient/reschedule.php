<?php
require_once '../includes/config.php';

// Check if user is logged in
if (!isset($_SESSION['patient_id'])) {
    header('Location: login.php');
    exit();
}

$appointment_id = isset($_GET['id']) ? $_GET['id'] : 0;
$patient_id = $_SESSION['patient_id'];
$error = '';
$success = '';

// Get appointment details
$sql = "SELECT a.*, 
        CONCAT(d.first_name, ' ', d.last_name) as doctor_name 
        FROM appointments a
        JOIN doctors d ON a.doctor_id = d.doctor_id
        WHERE a.appointment_id = ? AND a.patient_id = ?
        AND a.status IN ('scheduled', 'confirmed')";
$stmt = $pdo->prepare($sql);
$stmt->execute([$appointment_id, $patient_id]);
$appointment = $stmt->fetch();

if (!$appointment) {
    header('Location: dashboard.php?error=Invalid appointment');
    exit();
}

// Get doctors list
$doctor_sql = "SELECT doctor_id, first_name, last_name FROM doctors";
$doctor_stmt = $pdo->query($doctor_sql);
$doctors = $doctor_stmt->fetchAll();

// Handle reschedule form
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $new_date = $_POST['appointment_date'];
    $new_time = $_POST['appointment_time'];
    $doctor_id = $_POST['doctor_id'];
    $notes = $_POST['notes'] ?? '';
    
    $today = date('Y-m-d');
    
    if (empty($new_date) || empty($new_time) || empty($doctor_id)) {
        $error = "Please fill all required fields";
    } elseif ($new_date < $today) {
        $error = "Cannot select past dates";
    } else {
        // Check if patient already has another appointment on this date/time
        $check_sql = "SELECT appointment_id FROM appointments 
                      WHERE patient_id = ? AND appointment_date = ? 
                      AND appointment_id != ?
                      AND status IN ('scheduled', 'confirmed')";
        $check_stmt = $pdo->prepare($check_sql);
        $check_stmt->execute([$patient_id, $new_date, $appointment_id]);
        
        if ($check_stmt->rowCount() > 0) {
            $error = "You already have another appointment on this date";
        } else {
            // Get new queue number for the new date
            $queue_sql = "SELECT COALESCE(MAX(queue_number), 0) + 1 as next_queue 
                         FROM appointments WHERE appointment_date = ?";
            $queue_stmt = $pdo->prepare($queue_sql);
            $queue_stmt->execute([$new_date]);
            $queue = $queue_stmt->fetch();
            $new_queue = $queue['next_queue'];
            
            // Update appointment
            $update_sql = "UPDATE appointments SET 
                          appointment_date = ?, 
                          appointment_time = ?,
                          doctor_id = ?,
                          queue_number = ?,
                          notes = ?
                          WHERE appointment_id = ? AND patient_id = ?";
            $update_stmt = $pdo->prepare($update_sql);
            
            if ($update_stmt->execute([$new_date, $new_time, $doctor_id, $new_queue, $notes, $appointment_id, $patient_id])) {
                $success = "Appointment rescheduled successfully! New queue number: " . $new_queue;
            } else {
                $error = "Failed to reschedule appointment";
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
    <title>Reschedule Appointment</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
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
        
        .old-appointment {
            background: #f0f4f8;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border-left: 4px solid #667eea;
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
        }
        
        .success {
            background: #e8f5e8;
            color: #2e7d32;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
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
        <h1>📅 Reschedule Appointment</h1>
        <div>
            <span>Welcome, <?php echo htmlspecialchars($_SESSION['patient_name']); ?></span>
            <a href="dashboard.php" style="margin: 0 10px; color: #667eea;">Dashboard</a>
            <a href="logout.php" class="logout">Logout</a>
        </div>
    </div>
    
    <div class="container">
        <h2>Change Appointment Date/Time</h2>
        
        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success">
                <?php echo $success; ?>
                <br><br>
                <a href="dashboard.php" style="color: #2e7d32; font-weight: bold;">Return to Dashboard</a>
            </div>
        <?php else: ?>
        
            <div class="old-appointment">
                <h3>Current Appointment:</h3>
                <p><strong>Doctor:</strong> Dr. <?php echo htmlspecialchars($appointment['doctor_name']); ?></p>
                <p><strong>Date:</strong> <?php echo date('F j, Y', strtotime($appointment['appointment_date'])); ?></p>
                <p><strong>Time:</strong> <?php echo date('g:i A', strtotime($appointment['appointment_time'])); ?></p>
                <p><strong>Queue #:</strong> <?php echo $appointment['queue_number']; ?></p>
            </div>
            
            <div class="info-box">
                <strong>📌 Note:</strong> Changing the date will assign you a new queue number based on the new date.
            </div>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label>Select Doctor:</label>
                    <select name="doctor_id" required>
                        <option value="">-- Choose a doctor --</option>
                        <?php foreach($doctors as $doctor): ?>
                            <option value="<?php echo $doctor['doctor_id']; ?>" 
                                <?php echo $doctor['doctor_id'] == $appointment['doctor_id'] ? 'selected' : ''; ?>>
                                Dr. <?php echo htmlspecialchars($doctor['first_name'] . ' ' . $doctor['last_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>New Date:</label>
                    <input type="date" name="appointment_date" 
                           min="<?php echo date('Y-m-d'); ?>" 
                           value="<?php echo $appointment['appointment_date']; ?>" required>
                </div>
                
                <div class="form-group">
                    <label>New Time:</label>
                    <input type="time" name="appointment_time" 
                           value="<?php echo $appointment['appointment_time']; ?>" required>
                </div>
                
                <div class="form-group">
                    <label>Notes (optional):</label>
                    <textarea name="notes" rows="3"><?php echo htmlspecialchars($appointment['notes']); ?></textarea>
                </div>
                
                <button type="submit">Reschedule Appointment</button>
            </form>
            
            <div class="back-link">
                <a href="dashboard.php">← Back to Dashboard</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
