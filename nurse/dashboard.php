<?php
require_once '../includes/config.php';

session_start();

// Check if nurse is logged in
if (!isset($_SESSION['nurse_id'])) {
    header('Location: login.php');
    exit();
}

// Function to send Telegram notification to patient when appointment is confirmed
function sendTelegramNotification($patient_id, $appointment_id) {
    global $pdo;
    
    // Get patient's telegram_chat_id and appointment details
    $stmt = $pdo->prepare("
        SELECT p.telegram_chat_id, p.first_name, a.appointment_date, a.appointment_time, a.queue_number,
               CONCAT(d.first_name, ' ', d.last_name) as doctor_name
        FROM patients p
        JOIN appointments a ON p.patient_id = a.patient_id
        JOIN doctors d ON a.doctor_id = d.doctor_id
        WHERE a.appointment_id = ? AND p.telegram_chat_id IS NOT NULL
    ");
    $stmt->execute([$appointment_id]);
    $result = $stmt->fetch();
    
    if ($result && $result['telegram_chat_id']) {
        $bot_token = '8330456846:AAFJFM3cy7rbKr5diPbcYi8QaIDDIhktpVU';
        $date = date('l, F j, Y', strtotime($result['appointment_date']));
        $time = date('g:i A', strtotime($result['appointment_time']));
        
        $message = "✅ *Appointment Confirmed!*\n\n";
        $message .= "Dear {$result['first_name']}, your appointment has been confirmed.\n\n";
        $message .= "📅 Date: $date\n";
        $message .= "⏰ Time: $time\n";
        $message .= "👨‍⚕️ Doctor: Dr. {$result['doctor_name']}\n";
        $message .= "🎫 Queue #: {$result['queue_number']}\n\n";
        $message .= "_Please arrive 10 minutes early._";
        
        $url = "https://api.telegram.org/bot{$bot_token}/sendMessage";
        $data = ['chat_id' => $result['telegram_chat_id'], 'text' => $message, 'parse_mode' => 'Markdown'];
        
        $options = ['http' => ['header' => "Content-type: application/x-www-form-urlencoded\r\n", 'method' => 'POST', 'content' => http_build_query($data)]];
        $context = stream_context_create($options);
        @file_get_contents($url, false, $context);
    }
}

// Get filter
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'today';

// Handle actions
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
            // Send Telegram notification
            sendTelegramNotification(null, $appointment_id);
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
    
    header('Location: dashboard.php?filter=' . urlencode($filter));
    exit();
}

// Build query based on filter
if ($filter == 'today') {
    $sql = "SELECT a.*, 
            CONCAT(p.first_name, ' ', p.last_name) as patient_name,
            p.phone as patient_phone,
            p.telegram_chat_id,
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
            p.telegram_chat_id,
            CONCAT(d.first_name, ' ', d.last_name) as doctor_name
            FROM appointments a
            JOIN patients p ON a.patient_id = p.patient_id
            JOIN doctors d ON a.doctor_id = d.doctor_id
            WHERE a.appointment_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY)
            ORDER BY a.appointment_time ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
} else {
    $sql = "SELECT a.*, 
            CONCAT(p.first_name, ' ', p.last_name) as patient_name,
            p.phone as patient_phone,
            p.telegram_chat_id,
            CONCAT(d.first_name, ' ', d.last_name) as doctor_name
            FROM appointments a
            JOIN patients p ON a.patient_id = p.patient_id
            JOIN doctors d ON a.doctor_id = d.doctor_id
            ORDER BY a.appointment_date DESC, a.appointment_time ASC
            LIMIT 100";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
}
$appointments = $stmt->fetchAll();

// Get counts
$today_count = $pdo->query("SELECT COUNT(*) FROM appointments WHERE appointment_date = CURDATE()")->fetchColumn();
$tomorrow_count = $pdo->query("SELECT COUNT(*) FROM appointments WHERE appointment_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY)")->fetchColumn();
$scheduled_count = $pdo->query("SELECT COUNT(*) FROM appointments WHERE appointment_date = CURDATE() AND status = 'scheduled'")->fetchColumn();
$confirmed_count = $pdo->query("SELECT COUNT(*) FROM appointments WHERE appointment_date = CURDATE() AND status = 'confirmed'")->fetchColumn();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nurse Dashboard | Shifa Medical Center</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; background: #f0f4f8; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; }
        
        /* Header */
        .header { background: white; padding: 20px 30px; border-radius: 16px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .header h1 { font-size: 1.5rem; color: #1e2a3e; }
        .header h1 i { color: #4f46e5; margin-right: 10px; }
        .user-info { display: flex; align-items: center; gap: 15px; background: #f1f5f9; padding: 8px 20px; border-radius: 40px; }
        .logout { background: #ef4444; color: white; padding: 8px 16px; border-radius: 40px; text-decoration: none; font-size: 0.85rem; font-weight: 600; }
        .logout:hover { background: #dc2626; }
        
        /* Stats */
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .stat-card { background: white; padding: 20px; border-radius: 16px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .stat-number { font-size: 2rem; font-weight: 800; color: #4f46e5; }
        .stat-label { color: #64748b; font-size: 0.8rem; margin-top: 5px; }
        
        /* Filter Bar */
        .filter-bar { background: white; padding: 15px 20px; border-radius: 16px; margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .filter-btn { padding: 8px 20px; border-radius: 40px; text-decoration: none; background: #f1f5f9; color: #1e2a3e; font-weight: 600; font-size: 0.85rem; transition: all 0.2s; }
        .filter-btn.active { background: #4f46e5; color: white; }
        .filter-btn:hover:not(.active) { background: #e2e8f0; }
        .badge { background: #4f46e5; color: white; border-radius: 20px; padding: 2px 8px; font-size: 0.7rem; margin-left: 5px; }
        
        /* Table */
        .table-wrapper { background: white; border-radius: 16px; overflow-x: auto; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        table { width: 100%; border-collapse: collapse; min-width: 800px; }
        th { background: #4f46e5; color: white; padding: 14px; text-align: left; font-weight: 600; font-size: 0.85rem; }
        td { padding: 12px 14px; border-bottom: 1px solid #e2e8f0; font-size: 0.85rem; }
        tr:hover { background: #f8fafc; }
        
        .status-badge { display: inline-block; padding: 4px 12px; border-radius: 40px; font-size: 0.7rem; font-weight: 700; }
        .status-scheduled { background: #dbeafe; color: #1e40af; }
        .status-confirmed { background: #dcfce7; color: #166534; }
        .status-completed { background: #f3e8ff; color: #6b21a5; }
        .status-cancelled { background: #fee2e2; color: #991b1b; }
        
        .action-btn { display: inline-block; padding: 5px 12px; margin: 2px; border-radius: 40px; text-decoration: none; font-size: 0.7rem; font-weight: 600; transition: all 0.2s; }
        .btn-confirm { background: #22c55e; color: white; }
        .btn-cancel { background: #ef4444; color: white; }
        .btn-complete { background: #8b5cf6; color: white; }
        .action-btn:hover { transform: translateY(-1px); filter: brightness(0.9); }
        
        .queue-number { font-weight: 700; color: #4f46e5; font-size: 1rem; }
        .telegram-badge { background: #0088cc; color: white; padding: 2px 6px; border-radius: 20px; font-size: 0.6rem; display: inline-block; }
        .telegram-no { background: #cbd5e1; }
        
        .message { padding: 12px 20px; border-radius: 12px; margin-bottom: 20px; }
        .success { background: #dcfce7; color: #166534; border-left: 4px solid #22c55e; }
        .error { background: #fee2e2; color: #991b1b; border-left: 4px solid #ef4444; }
        
        .empty-row td { text-align: center; padding: 50px; color: #94a3b8; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-stethoscope"></i> Shifa Medical Center</h1>
            <div class="user-info">
                <i class="fas fa-user-nurse"></i>
                <span>Welcome, <?php echo htmlspecialchars($_SESSION['nurse_name']); ?></span>
                <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>
        
        <?php if (isset($_SESSION['success'])): ?>
            <div class="message success">✅ <?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
        <?php endif; ?>
        
        <div class="stats">
            <div class="stat-card"><div class="stat-number"><?php echo $today_count; ?></div><div class="stat-label">Today</div></div>
            <div class="stat-card"><div class="stat-number"><?php echo $tomorrow_count; ?></div><div class="stat-label">Tomorrow</div></div>
            <div class="stat-card"><div class="stat-number"><?php echo $scheduled_count; ?></div><div class="stat-label">Scheduled</div></div>
            <div class="stat-card"><div class="stat-number"><?php echo $confirmed_count; ?></div><div class="stat-label">Confirmed</div></div>
        </div>
        
        <div class="filter-bar">
            <a href="?filter=today" class="filter-btn <?php echo $filter == 'today' ? 'active' : ''; ?>">📅 Today <?php if($today_count > 0) echo "<span class='badge'>$today_count</span>"; ?></a>
            <a href="?filter=tomorrow" class="filter-btn <?php echo $filter == 'tomorrow' ? 'active' : ''; ?>">📆 Tomorrow <?php if($tomorrow_count > 0) echo "<span class='badge'>$tomorrow_count</span>"; ?></a>
            <a href="?filter=all" class="filter-btn <?php echo $filter == 'all' ? 'active' : ''; ?>">📋 All Appointments</a>
        </div>
        
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Patient Name</th>
                        <th>Phone</th>
                        <th>Telegram</th>
                        <th>Doctor</th>
                        <th>Queue</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($appointments) > 0): ?>
                        <?php foreach($appointments as $apt): ?>
                            <tr>
                                <td><?php echo date('M j, Y', strtotime($apt['appointment_date'])); ?></td>
                                <td><?php echo date('g:i A', strtotime($apt['appointment_time'])); ?></td>
                                <td><strong><?php echo htmlspecialchars($apt['patient_name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($apt['patient_phone']); ?></td>
                                <td>
                                    <?php if ($apt['telegram_chat_id']): ?>
                                        <span class="telegram-badge"><i class="fab fa-telegram"></i> Linked</span>
                                    <?php else: ?>
                                        <span class="telegram-badge telegram-no"><i class="fab fa-telegram"></i> Not linked</span>
                                    <?php endif; ?>
                                </td>
                                <td>Dr. <?php echo htmlspecialchars($apt['doctor_name']); ?></td>
                                <td><span class="queue-number">#<?php echo $apt['queue_number']; ?></span></td>
                                <td>
                                    <span class="status-badge status-<?php echo $apt['status']; ?>">
                                        <?php echo ucfirst($apt['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($apt['status'] == 'scheduled'): ?>
                                        <a href="?action=confirm&id=<?php echo $apt['appointment_id']; ?>&filter=<?php echo $filter; ?>" class="action-btn btn-confirm" onclick="return confirm('Confirm this appointment? Patient will receive Telegram notification.')">✅ Confirm</a>
                                        <a href="?action=cancel&id=<?php echo $apt['appointment_id']; ?>&filter=<?php echo $filter; ?>" class="action-btn btn-cancel" onclick="return confirm('Cancel this appointment?')">❌ Cancel</a>
                                    <?php endif; ?>
                                    <?php if ($apt['status'] == 'confirmed'): ?>
                                        <a href="?action=complete&id=<?php echo $apt['appointment_id']; ?>&filter=<?php echo $filter; ?>" class="action-btn btn-complete" onclick="return confirm('Mark as completed?')">✔️ Complete</a>
                                        <a href="?action=cancel&id=<?php echo $apt['appointment_id']; ?>&filter=<?php echo $filter; ?>" class="action-btn btn-cancel" onclick="return confirm('Cancel this appointment?')">❌ Cancel</a>
                                    <?php endif; ?>
                                 </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr class="empty-row">
                            <td colspan="9" style="text-align: center; padding: 40px;">
                                <i class="fas fa-calendar-times" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>
                                No appointments found for this period.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</body>
</html>
