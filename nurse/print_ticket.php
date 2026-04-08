<?php
require_once '../includes/config.php';

// FIX: Only nurses can print tickets (removed patient access)
if (!isset($_SESSION['nurse_id'])) {
    header('Location: login.php');
    exit();
}

$appointment_id = isset($_GET['id']) ? $_GET['id'] : 0;

// Get appointment details
$sql = "SELECT a.*, 
        CONCAT(p.first_name, ' ', p.last_name) as patient_name,
        p.phone as patient_phone,
        CONCAT(d.first_name, ' ', d.last_name) as doctor_name
        FROM appointments a
        JOIN patients p ON a.patient_id = p.patient_id
        JOIN doctors d ON a.doctor_id = d.doctor_id
        WHERE a.appointment_id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$appointment_id]);
$apt = $stmt->fetch();

if (!$apt) {
    die('Invalid appointment');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Queue Ticket</title>
    <style>
        body {
            font-family: 'Courier New', monospace;
            background: #fff;
            margin: 0;
            padding: 20px;
            display: flex;
            justify-content: center;
        }
        .ticket {
            width: 300px;
            border: 2px dashed #333;
            padding: 20px;
            text-align: center;
        }
        h1 {
            font-size: 48px;
            margin: 10px 0;
            color: #000;
        }
        h2 {
            font-size: 24px;
            margin: 5px 0;
            border-bottom: 1px solid #333;
            padding-bottom: 10px;
        }
        .queue-number {
            font-size: 72px;
            font-weight: bold;
            margin: 20px 0;
            color: #000;
        }
        .info {
            text-align: left;
            margin: 15px 0;
            font-size: 14px;
        }
        .info p {
            margin: 5px 0;
        }
        .footer {
            margin-top: 20px;
            font-size: 12px;
            border-top: 1px solid #333;
            padding-top: 10px;
        }
        .print-btn {
            margin-top: 20px;
            padding: 10px 20px;
            background: #48bb78;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
        }
        @media print {
            .print-btn {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="ticket">
        <h2>🏥 MEDICAL PRACTICE</h2>
        <div class="queue-number">#<?php echo $apt['queue_number']; ?></div>
        
        <div class="info">
            <p><strong>Date:</strong> <?php echo date('F j, Y', strtotime($apt['appointment_date'])); ?></p>
            <p><strong>Time:</strong> <?php echo date('g:i A', strtotime($apt['appointment_time'])); ?></p>
            <p><strong>Patient:</strong> <?php echo htmlspecialchars($apt['patient_name']); ?></p>
            <p><strong>Doctor:</strong> Dr. <?php echo htmlspecialchars($apt['doctor_name']); ?></p>
            <p><strong>Status:</strong> <?php echo ucfirst($apt['status']); ?></p>
        </div>
        
        <div class="footer">
            <p>Please wait for your number to be called</p>
            <p>Estimated wait time: ~15 minutes per patient</p>
            <p>Thank you for choosing our clinic!</p>
        </div>
        
        <button class="print-btn" onclick="window.print()">🖨️ Print Ticket</button>
        <button class="print-btn" onclick="window.close()" style="background: #718096;">Close</button>
    </div>
</body>
</html>
