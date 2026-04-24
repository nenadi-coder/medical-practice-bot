<?php
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', '/tmp/telegram_bot.log');
require_once 'includes/config.php';  

$bot_token = '8330456846:AAHSmyKZrvCL5yLqpHjynBMqC6tM2u9k6N8';

$content = file_get_contents('php://input');
$update = json_decode($content, true);

if (!$update) {
    exit();
}

// Check if already linked
$stmt = $pdo->prepare("SELECT * FROM patients WHERE telegram_chat_id = ?");
$stmt->execute([$chat_id ?? 0]);
$patient = $stmt->fetch();

if (isset($update['message'])) {
    $message = $update['message'];
    $chat_id = $message['chat']['id'];
    $text = trim($message['text'] ?? '');
    $first_name = $message['from']['first_name'] ?? '';
    
    // /start command
    if ($text == '/start') {
        if ($patient) {
            $response = "👋 *Welcome back, {$patient['first_name']}!*\n\n";
            $response .= "📋 *Commands:*\n";
            $response .= "• /next - Your next appointment\n";
            $response .= "• /appointments - View all\n";
            $response .= "• /queue - Queue position\n";
            $response .= "• /profile - Your profile\n";
            $response .= "• /status - Check status\n";
            $response .= "• /askappointment - Book new appointment\n";
            $response .= "• /help - All commands";
        } else {
            $response = "🏥 *Welcome to Shifa Medical Center Bot!*\n\n";
            $response .= "To link your account:\n";
            $response .= "1️⃣ Login to your patient portal\n";
            $response .= "2️⃣ Click 'Open Telegram Bot'\n\n";
            $response .= "_You'll receive reminders once linked._";
        }
        sendMessage($chat_id, $response, $bot_token);
        exit();
    }
    
    // /status
    if ($text == '/status') {
        if ($patient) {
            $response = "✅ *Account Linked!*\n\n";
            $response .= "Name: {$patient['first_name']} {$patient['last_name']}\n";
            $response .= "Email: {$patient['email']}\n\n";
            $response .= "You will receive appointment reminders here.";
        } else {
            $response = "❌ *Account Not Linked*\n\n";
            $response .= "Please login to your patient portal and click 'Open Telegram Bot'.";
        }
        sendMessage($chat_id, $response, $bot_token);
        exit();
    }
    
    // /help
    if ($text == '/help') {
        $response = "🤖 *Available Commands:*\n\n";
        $response .= "*/start* - Welcome message\n";
        $response .= "*/status* - Check account status\n";
        $response .= "*/next* - Your next appointment\n";
        $response .= "*/appointments* - All appointments\n";
        $response .= "*/queue* - Queue position\n";
        $response .= "*/profile* - Your profile\n";
        $response .= "*/askappointment* - Book a new appointment\n";
        $response .= "*/help* - This menu\n\n";
        $response .= "🌐 Website: https://shifacenter.me";
        sendMessage($chat_id, $response, $bot_token);
        exit();
    }
    
    // /next
    if ($text == '/next') {
        if (!$patient) {
            sendMessage($chat_id, "❌ Account not linked. Send /status for help.", $bot_token);
            exit();
        }
        
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
            $status_display = ($appointment['status'] == 'confirmed') ? '✅ Confirmed' : '⏳ Pending';
            $response = "📅 *Your Next Appointment*\n\n";
            $response .= "📆 Date: " . date('l, F j, Y', strtotime($appointment['appointment_date'])) . "\n";
            $response .= "⏰ Time: " . date('g:i A', strtotime($appointment['appointment_time'])) . "\n";
            $response .= "👨‍⚕️ Doctor: Dr. {$appointment['doctor_name']}\n";
            $response .= "🎫 Queue #: {$appointment['queue_number']}\n";
            $response .= "📌 Status: {$status_display}";
        } else {
            $response = "📅 *No Upcoming Appointments*\n\nBook using /askappointment";
        }
        sendMessage($chat_id, $response, $bot_token);
        exit();
    }
    
    // /appointments
    if ($text == '/appointments') {
        if (!$patient) {
            sendMessage($chat_id, "❌ Account not linked. Send /status for help.", $bot_token);
            exit();
        }
        
        $apt_stmt = $pdo->prepare("
            SELECT a.*, CONCAT(d.first_name, ' ', d.last_name) as doctor_name
            FROM appointments a
            JOIN doctors d ON a.doctor_id = d.doctor_id
            WHERE a.patient_id = ? 
            ORDER BY a.appointment_date DESC, a.appointment_time DESC
            LIMIT 10
        ");
        $apt_stmt->execute([$patient['patient_id']]);
        $appointments = $apt_stmt->fetchAll();
        
        if (count($appointments) > 0) {
            $response = "📋 *Your Appointments*\n\n";
            foreach ($appointments as $apt) {
                $emoji = match($apt['status']) {
                    'scheduled' => '⏳', 'confirmed' => '✅',
                    'completed' => '✔️', 'cancelled' => '❌',
                    default => '📌'
                };
                $response .= "{$emoji} " . date('M j, Y', strtotime($apt['appointment_date'])) . " - " . date('g:i A', strtotime($apt['appointment_time'])) . "\n";
                $response .= "   Dr. {$apt['doctor_name']} | #{$apt['queue_number']}\n\n";
            }
        } else {
            $response = "📋 *No Appointments Found*\n\nBook using /askappointment";
        }
        sendMessage($chat_id, $response, $bot_token);
        exit();
    }
    
    // /queue
    if ($text == '/queue') {
        if (!$patient) {
            sendMessage($chat_id, "❌ Account not linked. Send /status for help.", $bot_token);
            exit();
        }
        
        $apt_stmt = $pdo->prepare("
            SELECT a.*, CONCAT(d.first_name, ' ', d.last_name) as doctor_name
            FROM appointments a
            JOIN doctors d ON a.doctor_id = d.doctor_id
            WHERE a.patient_id = ? AND a.appointment_date = CURDATE() 
            AND a.appointment_time > CURTIME()
            AND a.status = 'confirmed'
            ORDER BY a.appointment_time ASC
            LIMIT 1
        ");
        $apt_stmt->execute([$patient['patient_id']]);
        $appointment = $apt_stmt->fetch();
        
        if ($appointment) {
            $queue_stmt = $pdo->prepare("SELECT COUNT(*) as ahead FROM appointments WHERE appointment_date = CURDATE() AND queue_number < ? AND status = 'confirmed'");
            $queue_stmt->execute([$appointment['queue_number']]);
            $ahead = $queue_stmt->fetchColumn();
            
            $response = "🎫 *Queue Information*\n\n";
            $response .= "👨‍⚕️ Dr. {$appointment['doctor_name']}\n";
            $response .= "🎟️ Your Queue #: *{$appointment['queue_number']}*\n";
            $response .= "👥 People ahead: $ahead\n";
            $response .= ($ahead == 0) ? "\n🔔 *You're NEXT!*" : "⏱️ Est. wait: ~" . ($ahead * 15) . " min";
        } else {
            $response = "🎫 *No Active Queue*\n\nNo confirmed appointments for today.";
        }
        sendMessage($chat_id, $response, $bot_token);
        exit();
    }
    
    // /profile
    if ($text == '/profile') {
        if (!$patient) {
            sendMessage($chat_id, "❌ Account not linked. Send /status for help.", $bot_token);
            exit();
        }
        
        $response = "👤 *Your Profile*\n\n";
        $response .= "*Name:* {$patient['first_name']} {$patient['last_name']}\n";
        $response .= "*Email:* {$patient['email']}\n";
        $response .= "*Phone:* " . ($patient['phone'] ?? 'Not set') . "\n\n";
        $response .= "_Update on website._";
        sendMessage($chat_id, $response, $bot_token);
        exit();
    }
    
    // /askappointment - Simple version
    if ($text == '/askappointment') {
        if (!$patient) {
            sendMessage($chat_id, "❌ Account not linked. Send /status for help.", $bot_token);
            exit();
        }
        
        $doctors_stmt = $pdo->prepare("SELECT doctor_id, first_name, last_name, specialization FROM doctors");
        $doctors_stmt->execute();
        $doctors = $doctors_stmt->fetchAll();
        
        if (count($doctors) == 0) {
            sendMessage($chat_id, "❌ No doctors available.", $bot_token);
            exit();
        }
        
        $response = "🏥 *Book Appointment*\n\nSelect a doctor:\n\n";
        foreach ($doctors as $index => $doctor) {
            $response .= ($index + 1) . ". Dr. {$doctor['first_name']} {$doctor['last_name']} - {$doctor['specialization']}\n";
        }
        $response .= "\n_Booking via website recommended for now._\n";
        $response .= "Portal: https://shifacenter.me/patient/book_appointment.php";
        
        sendMessage($chat_id, $response, $bot_token);
        exit();
    }
    
    // Unknown command
    sendMessage($chat_id, "🤖 Send /help for commands", $bot_token);
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
    @file_get_contents($url, false, $context);
}
?>
