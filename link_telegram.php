<?php
session_start();
require_once 'includes/config.php';

// Check if patient is logged in
if (!isset($_SESSION['patient_id'])) {
    header('Location: login.php');
    exit();
}

$patient_id = $_SESSION['patient_id'];
$patient_name = $_SESSION['patient_name'] ?? 'Patient';
$chat_id = isset($_GET['chat_id']) ? $_GET['chat_id'] : null;

// If chat_id is provided via URL, link it
if ($chat_id) {
    $stmt = $pdo->prepare("UPDATE patients SET telegram_chat_id = ?, telegram_linked_at = NOW() WHERE patient_id = ?");
    if ($stmt->execute([$chat_id, $patient_id])) {
        $_SESSION['success'] = "✅ Telegram account linked successfully! You will now receive appointment reminders.";
    } else {
        $_SESSION['error'] = "❌ Failed to link Telegram account. Please try again.";
    }
    header('Location: dashboard.php');
    exit();
}

// Get current linked status
$stmt = $pdo->prepare("SELECT telegram_chat_id FROM patients WHERE patient_id = ?");
$stmt->execute([$patient_id]);
$patient = $stmt->fetch();
$is_linked = !empty($patient['telegram_chat_id']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Link Telegram Account - Shifa Medical Center</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            max-width: 500px;
            width: 100%;
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            text-align: center;
        }
        .icon {
            font-size: 60px;
            margin-bottom: 20px;
        }
        h1 {
            font-size: 28px;
            color: #1e2a3e;
            margin-bottom: 10px;
        }
        .subtitle {
            color: #64748b;
            margin-bottom: 30px;
        }
        .info-box {
            background: #f1f5f9;
            padding: 20px;
            border-radius: 15px;
            margin: 20px 0;
            text-align: left;
        }
        .info-box p {
            margin: 10px 0;
            color: #1e2a3e;
        }
        .chat-id {
            background: white;
            padding: 10px;
            border-radius: 8px;
            font-family: monospace;
            font-weight: bold;
            color: #4f46e5;
            text-align: center;
            margin: 10px 0;
        }
        .btn {
            display: inline-block;
            padding: 12px 30px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            margin: 10px 5px;
            transition: all 0.3s ease;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102,126,234,0.3);
        }
        .btn-secondary {
            background: #e2e8f0;
            color: #1e2a3e;
        }
        .btn-secondary:hover {
            background: #cbd5e1;
        }
        .success {
            background: #dcfce7;
            color: #166534;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .error {
            background: #fee2e2;
            color: #991b1b;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .steps {
            text-align: left;
            margin: 20px 0;
        }
        .steps li {
            margin: 10px 0;
            margin-left: 20px;
        }
        footer {
            margin-top: 20px;
            font-size: 12px;
            color: #94a3b8;
        }
        @media (max-width: 480px) {
            .container { padding: 25px; }
            h1 { font-size: 24px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">🤖</div>
        <h1>Telegram Account</h1>
        <p class="subtitle">Link your Telegram account to receive appointment reminders</p>
        
        <?php if (isset($_SESSION['success'])): ?>
            <div class="success">✅ <?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="error">❌ <?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
        <?php endif; ?>
        
        <?php if ($is_linked): ?>
            <div class="success">
                ✅ <strong>Your Telegram account is already linked!</strong><br>
                You will receive appointment reminders on Telegram.
            </div>
            <div class="info-box">
                <p><strong>📱 What's next?</strong></p>
                <p>• Send <code>/next</code> to see your next appointment</p>
                <p>• Send <code>/appointments</code> to see all appointments</p>
                <p>• Send <code>/queue</code> to check queue position</p>
            </div>
            <a href="dashboard.php" class="btn btn-primary">← Back to Dashboard</a>
            
        <?php else: ?>
            <div class="info-box">
                <p><strong>📌 How to link your Telegram account:</strong></p>
                <ol class="steps">
                    <li>Open Telegram and search for <strong>@ShifaMedicalCenter_bot</strong></li>
                    <li>Start the bot and send <code>/start</code></li>
                    <li>Copy your <strong>Chat ID</strong> from the bot's response</li>
                    <li>Paste it below and click "Link Account"</li>
                </ol>
            </div>
            
            <form method="POST" action="">
                <input type="text" name="chat_id" placeholder="Enter your Telegram Chat ID" 
                       style="width: 100%; padding: 12px; border: 2px solid #e2e8f0; border-radius: 10px; font-size: 16px; margin-bottom: 15px;">
                <button type="submit" name="link" class="btn btn-primary" style="width: 100%; cursor: pointer;">
                    🔗 Link Telegram Account
                </button>
            </form>
            
            <?php if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['link']) && !empty($_POST['chat_id'])): ?>
                <?php
                $chat_id_input = trim($_POST['chat_id']);
                $stmt = $pdo->prepare("UPDATE patients SET telegram_chat_id = ?, telegram_linked_at = NOW() WHERE patient_id = ?");
                if ($stmt->execute([$chat_id_input, $patient_id])) {
                    echo '<div class="success">✅ Telegram account linked successfully!</div>';
                    echo '<meta http-equiv="refresh" content="2">';
                } else {
                    echo '<div class="error">❌ Failed to link. Please try again.</div>';
                }
                ?>
            <?php endif; ?>
            
            <div style="margin-top: 20px;">
                <a href="dashboard.php" class="btn btn-secondary">← Back to Dashboard</a>
            </div>
        <?php endif; ?>
        
        <footer>
            <p>Need help? Contact the clinic: <strong>0556431565</strong></p>
        </footer>
    </div>
</body>
</html>
