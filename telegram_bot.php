<?php
require_once 'includes/config.php';

$bot_token = '8330456846:AAFJFM3cy7rbKr5diPbcYi8QaIDDIhktpVU';

$content = file_get_contents('php://input');
$update = json_decode($content, true);

if (!$update || !isset($update['message'])) {
    exit();
}

$message = $update['message'];
$chat_id = $message['chat']['id'];
$text = trim($message['text'] ?? '');

// Handle /start command
if ($text === '/start') {
    $response = "👋 *Welcome to Shifa Medical Center!*\n\n";
    $response .= "Please send your email address to link your account.\n\n";
    $response .= "*You can also:*\n";
    $response .= "• 📅 Book appointments on our website\n";
    $response .= "• 🎫 Check your queue position\n";
    $response .= "• ❌ Cancel or reschedule appointments\n\n";
    $response .= "Visit: https://shifacenter.me";
    
    sendMessage($chat_id, $response, $bot_token);
    exit();
}

// Handle email (basic validation)
if (strpos($text, '@') !== false && strpos($text, '.') !== false) {
    $email = strtolower(trim($text));
    
    // Send typing indicator
    sendChatAction($chat_id, 'typing', $bot_token);
    
    // Check if email exists in database
    $stmt = $pdo->prepare("SELECT first_name, last_name, patient_id FROM patients WHERE email = ?");
    $stmt->execute([$email]);
    $patient = $stmt->fetch();
    
    if ($patient) {
        // Link the Telegram chat ID to this patient
        $update_stmt = $pdo->prepare("UPDATE patients SET telegram_chat_id = ? WHERE patient_id = ?");
        $update_stmt->execute([$chat_id, $patient['patient_id']]);
        
        $response = "✅ *Email Confirmed!*\n\n";
        $response .= "Welcome back *{$patient['first_name']} {$patient['last_name']}*!\n\n";
        $response .= "*Your account is now linked.* You can now:\n";
        $response .= "• 📅 Book appointments on our website\n";
        $response .= "• 🎫 Check your queue position\n";
        $response .= "• ❌ Cancel or reschedule appointments\n\n";
        $response .= "Visit: https://shifacenter.me";
    } else {
        $response = "❌ *Email Not Found*\n\n";
        $response .= "We couldn't find an account with the email: *{$email}*\n\n";
        $response .= "Please register on our website first:\n";
        $response .= "https://shifacenter.me/patient/register.php\n\n";
        $response .= "Then send your email again to link your account.";
    }
    
    sendMessage($chat_id, $response, $bot_token);
    exit();
}

// Default response for unknown messages
$response = "🤖 *Need Help?*\n\n";
$response .= "Send your email address to link your account.\n\n";
$response .= "*Commands:*\n";
$response .= "• /start - Restart the bot\n";
$response .= "• Send email - Link your account\n\n";
$response .= "Or visit our website: https://shifacenter.me";

sendMessage($chat_id, $response, $bot_token);

// ========== FUNCTIONS ==========

function sendMessage($chat_id, $message, $bot_token) {
    $url = "https://api.telegram.org/bot{$bot_token}/sendMessage";
    $data = [
        'chat_id' => $chat_id,
        'text' => $message,
        'parse_mode' => 'Markdown'
    ];
    
    $options = [
        'http' => [
            'header' => "Content-type: application/x-www-form-urlencoded\r\n",
            'method' => 'POST',
            'content' => http_build_query($data)
        ]
    ];
    
    $context = stream_context_create($options);
    file_get_contents($url, false, $context);
}

function sendChatAction($chat_id, $action, $bot_token) {
    $url = "https://api.telegram.org/bot{$bot_token}/sendChatAction";
    $data = ['chat_id' => $chat_id, 'action' => $action];
    
    $options = [
        'http' => [
            'header' => "Content-type: application/x-www-form-urlencoded\r\n",
            'method' => 'POST',
            'content' => http_build_query($data)
        ]
    ];
    
    $context = stream_context_create($options);
    file_get_contents($url, false, $context);
}
?>
