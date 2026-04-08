<?php
require_once '../includes/config.php';

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
    
    // Verify appointment exists
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
    header('Location: dashboard.php');
    exit();
}

// Get filter from URL (today, tomorrow, all)
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

// Get stats for today with FIX for NULL values
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

// FIX: Set default values if no appointments exist
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <meta charset="UTF-8">
    <title>Nurse Dashboard</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #f0f9f0; }
        
        .navbar {
            background: #48bb78;
            color: white;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .container {
            max-width: 1400px;
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
            color: #48bb78;
        }
        
        .stat-label {
            color: #666;
            margin-top: 0.5rem;
        }
        
        .filter-bar {
            background: white;
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 2rem;
            display: flex;
            gap: 1rem;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .filter-btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            color: white;
            background: #48bb78;
        }
        
        .filter-btn.active {
            background: #2f855a;
            font-weight: bold;
        }
        
        .filter-btn:hover {
            background: #38a169;
        }
        
        .appointments-table {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }
        
        th {
            background: #48bb78;
            color: white;
            padding: 1rem;
            text-align: left;
        }
        
        td {
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
        }
        
        tr:hover {
            background: #f0fff4;
        }
        
        .status-badge {
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
        .status-no-show { background: #fff3e0; color: #e65100; }
        
        .action-btn {
            display: inline-block;
            padding: 0.4rem 0.8rem;
            margin: 0.2rem;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            font-size: 0.875rem;
            color: white;
        }
        
        .btn-confirm { background: #48bb78; }
        .btn-complete { background: #9f7aea; }
        .btn-cancel { background: #f56565; }
        .btn-noshow { background: #ed8936; }
        .btn-print { background: #718096; }
        
        .action-group {
            display: flex;
            flex-wrap: wrap;
            gap: 0.25rem;
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
        
        .logout {
            background: #f56565;
            padding: 0.5rem 1rem;
            border-radius: 5px;
            color: white;
            text-decoration: none;
        }
        
        .print-ticket {
            background: #48bb78;
            color: white;
            padding: 0.4rem 0.8rem;
            border-radius: 5px;
            text-decoration: none;
            font-size: 0.875rem;
            display: inline-block;
        }
        
        .back-home {
            margin-top: 2rem;
            text-align: center;
        }
        
        .back-home a {
            display: inline-block;
            padding: 0.6rem 1.2rem;
            background: #718096;
            color: white;
            text-decoration: none;
            border-radius: 5px;
        }
        
        @media (max-width: 768px) {
            .navbar {
                flex-direction: column;
                text-align: center;
                gap: 1rem;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .filter-bar {
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="navbar">
        <h1>👩‍⚕️ Nurse Dashboard</h1>
        <div>
            <span>Welcome, <?php echo htmlspecialchars($_SESSION['nurse_name']); ?></span>
            <a href="logout.php" class="logout">Logout</a>
        </div>
    </div>
    
    <div class="container">
        <?php if (isset($_SESSION['success'])): ?>
            <div class="message success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="message error"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
        <?php endif; ?>
        
        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['total']; ?></div>
                <div class="stat-label">Total Today</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['scheduled']; ?></div>
                <div class="stat-label">Scheduled</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['confirmed']; ?></div>
                <div class="stat-label">Confirmed</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['completed']; ?></div>
                <div class="stat-label">Completed</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['cancelled']; ?></div>
                <div class="stat-label">Cancelled</div>
            </div>
        </div>
        
        <!-- Filter Bar -->
        <div class="filter-bar">
            <a href="?filter=today" class="filter-btn <?php echo $filter == 'today' ? 'active' : ''; ?>">Today</a>
            <a href="?filter=tomorrow" class="filter-btn <?php echo $filter == 'tomorrow' ? 'active' : ''; ?>">Tomorrow</a>
            <a href="?filter=all" class="filter-btn <?php echo $filter == 'all' ? 'active' : ''; ?>">All Appointments</a>
            <span style="margin-left: auto;">
                Showing: <strong>
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
                        <th>Time</th>
                        <th>Patient</th>
                        <th>Phone</th>
                        <th>Doctor</th>
                        <th>Queue #</th>
                        <th>Status</th>
                        <th>Actions</th>
                        <th>Ticket</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($appointments) > 0): ?>
                        <?php foreach($appointments as $apt): ?>
                        <tr>
                            <td><?php echo date('g:i A', strtotime($apt['appointment_time'])); ?></td>
                            <td><?php echo htmlspecialchars($apt['patient_name']); ?></td>
                            <td><?php echo htmlspecialchars($apt['patient_phone']); ?></td>
                            <td>Dr. <?php echo htmlspecialchars($apt['doctor_name']); ?></td>
                            <td><strong>#<?php echo $apt['queue_number']; ?></strong></td>
                            <td>
                                <span class="status-badge status-<?php echo $apt['status']; ?>">
                                    <?php echo ucfirst($apt['status']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="action-group">
                                    <?php if ($apt['status'] == 'scheduled'): ?>
                                        <a href="?action=confirm&id=<?php echo $apt['appointment_id']; ?>" 
                                           class="action-btn btn-confirm" 
                                           onclick="return confirm('Confirm this appointment?')">✅ Confirm</a>
                                        <a href="?action=cancel&id=<?php echo $apt['appointment_id']; ?>" 
                                           class="action-btn btn-cancel"
                                           onclick="return confirm('Cancel this appointment?')">❌ Cancel</a>
                                    <?php endif; ?>
                                    
                                    <?php if ($apt['status'] == 'confirmed'): ?>
                                        <a href="?action=complete&id=<?php echo $apt['appointment_id']; ?>" 
                                           class="action-btn btn-complete"
                                           onclick="return confirm('Mark as completed?')">✓ Complete</a>
                                        <a href="?action=noshow&id=<?php echo $apt['appointment_id']; ?>" 
                                           class="action-btn btn-noshow"
                                           onclick="return confirm('Mark patient as no-show?')">🚫 No Show</a>
                                    <?php endif; ?>
                                    
                                    <?php if ($apt['status'] == 'scheduled'): ?>
                                        <a href="?action=noshow&id=<?php echo $apt['appointment_id']; ?>" 
                                           class="action-btn btn-noshow"
                                           onclick="return confirm('Mark patient as no-show?')">🚫 No Show</a>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <a href="print_ticket.php?id=<?php echo $apt['appointment_id']; ?>" 
                                   class="print-ticket" target="_blank">🖨️ Print</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 2rem;">
                                No appointments found for this date.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Back to Home Button -->
        <div class="back-home">
            <a href="https://medicalpractice.free.nf/medical_practice/index.html">← Back to Home</a>
        </div>
    </div>
</body>
</html>
