<?php
require_once '../includes/config.php';

// CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Check if user is logged in
if (!isset($_SESSION['patient_id'])) {
    header('Location: login.php');
    exit();
}

// Handle cancellation request with CSRF protection
if (isset($_GET['cancel_id'])) {
    // Verify CSRF token
    if (!isset($_GET['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_GET['csrf_token'])) {
        $error = "Invalid security token";
    } else {
        $appointment_id = (int)$_GET['cancel_id'];
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
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            } else {
                $error = "Failed to cancel appointment.";
            }
        } else {
            $error = "Invalid appointment or you don't have permission to cancel this.";
        }
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
    <title>Patient Dashboard | Medical Practice</title>
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

        .logout {
            background: #f56565;
            padding: 0.5rem 1.2rem;
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
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 1.5rem;
        }

        /* Welcome Card */
        .welcome-card {
            background: white;
            padding: 1.8rem 2rem;
            border-radius: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 8px 25px -12px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            border: 1px solid rgba(102, 126, 234, 0.15);
        }

        .welcome-card h2 {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1e2a3e;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-group {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 0.7rem 1.3rem;
            background: linear-gradient(95deg, #4f46e5, #7c3aed);
            color: white;
            text-decoration: none;
            border-radius: 60px;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.25s ease;
            border: none;
            cursor: pointer;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(79, 70, 229, 0.3);
        }

        .btn-telegram {
            background: linear-gradient(95deg, #0088cc, #00a6e6);
        }

        .btn-telegram:hover {
            box-shadow: 0 8px 20px rgba(0, 136, 204, 0.3);
        }

        .btn-cancel {
            background: #f56565;
        }

        .btn-cancel:hover {
            background: #e53e3e;
        }

        .btn-disabled {
            background: #cbd5e0;
            cursor: not-allowed;
            pointer-events: none;
            opacity: 0.6;
        }

        /* Messages */
        .message {
            padding: 1rem 1.2rem;
            border-radius: 1rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 500;
        }

        .success {
            background: #e8f5e8;
            color: #2e7d32;
            border-left: 4px solid #48bb78;
        }

        .error {
            background: #ffebee;
            color: #c62828;
            border-left: 4px solid #f56565;
        }

        /* Appointments Grid */
        .appointments-grid {
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
        }

        .appointment-card {
            background: white;
            padding: 1.5rem;
            border-radius: 1.25rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            border-left: 5px solid #4f46e5;
            transition: all 0.2s ease;
        }

        .appointment-card:hover {
            transform: translateX(4px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .appointment-card.cancelled {
            border-left-color: #f56565;
            opacity: 0.75;
            background: #fefaf9;
        }

        .appointment-card.completed {
            border-left-color: #48bb78;
        }

        .appointment-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .doctor-name {
            font-size: 1.25rem;
            font-weight: 700;
            color: #1e2a3e;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 0.3rem 0.9rem;
            border-radius: 60px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-scheduled { background: #e3f2fd; color: #1976d2; }
        .status-confirmed { background: #e8f5e8; color: #388e3c; }
        .status-completed { background: #f3e5f5; color: #7b1fa2; }
        .status-cancelled { background: #ffebee; color: #c62828; }

        .appointment-details {
            color: #4a5568;
            line-height: 1.7;
            margin-bottom: 1rem;
        }

        .detail-item {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 8px 0;
            font-size: 0.9rem;
        }

        /* Queue Info Styles */
        .queue-info {
            background: linear-gradient(135deg, #f0f9ff, #eef2ff);
            padding: 1rem;
            border-radius: 1rem;
            margin: 1rem 0;
            border: 1px solid rgba(79, 70, 229, 0.2);
        }

        .queue-position {
            font-size: 1rem;
            font-weight: 700;
            color: #4f46e5;
            margin: 5px 0;
        }

        .wait-time {
            background: #fff3e0;
            padding: 8px 12px;
            border-radius: 0.75rem;
            color: #e67e22;
            font-weight: 600;
            margin-top: 8px;
        }

        .next-message {
            background: #f0fff4;
            padding: 8px 12px;
            border-radius: 0.75rem;
            color: #48bb78;
            font-weight: 700;
            margin-top: 8px;
        }

        /* ✅ NEW: Pending confirmation message style */
        .pending-message {
            background: #fff8e1;
            padding: 8px 12px;
            border-radius: 0.75rem;
            color: #f59e0b;
            font-weight: 600;
            margin-top: 8px;
        }

        .appointment-actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            margin-top: 1rem;
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            background: white;
            border-radius: 1.5rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        }

        .empty-state p {
            font-size: 1.1rem;
            color: #5b6e8c;
            margin-bottom: 1.5rem;
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
            .btn-group {
                justify-content: center;
            }
            .appointment-header {
                flex-direction: column;
            }
            .appointment-actions {
                flex-direction: column;
            }
            .btn {
                justify-content: center;
            }
            .container {
                padding: 0 1rem;
            }
            .btn-telegram {
                background: linear-gradient(95deg, #0088cc, #00a6e6);
            }
        }
    </style>
</head>
<body>
    <div class="navbar">
        <h1><i class="fas fa-stethoscope"></i> Shifa Medical Center</h1>
        <div class="user-info">
            <i class="fas fa-user-circle" style="color: #4f46e5; font-size: 1.2rem;"></i>
            <span>Welcome, <?php echo htmlspecialchars($_SESSION['patient_name']); ?></span>
            <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>
    
    <div class="container">
        <?php if (isset($success)): ?>
            <div class="message success">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="message error">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <div class="welcome-card">
            <h2><i class="fas fa-calendar-check" style="color: #4f46e5;"></i> My Appointments</h2>
            <div class="btn-group">            
                <a href="https://t.me/Medical_Practice_Bot" target="_blank" class="btn btn-telegram">
                    <i class="fab fa-telegram"></i> Telegram Bot
                </a>
                <a href="book_appointment.php" class="btn">
                    <i class="fas fa-plus-circle"></i> Book New Appointment
                </a>
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
                            <div class="doctor-name">
                                <i class="fas fa-user-md" style="color: #4f46e5;"></i>
                                Dr. <?php echo htmlspecialchars($appointment['doctor_name']); ?>
                            </div>
                            <span class="status status-<?php echo $appointment['status']; ?>">
                                <i class="fas <?php echo $appointment['status'] == 'scheduled' ? 'fa-clock' : ($appointment['status'] == 'confirmed' ? 'fa-check-circle' : ($appointment['status'] == 'completed' ? 'fa-flag-checkered' : 'fa-ban')); ?>"></i>
                                <?php echo ucfirst(htmlspecialchars($appointment['status'])); ?>
                            </span>
                        </div>
                        
                        <div class="appointment-details">
                            <div class="detail-item"><i class="far fa-calendar-alt"></i> <?php echo date('F j, Y', strtotime($appointment['appointment_date'])); ?></div>
                            <div class="detail-item"><i class="far fa-clock"></i> <?php echo date('g:i A', strtotime($appointment['appointment_time'])); ?></div>
                            
                            <?php if ($appointment['status'] == 'scheduled' || $appointment['status'] == 'confirmed'): ?>
                                <?php
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
                                
                                $total_sql = "SELECT COUNT(*) as total FROM appointments 
                                              WHERE appointment_date = ? 
                                              AND status IN ('scheduled', 'confirmed')";
                                $total_stmt = $pdo->prepare($total_sql);
                                $total_stmt->execute([$appointment['appointment_date']]);
                                $total_data = $total_stmt->fetch();
                                $total_waiting = $total_data['total'];
                                ?>
                                
                                <div class="queue-info">
                                    <div class="detail-item"><i class="fas fa-ticket-alt"></i> Queue #: <strong><?php echo htmlspecialchars($appointment['queue_number']); ?></strong></div>
                                    
                                    <!-- ✅ UPDATED: Different messages for scheduled vs confirmed -->
                                    <?php if ($appointment['status'] == 'scheduled'): ?>
                                        <!-- Scheduled = waiting for nurse confirmation -->
                                        <div class="pending-message">
                                            <i class="fas fa-hourglass-start"></i> Wait for nurse confirmation
                                        </div>
                                    <?php elseif ($appointment['status'] == 'confirmed'): ?>
                                        <!-- Confirmed = show queue position and wait estimates -->
                                        <div class="queue-position">
                                            📊 Position: <?php echo htmlspecialchars($people_ahead + 1); ?> of <?php echo htmlspecialchars($total_waiting); ?> waiting
                                        </div>
                                        <div class="detail-item"><i class="fas fa-users"></i> People ahead: <?php echo htmlspecialchars($people_ahead); ?></div>
                                        
                                        <?php if ($people_ahead > 0): ?>
                                            <div class="wait-time">
                                                <i class="fas fa-hourglass-half"></i> Estimated wait: ~<?php echo htmlspecialchars($people_ahead * 15); ?> minutes
                                                <br><small>(based on 15 min per patient)</small>
                                            </div>
                                        <?php else: ?>
                                            <div class="next-message">
                                                <i class="fas fa-bell"></i> You're NEXT! Please be ready when called.
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <?php if ($appointment['queue_number']): ?>
                                    <div class="detail-item"><i class="fas fa-ticket-alt"></i> Queue #: <?php echo htmlspecialchars($appointment['queue_number']); ?></div>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <?php if ($appointment['notes']): ?>
                                <div class="detail-item"><i class="fas fa-pencil-alt"></i> Notes: <?php echo htmlspecialchars($appointment['notes']); ?></div>
                            <?php endif; ?>
                            
                            <div class="detail-item"><i class="fas fa-calendar-plus"></i> Booked on: <?php echo date('F j, Y', strtotime($appointment['created_at'])); ?></div>
                        </div>
                        
                        <div class="appointment-actions">
                            <?php if ($appointment['status'] == 'scheduled' || $appointment['status'] == 'confirmed'): ?>
                                <a href="?cancel_id=<?php echo $appointment['appointment_id']; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" 
                                   class="btn btn-cancel"
                                   onclick="return confirm('Are you sure you want to cancel this appointment? This action cannot be undone.');">
                                    <i class="fas fa-times-circle"></i> Cancel
                                </a>
                                <a href="reschedule.php?id=<?php echo $appointment['appointment_id']; ?>" class="btn">
                                    <i class="fas fa-calendar-week"></i> Reschedule
                                </a>
                                <a href="details.php?id=<?php echo $appointment['appointment_id']; ?>" class="btn">
                                    <i class="fas fa-eye"></i> View Details
                                </a>
                            <?php elseif ($appointment['status'] == 'cancelled'): ?>
                                <span class="btn btn-disabled"><i class="fas fa-ban"></i> Cancelled</span>
                                <a href="book_appointment.php" class="btn"><i class="fas fa-plus-circle"></i> Book New</a>
                            <?php elseif ($appointment['status'] == 'completed'): ?>
                                <span class="btn btn-disabled"><i class="fas fa-check-circle"></i> Completed</span>
                                <a href="details.php?id=<?php echo $appointment['appointment_id']; ?>" class="btn"><i class="fas fa-eye"></i> View Details</a>
                                <a href="book_appointment.php" class="btn"><i class="fas fa-plus-circle"></i> Book New</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-calendar-times" style="font-size: 3rem; color: #cbd5e0; margin-bottom: 1rem; display: inline-block;"></i>
                    <p>No appointments found. Start your healthcare journey with us!</p>
                    <a href="book_appointment.php" class="btn" style="font-size: 1rem; padding: 0.8rem 1.8rem;">
                        <i class="fas fa-calendar-plus"></i> Book Your First Appointment
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
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
