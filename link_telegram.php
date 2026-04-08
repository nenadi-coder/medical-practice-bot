<?php
session_start();
require_once '../includes/config.php';

if (!isset($_SESSION['patient_id'])) {
    header('Location: login.php');
    exit();
}

$patient_id = $_SESSION['patient_id'];

// Generate unique linking code
$code = strtoupper(substr(md5(uniqid() . $patient_id . time()), 0, 8));

// SAVE CODE TO DATABASE
$sql = "UPDATE patients SET linking_code = ? WHERE patient_id = ?";
$stmt = $pdo->prepare($sql);
$result = $stmt->execute([$code, $patient_id]);

// Verify it was saved
$verify = $pdo->prepare("SELECT linking_code FROM patients WHERE patient_id = ?");
$verify->execute([$patient_id]);
$saved_code = $verify->fetchColumn();

// Check if already linked
$check = $pdo->prepare("SELECT telegram_chat_id FROM patients WHERE patient_id = ?");
$check->execute([$patient_id]);
$patient = $check->fetch();
$is_linked = !empty($patient['telegram_chat_id']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Link Telegram - Medical Practice</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 20px;
            padding: 40px;
            max-width: 500px;
            width: 100%;
            text-align: center;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
        }
        h1 { color: #333; margin-bottom: 10px; }
        .subtitle { color: #666; margin-bottom: 30px; }
        .steps {
            text-align: left;
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin: 20px 0;
        }
        .step {
            display: flex;
            align-items: center;
            margin: 15px 0;
            gap: 15px;
        }
        .step-number {
            width: 30px;
            height: 30px;
            background: #667eea;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }
        .code-box {
            background: #f0f2f5;
            padding: 20px;
            border-radius: 10px;
            margin: 20px 0;
        }
        .code {
            font-size: 32px;
            font-weight: bold;
            letter-spacing: 5px;
            font-family: monospace;
            background: white;
            padding: 15px;
            border-radius: 8px;
            border: 2px dashed #667eea;
            display: inline-block;
        }
        .btn {
            background: #0088cc;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            margin-top: 10px;
        }
        .btn:hover { background: #006699; }
        .linked-badge {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 10px;
            margin: 20px 0;
        }
        .back-link {
            display: inline-block;
            margin-top: 20px;
            color: #667eea;
            text-decoration: none;
        }
        .bot-link {
            background: #e3f2fd;
            padding: 10px;
            border-radius: 8px;
            margin: 15px 0;
        }
        .bot-link a { color: #0088cc; text-decoration: none; font-weight: bold; }
        .debug-info {
            font-size: 11px;
            color: #666;
            margin-top: 20px;
            padding-top: 10px;
            border-top: 1px solid #eee;
            background: #f8f9fa;
            border-radius: 5px;
            padding: 8px;
        }
        .success-info {
            background: #d4edda;
            color: #155724;
            padding: 10px;
            border-radius: 8px;
            margin-top: 15px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🤖 Link Telegram Account</h1>
        <p class="subtitle">Get appointment reminders on your phone!</p>
        
        <?php if ($is_linked): ?>
            <div class="linked-badge">
                ✅ Your Telegram account is already linked!<br>
                You will receive appointment reminders automatically.
            </div>
            <a href="dashboard.php" class="btn">← Back to Dashboard</a>
        <?php else: ?>
            <div class="steps">
                <div class="step">
                    <div class="step-number">1</div>
                    <div>Open Telegram and search for:</div>
                </div>
                <div class="bot-link">
                    🔍 <strong><a href="https://t.me/MedicalPracticeBot" target="_blank">@MedicalPracticeBot</a></strong>
                </div>
                
                <div class="step">
                    <div class="step-number">2</div>
                    <div>Start the bot and send this code:</div>
                </div>
                
                <div class="code-box">
                    <div class="code"><?php echo $saved_code; ?></div>
                </div>
                
                <div class="step">
                    <div class="step-number">3</div>
                    <div>Wait for confirmation from the bot</div>
                </div>
            </div>
            
            <button class="btn" onclick="checkStatus()">✅ I've Sent the Code</button>
            <br>
            <a href="dashboard.php" class="back-link">← Skip for now</a>
            
            <div class="debug-info">
                📋 Debug Info:<br>
                Code generated: <strong><?php echo $code; ?></strong><br>
                Code saved in DB: <strong><?php echo $saved_code ? $saved_code : 'NOT SAVED!'; ?></strong><br>
                <?php if($result): ?>
                    <span style="color:green;">✓ Database update successful</span>
                <?php else: ?>
                    <span style="color:red;">✗ Database update failed</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        let checkInterval;
        function checkStatus() {
            const btn = document.querySelector('.btn');
            btn.innerHTML = '⏳ Checking...';
            btn.disabled = true;
            
            checkInterval = setInterval(function() {
                fetch('check_telegram_linked.php')
                    .then(response => response.json())
                    .then(data => {
                        if (data.linked) {
                            clearInterval(checkInterval);
                            alert('✅ Success! Your Telegram account is now linked!');
                            window.location.href = 'dashboard.php';
                        }
                    });
            }, 3000);
        }
    </script>
</body>
</html>
