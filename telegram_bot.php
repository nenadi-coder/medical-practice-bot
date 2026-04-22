<?php
// Enable error logging
ini_set('log_errors', 1);
ini_set('error_log', 'php-error.log');
error_reporting(E_ALL);

// Create a log file in your project root
$log_file = 'telegram_debug.log';

function debug_log($message) {
    global $log_file;
    file_put_contents($log_file, date('Y-m-d H:i:s') . " - " . $message . PHP_EOL, FILE_APPEND);
}

debug_log("=== Script started ===");

require_once 'includes/config.php';
debug_log("Config loaded");

$bot_token = '8330456846:AAFJFM3cy7rbKr5diPbcYi8QaIDDIhktpVU';
debug_log("Bot token set");

$content = file_get_contents('php://input');
debug_log("Raw input received: " . ($content ?: "EMPTY"));

$update = json_decode($content, true);
debug_log("Decoded update: " . print_r($update, true));

if (!$update) {
    debug_log("No valid update received. Exiting.");
    exit();
}

debug_log("Processing update...");

// Handle messages
if (isset($update['message'])) {
    $message = $update['message'];
    $chat_id = $message['chat']['id'];
    $text = trim($message['text'] ?? '');
    debug_log("Message from chat_id: $chat_id, text: $text");
    
    // Handle /start command
    if ($text == '/start') {
        debug_log("Handling /start command");
        $response = "🏥 *Welcome to Shifa Medical Center Bot!*\n\n";
        $response .= "This bot helps you receive appointment reminders and updates.\n\n";
        $response .= "📌 *To link your account:*\n";
        $response .= "1. Login to your patient portal\n";
        $response .= "2. Click 'Link Telegram Account'\n";
        $response .= "3. Or use this link:\n";
        $response .= "https://shifacenter.me/patient/link_telegram.php?chat_id=$chat_id\n\n";
        $response .= "_You will receive appointment reminders here once linked._";
        
        sendMessage($chat_id, $response, $bot_token);
        debug_log("Sent /start response");
    }
    // Handle /help command
    elseif ($text == '/help') {
        debug_log("Handling /help command");
        $response = "🤖 *Available Commands:*\n\n";
        $response .= "/start - Welcome message\n";
        $response .= "/help - Show this help\n";
        $response .= "/status - Check your account status\n";
        $response .= "/next - Show your next appointment";
        
        sendMessage($chat_id, $response, $bot_token);
        debug_log("Sent /help response");
    }
    // Handle /status command
    elseif ($text == '/status') {
        debug_log("Handling /status command");
        $stmt = $pdo->prepare("SELECT * FROM patients WHERE telegram_chat_id = ? OR telegram_user_id = ?");
        $stmt->execute([$chat_id, $chat_id]);
        $patient = $stmt->fetch();
        
        if ($patient) {
            $response = "✅ *Account Linked!*\n\n";
            $response .= "Name: {$patient['first_name']} {$patient['last_name']}\n";
            $response .= "Email: {$patient['email']}\n";
            $response .= "You will receive appointment reminders here.";
        } else {
            $response = "❌ *Account Not Linked*\n\n";
            $response .= "Please login to your patient portal and link your Telegram account.\n";
            $response .= "Or contact the clinic for assistance.";
        }
        sendMessage($chat_id, $response, $bot_token);
        debug_log("Sent /status response");
    }
    // Handle /next command
    elseif ($text == '/next') {
        debug_log("Handling /next command");
        $stmt = $pdo->prepare("SELECT * FROM patients WHERE telegram_chat_id = ? OR telegram_user_id = ?");
        $stmt->execute([$chat_id, $chat_id]);
        $patient = $stmt->fetch();
        
        if ($patient) {
            $apt_stmt = $pdo->prepare("
                SELECT a.*, CONCAT(d.first_name, ' ', d.last_name) as doctor_name
                FROM appointments a
                JOIN doctors d ON a.doctor_id = d.doctor_id
                WHERE a.patient_id = ? AND a.appointment_date >= CURDATE() 
                AND a.status IN ('scheduled', 'confirmed')
                ORDER BY a.appointment_date ASC, a.appointment_time ASC
                LIMIT 1
            ");
            $apt_stmt->execute([$patient['patient_id']]);
            $appointment = $apt_stmt->fetch();
            
            if ($appointment) {
                $response = "📅 *Your Next Appointment*\n\n";
                $response .= "Date: " . date('l, F j, Y', strtotime($appointment['appointment_date'])) . "\n";
                $response .= "Time: " . date('g:i A', strtotime($appointment['appointment_time'])) . "\n";
                $response .= "Doctor: Dr. {$appointment['doctor_name']}\n";
                $response .= "Queue #: {$appointment['queue_number']}\n\n";
                $response .= "Please arrive 10 minutes early!";
            } else {
                $response = "📅 *No Upcoming Appointments*\n\nYou have no upcoming appointments scheduled.";
            }
            sendMessage($chat_id, $response, $bot_token);
        } else {
            $response = "❌ Please link your account first using the patient portal.";
            sendMessage($chat_id, $response, $bot_token);
        }
        debug_log("Sent /next response");
    }
} else {
    debug_log("No 'message' field in update. Update structure: " . print_r($update, true));
}

debug_log("=== Script ended ===");

function sendMessage($chat_id, $message, $bot_token) {
    debug_log("Sending message to $chat_id: " . $message);
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
    $result = file_get_contents($url, false, $context);
    debug_log("Send message result: " . ($result ?: "FAILED"));
}
?>
