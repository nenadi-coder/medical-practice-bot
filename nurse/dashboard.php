 nurse dashboard <?php
session_start();
require_once '../includes/config.php';

// ========== SMS FUNCTION - Using Environment Variables ==========
define('EASYSENDSMS_USERNAME', getenv('SMS_USERNAME') ?: '');
define('EASYSENDSMS_API_KEY', getenv('SMS_API_KEY') ?: '');

// FALLBACK - SET THESE IF ENV VARS NOT AVAILABLE
// define('EASYSENDSMS_USERNAME', 'nadoouamhou6s2026');
// define('EASYSENDSMS_API_KEY', 'IBxpv37c');

function sendSMS($phoneNumber, $message) {
    $username = EASYSENDSMS_USERNAME;
    $apiKey = EASYSENDSMS_API_KEY;
    
    if (empty($username) || empty($apiKey)) {
        error_log("SMS credentials not configured");
        return false;
    }
    
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

// CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Check if nurse is logged in
if (!isset($_SESSION['nurse_id'])) {
    header('Location: login.php');
    exit();
}

$today = date('Y-m-d');

// Handle status updates - WITH CSRF PROTECTION
if (isset($_GET['action']) && isset($_GET['id'])) {
    // Verify CSRF token for GET actions (using token in URL)
    $token_valid = isset($_GET['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_GET['csrf_token']);
    
    if (!$token_valid) {
        $_SESSION['error'] = 'Invalid security token';
        header('Location: dashboard.php?filter=' . urlencode($_GET['filter'] ?? 'today'));
        exit();
    }
    
    $appointment_id = (int)$_GET['id'];
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

if ($filter == 'all') {
    $sql = "SELECT a.*, a.send_sms,
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
    $sql = "SELECT a.*, a.send_sms,
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
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="message error">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>
        
        <div class="stats-grid">
            <div class="stat-card"><div class="stat-number"><?php echo htmlspecialchars($stats['total']); ?></div><div class="stat-label"><i class="fas fa-calendar-day"></i> Total Today</div></div>
            <div class="stat-card"><div class="stat-number"><?php echo htmlspecialchars($stats['scheduled']); ?></div><div class="stat-label"><i class="fas fa-clock"></i> Scheduled</div></div>
            <div class="stat-card"><div class="stat-number"><?php echo htmlspecialchars($stats['confirmed']); ?></div><div class="stat-label"><i class="fas fa-check-circle"></i> Confirmed</div></div>
            <div class="stat-card"><div class="stat-number"><?php echo htmlspecialchars($stats['completed']); ?></div><div class="stat-label"><i class="fas fa-flag-checkered"></i> Completed</div></div>
            <div class="stat-card"><div class="stat-number"><?php echo htmlspecialchars($stats['cancelled']); ?></div><div class="stat-label"><i class="fas fa-ban"></i> Cancelled</div></div>
        </div>
        
        <div class="filter-bar">
            <a href="?filter=today&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" class="filter-btn <?php echo $filter == 'today' ? 'active' : ''; ?>"><i class="fas fa-calendar-day"></i> Today</a>
            <a href="?filter=tomorrow&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" class="filter-btn <?php echo $filter == 'tomorrow' ? 'active' : ''; ?>"><i class="fas fa-calendar-plus"></i> Tomorrow</a>
            <a href="?filter=all&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" class="filter-btn <?php echo $filter == 'all' ? 'active' : ''; ?>"><i class="fas fa-list"></i> All Appointments</a>
            <span class="filter-info"><i class="fas fa-eye"></i> Showing: <strong><?php if ($filter == 'today') echo date('F j, Y'); elseif ($filter == 'tomorrow') echo date('F j, Y', strtotime('+1 day')); else echo 'All dates'; ?></strong></span>
        </div>
        
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
                            <td><span class="queue-number">#<?php echo htmlspecialchars($apt['queue_number']); ?></span></td>
                            <td><span class="status-badge status-<?php echo $apt['status']; ?>"><i class="fas <?php echo $apt['status'] == 'scheduled' ? 'fa-clock' : ($apt['status'] == 'confirmed' ? 'fa-check-circle' : ($apt['status'] == 'completed' ? 'fa-flag-checkered' : ($apt['status'] == 'cancelled' ? 'fa-ban' : 'fa-user-slash'))); ?>"></i> <?php echo ucfirst($apt['status']); ?></span></td>
                            <td><?php if (isset($apt['send_sms']) && $apt['send_sms'] == 1): ?><span class="sms-badge sms-yes"><i class="fas fa-check"></i> Yes</span><?php else: ?><span class="sms-badge sms-no"><i class="fas fa-times"></i> No</span><?php endif; ?></td>
                            <td>
                                <?php if ($apt['status'] == 'scheduled'): ?>
                                    <a href="?action=confirm&id=<?php echo $apt['appointment_id']; ?>&filter=<?php echo $filter; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" class="action-btn btn-confirm" onclick="return confirm('Confirm this appointment? SMS will be sent if patient requested.');"><i class="fas fa-check"></i> Confirm</a>
                                    <a href="?action=cancel&id=<?php echo $apt['appointment_id']; ?>&filter=<?php echo $filter; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" class="action-btn btn-cancel" onclick="return confirm('Cancel this appointment?')"><i class="fas fa-times"></i> Cancel</a>
                                    <a href="?action=noshow&id=<?php echo $apt['appointment_id']; ?>&filter=<?php echo $filter; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" class="action-btn btn-noshow" onclick="return confirm('Mark patient as no-show?')"><i class="fas fa-user-slash"></i> No Show</a>
                                <?php endif; ?>
                                <?php if ($apt['status'] == 'confirmed'): ?>
                                    <a href="?action=complete&id=<?php echo $apt['appointment_id']; ?>&filter=<?php echo $filter; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" class="action-btn btn-complete" onclick="return confirm('Mark as completed?')"><i class="fas fa-check-double"></i> Complete</a>
                                    <a href="?action=noshow&id=<?php echo $apt['appointment_id']; ?>&filter=<?php echo $filter; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" class="action-btn btn-noshow" onclick="return confirm('Mark patient as no-show?')"><i class="fas fa-user-slash"></i> No Show</a>
                                    <a href="?action=cancel&id=<?php echo $apt['appointment_id']; ?>&filter=<?php echo $filter; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" class="action-btn btn-cancel" onclick="return confirm('Cancel this appointment?')"><i class="fas fa-times"></i> Cancel</a>
                                <?php endif; ?>
                                <a href="print_ticket.php?id=<?php echo $apt['appointment_id']; ?>" class="print-ticket" target="_blank"><i class="fas fa-print"></i> Print</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr class="empty-row"><td colspan="9"><i class="fas fa-calendar-times" style="font-size: 2rem; display: block; margin-bottom: 0.5rem;"></i>No appointments found for this date.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>




















login..><?php
require_once '../includes/config.php';

if (isset($_SESSION['patient_id'])) {
    header('Location: dashboard.php');
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    
    if (empty($email) || empty($password)) {
        $error = "Email and password are required";
    } else {
        // SIMPLE VERSION - direct password comparison (preserved your original logic)
        $sql = "SELECT patient_id, first_name, last_name, email, password FROM patients WHERE email = ? AND password = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$email, $password]);
        
        if ($patient = $stmt->fetch()) {
            // Login successful
            $_SESSION['patient_id'] = $patient['patient_id'];
            $_SESSION['patient_name'] = $patient['first_name'] . ' ' . $patient['last_name'];
            $_SESSION['user_type'] = 'patient';
            
            header('Location: dashboard.php');
            exit();
        } else {
            $error = "Invalid email or password";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Patient Login - Medical Practice</title>
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
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 1.5rem;
            position: relative;
        }

        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: radial-gradient(circle at 20% 80%, rgba(102, 126, 234, 0.05) 0%, transparent 50%);
            pointer-events: none;
        }

        .login-card {
            background: white;
            border-radius: 2rem;
            box-shadow: 0 25px 45px -12px rgba(0, 0, 0, 0.15);
            width: 100%;
            max-width: 460px;
            padding: 2.2rem 2rem;
            border: 1px solid rgba(102, 126, 234, 0.15);
            position: relative;
            z-index: 2;
        }

        .portal-badge {
            display: inline-block;
            background: #eef2ff;
            padding: 0.4rem 1rem;
            border-radius: 40px;
            font-size: 0.75rem;
            font-weight: 700;
            color: #4f46e5;
            margin-bottom: 1.2rem;
        }

        .login-card h2 {
            font-size: 2rem;
            font-weight: 800;
            background: linear-gradient(120deg, #1e2a3e, #2d3a5e);
            background-clip: text;
            -webkit-background-clip: text;
            color: transparent;
            margin-bottom: 0.5rem;
        }

        .welcome-text {
            color: #5b6e8c;
            font-size: 0.9rem;
            margin-bottom: 1.8rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
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

        input {
            width: 100%;
            padding: 0.85rem 1rem 0.85rem 2.8rem;
            border: 1.5px solid #e2e8f0;
            border-radius: 1rem;
            font-size: 0.95rem;
            font-family: 'Inter', sans-serif;
            transition: all 0.2s ease;
            background: #fefefe;
            outline: none;
        }

        input:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.15);
        }

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

        .login-btn {
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

        .login-btn:hover {
            background: linear-gradient(95deg, #4338ca, #6d28d9);
            transform: translateY(-2px);
        }

        .register-link {
            text-align: center;
            margin-top: 1.5rem;
            font-size: 0.9rem;
            color: #4a5b6e;
        }

        .register-link a {
            color: #4f46e5;
            text-decoration: none;
            font-weight: 700;
        }

        .register-link a:hover {
            text-decoration: underline;
        }

        .back-home {
            text-align: center;
            margin-top: 1.5rem;
            padding-top: 1.2rem;
            border-top: 1px solid #edf2f7;
        }

        .back-home-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #f1f5f9;
            padding: 0.6rem 1.4rem;
            border-radius: 60px;
            color: #2d3a5e;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.85rem;
            transition: all 0.2s ease;
        }

        .back-home-btn:hover {
            background: #e6edf6;
            color: #4f46e5;
        }

        .secure-note {
            text-align: center;
            margin-top: 1.2rem;
            font-size: 0.7rem;
            color: #94a3b8;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        @media (max-width: 560px) {
            .login-card {
                padding: 1.8rem 1.4rem;
            }
            .login-card h2 {
                font-size: 1.6rem;
            }
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="portal-badge">
            <i class="fas fa-user-circle" style="margin-right: 6px;"></i> Secure Access
        </div>
        
        <h2>Patient Login</h2>
        <div class="welcome-text">
            Welcome back! Please sign in to access your health portal.
        </div>

        <?php if ($error): ?>
            <div class="error">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="form-group">
                <label>Email Address</label>
                <div class="input-wrapper">
                    <i class="fas fa-envelope"></i>
                    <input type="email" name="email" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" required>
                </div>
            </div>
            
            <div class="form-group">
                <label>Password</label>
                <div class="input-wrapper">
                    <i class="fas fa-key"></i>
                    <input type="password" name="password" required>
                </div>
            </div>
            
            <button type="submit" class="login-btn">
                <i class="fas fa-arrow-right-to-bracket"></i> Sign In
            </button>
        </form>
        
        <div class="register-link">
            <i class="fas fa-plus-circle"></i> New patient? <a href="register.php">Create an account</a>
        </div>
        
        <div class="back-home">
            <a href="https://medicalpractice.free.nf/medical_practice/index.php" class="back-home-btn">
                <i class="fas fa-arrow-left"></i> Back to Home
            </a>
        </div>
        
        
    </div>
</body>
</html>dashboard...><?php
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
                <i class="fas fa-check-circle"></i> <?php echo $success; ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="message error">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
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
                                <?php echo ucfirst($appointment['status']); ?>
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
                                    <div class="detail-item"><i class="fas fa-ticket-alt"></i> Queue #: <strong><?php echo $appointment['queue_number']; ?></strong></div>
                                    <div class="queue-position">
                                        📊 Position: <?php echo $people_ahead + 1; ?> of <?php echo $total_waiting; ?> waiting
                                    </div>
                                    <div class="detail-item"><i class="fas fa-users"></i> People ahead: <?php echo $people_ahead; ?></div>
                                    
                                    <?php if ($people_ahead > 0): ?>
                                        <div class="wait-time">
                                            <i class="fas fa-hourglass-half"></i> Estimated wait: ~<?php echo $people_ahead * 15; ?> minutes
                                            <br><small>(based on 15 min per patient)</small>
                                        </div>
                                    <?php else: ?>
                                        <div class="next-message">
                                            <i class="fas fa-bell"></i> You're NEXT! Please be ready when called.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <?php if ($appointment['queue_number']): ?>
                                    <div class="detail-item"><i class="fas fa-ticket-alt"></i> Queue #: <?php echo $appointment['queue_number']; ?></div>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <?php if ($appointment['notes']): ?>
                                <div class="detail-item"><i class="fas fa-pencil-alt"></i> Notes: <?php echo htmlspecialchars($appointment['notes']); ?></div>
                            <?php endif; ?>
                            
                            <div class="detail-item"><i class="fas fa-calendar-plus"></i> Booked on: <?php echo date('F j, Y', strtotime($appointment['created_at'])); ?></div>
                        </div>
                        
                        <div class="appointment-actions">
                            <?php if ($appointment['status'] == 'scheduled' || $appointment['status'] == 'confirmed'): ?>
                                <a href="?cancel_id=<?php echo $appointment['appointment_id']; ?>" 
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
        // Cancel confirmation is handled inline with onclick, but adding extra safety
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




















config...><?php
session_start();

$host = 'sql207.infinityfree.com';
$dbname = 'if0_41555171_medical_practice';
$username = 'if0_41555171';
$password = 'fkwDocFNbnScb0';

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8",
        $username,
        $password
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

/* ================================
   TELEGRAM LINKING PATCH (FIXED)
   ================================ */
if (isset($_GET['telegram_user_id']) && isset($_GET['patient_id'])) {

    $telegram_user_id = $_GET['telegram_user_id'];
    $patient_id = $_GET['patient_id'];

    $stmt = $pdo->prepare("
        UPDATE patients 
        SET telegram_user_id = ? 
        WHERE patient_id = ?
    ");

    $stmt->execute([$telegram_user_id, $patient_id]);
}

?> index.js..>export default {
    async fetch(request, env) {
        const url = new URL(request.url);
        
        // ========== TELEGRAM WEBHOOK ==========
        if (url.pathname === '/webhook' && request.method === 'POST') {
            try {
                const update = await request.json();
                await handleTelegramUpdate(update, env);
                return new Response('OK', { status: 200 });
            } catch (error) {
                return new Response('OK', { status: 200 });
            }
        }
        
        // ========== API FOR WEBSITE REGISTRATION ==========
        if (url.pathname === '/api/register' && request.method === 'POST') {
            try {
                const body = await request.json();
                const result = await forwardToInfinity('register', body, env);
                return new Response(JSON.stringify(result), {
                    status: result.success ? 200 : 400,
                    headers: { 'Content-Type': 'application/json' }
                });
            } catch (error) {
                return new Response(JSON.stringify({ success: false, error: error.message }), {
                    status: 500,
                    headers: { 'Content-Type': 'application/json' }
                });
            }
        }
        
        // ========== API FOR WEBSITE LOGIN ==========
        if (url.pathname === '/api/login' && request.method === 'POST') {
            try {
                const body = await request.json();
                const result = await forwardToInfinity('login', body, env);
                return new Response(JSON.stringify(result), {
                    status: result.success ? 200 : 401,
                    headers: { 'Content-Type': 'application/json' }
                });
            } catch (error) {
                return new Response(JSON.stringify({ success: false, error: error.message }), {
                    status: 500,
                    headers: { 'Content-Type': 'application/json' }
                });
            }
        }
        
        // ========== HEALTH CHECK ==========
        if (url.pathname === '/health') {
            return new Response('OK', { status: 200 });
        }
        
        return new Response('Bot is running', { status: 200 });
    }
};

// Forward request to your InfinityFree API
async function forwardToInfinity(action, data, env) {
    const apiUrl = `https://${env.SITE_DOMAIN}/api.php`;
    
    const response = await fetch(apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: action,
            key: env.API_SECRET,
            ...data
        })
    });
    
    return await response.json();
}

// ========== TELEGRAM BOT HANDLER (YOUR EXISTING CODE) ==========
// PASTE YOUR EXISTING TELEGRAM BOT CODE HERE
// Keep your working bot logic

async function handleTelegramUpdate(update, env) {
    // YOUR EXISTING TELEGRAM BOT CODE
    if (update.message) {
        const chatId = update.message.chat.id;
        const text = update.message.text;
        
        if (text === '/start') {
            await sendMessage(chatId, '👋 Welcome! Send your email.', env);
        } else if (text && text.includes('@')) {
            await sendMessage(chatId, `✅ Email received: ${text}`, env);
        }
    }
}

async function sendMessage(chatId, text, env) {
    const url = `https://api.telegram.org/bot${env.BOT_TOKEN}/sendMessage`;
    await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ chat_id: chatId, text: text })
    });
}   
