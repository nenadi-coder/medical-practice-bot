<?php
require_once '../includes/config.php';

// Check if doctor is logged in
if (!isset($_SESSION['doctor_id'])) {
    header('Location: login.php');
    exit();
}

$today = date('Y-m-d');
$doctor_id = $_SESSION['doctor_id'];

// Get today's appointments
$sql = "SELECT a.*, 
        CONCAT(p.first_name, ' ', p.last_name) as patient_name,
        p.phone as patient_phone,
        p.date_of_birth,
        p.email as patient_email
        FROM appointments a
        JOIN patients p ON a.patient_id = p.patient_id
        WHERE a.doctor_id = ? AND a.appointment_date = ?
        ORDER BY a.appointment_time ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$doctor_id, $today]);
$today_appointments = $stmt->fetchAll();

// Get stats
$stats_sql = "SELECT 
              COUNT(*) as total,
              SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) as scheduled,
              SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
              SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
              FROM appointments WHERE doctor_id = ? AND appointment_date = ?";
$stats_stmt = $pdo->prepare($stats_sql);
$stats_stmt->execute([$doctor_id, $today]);
$stats = $stats_stmt->fetch();

if (!$stats) {
    $stats = ['total' => 0, 'scheduled' => 0, 'confirmed' => 0, 'completed' => 0];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Doctor Dashboard</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #f7fafc; }
        
       .navbar {
    background: #f56565;
    color: white;
    padding: 1rem 2rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.logout {
    background: #c53030;
    padding: 0.5rem 1.2rem;
    border-radius: 5px;
    color: white;
    text-decoration: none;
    display: inline-block;
    transition: all 0.2s ease;
}

.logout:hover {
    background: #9b2c2c;
    transform: translateY(-1px);
}
        
        .container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 1rem;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: #f56565;
        }
        
        .date-badge {
            background: white;
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            text-align: center;
            font-size: 1.2rem;
            font-weight: bold;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .patients-grid {
            display: grid;
            gap: 1rem;
        }
        
        .patient-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            border-left: 4px solid #f56565;
        }
        
        .patient-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        
        .patient-name {
            font-size: 1.3rem;
            font-weight: bold;
            color: #2d3748;
        }
        
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: bold;
        }
        
        .status-scheduled { background: #e3f2fd; color: #1976d2; }
        .status-confirmed { background: #e8f5e8; color: #388e3c; }
        .status-completed { background: #f3e5f5; color: #7b1fa2; }
        .status-cancelled { background: #ffebee; color: #c62828; }
        
        .patient-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin: 1rem 0;
            padding: 1rem 0;
            border-top: 1px solid #eee;
            border-bottom: 1px solid #eee;
        }
        
        .detail-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-top: 1rem;
        }
        
        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            font-size: 0.9rem;
            color: white;
            transition: transform 0.2s, opacity 0.2s;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            opacity: 0.9;
        }
        
        .btn-view { background: #4299e1; }
        .btn-notes { background: #9f7aea; }
        .btn-complete { background: #48bb78; }
        .btn-prescribe { background: #ed8936; }
        .btn-lab { background: #667eea; }
        
        .logout {
            background: #c53030;
            padding: 0.5rem 1rem;
            border-radius: 5px;
            color: white;
            text-decoration: none;
        }
        
        .logout:hover {
            background: #9b2c2c;
        }
        
        .no-patients {
            text-align: center;
            padding: 3rem;
            background: white;
            border-radius: 10px;
        }
        
        h2 {
            margin-bottom: 1rem;
            color: #2d3748;
        }
        
        .doctor-name {
            font-weight: bold;
        }
    </style>
</head>
<body>
 <div class="navbar">
    <h1>👨‍⚕️ Doctor Dashboard</h1>
    <div style="display: flex; align-items: center; gap: 1rem;">
        <span>Welcome, <span class="doctor-name"><?php echo htmlspecialchars($_SESSION['doctor_name']); ?></span></span>
        <a href="logout.php" class="logout">Logout</a>
    </div>
</div>
    
    <div class="container">
        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['total']; ?></div>
                <div>Total Today</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['scheduled']; ?></div>
                <div>Scheduled</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['confirmed']; ?></div>
                <div>Confirmed</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['completed']; ?></div>
                <div>Completed</div>
            </div>
        </div>
        
        <!-- Date -->
        <div class="date-badge">
            📅 <?php echo date('l, F j, Y'); ?>
        </div>
        
        <!-- Patients List -->
        <div class="patients-grid">
            <h2>Today's Patients</h2>
            
            <?php if (count($today_appointments) > 0): ?>
                <?php foreach($today_appointments as $apt): ?>
                <div class="patient-card">
                    <div class="patient-header">
                        <span class="patient-name">👤 <?php echo htmlspecialchars($apt['patient_name']); ?></span>
                        <span class="status-badge status-<?php echo $apt['status']; ?>">
                            <?php echo ucfirst($apt['status']); ?>
                        </span>
                    </div>
                    
                    <div class="patient-details">
                        <div class="detail-item">🕒 <strong>Time:</strong> <?php echo date('g:i A', strtotime($apt['appointment_time'])); ?></div>
                        <div class="detail-item">📞 <strong>Phone:</strong> <?php echo htmlspecialchars($apt['patient_phone'] ?? 'N/A'); ?></div>
                        <div class="detail-item">🎫 <strong>Queue #:</strong> <?php echo $apt['queue_number'] ?? 'N/A'; ?></div>
                        <div class="detail-item">📧<strong>Email:</strong><?php echo htmlspecialchars($apt['patient_email']); ?></div>
                          <div class="detail-item"> 🎂 <strong>DOB:</strong> <?php echo $apt['date_of_birth'] ? date('M j, Y', strtotime($apt['date_of_birth'])) : 'N/A'; ?></div>
                    </div>
                    
                    <?php if ($apt['notes']): ?>
                        <div style="background: #f7fafc; padding: 0.75rem; border-radius: 5px; margin: 0.5rem 0;">
                            <strong>📝 Notes:</strong> <?php echo htmlspecialchars($apt['notes']); ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="action-buttons">
                        <a href="view_patient.php?patient_id=<?php echo $apt['patient_id']; ?>" class="btn btn-view">👁️ View Profile</a>
                        <a href="add_notes.php?patient_id=<?php echo $apt['patient_id']; ?>&appointment_id=<?php echo $apt['appointment_id']; ?>" class="btn btn-notes">📝 Add Notes</a>
                        
                        <?php if ($apt['status'] != 'completed'): ?>
                            <a href="mark_complete.php?appointment_id=<?php echo $apt['appointment_id']; ?>&patient_id=<?php echo $apt['patient_id']; ?>" class="btn btn-complete" onclick="return confirm('Mark this appointment as completed?')">✓ Complete</a>
                        <?php endif; ?>
                        
                        <a href="prescribe.php?patient_id=<?php echo $apt['patient_id']; ?>&appointment_id=<?php echo $apt['appointment_id']; ?>" class="btn btn-prescribe">💊 Prescribe</a>
                        <a href="lab_test.php?patient_id=<?php echo $apt['patient_id']; ?>&appointment_id=<?php echo $apt['appointment_id']; ?>" class="btn btn-lab">🔬 Lab Test</a>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="no-patients">
                    <p style="font-size: 1.2rem; color: #666;">No patients scheduled for today</p>
                    <p style="margin-top: 1rem; color: #999;">Enjoy your day! 🎉</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
