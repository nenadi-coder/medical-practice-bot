<?php
session_start(); // IMPORTANT - MISSING BEFORE
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
// ========== END SMS FUNCTION ==========

// Check if nurse is logged in
if (!isset($_SESSION['nurse_id'])) {
    header('Location: login.php');
    exit();
}

$today = date('Y-m-d');

// Handle status updates with SMS
if (isset($_GET['action']) && isset($_GET['id'])) {
    $appointment_id = $_GET['id'];
    $action = $_GET['action'];
    $nurse_id = $_SESSION['nurse_id'];
    
    $new_status = '';
    $message = '';
    
    switch($action) {
        case 'confirm':
            $new_status = 'confirmed';
            $message = 'Appointment confirmed';
            
            // Get patient info for SMS
            $apt_sql = "SELECT a.send_sms, p.phone, p.first_name, a.appointment_date, a.appointment_time, a.queue_number,
                               d.last_name as doctor_last
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
                sendSMS($apt['phone'], $smsMessage);
                $message .= " + SMS sent";
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
        $update_stmt->execute([$new_status, $nurse_id, $appointment_id]);
        $_SESSION['success'] = $message;
    }
    
    header('Location: dashboard.php?filter=' . urlencode($_GET['filter'] ?? 'today'));
    exit();
}

$filter = isset($_GET['filter']) ? $_GET['filter'] : 'today';

if ($filter == 'today') {
    $sql = "SELECT a.*, CONCAT(p.first_name, ' ', p.last_name) as patient_name, p.phone as patient_phone, CONCAT(d.first_name, ' ', d.last_name) as doctor_name 
            FROM appointments a JOIN patients p ON a.patient_id = p.patient_id JOIN doctors d ON a.doctor_id = d.doctor_id 
            WHERE a.appointment_date = CURDATE() ORDER BY a.appointment_time ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
} elseif ($filter == 'tomorrow') {
    $sql = "SELECT a.*, CONCAT(p.first_name, ' ', p.last_name) as patient_name, p.phone as patient_phone, CONCAT(d.first_name, ' ', d.last_name) as doctor_name 
            FROM appointments a JOIN patients p ON a.patient_id = p.patient_id JOIN doctors d ON a.doctor_id = d.doctor_id 
            WHERE a.appointment_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY) ORDER BY a.appointment_time ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
} else {
    $sql = "SELECT a.*, CONCAT(p.first_name, ' ', p.last_name) as patient_name, p.phone as patient_phone, CONCAT(d.first_name, ' ', d.last_name) as doctor_name 
            FROM appointments a JOIN patients p ON a.patient_id = p.patient_id JOIN doctors d ON a.doctor_id = d.doctor_id 
            ORDER BY a.appointment_date DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
}
$appointments = $stmt->fetchAll();

$stats_sql = "SELECT COUNT(*) as total, SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) as scheduled, SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed FROM appointments WHERE appointment_date = CURDATE()";
$stats_stmt = $pdo->prepare($stats_sql);
$stats_stmt->execute();
$stats = $stats_stmt->fetch();
if (!$stats) $stats = ['total' => 0, 'scheduled' => 0, 'confirmed' => 0];
?>

<!DOCTYPE html>
<html>
<head>
    <title>Nurse Dashboard | Shifa Medical Center</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #f0f4f8; padding: 20px; }
        .container { max-width: 1300px; margin: auto; background: white; border-radius: 10px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h2 { color: #1e2a3e; margin-bottom: 20px; }
        .stats { display: flex; gap: 15px; margin-bottom: 20px; flex-wrap: wrap; }
        .stat-card { background: #f1f5f9; padding: 15px 25px; border-radius: 10px; text-align: center; }
        .stat-number { font-size: 28px; font-weight: bold; color: #4f46e5; }
        .filter-bar { margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap; }
        .filter-btn { padding: 8px 20px; border-radius: 25px; text-decoration: none; background: #e2e8f0; color: #333; transition: 0.2s; }
        .filter-btn.active { background: #4f46e5; color: white; }
        .filter-btn:hover { opacity: 0.8; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #4f46e5; color: white; }
        tr:hover { background: #f8fafc; }
        .status-badge { padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: bold; display: inline-block; }
        .status-scheduled { background: #dbeafe; color: #1e40af; }
        .status-confirmed { background: #dcfce7; color: #166534; }
        .status-completed { background: #f3e8ff; color: #6b21a5; }
        .status-cancelled { background: #fee2e2; color: #991b1b; }
        .status-no-show { background: #fff3e0; color: #e65100; }
        .btn { display: inline-block; padding: 5px 12px; margin: 2px; border-radius: 20px; text-decoration: none; font-size: 12px; font-weight: bold; border: none; cursor: pointer; transition: 0.2s; }
        .btn-confirm { background: #22c55e; color: white; }
        .btn-cancel { background: #ef4444; color: white; }
        .btn-complete { background: #8b5cf6; color: white; }
        .btn-noshow { background: #f97316; color: white; }
        .btn-print { background: #64748b; color: white; }
        .btn:hover { opacity: 0.8; transform: translateY(-1px); }
        .message { padding: 10px; border-radius: 8px; margin-bottom: 15px; }
        .success { background: #dcfce7; color: #166534; border-left: 4px solid #22c55e; }
        .error { background: #fee2e2; color: #991b1b; border-left: 4px solid #ef4444; }
        .queue-num { font-weight: bold; color: #4f46e5; font-size: 16px; }
        .logout { color: #ef4444; text-decoration: none; }
        .logout:hover { text-decoration: underline; }
        @media (max-width: 768px) { th, td { padding: 8px; font-size: 12px; } .btn { padding: 3px 8px; font-size: 10px; } }
    </style>
</head>
<body>
    <div class="container">
        <h2>🏥 Shifa Medical Center - Nurse Dashboard</h2>
        <p>Welcome, <?php echo htmlspecialchars($_SESSION['nurse_name']); ?> | <a href="logout.php" class="logout">Logout</a></p>
        
        <?php if (isset($_SESSION['success'])): ?>
            <div class="message success">✅ <?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="message error">❌ <?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
        <?php endif; ?>
        
        <div class="stats">
            <div class="stat-card"><div class="stat-number"><?php echo $stats['total']; ?></div><div>Today's Appointments</div></div>
            <div class="stat-card"><div class="stat-number"><?php echo $stats['scheduled']; ?></div><div>Scheduled</div></div>
            <div class="stat-card"><div class="stat-number"><?php echo $stats['confirmed']; ?></div><div>Confirmed</div></div>
        </div>
        
        <div class="filter-bar">
            <a href="?filter=today" class="filter-btn <?php echo $filter == 'today' ? 'active' : ''; ?>">📅 Today</a>
            <a href="?filter=tomorrow" class="filter-btn <?php echo $filter == 'tomorrow' ? 'active' : ''; ?>">📆 Tomorrow</a>
            <a href="?filter=all" class="filter-btn <?php echo $filter == 'all' ? 'active' : ''; ?>">📋 All Appointments</a>
        </div>
        
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
                            <td><?php echo date('g:i A', strtotime($apt['appointment_time'])); ?></td>
                            <td><strong><?php echo htmlspecialchars($apt['patient_name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($apt['patient_phone']); ?></td>
                            <td>Dr. <?php echo htmlspecialchars($apt['doctor_name']); ?></td>
                            <td class="queue-num">#<?php echo $apt['queue_number']; ?></td>
                            <td><span class="status-badge status-<?php echo $apt['status']; ?>"><?php echo ucfirst($apt['status']); ?></span></td>
                            <td>
                                <?php if ($apt['status'] == 'scheduled'): ?>
                                    <a href="?action=confirm&id=<?php echo $apt['appointment_id']; ?>&filter=<?php echo $filter; ?>" class="btn btn-confirm" onclick="return confirm('Confirm this appointment?')">✓ Confirm</a>
                                    <a href="?action=cancel&id=<?php echo $apt['appointment_id']; ?>&filter=<?php echo $filter; ?>" class="btn btn-cancel" onclick="return confirm('Cancel this appointment?')">✗ Cancel</a>
                                    <a href="?action=noshow&id=<?php echo $apt['appointment_id']; ?>&filter=<?php echo $filter; ?>" class="btn btn-noshow" onclick="return confirm('Mark patient as no-show?')">⚠ No Show</a>
                                <?php endif; ?>
                                <?php if ($apt['status'] == 'confirmed'): ?>
                                    <a href="?action=complete&id=<?php echo $apt['appointment_id']; ?>&filter=<?php echo $filter; ?>" class="btn btn-complete" onclick="return confirm('Mark as completed?')">✔ Complete</a>
                                    <a href="?action=noshow&id=<?php echo $apt['appointment_id']; ?>&filter=<?php echo $filter; ?>" class="btn btn-noshow" onclick="return confirm('Mark patient as no-show?')">⚠ No Show</a>
                                    <a href="?action=cancel&id=<?php echo $apt['appointment_id']; ?>&filter=<?php echo $filter; ?>" class="btn btn-cancel" onclick="return confirm('Cancel this appointment?')">✗ Cancel</a>
                                <?php endif; ?>
                                <a href="print_ticket.php?id=<?php echo $apt['appointment_id']; ?>" class="btn btn-print" target="_blank">🖨 Print</a>
                            </div>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="7" style="text-align: center; padding: 40px;">📭 No appointments found</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</body>
</html>
