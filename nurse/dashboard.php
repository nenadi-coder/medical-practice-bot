<?php
require_once '../includes/config.php';

session_start();

// Check if nurse is logged in
if (!isset($_SESSION['nurse_id'])) {
    header('Location: login.php');
    exit();
}

// Get filter
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'today';

// Build query based on filter
if ($filter == 'today') {
    $sql = "SELECT a.*, 
            CONCAT(p.first_name, ' ', p.last_name) as patient_name,
            p.phone as patient_phone,
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
            CONCAT(d.first_name, ' ', d.last_name) as doctor_name
            FROM appointments a
            JOIN patients p ON a.patient_id = p.patient_id
            JOIN doctors d ON a.doctor_id = d.doctor_id
            ORDER BY a.appointment_date DESC, a.appointment_time ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
}
$appointments = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nurse Dashboard</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f0f4f8; }
        .container { max-width: 1200px; margin: auto; background: white; padding: 20px; border-radius: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px; border: 1px solid #ddd; text-align: left; }
        th { background: #4f46e5; color: white; }
        .filter-bar { margin-bottom: 20px; }
        .filter-btn { padding: 8px 15px; margin-right: 10px; background: #e2e8f0; text-decoration: none; color: #333; border-radius: 5px; }
        .filter-btn.active { background: #4f46e5; color: white; }
        .status-scheduled { background: #dbeafe; color: #1e40af; padding: 3px 8px; border-radius: 20px; }
        .status-confirmed { background: #dcfce7; color: #166534; padding: 3px 8px; border-radius: 20px; }
        .status-completed { background: #f3e8ff; color: #6b21a5; padding: 3px 8px; border-radius: 20px; }
        .status-cancelled { background: #fee2e2; color: #991b1b; padding: 3px 8px; border-radius: 20px; }
        .btn { padding: 5px 10px; margin: 2px; border: none; border-radius: 5px; cursor: pointer; color: white; }
        .btn-confirm { background: #22c55e; }
        .btn-cancel { background: #ef4444; }
        .btn-complete { background: #8b5cf6; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Nurse Dashboard</h2>
        <p>Welcome, <?php echo htmlspecialchars($_SESSION['nurse_name']); ?></p>
        
        <div class="filter-bar">
            <a href="?filter=today" class="filter-btn <?php echo $filter == 'today' ? 'active' : ''; ?>">Today</a>
            <a href="?filter=tomorrow" class="filter-btn <?php echo $filter == 'tomorrow' ? 'active' : ''; ?>">Tomorrow</a>
            <a href="?filter=all" class="filter-btn <?php echo $filter == 'all' ? 'active' : ''; ?>">All</a>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Patient</th>
                    <th>Phone</th>
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
                            <td><?php echo $apt['appointment_date']; ?></td>
                            <td><?php echo date('g:i A', strtotime($apt['appointment_time'])); ?></td>
                            <td><?php echo htmlspecialchars($apt['patient_name']); ?></td>
                            <td><?php echo htmlspecialchars($apt['patient_phone']); ?></td>
                            <td>Dr. <?php echo htmlspecialchars($apt['doctor_name']); ?></td>
                            <td>#<?php echo $apt['queue_number']; ?></td>
                            <td><span class="status-<?php echo $apt['status']; ?>"><?php echo ucfirst($apt['status']); ?></span></td>
                            <td>
                                <?php if ($apt['status'] == 'scheduled'): ?>
                                    <a href="?action=confirm&id=<?php echo $apt['appointment_id']; ?>&filter=<?php echo $filter; ?>" class="btn btn-confirm" onclick="return confirm('Confirm?')">Confirm</a>
                                    <a href="?action=cancel&id=<?php echo $apt['appointment_id']; ?>&filter=<?php echo $filter; ?>" class="btn btn-cancel" onclick="return confirm('Cancel?')">Cancel</a>
                                <?php endif; ?>
                                <?php if ($apt['status'] == 'confirmed'): ?>
                                    <a href="?action=complete&id=<?php echo $apt['appointment_id']; ?>&filter=<?php echo $filter; ?>" class="btn btn-complete" onclick="return confirm('Complete?')">Complete</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8" style="text-align: center;">No appointments found</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <p style="margin-top: 20px;"><a href="logout.php">Logout</a></p>
    </div>
</body>
</html>
