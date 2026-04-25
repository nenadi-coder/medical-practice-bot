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
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return $response && strpos($response, 'OK:') === 0;
}

// Handle print ticket request FIRST
if (isset($_GET['print_id'])) {
    $print_id = (int)$_GET['print_id'];
    $print_stmt = $pdo->prepare("SELECT a.*, p.first_name, p.last_name, p.phone, CONCAT(d.first_name, ' ', d.last_name) as doctor_name 
                                 FROM appointments a 
                                 JOIN patients p ON a.patient_id = p.patient_id 
                                 JOIN doctors d ON a.doctor_id = d.doctor_id 
                                 WHERE a.appointment_id = ?");
    $print_stmt->execute([$print_id]);
    $print_apt = $print_stmt->fetch();
    
    if ($print_apt):
?><!DOCTYPE html>
<html>
<head>
    <title>Ticket #<?php echo $print_apt['queue_number']; ?></title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40mm 20mm; background: white; }
        @media print { body { margin: 0; } .no-print { display: none; } }
        .ticket { max-width: 300px; margin: 0 auto; padding: 20px; border: 2px dashed #4f46e5; border-radius: 15px; text-align: center; }
        .header { border-bottom: 2px solid #4f46e5; padding-bottom: 15px; margin-bottom: 15px; }
        .header h1 { color: #4f46e5; margin: 0; font-size: 24px; }
        .queue-number { font-size: 48px; font-weight: bold; color: #4f46e5; margin: 20px 0; }
        .details { text-align: left; margin: 20px 0; }
        .details p { margin: 8px 0; }
        .footer { border-top: 1px solid #ddd; padding-top: 15px; margin-top: 15px; font-size: 10px; color: #999; }
        .button { background: #4f46e5; color: white; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; margin-top: 15px; }
    </style>
</head>
<body>
    <div class="ticket">
        <div class="header"><h1>🏥 Shifa Medical Center</h1><p>Appointment Ticket</p></div>
        <div class="queue-number">#<?php echo $print_apt['queue_number']; ?></div>
        <div class="details">
            <p><strong>📅 Date:</strong> <?php echo date('l, F j, Y', strtotime($print_apt['appointment_date'])); ?></p>
            <p><strong>⏰ Time:</strong> <?php echo date('g:i A', strtotime($print_apt['appointment_time'])); ?></p>
            <p><strong>👤 Patient:</strong> <?php echo htmlspecialchars($print_apt['first_name'] . ' ' . $print_apt['last_name']); ?></p>
            <p><strong>📞 Phone:</strong> <?php echo htmlspecialchars($print_apt['phone']); ?></p>
            <p><strong>👨‍⚕️ Doctor:</strong> Dr. <?php echo htmlspecialchars($print_apt['doctor_name']); ?></p>
        </div>
        <div class="footer">Please arrive 10 minutes early.<br>Thank you for choosing Shifa Medical Center</div>
    </div>
    <div class="no-print" style="text-align:center; margin-top:20px;">
        <button onclick="window.print();" class="button"><i class="fas fa-print"></i> Print</button>
        <button onclick="window.close();" class="button" style="background:#6c757d; margin-left:10px;">Close</button>
    </div>
    <script>setTimeout(function(){ window.print(); }, 500);</script>
</body>
</html><?php
    exit();
    endif;
}

// Check if nurse is logged in
if (!isset($_SESSION['nurse_id'])) {
    header('Location: login.php');
    exit();
}

$nurse_name = $_SESSION['nurse_name'] ?? 'Nurse';
$today = date('Y-m-d');

// Handle status updates
if (isset($_GET['action']) && isset($_GET['id'])) {
    $appointment_id = (int)$_GET['id'];
    $action = $_GET['action'];
    $nurse_id = $_SESSION['nurse_id'];
    $filter = $_GET['filter'] ?? 'today';
    
    $new_status = '';
    switch($action) {
        case 'confirm': $new_status = 'confirmed'; break;
        case 'cancel': $new_status = 'cancelled'; break;
        case 'noshow': $new_status = 'no-show'; break;
        case 'complete': $new_status = 'completed'; break;
    }
    
    if ($new_status) {
        $update_stmt = $pdo->prepare("UPDATE appointments SET status = ?, nurse_id = ? WHERE appointment_id = ?");
        if ($update_stmt->execute([$new_status, $nurse_id, $appointment_id])) {
            if ($action == 'confirm') {
                $apt_stmt = $pdo->prepare("SELECT a.send_sms, p.phone, p.first_name, a.appointment_date, a.appointment_time, a.queue_number, CONCAT(d.first_name, ' ', d.last_name) as doctor_name FROM appointments a JOIN patients p ON a.patient_id = p.patient_id JOIN doctors d ON a.doctor_id = d.doctor_id WHERE a.appointment_id = ?");
                $apt_stmt->execute([$appointment_id]);
                $apt = $apt_stmt->fetch();
                if ($apt && $apt['send_sms'] == 1 && $apt['phone']) {
                    $date = date('d/m', strtotime($apt['appointment_date']));
                    $time = date('H:i', strtotime($apt['appointment_time']));
                    sendSMS($apt['phone'], "✅ Appt confirmed: $date $time with Dr. {$apt['doctor_name']}. Queue #{$apt['queue_number']}");
                }
            }
            $_SESSION['success'] = ucfirst($action) . ' completed';
        } else {
            $_SESSION['error'] = 'Update failed';
        }
    }
    header("Location: dashboard.php?filter=" . urlencode($filter));
    exit();
}

// Get filter
$filter = $_GET['filter'] ?? 'today';

// Build query
if ($filter == 'today') {
    $sql = "SELECT a.*, a.send_sms, CONCAT(p.first_name, ' ', p.last_name) as patient_name, p.phone as patient_phone, CONCAT(d.first_name, ' ', d.last_name) as doctor_name FROM appointments a JOIN patients p ON a.patient_id = p.patient_id JOIN doctors d ON a.doctor_id = d.doctor_id WHERE a.appointment_date = CURDATE() ORDER BY a.appointment_time ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
} elseif ($filter == 'tomorrow') {
    $sql = "SELECT a.*, a.send_sms, CONCAT(p.first_name, ' ', p.last_name) as patient_name, p.phone as patient_phone, CONCAT(d.first_name, ' ', d.last_name) as doctor_name FROM appointments a JOIN patients p ON a.patient_id = p.patient_id JOIN doctors d ON a.doctor_id = d.doctor_id WHERE a.appointment_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY) ORDER BY a.appointment_time ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
} elseif ($filter == 'telegram') {
    $sql = "SELECT a.*, a.send_sms, CONCAT(p.first_name, ' ', p.last_name) as patient_name, p.phone as patient_phone, CONCAT(d.first_name, ' ', d.last_name) as doctor_name FROM appointments a JOIN patients p ON a.patient_id = p.patient_id JOIN doctors d ON a.doctor_id = d.doctor_id WHERE a.status IN ('scheduled', 'confirmed') ORDER BY a.appointment_date ASC, a.appointment_time ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
} else {
    $sql = "SELECT a.*, a.send_sms, CONCAT(p.first_name, ' ', p.last_name) as patient_name, p.phone as patient_phone, CONCAT(d.first_name, ' ', d.last_name) as doctor_name FROM appointments a JOIN patients p ON a.patient_id = p.patient_id JOIN doctors d ON a.doctor_id = d.doctor_id ORDER BY a.appointment_date DESC, a.appointment_time DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
}
$appointments = $stmt->fetchAll();

// Stats
$stats_stmt = $pdo->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) as scheduled, SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed, SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed, SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled, SUM(CASE WHEN status = 'no-show' THEN 1 ELSE 0 END) as noshow FROM appointments WHERE appointment_date = CURDATE()");
$stats_stmt->execute();
$stats = $stats_stmt->fetch() ?: ['total' => 0, 'scheduled' => 0, 'confirmed' => 0, 'completed' => 0, 'cancelled' => 0, 'noshow' => 0];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nurse Dashboard - Shifa Medical Center</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
        .navbar { background: white; padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .logo { font-size: 1.5rem; font-weight: 800; color: #4f46e5; }
        .user-info { display: flex; align-items: center; gap: 1rem; }
        .logout { background: #ef4444; color: white; padding: 0.5rem 1rem; border-radius: 8px; text-decoration: none; font-weight: 600; }
        .container { max-width: 1400px; margin: 2rem auto; padding: 0 1.5rem; }
        .message { padding: 1rem; border-radius: 10px; margin-bottom: 1.5rem; }
        .success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
        .error { background: #f8d7da; color: #721c24; border-left: 4px solid #dc3545; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
        .stat-card { background: white; padding: 1.2rem; border-radius: 15px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .stat-number { font-size: 2rem; font-weight: 800; color: #4f46e5; }
        .stat-label { color: #64748b; font-size: 0.8rem; margin-top: 0.3rem; }
        .filter-bar { background: white; padding: 1rem; border-radius: 15px; margin-bottom: 1.5rem; display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; }
        .filter-btn { padding: 0.5rem 1.2rem; border-radius: 25px; text-decoration: none; background: #f1f5f9; color: #1e2a3e; font-weight: 600; font-size: 0.85rem; transition: all 0.2s; }
        .filter-btn.active, .filter-btn:hover { background: #4f46e5; color: white; }
        .filter-info { margin-left: auto; font-size: 0.85rem; color: #64748b; }
        .table-container { background: white; border-radius: 15px; overflow-x: auto; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        table { width: 100%; border-collapse: collapse; min-width: 800px; }
        th { background: #4f46e5; color: white; padding: 1rem; text-align: left; font-weight: 600; font-size: 0.85rem; }
        td { padding: 1rem; border-bottom: 1px solid #e2e8f0; font-size: 0.85rem; }
        tr:hover { background: #f8fafc; }
        .status-badge { display: inline-block; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.7rem; font-weight: 700; }
        .status-scheduled { background: #dbeafe; color: #1e40af; }
        .status-confirmed { background: #dcfce7; color: #166534; }
        .status-completed { background: #f3e8ff; color: #6b21a5; }
        .status-cancelled { background: #fee2e2; color: #991b1b; }
        .status-no-show { background: #fff3e0; color: #e65100; }
        .action-btn { display: inline-block; padding: 0.3rem 0.7rem; margin: 0.2rem; border-radius: 20px; text-decoration: none; font-size: 0.7rem; font-weight: 600; transition: all 0.2s; }
        .btn-confirm { background: #22c55e; color: white; }
        .btn-complete { background: #8b5cf6; color: white; }
        .btn-cancel { background: #ef4444; color: white; }
        .btn-noshow { background: #f97316; color: white; }
        .btn-print { background: #64748b; color: white; }
        .action-btn:hover { transform: translateY(-1px); filter: brightness(0.9); }
        .sms-badge { display: inline-block; padding: 0.2rem 0.5rem; border-radius: 15px; font-size: 0.65rem; font-weight: 600; }
        .sms-yes { background: #dcfce7; color: #166534; }
        .sms-no { background: #fee2e2; color: #991b1b; }
        .queue-num { font-weight: 700; color: #4f46e5; font-size: 1rem; }
        .telegram-icon { color: #0088cc; margin-left: 5px; }
        .empty-row td { text-align: center; padding: 3rem; color: #94a3b8; }
        @media (max-width: 768px) { .filter-info { margin-left: 0; width: 100%; text-align: center; } }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="logo"><i class="fas fa-heartbeat"></i> Shifa Medical Center</div>
        <div class="user-info">
            <span>Welcome, <?php echo htmlspecialchars($nurse_name); ?></span>
            <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </nav>
    
    <div class="container">
        <?php if (isset($_SESSION['success'])): ?>
            <div class="message success">✅ <?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            <div class="message error">❌ <?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
        <?php endif; ?>
        
        <div class="stats-grid">
            <div class="stat-card"><div class="stat-number"><?php echo $stats['total']; ?></div><div class="stat-label">Today's Total</div></div>
            <div class="stat-card"><div class="stat-number"><?php echo $stats['scheduled']; ?></div><div class="stat-label">Scheduled</div></div>
            <div class="stat-card"><div class="stat-number"><?php echo $stats['confirmed']; ?></div><div class="stat-label">Confirmed</div></div>
            <div class="stat-card"><div class="stat-number"><?php echo $stats['completed']; ?></div><div class="stat-label">Completed</div></div>
            <div class="stat-card"><div class="stat-number"><?php echo $stats['cancelled']; ?></div><div class="stat-label">Cancelled</div></div>
        </div>
        
        <div class="filter-bar">
            <a href="?filter=today" class="filter-btn <?php echo $filter == 'today' ? 'active' : ''; ?>"><i class="fas fa-calendar-day"></i> Today</a>
            <a href="?filter=tomorrow" class="filter-btn <?php echo $filter == 'tomorrow' ? 'active' : ''; ?>"><i class="fas fa-calendar-plus"></i> Tomorrow</a>
            <a href="?filter=telegram" class="filter-btn <?php echo $filter == 'telegram' ? 'active' : ''; ?>"><i class="fab fa-telegram"></i> Telegram Bookings</a>
            <a href="?filter=all" class="filter-btn <?php echo $filter == 'all' ? 'active' : ''; ?>"><i class="fas fa-list"></i> All Appointments</a>
            <div class="filter-info"><i class="fas fa-eye"></i> Showing <?php echo count($appointments); ?> appointments</div>
        </div>
        
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Patient</th>
                        <th>Phone</th>
                        <th>Doctor</th>
                        <th>Queue</th>
                        <th>Status</th>
                        <th>SMS</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($appointments) > 0): ?>
                        <?php foreach($appointments as $apt): ?>
                            <tr>
                                <td><?php echo date('g:i A', strtotime($apt['appointment_time'])); ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($apt['patient_name']); ?></strong>
                                    <?php if (($apt['send_sms'] ?? 0) == 1): ?>
                                        <i class="fab fa-telegram telegram-icon" title="Telegram Booking"></i>
                                    <?php endif; ?>
                                 </td>
                                <td><?php echo htmlspecialchars($apt['patient_phone'] ?? 'N/A'); ?></td>
                                <td>Dr. <?php echo htmlspecialchars($apt['doctor_name']); ?></td>
                                <td><span class="queue-num">#<?php echo $apt['queue_number']; ?></span></td>
                                <td><span class="status-badge status-<?php echo $apt['status']; ?>"><?php echo ucfirst($apt['status']); ?></span></td>
                                <td><span class="sms-badge <?php echo ($apt['send_sms'] ?? 0) == 1 ? 'sms-yes' : 'sms-no'; ?>"><?php echo ($apt['send_sms'] ?? 0) == 1 ? 'Yes' : 'No'; ?></span></td>
                                <td>
                                    <?php if ($apt['status'] == 'scheduled'): ?>
                                        <a href="?action=confirm&id=<?php echo $apt['appointment_id']; ?>&filter=<?php echo urlencode($filter); ?>" class="action-btn btn-confirm" onclick="return confirm('Confirm?')">✓ Confirm</a>
                                        <a href="?action=cancel&id=<?php echo $apt['appointment_id']; ?>&filter=<?php echo urlencode($filter); ?>" class="action-btn btn-cancel" onclick="return confirm('Cancel?')">✗ Cancel</a>
                                        <a href="?action=noshow&id=<?php echo $apt['appointment_id']; ?>&filter=<?php echo urlencode($filter); ?>" class="action-btn btn-noshow" onclick="return confirm('No Show?')">⚠ No Show</a>
                                    <?php endif; ?>
                                    <?php if ($apt['status'] == 'confirmed'): ?>
                                        <a href="?action=complete&id=<?php echo $apt['appointment_id']; ?>&filter=<?php echo urlencode($filter); ?>" class="action-btn btn-complete" onclick="return confirm('Complete?')">✔ Complete</a>
                                        <a href="?action=noshow&id=<?php echo $apt['appointment_id']; ?>&filter=<?php echo urlencode($filter); ?>" class="action-btn btn-noshow" onclick="return confirm('No Show?')">⚠ No Show</a>
                                        <a href="?action=cancel&id=<?php echo $apt['appointment_id']; ?>&filter=<?php echo urlencode($filter); ?>" class="action-btn btn-cancel" onclick="return confirm('Cancel?')">✗ Cancel</a>
                                    <?php endif; ?>
                                    <a href="?print_id=<?php echo $apt['appointment_id']; ?>" class="action-btn btn-print" target="_blank">🖨 Print</a>
                                 </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr class="empty-row"><td colspan="8">No appointments found</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
