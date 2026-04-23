<?php
require_once '../includes/config.php';

session_start();

// Check if nurse is logged in
if (!isset($_SESSION['nurse_id'])) {
    header('Location: login.php');
    exit();
}

// Get today's date
$today = date('Y-m-d');

// Handle status updates
if (isset($_GET['action']) && isset($_GET['id'])) {
    $appointment_id = $_GET['id'];
    $action = $_GET['action'];
    $nurse_id = $_SESSION['nurse_id'];
    
    $new_status = '';
    $message = '';
    
    switch($action) {
        case 'confirm':
            $new_status = 'confirmed';
            $message = 'Appointment confirmed successfully';
            break;
        case 'cancel':
            $new_status = 'cancelled';
            $message = 'Appointment cancelled';
            break;
        case 'complete':
            $new_status = 'completed';
            $message = 'Appointment completed';
            break;
    }
    
    if ($new_status) {
        $update_sql = "UPDATE appointments SET status = ?, nurse_id = ? WHERE appointment_id = ?";
        $update_stmt = $pdo->prepare($update_sql);
        $update_stmt->execute([$new_status, $nurse_id, $appointment_id]);
        $_SESSION['success'] = $message;
    }
    
    header('Location: dashboard.php?filter=' . urlencode($_GET['filter'] ?? 'today'));
    exit();
}

// Get filter from URL
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'today';

// Debug - log to file
$log_message = date('Y-m-d H:i:s') . " - Filter: $filter - Today: $today\n";
file_put_contents('/tmp/nurse_debug.log', $log_message, FILE_APPEND);

// Build query
if ($filter == 'today') {
    $sql = "SELECT a.*, 
            CONCAT(p.first_name, ' ', p.last_name) as patient_name,
            p.phone as patient_phone,
            p.email as patient_email,
            CONCAT(d.first_name, ' ', d.last_name) as doctor_name
            FROM appointments a
            JOIN patients p ON a.patient_id = p.patient_id
            JOIN doctors d ON a.doctor_id = d.doctor_id
            WHERE a.appointment_date = CURDATE()
            ORDER BY a.appointment_time ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
} elseif ($filter == 'tomorrow') {
    $sql = "SELECT a.*, 
            CONCAT(p.first_name, ' ', p.last_name) as patient_name,
            p.phone as patient_phone,
            p.email as patient_email,
            CONCAT(d.first_name, ' ', d.last_name) as doctor_name
            FROM appointments a
            JOIN patients p ON a.patient_id = p.patient_id
            JOIN doctors d ON a.doctor_id = d.doctor_id
            WHERE a.appointment_date = CURDATE() + INTERVAL 1 DAY
            ORDER BY a.appointment_time ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
} else {
    $sql = "SELECT a.*, 
            CONCAT(p.first_name, ' ', p.last_name) as patient_name,
            p.phone as patient_phone,
            p.email as patient_email,
            CONCAT(d.first_name, ' ', d.last_name) as doctor_name
            FROM appointments a
            JOIN patients p ON a.patient_id = p.patient_id
            JOIN doctors d ON a.doctor_id = d.doctor_id
            WHERE a.status IN ('scheduled', 'confirmed', 'completed', 'cancelled')
            ORDER BY a.appointment_date DESC, a.appointment_time ASC
            LIMIT 100";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
}
$appointments = $stmt->fetchAll();

// Debug log count
file_put_contents('/tmp/nurse_debug.log', date('Y-m-d H:i:s') . " - Found " . count($appointments) . " appointments\n", FILE_APPEND);

// Get stats for today
$stats_sql = "SELECT 
              COUNT(*) as total,
              SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) as scheduled,
              SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
              SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
              SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
              FROM appointments WHERE appointment_date = CURDATE()";
$stats_stmt = $pdo->prepare($stats_sql);
$stats_stmt->execute();
$stats = $stats_stmt->fetch();

if (!$stats) {
    $stats = ['total' => 0, 'scheduled' => 0, 'confirmed' => 0, 'completed' => 0, 'cancelled' => 0];
}

// Get tomorrow stats for display
$tomorrow_sql = "SELECT COUNT(*) as count FROM appointments WHERE appointment_date = CURDATE() + INTERVAL 1 DAY";
$tomorrow_stmt = $pdo->prepare($tomorrow_sql);
$tomorrow_stmt->execute();
$tomorrow_count = $tomorrow_stmt->fetchColumn();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nurse Dashboard | Shifa Medical Center</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #f0f4f8;
            color: #1e2a3e;
        }
        .navbar {
            background: white;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            flex-wrap: wrap;
            gap: 1rem;
        }
        .navbar h1 { font-size: 1.5rem; }
        .navbar h1 i { color: #4f46e5; margin-right: 8px; }
        .user-info { display: flex; align-items: center; gap: 1rem; }
        .logout {
            background: #ef4444;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            color: white;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.85rem;
        }
        .container { max-width: 1400px; margin: 2rem auto; padding: 0 1.5rem; }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        .stat-card {
            background: white;
            padding: 1.2rem;
            border-radius: 1rem;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        .stat-number { font-size: 2rem; font-weight: 800; color: #4f46e5; }
        .stat-label { color: #64748b; margin-top: 0.4rem; font-size: 0.85rem; }
        
        .filter-bar {
            background: white;
            padding: 1rem 1.5rem;
            border-radius: 1rem;
            margin-bottom: 1.5rem;
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: center;
        }
        .filter-btn {
            padding: 0.5rem 1.2rem;
            border-radius: 60px;
            text-decoration: none;
            background: #f1f5f9;
            color: #1e2a3e;
            font-weight: 600;
            font-size: 0.85rem;
            transition: all 0.2s;
        }
        .filter-btn.active { background: #4f46e5; color: white; }
        .filter-btn:hover:not(.active) { background: #e2e8f0; }
        
        .badge-count {
            background: #4f46e5;
            color: white;
            border-radius: 20px;
            padding: 0.1rem 0.5rem;
            font-size: 0.7rem;
            margin-left: 0.3rem;
        }
        
        .appointments-table {
            background: white;
            border-radius: 1rem;
            overflow-x: auto;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        table { width: 100%; border-collapse: collapse; min-width: 800px; }
        th {
            background: #4f46e5;
            color: white;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.85rem;
        }
        td { padding: 1rem; border-bottom: 1px solid #e2e8f0; font-size: 0.85rem; }
        tr:hover { background: #f8fafc; }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 0.25rem 0.75rem;
            border-radius: 60px;
            font-size: 0.7rem;
            font-weight: 700;
        }
        .status-scheduled { background: #dbeafe; color: #1e40af; }
        .status-confirmed { background: #dcfce7; color: #166534; }
        .status-completed { background: #f3e8ff; color: #6b21a5; }
        .status-cancelled { background: #fee2e2; color: #991b1b; }
        
        .action-btn {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 0.3rem 0.7rem;
            margin: 0.1rem;
            border: none;
            border-radius: 60px;
            cursor: pointer;
            text-decoration: none;
            font-size: 0.7rem;
            font-weight: 600;
            transition: all 0.2s;
        }
        .btn-confirm { background: #22c55e; color: white; }
        .btn-cancel { background: #ef4444; color: white; }
        .btn-complete { background: #8b5cf6; color: white; }
        .action-btn:hover { transform: translateY(-1px); filter: brightness(0.9); }
        
        .message { padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; }
        .success { background: #dcfce7; color: #166534; border-left: 4px solid #22c55e; }
        .error { background: #fee2e2; color: #991b1b; border-left: 4px solid #ef4444; }
        
        .queue-number { font-weight: 700; color: #4f46e5; font-size: 1rem; }
        
        .debug-info {
            background: #f8f9fa;
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 8px;
            font-size: 12px;
            font-family: monospace;
            display: none;
        }
    </style>
</head>
<body>
    <div class="navbar">
        <h1><i class="fas fa-stethoscope"></i> Shifa Medical Center</h1>
        <div class="user-info">
            <i class="fas fa-user-nurse"></i>
            <span>Welcome, <?php echo htmlspecialchars($_SESSION['nurse_name']); ?></span>
            <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>
    
    <div class="container">
        <?php if (isset($_SESSION['success'])): ?>
            <div class="message success">✅ <?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
        <?php endif; ?>
        
        <!-- Debug Info (hidden, can be unhidden for testing) -->
        <div class="debug-info">
            <strong>Debug Info:</strong><br>
            Filter: <?php echo $filter; ?><br>
            Today: <?php echo $today; ?><br>
            Tomorrow: <?php echo date('Y-m-d', strtotime('+1 day')); ?><br>
            Appointments found: <?php echo count($appointments); ?><br>
            Tomorrow count: <?php echo $tomorrow_count; ?>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card"><div class="stat-number"><?php echo $stats['total']; ?></div><div class="stat-label">Total Today</div></div>
            <div class="stat-card"><div class="stat-number"><?php echo $stats['scheduled']; ?></div><div class="stat-label">Scheduled</div></div>
            <div class="stat-card"><div class="stat-number"><?php echo $stats['confirmed']; ?></div><div class="stat-label">Confirmed</div></div>
            <div class="stat-card"><div class="stat-number"><?php echo $stats['completed']; ?></div><div class="stat-label">Completed</div></div>
            <div class="stat-card"><div class="stat-number"><?php echo $stats['cancelled']; ?></div><div class="stat-label">Cancelled</div></div>
        </div>
        
        <div class="filter-bar">
            <a href="?filter=today" class="filter-btn <?php echo $filter == 'today' ? 'active' : ''; ?>">
                <i class="fas fa-calendar-day"></i> Today
            </a>
            <a href="?filter=tomorrow" class="filter-btn <?php echo $filter == 'tomorrow' ? 'active' : ''; ?>">
                <i class="fas fa-calendar-plus"></i> Tomorrow
                <?php if ($tomorrow_count > 0): ?>
                    <span class="badge-count"><?php echo $tomorrow_count; ?></span>
                <?php endif; ?>
            </a>
            <a href="?filter=all" class="filter-btn <?php echo $filter == 'all' ? 'active' : ''; ?>">
                <i class="fas fa-list"></i> All Appointments
            </a>
        </div>
        
        <div class="appointments-table">
            <table>
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Patient</th>
                        <th>Phone</th>
                        <th>Doctor</th>
                        <th>Queue #</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($appointments) > 0): ?>
                        <?php foreach($appointments as $apt): ?>
                            <tr>
                                <td><i class="far fa-clock"></i> <?php echo date('g:i A', strtotime($apt['appointment_time'])); ?></td>
                                <td><strong><?php echo htmlspecialchars($apt['patient_name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($apt['patient_phone']); ?></td>
                                <td>Dr. <?php echo htmlspecialchars($apt['doctor_name']); ?></td>
                                <td><span class="queue-number">#<?php echo $apt['queue_number']; ?></span></td>
                                <td>
                                    <span class="status-badge status-<?php echo $apt['status']; ?>">
                                        <?php echo ucfirst($apt['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($apt['status'] == 'scheduled'): ?>
                                        <a href="?action=confirm&id=<?php echo $apt['appointment_id']; ?>&filter=<?php echo $filter; ?>" class="action-btn btn-confirm" onclick="return confirm('Confirm this appointment?')">✅ Confirm</a>
                                        <a href="?action=cancel&id=<?php echo $apt['appointment_id']; ?>&filter=<?php echo $filter; ?>" class="action-btn btn-cancel" onclick="return confirm('Cancel this appointment?')">❌ Cancel</a>
                                    <?php endif; ?>
                                    <?php if ($apt['status'] == 'confirmed'): ?>
                                        <a href="?action=complete&id=<?php echo $apt['appointment_id']; ?>&filter=<?php echo $filter; ?>" class="action-btn btn-complete" onclick="return confirm('Mark as completed?')">✔️ Complete</a>
                                        <a href="?action=cancel&id=<?php echo $apt['appointment_id']; ?>&filter=<?php echo $filter; ?>" class="action-btn btn-cancel" onclick="return confirm('Cancel this appointment?')">❌ Cancel</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 3rem;">
                                <i class="fas fa-calendar-times" style="font-size: 2rem; margin-bottom: 0.5rem; display: block;"></i>
                                No appointments found for <?php echo $filter == 'today' ? 'today' : ($filter == 'tomorrow' ? 'tomorrow' : 'this period'); ?>.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
