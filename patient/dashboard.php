<?php
require_once '../includes/config.php';

// Check if user is logged in
if (!isset($_SESSION['patient_id'])) {
    header('Location: login.php');
    exit();
}

// Handle cancellation request
if (isset($_GET['cancel_id'])) {
    $appointment_id = $_GET['cancel_id'];
    $patient_id = $_SESSION['patient_id'];
    
    // Verify this appointment belongs to the logged-in patient
    $check_sql = "SELECT appointment_id FROM appointments 
                  WHERE appointment_id = ? AND patient_id = ? 
                  AND status IN ('scheduled', 'confirmed')";
    $check_stmt = $pdo->prepare($check_sql);
    $check_stmt->execute([$appointment_id, $patient_id]);
    
    if ($check_stmt->rowCount() > 0) {
        // Update status to cancelled
        $cancel_sql = "UPDATE appointments SET status = 'cancelled' 
                       WHERE appointment_id = ?";
        $cancel_stmt = $pdo->prepare($cancel_sql);
        
        if ($cancel_stmt->execute([$appointment_id])) {
            $success = "Appointment cancelled successfully.";
        } else {
            $error = "Failed to cancel appointment.";
        }
    } else {
        $error = "Invalid appointment or you don't have permission to cancel this.";
    }
}

// Get patient appointments
$sql = "SELECT a.*, 
        CONCAT(d.first_name, ' ', d.last_name) as doctor_name 
        FROM appointments a 
        LEFT JOIN doctors d ON a.doctor_id = d.doctor_id 
        WHERE a.patient_id = ? 
        ORDER BY a.appointment_date DESC, a.appointment_time DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$_SESSION['patient_id']]);
$appointments = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Patient Dashboard</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #f4f4f4; }
        
        .navbar {
            background: #667eea;
            color: white;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 1rem;
        }
        
        .welcome-card {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .appointments-grid {
            display: grid;
            gap: 1rem;
        }
        
        .appointment-card {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            border-left: 4px solid #667eea;
            position: relative;
        }
        
        .appointment-card.cancelled {
            border-left-color: #f56565;
            opacity: 0.7;
            background: #fef5f5;
        }
        
        .appointment-card.completed {
            border-left-color: #48bb78;
        }
        
        .status {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: bold;
        }
        
        .status-scheduled { background: #e3f2fd; color: #1976d2; }
        .status-confirmed { background: #e8f5e8; color: #388e3c; }
        .status-completed { background: #f3e5f5; color: #7b1fa2; }
        .status-cancelled { background: #ffebee; color: #c62828; }
        
        .btn {
            display: inline-block;
            padding: 0.5rem 1rem;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin-top: 1rem;
            margin-right: 0.5rem;
            border: none;
            cursor: pointer;
            font-size: 0.9rem;
        }
        
        .btn:hover { background: #5a67d8; }
        
        .btn-cancel {
            background: #f56565;
        }
        
        .btn-cancel:hover {
            background: #e53e3e;
        }
        
        .btn-telegram {
            background: #667eea;
            color: white;
        }
        
        .btn-telegram:hover {
            background: #5a67d8;
        }
        
        .btn-disabled {
            background: #cbd5e0;
            cursor: not-allowed;
            pointer-events: none;
        }
        
        .logout {
            background: #f56565;
            padding: 0.5rem 1rem;
            border-radius: 5px;
            color: white;
            text-decoration: none;
        }
        
        .message {
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1rem;
        }
        
        .success {
            background: #e8f5e8;
            color: #2e7d32;
            border: 1px solid #a5d6a7;
        }
        
        .error {
            background: #ffebee;
            color: #c62828;
            border: 1px solid #ffcdd2;
        }
        
        .appointment-actions {
            margin-top: 1rem;
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        
        /* Queue Information Styles */
        .queue-info {
            background: #f0f9ff;
            padding: 12px;
            border-radius: 5px;
            margin: 10px 0;
            border-left: 3px solid #667eea;
        }
        
        .queue-position {
            font-size: 1.1em;
            color: #2c3e50;
            margin: 5px 0;
            font-weight: bold;
        }
        
        .wait-time {
            color: #e67e22;
            font-weight: bold;
            margin: 5px 0;
            padding: 5px;
            background: #fff3e0;
            border-radius: 3px;
        }
        
        .next-message {
            color: #48bb78;
            font-weight: bold;
            margin: 5px 0;
            padding: 5px;
            background: #f0fff4;
            border-radius: 3px;
        }
        
        .appointment-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 10px;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        
        .doctor-name {
            color: #2c3e50;
            margin-bottom: 10px;
        }
        
        .appointment-details {
            color: #4a5568;
            line-height: 1.6;
        }
        
        .detail-item {
            margin: 5px 0;
        }
        
        @media (max-width: 768px) {
            .navbar {
                flex-direction: column;
                text-align: center;
            }
            
            .welcome-card {
                flex-direction: column;
                text-align: center;
            }
            
            .appointment-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .appointment-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                text-align: center;
                margin-right: 0;
            }
        }
    </style>
</head>
<body>
    <div class="navbar">
        <h1>🏥 Patient Dashboard</h1>
        <div>
            <span>Welcome, <?php echo htmlspecialchars($_SESSION['patient_name']); ?></span>
            <a href="logout.php" class="logout">Logout</a>
        </div>
    </div>
    
    <div class="container">
        <?php if (isset($success)): ?>
            <div class="message success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="message error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <div class="welcome-card">
            <h2>My Appointments</h2>
            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                <a href="https://t.me/Medical_Practice_Bot" target="_blank" class="btn btn-telegram">📱 Telegram Bot</a>
                <a href="book_appointment.php" class="btn">📅 Book New Appointment</a>
            </div>
        </div>
        
        <div class="appointments-grid">
            <?php if (count($appointments) > 0): ?>
                <?php foreach($appointments as $appointment): ?>
                    <div class="appointment-card 
                        <?php echo $appointment['status'] == 'cancelled' ? 'cancelled' : ''; ?>
                        <?php echo $appointment['status'] == 'completed' ? 'completed' : ''; ?>
                    ">
                        <div class="appointment-header">
                            <h2 class="doctor-name">Dr. <?php echo htmlspecialchars($appointment['doctor_name']); ?></h2>
                            <span class="status status-<?php echo $appointment['status']; ?>">
                                <?php echo ucfirst($appointment['status']); ?>
                            </span>
                        </div>
                        
                        <div class="appointment-details">
                            <div class="detail-item">📅 Date: <?php echo date('F j, Y', strtotime($appointment['appointment_date'])); ?></div>
                            <div class="detail-item">⏰ Time: <?php echo date('g:i A', strtotime($appointment['appointment_time'])); ?></div>
                            
                            <!-- QUEUE POSITION SECTION - Shows for active appointments -->
                            <?php if ($appointment['status'] == 'scheduled' || $appointment['status'] == 'confirmed'): ?>
                                <?php
                                // Calculate how many people are ahead in queue
                                $queue_sql = "SELECT COUNT(*) as people_ahead FROM appointments 
                                              WHERE appointment_date = ? 
                                              AND queue_number < ? 
                                              AND status IN ('scheduled', 'confirmed')
                                              AND appointment_id != ?";
                                $queue_stmt = $pdo->prepare($queue_sql);
                                $queue_stmt->execute([
                                    $appointment['appointment_date'], 
                                    $appointment['queue_number'],
                                    $appointment['appointment_id']
                                ]);
                                $queue_data = $queue_stmt->fetch();
                                $people_ahead = $queue_data['people_ahead'];
                                
                                // Get total waiting for the day
                                $total_sql = "SELECT COUNT(*) as total FROM appointments 
                                              WHERE appointment_date = ? 
                                              AND status IN ('scheduled', 'confirmed')";
                                $total_stmt = $pdo->prepare($total_sql);
                                $total_stmt->execute([$appointment['appointment_date']]);
                                $total_data = $total_stmt->fetch();
                                $total_waiting = $total_data['total'];
                                ?>
                                
                                <div class="queue-info">
                                    <div class="detail-item">🎫 Queue #: <strong><?php echo $appointment['queue_number']; ?></strong></div>
                                    <div class="queue-position">
                                        📊 Position: <?php echo $people_ahead + 1; ?> of <?php echo $total_waiting; ?> waiting
                                    </div>
                                    <div class="detail-item">👥 People ahead: <?php echo $people_ahead; ?></div>
                                    
                                    <?php if ($people_ahead > 0): ?>
                                        <div class="wait-time">
                                            ⏱️ Estimated wait: ~<?php echo $people_ahead * 15; ?> minutes
                                            <br><small>(based on 15 min per patient)</small>
                                        </div>
                                    <?php else: ?>
                                        <div class="next-message">
                                            ✅ You're NEXT! Please be ready when called.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <?php if ($appointment['queue_number']): ?>
                                    <div class="detail-item">🎫 Queue #: <?php echo $appointment['queue_number']; ?></div>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <?php if ($appointment['notes']): ?>
                                <div class="detail-item">📝 Notes: <?php echo htmlspecialchars($appointment['notes']); ?></div>
                            <?php endif; ?>
                            
                            <div class="detail-item">📅 Booked on: <?php echo date('F j, Y', strtotime($appointment['created_at'])); ?></div>
                        </div>
                        
                        <!-- Action buttons -->
                        <div class="appointment-actions">
                            <?php if ($appointment['status'] == 'scheduled' || $appointment['status'] == 'confirmed'): ?>
                                <a href="?cancel_id=<?php echo $appointment['appointment_id']; ?>" 
                                   class="btn btn-cancel"
                                   onclick="return confirm('Are you sure you want to cancel this appointment? This action cannot be undone.');">
                                    ❌ Cancel
                                </a>
                                <a href="reschedule.php?id=<?php echo $appointment['appointment_id']; ?>" 
                                   class="btn">
                                    📅 Reschedule
                                </a>
                                <a href="details.php?id=<?php echo $appointment['appointment_id']; ?>" 
                                   class="btn">
                                    👁️ View Details
                                </a>
                            <?php elseif ($appointment['status'] == 'cancelled'): ?>
                                <span class="btn btn-disabled">❌ Cancelled</span>
                                <a href="book_appointment.php" class="btn">📅 Book New</a>
                            <?php elseif ($appointment['status'] == 'completed'): ?>
                                <span class="btn btn-disabled">✅ Completed</span>
                                <a href="details.php?id=<?php echo $appointment['appointment_id']; ?>" class="btn">👁️ View Details</a>
                                <a href="book_appointment.php" class="btn">📅 Book New</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="text-align: center; padding: 3rem; background: white; border-radius: 8px;">
                    <p style="font-size: 1.2rem; color: #666; margin-bottom: 1rem;">No appointments found.</p>
                    <a href="book_appointment.php" class="btn" style="font-size: 1.1rem; padding: 0.75rem 2rem;">📅 Book Your First Appointment</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        // Add custom cancel confirmation
        document.querySelectorAll('.btn-cancel').forEach(button => {
            button.addEventListener('click', function(e) {
                if (!confirm('Are you sure you want to cancel this appointment? This action cannot be undone.')) {
                    e.preventDefault();
                }
            });
        });
    </script>
</body>
</html>
