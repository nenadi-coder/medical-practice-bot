<?php
require_once 'includes/config.php';

$bot_token = '8330456846:AAHSmyKZrvCL5yLqpHjynBMqC6tM2u9k6N8'; 

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
    $telegram_user_id = $message['from']['id'] ?? $chat_id; // Telegram's unique user ID
    
    // Handle /start command
    if ($text == '/start') {
        $response = "🏥 *Welcome to Shifa Medical Center Bot!*\n\n";
        $response .= "This bot helps you receive appointment reminders and updates.\n\n";
        $response .= "📌 *To link your account:*\n";
        $response .= "1. Login to your patient portal\n";
        $response .= "2. Click 'Link Telegram Account'\n";
        $response .= "3. Or use this link:\n";
        $response .= "https://yourdomain.com/patient/link_telegram.php?telegram_id=$telegram_user_id\n\n";
        $response .= "_You will receive appointment reminders here once linked._";
        
        sendMessage($chat_id, $response, $bot_token);
    }
    // Handle /help
    elseif ($text == '/help') {
        $response = "🤖 *Available Commands:*\n\n";
        $response .= "/start - Welcome message\n";
        $response .= "/help - Show this help\n";
        $response .= "/status - Check your account status\n";
        $response .= "/next - Show your next appointment\n";
        $response .= "/unlink - Unlink your Telegram account";
        
        sendMessage($chat_id, $response, $bot_token);
    }
    // Handle /status
    elseif ($text == '/status') {
        // Check by telegram_user_id (primary) or telegram_chat_id (backup)
        $stmt = $pdo->prepare("SELECT * FROM patients WHERE telegram_user_id = ? OR telegram_chat_id = ?");
        $stmt->execute([$telegram_user_id, $chat_id]);
        $patient = $stmt->fetch();
        
        if ($patient) {
            $response = "✅ *Account Linked!*\n\n";
            $response .= "Name: {$patient['first_name']} {$patient['last_name']}\n";
            $response .= "Email: {$patient['email']}\n";
            $response .= "Phone: {$patient['phone']}\n\n";
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
        // First get patient by telegram_id
        $stmt = $pdo->prepare("SELECT patient_id, first_name, last_name FROM patients WHERE telegram_user_id = ? OR telegram_chat_id = ?");
        $stmt->execute([$telegram_user_id, $chat_id]);
        $patient = $stmt->fetch();
        
        if ($patient) {
            $apt_stmt = $pdo->prepare("
                SELECT a.*, 
                       CONCAT(d.first_name, ' ', d.last_name) as doctor_name,
                       d.specialization
                FROM appointments a
                JOIN doctors d ON a.doctor_id = d.doctor_id
                WHERE a.patient_id = ? 
                  AND a.appointment_date >= CURDATE() 
                  AND a.status IN ('scheduled', 'confirmed')
                ORDER BY a.appointment_date ASC, a.appointment_time ASC
                LIMIT 1
            ");
            $apt_stmt->execute([$patient['patient_id']]);
            $appointment = $apt_stmt->fetch();
            
            if ($appointment) {
                $date = new DateTime($appointment['appointment_date']);
                $time = new DateTime($appointment['appointment_time']);
                
                $response = "📅 *Your Next Appointment*\n\n";
                $response .= "Date: " . $date->format('l, F j, Y') . "\n";
                $response .= "Time: " . $time->format('g:i A') . "\n";
                $response .= "Doctor: Dr. {$appointment['doctor_name']}\n";
                if ($appointment['specialization']) {
                    $response .= "Specialty: {$appointment['specialization']}\n";
                }
                $response .= "Queue #: {$appointment['queue_number']}\n";
                $response .= "Status: " . ucfirst($appointment['status']) . "\n\n";
                
                if ($appointment['notes']) {
                    $response .= "Notes: {$appointment['notes']}\n\n";
                }
                $response .= "📍 Please arrive 10 minutes early!";
            } else {
                $response = "📅 *No Upcoming Appointments*\n\nYou have no upcoming appointments scheduled.\n";
                $response .= "To book an appointment, please call the clinic or use the patient portal.";
            }
            sendMessage($chat_id, $response, $bot_token);
        } else {
            $response = "❌ *Account Not Linked*\n\nPlease link your account first using the patient portal.\n";
            $response .= "Visit: https://yourdomain.com/patient/link_telegram.php?telegram_id=$telegram_user_id";
            sendMessage($chat_id, $response, $bot_token);
        }
    }
    // Handle /unlink
    elseif ($text == '/unlink') {
        $stmt = $pdo->prepare("UPDATE patients SET telegram_user_id = NULL, telegram_chat_id = NULL, telegram_username = NULL, telegram_linked_at = NULL WHERE telegram_user_id = ? OR telegram_chat_id = ?");
        $stmt->execute([$telegram_user_id, $chat_id]);
        
        if ($stmt->rowCount() > 0) {
            $response = "🔓 *Account Unlinked*\n\nYour Telegram account has been unlinked from the patient portal.\n";
            $response .= "You will no longer receive appointment reminders.\n\n";
            $response .= "To link again, use /start";
        } else {
            $response = "❌ Your account was not linked to begin with.\n\nUse /start to get linking instructions.";
        }
        sendMessage($chat_id, $response, $bot_token);
    }
    // Handle unknown commands
    elseif (strpos($text, '/') === 0) {
        sendMessage($chat_id, "🤖 Unknown command. Type /help for available commands.", $bot_token);
    }
}

// Function to send message to Telegram
function sendMessage($chat_id, $message, $bot_token) {
    $url = "https://api.telegram.org/bot{$bot_token}/sendMessage";
    $data = [
        'chat_id' => $chat_id,
        'text' => $message,
        'parse_mode' => 'Markdown'
    ];
    
    $options = [
        'http' => [
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'method' => 'POST',
            'content' => http_build_query($data)
        ]
    ];
    
    $context = stream_context_create($options);
    $result = file_get_contents($url, false, $context);
    
    // Log errors if any (for debugging)
    if ($result === false) {
        error_log("Telegram API error for chat_id: $chat_id");
    }
    
    return $result;
}

// Optional: Function to send appointment reminder (call this from your appointment creation script)
function sendAppointmentReminder($patient_id, $appointment_id, $pdo, $bot_token) {
    // Get patient and appointment details
    $stmt = $pdo->prepare("
        SELECT p.telegram_user_id, p.telegram_chat_id, p.first_name, p.last_name,
               a.appointment_date, a.appointment_time, a.queue_number,
               CONCAT(d.first_name, ' ', d.last_name) as doctor_name
        FROM patients p
        JOIN appointments a ON p.patient_id = a.patient_id
        JOIN doctors d ON a.doctor_id = d.doctor_id
        WHERE p.patient_id = ? AND a.appointment_id = ?
    ");
    $stmt->execute([$patient_id, $appointment_id]);
    $data = $stmt->fetch();
    
    if ($data && ($data['telegram_user_id'] || $data['telegram_chat_id'])) {
        $chat_id = $data['telegram_user_id'] ?? $data['telegram_chat_id'];
        $date = new DateTime($data['appointment_date']);
        $time = new DateTime($data['appointment_time']);
        
        $message = "🏥 *Appointment Reminder*\n\n";
        $message .= "Hello {$data['first_name']} {$data['last_name']},\n\n";
        $message .= "This is a reminder for your upcoming appointment:\n\n";
        $message .= "📅 Date: " . $date->format('l, F j, Y') . "\n";
        $message .= "⏰ Time: " . $time->format('g:i A') . "\n";
        $message .= "👨‍⚕️ Doctor: Dr. {$data['doctor_name']}\n";
        $message .= "🎫 Queue #: {$data['queue_number']}\n\n";
        $message .= "Please arrive 10 minutes before your scheduled time.\n";
        $message .= "To cancel or reschedule, please call the clinic.";
        
        sendMessage($chat_id, $message, $bot_token);
        return true;
    }
    return false;
}
?>
