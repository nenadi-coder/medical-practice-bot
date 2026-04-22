<?php
require_once '../includes/config.php';

if (!isset($_SESSION['patient_id'])) {
    header('Location: login.php');
    exit();
}

$patient_id = $_SESSION['patient_id'];
$chat_id = isset($_GET['chat_id']) ? $_GET['chat_id'] : null;
$message = '';
$error = '';

if ($chat_id) {
    // Update patient with telegram chat ID
    $stmt = $pdo->prepare("UPDATE patients SET telegram_chat_id = ?, telegram_linked_at = NOW() WHERE patient_id = ?");
    if ($stmt->execute([$chat_id, $patient_id])) {
        $message = "✅ Your Telegram account has been linked successfully! You will now receive appointment reminders.";
    } else {
        $error = "Failed to link Telegram account. Please try again.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Link Telegram Account</title>
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(125deg, #e0f0ff 0%, #f5f0fc 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .card {
            background: white;
            border-radius: 1.5rem;
            padding: 2rem;
            max-width: 500px;
            text-align: center;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }
        .success { color: #2e7d32; background: #e8f5e8; padding: 1rem; border-radius: 1rem; }
        .error { color: #c62828; background: #ffebee; padding: 1rem; border-radius: 1rem; }
        .btn { display: inline-block; margin-top: 1rem; padding: 0.8rem 1.5rem; background: #0088cc; color: white; text-decoration: none; border-radius: 60px; }
    </style>
</head>
<body>
    <div class="card">
        <h2><i class="fab fa-telegram"></i> Link Telegram Account</h2>
        
        <?php if ($message): ?>
            <div class="success"><?php echo $message; ?></div>
            <a href="dashboard.php" class="btn">Go to Dashboard</a>
        <?php elseif ($error): ?>
            <div class="error"><?php echo $error; ?></div>
            <a href="dashboard.php" class="btn">Back to Dashboard</a>
        <?php else: ?>
            <p>To link your Telegram account:</p>
            <ol style="text-align: left; margin: 1rem 0;">
                <li>Open Telegram and search for <strong>@YourBotUsername</strong></li>
                <li>Send <code>/start</code> to the bot</li>
                <li>Copy the chat ID or use the link provided by the bot</li>
            </ol>
            <form method="GET">
                <input type="text" name="chat_id" placeholder="Enter your Telegram Chat ID" style="width: 100%; padding: 0.8rem; margin: 1rem 0; border-radius: 1rem; border: 1px solid #ddd;">
                <button type="submit" class="btn" style="background: #4f46e5;">Link Account</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
