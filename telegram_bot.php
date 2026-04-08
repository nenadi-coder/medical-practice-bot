<?php
    // Force outgoing connections
stream_context_set_default([
    'http' => [
        'timeout' => 30,
        'user_agent' => 'Mozilla/5.0 (compatible; TelegramBot)'
    ]
]);
require_once __DIR__ . '/includes/config.php';

$bot_token = '8330456846:AAFt-Ae7-gJs4hml0vUkjMnmX-CqRMuAKes';
ini_set('display_errors', 1);
error_reporting(E_ALL);
file_put_contents('telegram_log.txt', date('Y-m-d H:i:s') . " - Bot hit\n", FILE_APPEND);

$content = file_get_contents("php://input");
$update = json_decode($content, true);

// Handle button clicks
if (isset($update['callback_query'])) {
    $chat_id = $update['callback_query']['message']['chat']['id'];
    $data = $update['callback_query']['data'];
    
    answerCallbackQuery($update['callback_query']['id'], $bot_token);
    
    if ($data == 'myappointments') {
        $patient = getPatientByChatId($chat_id, $pdo);
        if ($patient) {
            showAppointments($chat_id, $patient['patient_id'], $pdo, $bot_token);
        } else {
            sendTelegramMessage($chat_id, "❌ Account not linked. Please send your email address.", $bot_token);
        }
    }
    elseif ($data == 'next') {
        $patient = getPatientByChatId($chat_id, $pdo);
        if ($patient) {
            showNextAppointment($chat_id, $patient['patient_id'], $pdo, $bot_token);
        } else {
            sendTelegramMessage($chat_id, "❌ Account not linked. Please send your email address.", $bot_token);
        }
    }
    elseif ($data == 'queue') {
        $patient = getPatientByChatId($chat_id, $pdo);
        if ($patient) {
            showQueuePosition($chat_id, $patient['patient_id'], $pdo, $bot_token);
        } else {
            sendTelegramMessage($chat_id, "❌ Account not linked. Please send your email address.", $bot_token);
        }
    }
    elseif ($data == 'cancel') {
        sendTelegramMessage($chat_id, "❌ To cancel: /cancel [id]\nExample: /cancel 5", $bot_token);
    }
    elseif ($data == 'reschedule') {
        sendTelegramMessage($chat_id, "📅 Reschedule on website: https://medicalpractice.free.nf/medical_practice/", $bot_token);
    }
    elseif ($data == 'link') {
        sendTelegramMessage($chat_id, "🔗 Please send your email address to link your account.", $bot_token);
    }
    elseif ($data == 'help') {
        showHelpMenu($chat_id, $bot_token);
    }
    elseif ($data == 'main_menu') {
        showMainMenu($chat_id, $bot_token);
    }
}
// Handle messages
elseif (isset($update['message'])) {
    $chat_id = $update['message']['chat']['id'];
    $message_text = trim($update['message']['text']);
    $username = $update['message']['from']['username'] ?? 'unknown';
    $first_name = $update['message']['from']['first_name'] ?? '';
    
    // Check if message is an email
    if (filter_var($message_text, FILTER_VALIDATE_EMAIL)) {
        $sql = "SELECT patient_id, first_name FROM patients WHERE email = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$message_text]);
        $patient = $stmt->fetch();
        
        if ($patient) {
            $update_sql = "UPDATE patients SET telegram_chat_id = ?, telegram_username = ?, telegram_linked_at = NOW() WHERE patient_id = ?";
            $update_stmt = $pdo->prepare($update_sql);
            if ($update_stmt->execute([$chat_id, $username, $patient['patient_id']])) {
                sendTelegramMessage($chat_id, "✅ Successfully linked! Welcome " . $patient['first_name'] . "! 🎉", $bot_token);
                showMainMenu($chat_id, $bot_token);
            } else {
                sendTelegramMessage($chat_id, "❌ Failed to link. Please try again.", $bot_token);
            }
        } else {
            sendTelegramMessage($chat_id, "❌ Email not found in our system.\n\nPlease register first:\nhttps://medicalpractice.free.nf/medical_practice/patient/register.php", $bot_token);
        }
    }
    // Handle /start command
    elseif ($message_text == '/start') {
        $patient = getPatientByChatId($chat_id, $pdo);
        if ($patient) {
            // Already linked - show welcome back
            $welcome = "🏥 Welcome back, " . htmlspecialchars($patient['first_name']) . "! 👋\n\n";
            $welcome .= "What would you like to do today?";
            sendTelegramMessage($chat_id, $welcome, $bot_token);
            showMainMenu($chat_id, $bot_token);
        } else {
            // Not linked - ask for email
            $welcome = "🏥 *Welcome to Medical Practice Bot* " . htmlspecialchars($first_name) . "! 🏥\n\n";
            $welcome .= "I'm here to help you manage your medical appointments.\n\n";
            $welcome .= "📋 *What I can do for you:*\n";
            $welcome .= "• View your appointments\n";
            $welcome .= "• Check your queue position\n";
            $welcome .= "• Cancel appointments\n";
            $welcome .= "• Get appointment reminders\n\n";
            $welcome .= "🔐 *To get started, please send me your email address.*\n\n";
            $welcome .= "📝 *Example:* `johndoe@gmail.com`\n\n";
            $welcome .= "⚠️ *Don't have an account?*\n";
            $welcome .= "Register here: https://medicalpractice.free.nf/medical_practice/patient/register.php";
            
            sendTelegramMessage($chat_id, $welcome, $bot_token);
        }
    }
    elseif ($message_text == '/help') {
        showHelpMenu($chat_id, $bot_token);
    }
    elseif ($message_text == '/myappointments') {
        $patient = getPatientByChatId($chat_id, $pdo);
        if ($patient) {
            showAppointments($chat_id, $patient['patient_id'], $pdo, $bot_token);
        } else {
            sendTelegramMessage($chat_id, "❌ Please send your email address first to link your account.", $bot_token);
        }
    }
    elseif ($message_text == '/next') {
        $patient = getPatientByChatId($chat_id, $pdo);
        if ($patient) {
            showNextAppointment($chat_id, $patient['patient_id'], $pdo, $bot_token);
        } else {
            sendTelegramMessage($chat_id, "❌ Please send your email address first to link your account.", $bot_token);
        }
    }
    elseif ($message_text == '/queue') {
        $patient = getPatientByChatId($chat_id, $pdo);
        if ($patient) {
            showQueuePosition($chat_id, $patient['patient_id'], $pdo, $bot_token);
        } else {
            sendTelegramMessage($chat_id, "❌ Please send your email address first to link your account.", $bot_token);
        }
    }
    elseif (strpos($message_text, '/cancel') === 0) {
        $patient = getPatientByChatId($chat_id, $pdo);
        if ($patient) {
            cancelAppointment($chat_id, $message_text, $patient['patient_id'], $pdo, $bot_token);
        } else {
            sendTelegramMessage($chat_id, "❌ Please send your email address first to link your account.", $bot_token);
        }
    }
    else {
        // Unknown command - ask for email or /start
        sendTelegramMessage($chat_id, "❌ I don't understand that.\n\nPlease type /start to begin or send your email address to link your account.", $bot_token);
    }
}

// ========== FUNCTIONS ==========
function answerCallbackQuery($callback_id, $bot_token) {
    $url = "https://api.telegram.org/bot{$bot_token}/answerCallbackQuery";
    $data = ['callback_query_id' => $callback_id];
    $options = ['http' => ['header' => "Content-type: application/x-www-form-urlencoded\r\n", 'method' => 'POST', 'content' => http_build_query($data)]];
    @file_get_contents($url, false, stream_context_create($options));
}

function showMainMenu($chat_id, $bot_token) {
    $keyboard = ['inline_keyboard' => [
        [['text' => '📋 My Appointments', 'callback_data' => 'myappointments'], ['text' => '⏰ Next Appointment', 'callback_data' => 'next']],
        [['text' => '🎫 Queue Position', 'callback_data' => 'queue'], ['text' => '❌ Cancel Appointment', 'callback_data' => 'cancel']],
        [['text' => '📅 Reschedule', 'callback_data' => 'reschedule'], ['text' => '❓ Help', 'callback_data' => 'help']]
    ]];
    sendTelegramMessageWithButtons($chat_id, "🏥 *Main Menu*\n\nChoose an option:", $bot_token, $keyboard);
}

function showHelpMenu($chat_id, $bot_token) {
    $text = "📋 *Available Commands:*\n\n";
    $text .= "/start - Show main menu\n";
    $text .= "/help - Show this help\n";
    $text .= "/myappointments - List all your appointments\n";
    $text .= "/next - Show your next appointment\n";
    $text .= "/queue - Check your queue position\n";
    $text .= "/cancel [id] - Cancel an appointment\n\n";
    $text .= "📧 *Need to link your account?*\n";
    $text .= "Just send your email address.\n\n";
    $text .= "💻 *Website:*\nhttps://medicalpractice.free.nf/medical_practice/";
    
    $keyboard = ['inline_keyboard' => [[['text' => '◀️ Back to Menu', 'callback_data' => 'main_menu']]]];
    sendTelegramMessageWithButtons($chat_id, $text, $bot_token, $keyboard);
}

function getPatientByChatId($chat_id, $pdo) {
    $stmt = $pdo->prepare("SELECT * FROM patients WHERE telegram_chat_id = ?");
    $stmt->execute([$chat_id]);
    return $stmt->fetch();
}

function showAppointments($chat_id, $patient_id, $pdo, $bot_token) {
    $stmt = $pdo->prepare("SELECT a.*, CONCAT(d.first_name,' ',d.last_name) as doctor_name FROM appointments a JOIN doctors d ON a.doctor_id = d.doctor_id WHERE a.patient_id = ? AND a.appointment_date >= CURDATE() AND a.status NOT IN ('cancelled', 'completed') ORDER BY a.appointment_date ASC LIMIT 5");
    $stmt->execute([$patient_id]);
    $apts = $stmt->fetchAll();
    
    if (count($apts) > 0) {
        $msg = "📋 *Your Upcoming Appointments:*\n\n";
        foreach ($apts as $a) {
            $date = date('l, F j, Y', strtotime($a['appointment_date']));
            $time = date('g:i A', strtotime($a['appointment_time']));
            $msg .= "👨‍⚕️ *Dr. {$a['doctor_name']}*\n";
            $msg .= "📅 {$date}\n";
            $msg .= "🕒 {$time}\n";
            $msg .= "🎫 Queue #{$a['queue_number']}\n";
            $msg .= "📌 Status: " . ucfirst($a['status']) . "\n\n";
            $msg .= "─ ─ ─ ─ ─ ─ ─ ─ ─ ─\n\n";
        }
        $msg .= "To cancel: /cancel [appointment_id]";
    } else {
        $msg = "📭 *No upcoming appointments*\n\n";
        $msg .= "Book an appointment on our website:\n";
        $msg .= "https://medicalpractice.free.nf/medical_practice/patient/book_appointment.php";
    }
    
    $keyboard = ['inline_keyboard' => [[['text' => '◀️ Back to Menu', 'callback_data' => 'main_menu']]]];
    sendTelegramMessageWithButtons($chat_id, $msg, $bot_token, $keyboard);
}

function showNextAppointment($chat_id, $patient_id, $pdo, $bot_token) {
    $stmt = $pdo->prepare("SELECT a.*, CONCAT(d.first_name,' ',d.last_name) as doctor_name FROM appointments a JOIN doctors d ON a.doctor_id = d.doctor_id WHERE a.patient_id = ? AND a.appointment_date >= CURDATE() AND a.status NOT IN ('cancelled', 'completed') ORDER BY a.appointment_date ASC LIMIT 1");
    $stmt->execute([$patient_id]);
    $a = $stmt->fetch();
    
    if ($a) {
        $date = date('l, F j, Y', strtotime($a['appointment_date']));
        $time = date('g:i A', strtotime($a['appointment_time']));
        
        $msg = "⏰ *Your Next Appointment*\n\n";
        $msg .= "👨‍⚕️ *Dr. {$a['doctor_name']}*\n";
        $msg .= "📅 {$date}\n";
        $msg .= "🕒 {$time}\n";
        $msg .= "🎫 Queue #{$a['queue_number']}\n";
        $msg .= "📌 Status: " . ucfirst($a['status']) . "\n";
        
        if ($a['appointment_date'] == date('Y-m-d')) { 
            $msg .= "\n✅ *TODAY!* Please arrive 10 minutes early.\n";
        }
        
        $days_until = (strtotime($a['appointment_date']) - strtotime(date('Y-m-d'))) / 86400;
        if ($days_until == 1) {
            $msg .= "\n📅 *Tomorrow!* Don't forget your appointment.";
        }
    } else { 
        $msg = "📭 *No upcoming appointments*\n\nBook one on our website!"; 
    }
    
    $keyboard = ['inline_keyboard' => [[['text' => '◀️ Back to Menu', 'callback_data' => 'main_menu']]]];
    sendTelegramMessageWithButtons($chat_id, $msg, $bot_token, $keyboard);
}

function showQueuePosition($chat_id, $patient_id, $pdo, $bot_token) {
    $today = date('Y-m-d');
    
    $stmt = $pdo->prepare("SELECT appointment_id, queue_number, status FROM appointments WHERE patient_id = ? AND appointment_date = ? AND status IN ('scheduled','confirmed','checked_in')");
    $stmt->execute([$patient_id, $today]);
    $a = $stmt->fetch();
    
    if ($a) {
        $ahead = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_date = ? AND queue_number < ? AND status IN ('scheduled','confirmed','checked_in')");
        $ahead->execute([$today, $a['queue_number']]);
        $people = $ahead->fetchColumn();
        
        $position = $people + 1;
        
        $msg = "🎫 *Your Queue Information*\n\n";
        $msg .= "📅 Date: " . date('F j, Y') . "\n";
        $msg .= "🎫 Your Queue #: *{$a['queue_number']}*\n";
        $msg .= "📍 Current Position: *{$position}*\n";
        $msg .= "👥 People ahead: {$people}\n\n";
        
        if ($people == 0) {
            $msg .= "✅ *You're NEXT!* 🏥\n";
            $msg .= "Please be ready, the doctor will call you shortly.\n";
        } else {
            $wait_time = $people * 15;
            $msg .= "⏱️ *Estimated wait time:* ~{$wait_time} minutes\n";
            $msg .= "(Based on 15 minutes per patient)\n\n";
            $msg .= "We'll notify you when it's almost your turn!";
        }
        
        if ($a['status'] == 'checked_in') {
            $msg .= "\n\n✅ *You have checked in.*\nWaiting for the doctor...";
        }
    } else {
        $completed = $pdo->prepare("SELECT status FROM appointments WHERE patient_id = ? AND appointment_date = ? AND status = 'completed'");
        $completed->execute([$patient_id, $today]);
        if ($completed->rowCount() > 0) {
            $msg = "✅ *Appointment Completed*\n\nThank you for visiting us today!\n\nWe hope you had a good experience. 🤝";
        } else {
            $msg = "🎫 *No active appointment today*\n\n";
            $msg .= "You don't have any appointment scheduled for today.\n\n";
            $msg .= "Check your upcoming appointments using /myappointments";
        }
    }
    
    $keyboard = ['inline_keyboard' => [[['text' => '◀️ Back to Menu', 'callback_data' => 'main_menu']]]];
    sendTelegramMessageWithButtons($chat_id, $msg, $bot_token, $keyboard);
}

function cancelAppointment($chat_id, $text, $patient_id, $pdo, $bot_token) {
    $parts = explode(' ', $text);
    if (count($parts) < 2) { 
        sendTelegramMessage($chat_id, "❌ *How to cancel:*\n\nSend: /cancel [appointment_id]\n\nExample: /cancel 5\n\nUse /myappointments to see your appointment IDs.", $bot_token); 
        return; 
    }
    $id = intval($parts[1]);
    $check = $pdo->prepare("SELECT a.*, CONCAT(d.first_name,' ',d.last_name) as doctor_name FROM appointments a JOIN doctors d ON a.doctor_id = d.doctor_id WHERE a.appointment_id = ? AND a.patient_id = ? AND a.status IN ('scheduled','confirmed')");
    $check->execute([$id, $patient_id]);
    $apt = $check->fetch();
    
    if ($apt) {
        $pdo->prepare("UPDATE appointments SET status = 'cancelled' WHERE appointment_id = ?")->execute([$id]);
        $msg = "✅ *Appointment Cancelled*\n\n";
        $msg .= "Appointment #{$id} with Dr. {$apt['doctor_name']} on " . date('F j, Y', strtotime($apt['appointment_date'])) . " has been cancelled.\n\n";
        $msg .= "You can book a new appointment on our website.";
        sendTelegramMessage($chat_id, $msg, $bot_token);
    } else { 
        sendTelegramMessage($chat_id, "❌ Appointment #{$id} not found or already cancelled/completed.\n\nUse /myappointments to see your active appointments.", $bot_token); 
    }
}

function sendTelegramMessage($chat_id, $message, $bot_token) {
    $url = "https://api.telegram.org/bot{$bot_token}/sendMessage";
    $data = ['chat_id' => $chat_id, 'text' => $message, 'parse_mode' => 'Markdown'];
    
    $options = [
        'http' => [
            'header' => "Content-type: application/x-www-form-urlencoded\r\n",
            'method' => 'POST',
            'content' => http_build_query($data),
            'timeout' => 30,
            'ignore_errors' => true
        ]
    ];
    
    $context = stream_context_create($options);
    $result = @file_get_contents($url, false, $context);
    
    // Log the result for debugging
    file_put_contents('telegram_log.txt', date('Y-m-d H:i:s') . " - Send result: " . $result . "\n", FILE_APPEND);
    
    return $result;
}

function sendTelegramMessageWithButtons($chat_id, $message, $bot_token, $keyboard) {
    $url = "https://api.telegram.org/bot{$bot_token}/sendMessage";
    $data = ['chat_id' => $chat_id, 'text' => $message, 'parse_mode' => 'Markdown', 'reply_markup' => json_encode($keyboard)];
    $options = ['http' => ['header' => "Content-type: application/x-www-form-urlencoded\r\n", 'method' => 'POST', 'content' => http_build_query($data)]];
    @file_get_contents($url, false, stream_context_create($options));
}
?>
