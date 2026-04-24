<?php
require_once 'includes/config.php';

$bot_token = '8330456846:AAFJFM3cy7rbKr5diPbcYi8QaIDDIhktpVU'; 

$content = file_get_contents('php://input');
$update = json_decode($content, true);

if (!$update) {
    exit();
}

// Handle messages
if (isset($update['message'])) {
    $message = $update['message'];
    $chat_id = $message['chat']['id'];
    $text = trim($message['text'] ?? '');
    $username = $message['from']['username'] ?? '';
    $first_name = $message['from']['first_name'] ?? '';
    
    // Handle /start command
    if ($text == '/start') {
        $response = "🏥 *Welcome to Shifa Medical Center Bot!*\n\n";
        $response .= "This bot helps you receive appointment reminders and updates.\n\n";
        $response .= "📌 *To link your account:*\n";
        $response .= "1. Login to your patient portal\n";
        $response .= "2. Click 'Link Telegram Account'\n";
        $response .= "3. Or use this link:\n";
        $response .= "https://shifacenter.me/patient/link_telegram.php?chat_id=$chat_id\n\n";
        $response .= "_You will receive appointment reminders here once linked._";
        
        sendMessage($chat_id, $response, $bot_token);
    }
    // Handle /help
    elseif ($text == '/help') {
        $response = "🤖 *Available Commands:*\n\n";
        $response .= "/start - Welcome message\n";
        $response .= "/help - Show this help\n";
        $response .= "/status - Check your account status\n";
        $response .= "/next - Show your next appointment";
        
        sendMessage($chat_id, $response, $bot_token);
    }
    // Handle /status
    elseif ($text == '/status') {
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
    }
    // Handle /next
    elseif ($text == '/next') {
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
    }
}

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
?>
