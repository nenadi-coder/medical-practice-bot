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
    
    // === ✅ WORKING HOURS VALIDATION (Server-Side Only) ===
    $day_of_week = (int)date('N', strtotime($new_date)); // 1=Mon, 7=Sun
    $hour = (int)date('H', strtotime($new_time));        // 0-23
    
    if (empty($new_date) || empty($new_time) || empty($doctor_id)) {
        $error = "Please fill all required fields";
    } elseif ($new_date < $today) {
        $error = "Cannot select past dates";
    } elseif ($day_of_week == 5 || $day_of_week == 6) { // Friday=5, Saturday=6
        $error = "Clinic is closed on Fridays and Saturdays. Please choose Sunday–Thursday.";
    } elseif ($hour < 8 || $hour >= 17) { // Before 8:00 AM or 5:00 PM and later
        $error = "Working hours are 8:00 AM – 5:00 PM. Please select a time within this range.";
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
            // ✅ Check if DOCTOR is already booked at this date/time (exclude current appointment)
            $conflict_sql = "SELECT appointment_id FROM appointments 
                             WHERE doctor_id = ? 
                             AND appointment_date = ? 
                             AND appointment_time = ? 
                             AND appointment_id != ?
                             AND status IN ('scheduled', 'confirmed')";
            $conflict_stmt = $pdo->prepare($conflict_sql);
            $conflict_stmt->execute([$doctor_id, $new_date, $new_time, $appointment_id]);
            
            if ($conflict_stmt->rowCount() > 0) {
                $error = "This time slot is no longer available. Please choose another.";
            } else {
                // ✅ DYNAMIC QUEUE: Update with queue_number = NULL
                // ✅ BUSINESS LOGIC: Reset status to 'scheduled' for nurse re-confirmation
                $update_sql = "UPDATE appointments SET 
                              appointment_date = ?, 
                              appointment_time = ?,
                              doctor_id = ?,
                              queue_number = NULL,
                              notes = ?,
                              status = 'scheduled'  -- ← Reset for nurse review
                              WHERE appointment_id = ? AND patient_id = ?";
                $update_stmt = $pdo->prepare($update_sql);
                
                if ($update_stmt->execute([$new_date, $new_time, $doctor_id, $notes, $appointment_id, $patient_id])) {
                    
                    // ✅ Calculate dynamic queue position for success message
                    $queue_sql = "SELECT COUNT(*) as people_ahead FROM appointments 
                                  WHERE appointment_date = ? 
                                  AND appointment_time < ? 
                                  AND status IN ('scheduled', 'confirmed')
                                  AND appointment_id != ?";
                    $queue_stmt = $pdo->prepare($queue_sql);
                    $queue_stmt->execute([$new_date, $new_time, $appointment_id]);
                    $people_ahead = (int)$queue_stmt->fetchColumn();
                    $new_queue_position = $people_ahead + 1;
                    
                    // ✅ Updated success message to reflect nurse confirmation workflow
                    $success = "Appointment rescheduled successfully! Your appointment is now pending nurse confirmation.";
                } else {
                    $error = "Failed to reschedule appointment";
                }
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
    <title>Reschedule Appointment |Shifa Medical Center</title>
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

        /* Old Appointment Card */
        .old-appointment {
            background: linear-gradient(135deg, #f0f9ff, #eef2ff);
            padding: 1.2rem;
            border-radius: 1rem;
            margin-bottom: 1.5rem;
            border-left: 4px solid #4f46e5;
        }

        .old-appointment h3 {
            font-size: 1rem;
            font-weight: 700;
            color: #4f46e5;
            margin-bottom: 0.8rem;
        }

        .old-appointment p {
            margin: 0.5rem 0;
            color: #2d3a5e;
            font-size: 0.9rem;
        }

        /* Info Box */
        .info-box {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 1rem;
            border-radius: 1rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.85rem;
            color: #92400e;
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

        /* Buttons */
        .reschedule-btn {
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

        .reschedule-btn:hover {
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
            <h2><i class="fas fa-calendar-week" style="color: #4f46e5;"></i> Reschedule Appointment</h2>
            
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
                    <a href="dashboard.php"><i class="fas fa-arrow-right"></i> Return to Dashboard</a>
                </div>
            <?php else: ?>
            
                <div class="old-appointment">
                    <h3><i class="fas fa-clock"></i> Current Appointment</h3>
                    <p><i class="fas fa-user-md"></i> <strong>Doctor:</strong> Dr. <?php echo htmlspecialchars($appointment['doctor_name']); ?></p>
                    <p><i class="far fa-calendar-alt"></i> <strong>Date:</strong> <?php echo date('F j, Y', strtotime($appointment['appointment_date'])); ?></p>
                    <p><i class="far fa-clock"></i> <strong>Time:</strong> <?php echo date('g:i A', strtotime($appointment['appointment_time'])); ?></p>
                    <p><i class="fas fa-ticket-alt"></i> <strong>Queue #:</strong> <?php echo $appointment['queue_number']; ?></p>
                    <p><i class="fas fa-tag"></i> <strong>Status:</strong> <span style="text-transform: capitalize;"><?php echo htmlspecialchars($appointment['status']); ?></span></p>
                </div>
                
                <div class="info-box">
                    <i class="fas fa-info-circle" style="font-size: 1.2rem;"></i>
                    <span><strong>Note:</strong> Changing your appointment will require nurse re-confirmation.</span>
                </div>
                
                <form method="POST" action="">
                    <div class="form-group">
                        <label for="doctor_id"><i class="fas fa-user-md"></i> Select Doctor</label>
                        <div class="input-wrapper">
                            <i class="fas fa-user-md"></i>
                            <select id="doctor_id" name="doctor_id" required>
                                <option value="">-- Choose a doctor --</option>
                                <?php foreach($doctors as $doctor): ?>
                                    <option value="<?php echo $doctor['doctor_id']; ?>" 
                                        <?php echo $doctor['doctor_id'] == $appointment['doctor_id'] ? 'selected' : ''; ?>>
                                        Dr. <?php echo htmlspecialchars($doctor['first_name'] . ' ' . $doctor['last_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="appointment_date"><i class="far fa-calendar-alt"></i> New Date</label>
                        <div class="input-wrapper">
                            <i class="far fa-calendar-alt"></i>
                            <input type="date" id="appointment_date" name="appointment_date" 
                                   min="<?php echo date('Y-m-d'); ?>" 
                                   value="<?php echo htmlspecialchars($appointment['appointment_date']); ?>" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="appointment_time"><i class="far fa-clock"></i> New Time</label>
                        <div class="input-wrapper">
                            <i class="far fa-clock"></i>
                            <input type="time" id="appointment_time" name="appointment_time" 
                                   value="<?php echo htmlspecialchars($appointment['appointment_time']); ?>" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="notes"><i class="fas fa-pencil-alt"></i> Notes (optional)</label>
                        <div class="input-wrapper">
                            <i class="fas fa-pencil-alt" style="top: 12px;"></i>
                            <textarea id="notes" name="notes" rows="3" placeholder="Any additional information for the doctor..."><?php echo htmlspecialchars($appointment['notes'] ?? ''); ?></textarea>
                        </div>
                    </div>
                    
                    <button type="submit" class="reschedule-btn">
                        <i class="fas fa-calendar-check"></i> Reschedule Appointment
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
