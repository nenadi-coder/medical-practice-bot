<?php
session_start();
require_once '../includes/config.php';

// ========== SMS FUNCTION ==========
define('EASYSENDSMS_USERNAME', 'nadoouamhou6s2026');
define('EASYSENDSMS_API_KEY', 'IBxpv37c');

function sendSMS($phoneNumber, $message) {
    $username = EASYSENDSMS_USERNAME;
    $apiKey = EASYSENDSMS_API_KEY;
    
    $phoneNumber = preg_replace('/^0+/', '', $phoneNumber);
    if (!preg_match('/^213/', $phoneNumber)) {
        $phoneNumber = '213' . $phoneNumber;
    }
    
    $url = 'https://api.easysendsms.app/bulksms';
    
    $postData = http_build_query([
        'username' => $username,
        'password' => $apiKey,
        'from'     => 'Clinic',
        'to'       => $phoneNumber,
        'text'     => $message,
        'type'     => '0'
    ]);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT => 30,
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return $response && strpos($response, 'OK:') === 0;
}

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
    
    $check_sql = "SELECT appointment_id FROM appointments WHERE appointment_id = ?";
    $check_stmt = $pdo->prepare($check_sql);
    $check_stmt->execute([$appointment_id]);
    
    if ($check_stmt->rowCount() > 0) {
        $new_status = '';
        $message = '';
        
        switch($action) {
            case 'confirm':
                $new_status = 'confirmed';
                $message = 'Appointment confirmed successfully';
                
                $apt_sql = "SELECT a.send_sms, p.phone, p.first_name, p.last_name, 
                                   d.first_name as doctor_first, d.last_name as doctor_last,
                                   a.appointment_date, a.appointment_time, a.queue_number
                            FROM appointments a
                            JOIN patients p ON a.patient_id = p.patient_id
                            JOIN doctors d ON a.doctor_id = d.doctor_id
                            WHERE a.appointment_id = ?";
                $apt_stmt = $pdo->prepare($apt_sql);
                $apt_stmt->execute([$appointment_id]);
                $apt = $apt_stmt->fetch();
                
                if ($apt && $apt['send_sms'] == 1) {
                    $date = date('d/m', strtotime($apt['appointment_date']));
                    $time = date('H:i', strtotime($apt['appointment_time']));
                    $smsMessage = "✅ Appt confirmed: $date at $time with Dr. {$apt['doctor_last']}. Queue #{$apt['queue_number']}";
                    
                    if (sendSMS($apt['phone'], $smsMessage)) {
                        $message .= " & SMS sent";
                    } else {
                        $message .= " (SMS failed)";
                    }
                } elseif ($apt && $apt['send_sms'] == 0) {
                    $message .= " (No SMS requested)";
                }
                break;
            case 'cancel':
                $new_status = 'cancelled';
                $message = 'Appointment cancelled';
                break;
            case 'noshow':
                $new_status = 'no-show';
                $message = 'Patient marked as no-show';
                break;
            case 'complete':
                $new_status = 'completed';
                $message = 'Appointment completed';
                break;
        }
        
        if ($new_status) {
            $update_sql = "UPDATE appointments SET status = ?, nurse_id = ? WHERE appointment_id = ?";
            $update_stmt = $pdo->prepare($update_sql);
            if ($update_stmt->execute([$new_status, $nurse_id, $appointment_id])) {
                $_SESSION['success'] = $message;
            } else {
                $_SESSION['error'] = 'Failed to update appointment';
            }
        }
    } else {
        $_SESSION['error'] = 'Invalid appointment';
    }
    header('Location: dashboard.php?filter=' . urlencode($_GET['filter'] ?? 'today'));
    exit();
}

// Get filter from URL
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'today';
$date_filter = $today;

if ($filter == 'tomorrow') {
    $date_filter = date('Y-m-d', strtotime('+1 day'));
} elseif ($filter == 'all') {
    $date_filter = '';
}

// Build query based on filter
if ($filter == 'all') {
    $sql = "SELECT a.*, 
            CONCAT(p.first_name, ' ', p.last_name) as patient_name,
            p.phone as patient_phone,
            CONCAT(d.first_name, ' ', d.last_name) as doctor_name
            FROM appointments a
            JOIN patients p ON a.patient_id = p.patient_id
            JOIN doctors d ON a.doctor_id = d.doctor_id
            ORDER BY a.appointment_date DESC, a.appointment_time ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
} else {
    $sql = "SELECT a.*, 
            CONCAT(p.first_name, ' ', p.last_name) as patient_name,
            p.phone as patient_phone,
            CONCAT(d.first_name, ' ', d.last_name) as doctor_name
            FROM appointments a
            JOIN patients p ON a.patient_id = p.patient_id
            JOIN doctors d ON a.doctor_id = d.doctor_id
            WHERE a.appointment_date = ?
            ORDER BY a.appointment_time ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$date_filter]);
}
$appointments = $stmt->fetchAll();

// Get stats for today
$stats_sql = "SELECT 
              COUNT(*) as total,
              SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) as scheduled,
              SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
              SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
              SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
              SUM(CASE WHEN status = 'no-show' THEN 1 ELSE 0 END) as noshow
              FROM appointments WHERE appointment_date = ?";
$stats_stmt = $pdo->prepare($stats_sql);
$stats_stmt->execute([$today]);
$stats = $stats_stmt->fetch();

if (!$stats) {
    $stats = [
        'total' => 0,
        'scheduled' => 0,
        'confirmed' => 0,
        'completed' => 0,
        'cancelled' => 0,
        'noshow' => 0
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Nurse Dashboard | Medical Practice</title>
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
            border-bottom: 1px solid rgba(102, 126, 234, 0.15);
            flex-wrap: wrap;
            gap: 1rem;
        }
        .navbar h1 { font-size: 1.5rem; font-weight: 700; background: linear-gradient(120deg, #1e2a3e, #2d3a5e); background-clip: text; -webkit-background-clip: text; color: transparent; }
        .navbar h1 i { background: none; color: #4f46e5; margin-right: 8px; }
        .user-info { display: flex; align-items: center; gap: 1rem; background: #f1f5f9; padding: 0.5rem 1rem; border-radius: 60px; }
        .user-info span { font-weight: 600; color: #1e2a3e; }
        .logout { background: #f56565; padding: 0.5rem 1rem; border-radius: 60px; color: white; text-decoration: none; font-weight: 600; font-size: 0.85rem; transition: all 0.2s ease; display: inline-flex; align-items: center; gap: 6px; }
        .logout:hover { background: #e53e3e; transform: translateY(-1px); }
        .container { max-width: 1400px; margin: 2rem auto; padding: 0 1.5rem; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
        .stat-card { background: white; padding: 1.2rem; border-radius: 1.2rem; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05); text-align: center; border: 1px solid rgba(102, 126, 234, 0.1); transition: all 0.2s ease; }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0, 0, 0, 0.08); }
        .stat-number { font-size: 2rem; font-weight: 800; background: linear-gradient(95deg, #4f46e5, #7c3aed); background-clip: text; -webkit-background-clip: text; color: transparent; }
        .stat-label { color: #5b6e8c; margin-top: 0.4rem; font-size: 0.8rem; font-weight: 500; }
        .filter-bar { background: white; padding: 1rem 1.5rem; border-radius: 1.2rem; margin-bottom: 2rem; display: flex; gap: 1rem; align-items: center; flex-wrap: wrap; border: 1px solid rgba(102, 126, 234, 0.1); }
        .filter-btn { padding: 0.5rem 1.2rem; border: none; border-radius: 60px; cursor: pointer; text-decoration: none; background: #f1f5f9; color: #1e2a3e; font-weight: 600; font-size: 0.85rem; transition: all 0.2s ease; }
        .filter-btn.active { background: linear-gradient(95deg, #4f46e5, #7c3aed); color: white; }
        .filter-btn:hover:not(.active) { background: #e2e8f0; }
        .filter-info { margin-left: auto; font-size: 0.85rem; color: #5b6e8c; }
        .appointments-table { background: white; border-radius: 1.2rem; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05); overflow-x: auto; border: 1px solid rgba(102, 126, 234, 0.1); }
        table { width: 100%; border-collapse: collapse; min-width: 900px; }
        th { background: linear-gradient(95deg, #4f46e5, #7c3aed); color: white; padding: 1rem; text-align: left; font-weight: 600; font-size: 0.85rem; }
        td { padding: 1rem; border-bottom: 1px solid #e2e8f0; font-size: 0.85rem; }
        tr:hover { background: #f8fafc; }
        .status-badge { display: inline-flex; align-items: center; gap: 5px; padding: 0.25rem 0.75rem; border-radius: 60px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; }
        .status-scheduled { background: #e3f2fd; color: #1976d2; }
        .status-confirmed { background: #e8f5e8; color: #388e3c; }
        .status-completed { background: #f3e5f5; color: #7b1fa2; }
        .status-cancelled { background: #ffebee; color: #c62828; }
        .status-no-show { background: #fff3e0; color: #e65100; }
        .action-btn { display: inline-flex; align-items: center; gap: 5px; padding: 0.4rem 0.8rem; margin: 0.2rem; border: none; border-radius: 60px; cursor: pointer; text-decoration: none; font-size: 0.7rem; font-weight: 600; transition: all 0.2s ease; }
        .btn-confirm { background: #48bb78; color: white; }
        .btn-complete { background: #9f7aea; color: white; }
        .btn-cancel { background: #f56565; color: white; }
        .btn-noshow { background: #ed8936; color: white; }
        .action-btn:hover { transform: translateY(-1px); filter: brightness(0.9); }
        .print-ticket { display: inline-flex; align-items: center; gap: 5px; background: #718096; color: white; padding: 0.4rem 0.8rem; border-radius: 60px; text-decoration: none; font-size: 0.7rem; font-weight: 600; transition: all 0.2s ease; }
        .print-ticket:hover { background: #4a5568; transform: translateY(-1px); }
        .sms-badge { display: inline-flex; align-items: center; gap: 4px; padding: 0.25rem 0.6rem; border-radius: 60px; font-size: 0.65rem; font-weight: 700; }
        .sms-yes { background: #e8f5e8; color: #2e7d32; }
        .sms-no { background: #ffebee; color: #c62828; }
        .queue-number { font-weight: 700; color: #4f46e5; font-size: 1rem; }
        .message { padding: 1rem; border-radius: 1rem; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 10px; }
        .success { background: #e8f5e8; color: #2e7d32; border-left: 4px solid #48bb78; }
        .error { background: #ffebee; color: #c62828; border-left: 4px solid #f56565; }
        .empty-row td { text-align: center; padding: 3rem; color: #94a3b8; }
        @media (max-width: 768px) {
            .navbar { flex-direction: column; text-align: center; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .filter-bar { justify-content: center; }
            .filter-info { margin-left: 0; width: 100%; text-align: center; }
            .container { padding: 0 1rem; }
        }
    </style>
</head>
<body>
    <div class="navbar">
        <h1><i class="fas fa-stethoscope"></i>Shifa Medical Center</h1>
        <div class="user-info">
            <i class="fas fa-user-nurse" style="color: #4f46e5;"></i>
            <span>Welcome, <?php echo htmlspecialchars($_SESSION['nurse_name']); ?></span>
            <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>
    
    <div class="container">
        <?php if (isset($_SESSION['success'])): ?>
            <div class="message success">
                <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="message error">
                <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>
        
        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['total']; ?></div>
                <div class="stat-label"><i class="fas fa-calendar-day"></i> Total Today</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['scheduled']; ?></div>
                <div class="stat-label"><i class="fas fa-clock"></i> Scheduled</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['confirmed']; ?></div>
                <div class="stat-label"><i class="fas fa-check-circle"></i> Confirmed</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['completed']; ?></div>
                <div class="stat-label"><i class="fas fa-flag-checkered"></i> Completed</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['cancelled']; ?></div>
                <div class="stat-label"><i class="fas fa-ban"></i> Cancelled</div>
            </div>
        </div>
        
        <!-- Filter Bar -->
        <div class="filter-bar">
            <a href="?filter=today" class="filter-btn <?php echo $filter == 'today' ? 'active' : ''; ?>">
                <i class="fas fa-calendar-day"></i> Today
            </a>
            <a href="?filter=tomorrow" class="filter-btn <?php echo $filter == 'tomorrow' ? 'active' : ''; ?>">
                <i class="fas fa-calendar-plus"></i> Tomorrow
            </a>
            <a href="?filter=all" class="filter-btn <?php echo $filter == 'all' ? 'active' : ''; ?>">
                <i class="fas fa-list"></i> All Appointments
            </a>
            <span class="filter-info">
                <i class="fas fa-eye"></i> Showing: <strong>
                <?php 
                if ($filter == 'today') echo date('F j, Y');
                elseif ($filter == 'tomorrow') echo date('F j, Y', strtotime('+1 day'));
                else echo 'All dates';
                ?>
                </strong>
            </span>
        </div>
        
        <!-- Appointments Table -->
        <div class="appointments-table">
            <table>
                <thead>
                    <tr>
                        <th><i class="far fa-clock"></i> Time</th>
                        <th><i class="fas fa-user"></i> Patient</th>
                        <th><i class="fas fa-phone"></i> Phone</th>
                        <th><i class="fas fa-user-md"></i> Doctor</th>
                        <th><i class="fas fa-ticket-alt"></i> Queue #</th>
                        <th><i class="fas fa-tag"></i> Status</th>
                        <th><i class="fas fa-sms"></i> SMS</th>
                        <th><i class="fas fa-cog"></i> Actions</th>
                        <th><i class="fas fa-print"></i> Ticket</th>
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
                                        <i class="fas <?php echo $apt['status'] == 'scheduled' ? 'fa-clock' : ($apt['status'] == 'confirmed' ? 'fa-check-circle' : ($apt['status'] == 'completed' ? 'fa-flag-checkered' : ($apt['status'] == 'cancelled' ? 'fa-ban' : 'fa-user-slash'))); ?>"></i>
                                        <?php echo ucfirst($apt['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (isset($apt['send_sms']) && $apt['send_sms'] == 1): ?>
                                        <span class="sms-badge sms-yes"><i class="fas fa-check"></i> Yes</span>
                                    <?php else: ?>
                                        <span class="sms-badge sms-no"><i class="fas fa-times"></i> No</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($apt['status'] == 'scheduled'): ?>
                                        <a href="?action=confirm&id=<?php echo $apt['appointment_id']; ?>&filter=<?php echo $filter; ?>" 
                                           class="action-btn btn-confirm" 
                                           onclick="return confirm('Confirm this appointment? SMS will be sent if patient requested.');">
                                            <i class="fas fa-check"></i> Confirm
                                        </a>
                                        <a href="?action=cancel&id=<?php echo $apt['appointment_id']; ?>&filter=<?php echo $filter; ?>" 
                                           class="action-btn btn-cancel"
                                           onclick="return confirm('Cancel this appointment?')">
                                            <i class="fas fa-times"></i> Cancel
                                        </a>
                                        <a href="?action=noshow&id=<?php echo $apt['appointment_id']; ?>&filter=<?php echo $filter; ?>" 
                                           class="action-btn btn-noshow"
                                           onclick="return confirm('Mark patient as no-show?')">
                                            <i class="fas fa-user-slash"></i> No Show
                                        </a>
                                    <?php endif; ?>
                                    
                                    <?php if ($apt['status'] == 'confirmed'): ?>
                                        <a href="?action=complete&id=<?php echo $apt['appointment_id']; ?>&filter=<?php echo $filter; ?>" 
                                           class="action-btn btn-complete"
                                           onclick="return confirm('Mark as completed?')">
                                            <i class="fas fa-check-double"></i> Complete
                                        </a>
                                        <a href="?action=noshow&id=<?php echo $apt['appointment_id']; ?>&filter=<?php echo $filter; ?>" 
                                           class="action-btn btn-noshow"
                                           onclick="return confirm('Mark patient as no-show?')">
                                            <i class="fas fa-user-slash"></i> No Show
                                        </a>
                                        <a href="?action=cancel&id=<?php echo $apt['appointment_id']; ?>&filter=<?php echo $filter; ?>" 
                                           class="action-btn btn-cancel"
                                           onclick="return confirm('Cancel this appointment?')">
                                            <i class="fas fa-times"></i> Cancel
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="print_ticket.php?id=<?php echo $apt['appointment_id']; ?>" 
                                       class="print-ticket" target="_blank">
                                        <i class="fas fa-print"></i> Print
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr class="empty-row">
                            <td colspan="9">
                                <i class="fas fa-calendar-times" style="font-size: 2rem; display: block; margin-bottom: 0.5rem;"></i>
                                No appointments found for this date.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
