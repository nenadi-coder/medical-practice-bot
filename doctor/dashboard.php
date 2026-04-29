<?php
require_once '../includes/config.php';

// Check if doctor is logged in
if (!isset($_SESSION['doctor_id'])) {
    header('Location: login.php');
    exit();
}

$today = date('Y-m-d');
$doctor_id = $_SESSION['doctor_id'];

// ========== FIX: Only fetch CONFIRMED appointments ==========
$sql = "SELECT a.*, 
        CONCAT(p.first_name, ' ', p.last_name) as patient_name,
        p.phone as patient_phone,
        p.date_of_birth,
        p.email as patient_email
        FROM appointments a
        JOIN patients p ON a.patient_id = p.patient_id
        WHERE a.doctor_id = ? 
        AND a.appointment_date = ? 
        AND a.status = 'confirmed'
        ORDER BY a.appointment_time ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$doctor_id, $today]);
$today_appointments = $stmt->fetchAll();

// Get stats (optional: keep all statuses for overview, or filter to confirmed only)
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
    <title>Doctor Dashboard | Medical Practice</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(125deg, #e0f0ff 0%, #f5f0fc 100%);
            min-height: 100vh;
            color: #1e2a3e;
        }
        .navbar {
            background: white;
            backdrop-filter: blur(10px);
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border-bottom: 1px solid rgba(245, 101, 101, 0.15);
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
            color: #f56565;
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
        .doctor-name {
            color: #f56565;
            font-weight: 700;
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
        .container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 1.5rem;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        .stat-card {
            background: white;
            padding: 1.2rem;
            border-radius: 1.2rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            text-align: center;
            border: 1px solid rgba(245, 101, 101, 0.1);
            transition: all 0.2s ease;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.08);
        }
        .stat-number {
            font-size: 2rem;
            font-weight: 800;
            background: linear-gradient(95deg, #f56565, #e53e3e);
            background-clip: text;
            -webkit-background-clip: text;
            color: transparent;
        }
        .stat-label {
            color: #5b6e8c;
            margin-top: 0.4rem;
            font-size: 0.8rem;
            font-weight: 500;
        }
        .date-badge {
            background: white;
            padding: 1rem;
            border-radius: 1rem;
            margin-bottom: 1.5rem;
            text-align: center;
            font-size: 1rem;
            font-weight: 600;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(245, 101, 101, 0.1);
            color: #4a5568;
        }
        .section-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 1.5rem;
        }
        .section-header h2 {
            font-size: 1.3rem;
            font-weight: 700;
            color: #1e2a3e;
        }
        .section-header i {
            color: #f56565;
            font-size: 1.3rem;
        }
        .patients-grid {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        .patient-card {
            background: white;
            padding: 1.5rem;
            border-radius: 1.2rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            border-left: 4px solid #f56565;
            transition: all 0.2s ease;
        }
        .patient-card:hover {
            transform: translateX(4px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.08);
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
            font-size: 1.2rem;
            font-weight: 700;
            color: #1e2a3e;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 0.3rem 0.9rem;
            border-radius: 60px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
        }
        .status-scheduled { background: #e3f2fd; color: #1976d2; }
        .status-confirmed { background: #e8f5e8; color: #388e3c; }
        .status-completed { background: #f3e5f5; color: #7b1fa2; }
        .status-cancelled { background: #ffebee; color: #c62828; }
        .patient-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 0.8rem;
            margin: 1rem 0;
            padding: 1rem 0;
            border-top: 1px solid #e2e8f0;
            border-bottom: 1px solid #e2e8f0;
        }
        .detail-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
            color: #4a5568;
        }
        .detail-item strong {
            color: #2d3748;
        }
        .notes-preview {
            background: #f8fafc;
            padding: 0.75rem;
            border-radius: 0.75rem;
            margin: 0.75rem 0;
            font-size: 0.85rem;
            color: #4a5568;
            border-left: 3px solid #f56565;
        }
        .action-buttons {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            margin-top: 1rem;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 0.6rem 1.2rem;
            border: none;
            border-radius: 60px;
            cursor: pointer;
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 600;
            transition: all 0.2s ease;
        }
        .btn:hover {
            transform: translateY(-2px);
        }
        .btn-view { background: #4299e1; color: white; }
        .btn-notes { background: #9f7aea; color: white; }
        .btn-complete { background: #48bb78; color: white; }
        .btn-prescribe { background: #ed8936; color: white; }
        .btn-lab { background: #667eea; color: white; }
        .no-patients {
            text-align: center;
            padding: 3rem;
            background: white;
            border-radius: 1.2rem;
            border: 1px solid rgba(245, 101, 101, 0.1);
        }
        .no-patients p {
            color: #94a3b8;
        }
        .no-patients i {
            font-size: 3rem;
            color: #cbd5e0;
            margin-bottom: 1rem;
        }
        @media (max-width: 768px) {
            .navbar { flex-direction: column; text-align: center; }
            .patient-details { grid-template-columns: 1fr; }
            .action-buttons { justify-content: center; }
            .container { padding: 0 1rem; }
        }
    </style>
</head>
<body>
    <div class="navbar">
        <h1><i class="fas fa-stethoscope"></i>Shifa Medical Center</h1>
        <div class="user-info">
            <i class="fas fa-user-md" style="color: #f56565;"></i>
            <span>Welcome, <span class="doctor-name">Dr. <?php echo htmlspecialchars($_SESSION['doctor_name']); ?></span></span>
            <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>
    
    <div class="container">
        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['confirmed']; ?></div>
                <div class="stat-label"><i class="fas fa-check-circle"></i> Confirmed Today</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['scheduled']; ?></div>
                <div class="stat-label"><i class="fas fa-clock"></i> Pending</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['completed']; ?></div>
                <div class="stat-label"><i class="fas fa-flag-checkered"></i> Completed</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['total']; ?></div>
                <div class="stat-label"><i class="fas fa-list"></i> All Appointments</div>
            </div>
        </div>
        
        <!-- Date -->
        <div class="date-badge">
            <i class="fas fa-calendar-alt"></i> <?php echo date('l, F j, Y'); ?>
        </div>
        
        <!-- Patients List (CONFIRMED ONLY) -->
        <div class="section-header">
            <i class="fas fa-user-check"></i>
            <h2>Confirmed Patients</h2>
        </div>
        
        <div class="patients-grid">
            <?php if (count($today_appointments) > 0): ?>
                <?php foreach($today_appointments as $apt): ?>
                <div class="patient-card">
                    <div class="patient-header">
                        <span class="patient-name">
                            <i class="fas fa-user-circle" style="color: #f56565;"></i>
                            <?php echo htmlspecialchars($apt['patient_name']); ?>
                        </span>
                        <span class="status-badge status-confirmed">
                            <i class="fas fa-check-circle"></i>
                            Confirmed
                        </span>
                    </div>
                    
                    <div class="patient-details">
                        <div class="detail-item"><i class="far fa-clock"></i> <strong>Time:</strong> <?php echo date('g:i A', strtotime($apt['appointment_time'])); ?></div>
                        <div class="detail-item"><i class="fas fa-phone"></i> <strong>Phone:</strong> <?php echo htmlspecialchars($apt['patient_phone'] ?? 'N/A'); ?></div>
                        <div class="detail-item"><i class="fas fa-ticket-alt"></i> <strong>Queue #:</strong> <?php echo $apt['queue_number'] ?? 'N/A'; ?></div>
                        <div class="detail-item"><i class="fas fa-envelope"></i> <strong>Email:</strong> <?php echo htmlspecialchars($apt['patient_email']); ?></div>
                        <div class="detail-item"><i class="fas fa-birthday-cake"></i> <strong>DOB:</strong> <?php echo $apt['date_of_birth'] ? date('M j, Y', strtotime($apt['date_of_birth'])) : 'N/A'; ?></div>
                    </div>
                    
                    <?php if ($apt['notes']): ?>
                        <div class="notes-preview">
                            <i class="fas fa-pencil-alt"></i> <strong>Notes:</strong> <?php echo htmlspecialchars($apt['notes']); ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="action-buttons">
                        <a href="view_patient.php?patient_id=<?php echo $apt['patient_id']; ?>" class="btn btn-view">
                            <i class="fas fa-eye"></i> View Profile
                        </a>
                        <a href="add_notes.php?patient_id=<?php echo $apt['patient_id']; ?>&appointment_id=<?php echo $apt['appointment_id']; ?>" class="btn btn-notes">
                            <i class="fas fa-notes-medical"></i> Add Notes
                        </a>
                        <a href="mark_complete.php?appointment_id=<?php echo $apt['appointment_id']; ?>&patient_id=<?php echo $apt['patient_id']; ?>" class="btn btn-complete" onclick="return confirm('Mark this appointment as completed?')">
                            <i class="fas fa-check-double"></i> Complete
                        </a>
                        <a href="prescribe.php?patient_id=<?php echo $apt['patient_id']; ?>&appointment_id=<?php echo $apt['appointment_id']; ?>" class="btn btn-prescribe">
                            <i class="fas fa-prescription-bottle"></i> Prescribe
                        </a>
                        <a href="lab_test.php?patient_id=<?php echo $apt['patient_id']; ?>&appointment_id=<?php echo $apt['appointment_id']; ?>" class="btn btn-lab">
                            <i class="fas fa-flask"></i> Lab Test
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="no-patients">
                    <i class="fas fa-clipboard-check"></i>
                    <p style="font-size: 1rem; margin-top: 0.5rem;">No confirmed appointments for today</p>
                    <p style="font-size: 0.85rem; margin-top: 0.5rem; color: #cbd5e0;">Pending appointments will appear here once confirmed by nursing staff.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
