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

// ✅ DYNAMIC QUEUE: Calculate position on-the-fly using appointment_time
$queue_stmt = $pdo->prepare("
    SELECT COUNT(*) + 1 as position FROM appointments 
    WHERE appointment_date = ? 
    AND appointment_time < ? 
    AND status IN ('scheduled', 'confirmed')
    AND appointment_id != ?
");
$queue_stmt->execute([$apt['appointment_date'], $apt['appointment_time'], $apt['appointment_id']]);
$queue_position = (int)$queue_stmt->fetchColumn();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Queue Ticket | Medical Practice</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Courier New', monospace;
            background: #f5f5f5;
            margin: 0;
            padding: 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        
        .ticket {
            width: 350px;
            background: white;
            border: 2px dashed #333;
            border-radius: 8px;
            padding: 25px;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .clinic-name {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 5px;
            color: #2d3748;
        }
        
        h2 {
            font-size: 24px;
            margin: 10px 0;
            border-bottom: 1px solid #333;
            padding-bottom: 10px;
            color: #1a202c;
        }
        
        .queue-number {
            font-size: 80px;
            font-weight: bold;
            margin: 20px 0;
            color: #48bb78;
            letter-spacing: 5px;
        }
        
        .info {
            text-align: left;
            margin: 15px 0;
            font-size: 14px;
            border-top: 1px solid #ddd;
            padding-top: 15px;
        }
        
        .info p {
            margin: 8px 0;
        }
        
        .footer {
            margin-top: 20px;
            font-size: 11px;
            border-top: 1px solid #ddd;
            padding-top: 12px;
            color: #4a5568;
        }
        
        .btn-group {
            margin-top: 20px;
            display: flex;
            gap: 10px;
            justify-content: center;
        }
        
        .print-btn {
            padding: 10px 20px;
            background: #48bb78;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
            transition: all 0.2s ease;
        }
        
        .print-btn:hover {
            background: #38a169;
            transform: translateY(-1px);
        }
        
        .close-btn {
            background: #718096;
        }
        
        .close-btn:hover {
            background: #4a5568;
        }
        
        @media print {
            .btn-group {
                display: none;
            }
            body {
                background: white;
                padding: 0;
            }
            .ticket {
                box-shadow: none;
                border: 2px dashed #333;
            }
        }
    </style>
</head>
<body>
    <div class="ticket">
        <div class="clinic-name">🏥 MEDICAL PRACTICE</div>
        <h2>Queue Ticket</h2>
        
        <!-- ✅ Display calculated dynamic position instead of stored queue_number -->
        <div class="queue-number">
            #<?php echo htmlspecialchars($queue_position); ?>
        </div>
        
        <div class="info">
            <p><strong>📅 Date:</strong> <?php echo date('F j, Y', strtotime($apt['appointment_date'])); ?></p>
            <p><strong>⏰ Time:</strong> <?php echo date('g:i A', strtotime($apt['appointment_time'])); ?></p>
            <p><strong>👤 Patient:</strong> <?php echo htmlspecialchars($apt['patient_name']); ?></p>
            <p><strong>👨‍⚕️ Doctor:</strong> Dr. <?php echo htmlspecialchars($apt['doctor_name']); ?></p>
            <p><strong>📌 Status:</strong> <?php echo ucfirst(htmlspecialchars($apt['status'])); ?></p>
        </div>
        
        <div class="footer">
            <p>Please wait for your number to be called</p>
            <p>⏱️ Estimated wait: ~15 minutes per patient</p>
            <p>Thank you for choosing our clinic!</p>
        </div>
        
        <div class="btn-group">
            <button class="print-btn" onclick="window.print()">
                🖨️ Print Ticket
            </button>
            <button class="print-btn close-btn" onclick="window.close()">
                ✖ Close
            </button>
        </div>
    </div>
</body>
</html>
