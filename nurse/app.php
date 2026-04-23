<?php
require_once '../includes/config.php';

// Get ALL appointments from database
$stmt = $pdo->query("
    SELECT a.*, p.first_name, p.last_name, p.phone 
    FROM appointments a 
    JOIN patients p ON a.patient_id = p.patient_id 
    ORDER BY a.appointment_id DESC 
    LIMIT 30
");
$appointments = $stmt->fetchAll();

// Get database info
$db_name = $pdo->query("SELECT DATABASE()")->fetchColumn();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Database Test</title>
    <style>
        body { font-family: monospace; padding: 20px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #4f46e5; color: white; }
        .scheduled { background: #dbeafe; }
        .confirmed { background: #dcfce7; }
    </style>
</head>
<body>
    <h1>Database: <?php echo $db_name; ?></h1>
    <h2>Total Appointments: <?php echo count($appointments); ?></h2>
    
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Patient Name</th>
                <th>Phone</th>
                <th>Date</th>
                <th>Time</th>
                <th>Queue</th>
                <th>Status</th>
                <th>Created</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($appointments as $apt): ?>
            <tr class="<?php echo $apt['status']; ?>">
                <td><?php echo $apt['appointment_id']; ?></td>
                <td><?php echo $apt['first_name'] . ' ' . $apt['last_name']; ?></td>
                <td><?php echo $apt['phone']; ?></td>
                <td><?php echo $apt['appointment_date']; ?></td>
                <td><?php echo $apt['appointment_time']; ?></td>
                <td><?php echo $apt['queue_number']; ?></td>
                <td><?php echo $apt['status']; ?></td>
                <td><?php echo $apt['created_at']; ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>
