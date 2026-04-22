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
$first_name = $message['from']['first_name'] ?? '';

// Check if user is already linked
$stmt = $pdo->prepare("SELECT * FROM patients WHERE telegram_chat_id = ? OR telegram_user_id = ?");
$stmt->execute([$chat_id, $chat_id]);
$patient = $stmt->fetch();

// ========== COMMAND HANDLERS ==========

// /start - Welcome message
if ($text === '/start') {
    if ($patient) {
        // Already linked
        $response = "👋 *Welcome back, {$patient['first_name']}!*\n\n";
        $response .= "Your account is already linked. Here's what you can do:\n\n";
        $response .= "📋 *Commands:*\n";
        $response .= "🔹 /appointments - View your appointments\n";
        $response .= "🔹 /next - Your next appointment\n";
        $response .= "🔹 /queue - Check queue position\n";
        $response .= "🔹 /profile - View your profile\n";
        $response .= "🔹 /help - Show all commands\n\n";
        $response .= "_You will automatically receive appointment reminders._";
    } else {
        // Not linked - tell them to use website
        $response = "👋 *Welcome to Shifa Medical Center, $first_name!*\n\n";
        $response .= "I'm your health assistant. To get started:\n\n";
        $response .= "1️⃣ *Login to your patient portal*\n";
        $response .= "2️⃣ *Click 'Link Telegram Account'*\n";
        $response .= "3️⃣ *Your account will be automatically linked*\n\n";
        $response .= "🔗 Portal: https://shifacenter.me/patient/dashboard.php\n\n";
        $response .= "*After linking, you can use these commands:*\n";
        $response .= "• /appointments - View your appointments\n";
        $response .= "• /next - Your next appointment\n";
        $response .= "• /queue - Check your queue position\n";
        $response .= "• /profile - View your profile\n";
        $response .= "• /help - Show all commands\n\n";
        $response .= "_You will receive automatic reminders for your appointments._";
    }
    
    sendMessage($chat_id, $response, $bot_token);
    exit();
}

// /help - Show all commands
if ($text === '/help') {
    $response = "🤖 *Available Commands:*\n\n";
    $response .= "*/start* - Welcome message\n";
    $response .= "*/appointments* - View all your appointments\n";
    $response .= "*/next* - Show your next appointment\n";
    $response .= "*/queue* - Check your queue position\n";
    $response .= "*/profile* - View your profile information\n";
    $response .= "*/reschedule* - How to reschedule\n";
    $response .= "*/cancel* - How to cancel\n";
    $response .= "*/help* - Show this message\n\n";
    $response .= "*Need help?* Visit our website: https://shifacenter.me";
    
    sendMessage($chat_id, $response, $bot_token);
    exit();
}

// /profile - View user profile
if ($text === '/profile') {
    if (!$patient) {
        $response = "❌ *Account Not Linked*\n\n";
        $response .= "Please login to our website and click 'Link Telegram Account' first.\n\n";
        $response .= "Portal: https://shifacenter.me/patient/dashboard.php";
        sendMessage($chat_id, $response, $bot_token);
        exit();
    }
    
    $response = "👤 *Your Profile*\n\n";
    $response .= "*Name:* {$patient['first_name']} {$patient['last_name']}\n";
    $response .= "*Email:* {$patient['email']}\n";
    $response .= "*Phone:* " . ($patient['phone'] ?? 'Not set') . "\n";
    $response .= "*Member since:* " . date('F j, Y', strtotime($patient['created_at'])) . "\n\n";
    $response .= "_To update your profile, please visit our website._";
    
    sendMessage($chat_id, $response, $bot_token);
    exit();
}

// /next - Show next appointment
if ($text === '/next') {
    if (!$patient) {
        $response = "❌ *Account Not Linked*\n\n";
        $response .= "Please login to our website and click 'Link Telegram Account' first.\n\n";
        $response .= "Portal: https://shifacenter.me/patient/dashboard.php";
        sendMessage($chat_id, $response, $bot_token);
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
        $response = "📅 *Your Next Appointment*\n\n";
        $response .= "📆 Date: " . date('l, F j, Y', strtotime($appointment['appointment_date'])) . "\n";
        $response .= "⏰ Time: " . date('g:i A', strtotime($appointment['appointment_time'])) . "\n";
        $response .= "👨‍⚕️ Doctor: Dr. {$appointment['doctor_name']}\n";
        $response .= "🎫 Queue #: {$appointment['queue_number']}\n";
        $response .= "📌 Status: " . ucfirst($appointment['status']) . "\n\n";
        $response .= "_Please arrive 10 minutes early!_";
    } else {
        $response = "📅 *No Upcoming Appointments*\n\n";
        $response .= "You have no upcoming appointments scheduled.\n\n";
        $response .= "Book one on our website: https://shifacenter.me/patient/book_appointment.php";
    }
    
    sendMessage($chat_id, $response, $bot_token);
    exit();
}

// /appointments - Show all appointments
if ($text === '/appointments') {
    if (!$patient) {
        $response = "❌ *Account Not Linked*\n\n";
        $response .= "Please login to our website and click 'Link Telegram Account' first.\n\n";
        $response .= "Portal: https://shifacenter.me/patient/dashboard.php";
        sendMessage($chat_id, $response, $bot_token);
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
            $status_emoji = match($apt['status']) {
                'scheduled' => '⏳',
                'confirmed' => '✅',
                'completed' => '✔️',
                'cancelled' => '❌',
                default => '📌'
            };
            $response .= "{$status_emoji} *" . date('M j, Y', strtotime($apt['appointment_date'])) . "* - " . date('g:i A', strtotime($apt['appointment_time'])) . "\n";
            $response .= "   Dr. {$apt['doctor_name']} | Queue #{$apt['queue_number']}\n";
            $response .= "   Status: " . ucfirst($apt['status']) . "\n\n";
        }
        $response .= "_To book a new appointment, visit our website._";
    } else {
        $response = "📋 *No Appointments Found*\n\n";
        $response .= "You don't have any appointments yet.\n\n";
        $response .= "Book one: https://shifacenter.me/patient/book_appointment.php";
    }
    
    sendMessage($chat_id, $response, $bot_token);
    exit();
}

// /queue - Check queue position
if ($text === '/queue') {
    if (!$patient) {
        $response = "❌ *Account Not Linked*\n\n";
        $response .= "Please login to our website and click 'Link Telegram Account' first.\n\n";
        $response .= "Portal: https://shifacenter.me/patient/dashboard.php";
        sendMessage($chat_id, $response, $bot_token);
        exit();
    }
    
    // Get today's upcoming appointment
    $apt_stmt = $pdo->prepare("
        SELECT a.*, CONCAT(d.first_name, ' ', d.last_name) as doctor_name
        FROM appointments a
        JOIN doctors d ON a.doctor_id = d.doctor_id
        WHERE a.patient_id = ? AND a.appointment_date = CURDATE() 
        AND a.status IN ('scheduled', 'confirmed')
        ORDER BY a.appointment_time ASC
        LIMIT 1
    ");
    $apt_stmt->execute([$patient['patient_id']]);
    $appointment = $apt_stmt->fetch();
    
    if ($appointment) {
        // Count people ahead
        $queue_stmt = $pdo->prepare("
            SELECT COUNT(*) as ahead FROM appointments 
            WHERE appointment_date = CURDATE() 
            AND queue_number < ? 
            AND status IN ('scheduled', 'confirmed')
        ");
        $queue_stmt->execute([$appointment['queue_number']]);
        $ahead = $queue_stmt->fetchColumn();
        
        $total_stmt = $pdo->prepare("
            SELECT COUNT(*) as total FROM appointments 
            WHERE appointment_date = CURDATE() 
            AND status IN ('scheduled', 'confirmed')
        ");
        $total_stmt->execute();
        $total = $total_stmt->fetchColumn();
        
        $response = "🎫 *Queue Information*\n\n";
        $response .= "👨‍⚕️ Doctor: Dr. {$appointment['doctor_name']}\n";
        $response .= "🎟️ Your Queue #: *{$appointment['queue_number']}*\n";
        $response .= "📊 Position: " . ($ahead + 1) . " of $total waiting\n";
        $response .= "👥 People ahead: $ahead\n\n";
        
        if ($ahead == 0) {
            $response .= "🔔 *You're NEXT!* Please be ready when called.\n";
        } else {
            $response .= "⏱️ Estimated wait: ~" . ($ahead * 15) . " minutes\n";
            $response .= "_Based on 15 minutes per patient._\n";
        }
    } else {
        $response = "🎫 *No Active Queue*\n\n";
        $response .= "You don't have any appointments scheduled for today.\n\n";
        $response .= "Send /next to see your next appointment.";
    }
    
    sendMessage($chat_id, $response, $bot_token);
    exit();
}

// /reschedule - Reschedule instruction
if ($text === '/reschedule') {
    $response = "🔄 *How to Reschedule*\n\n";
    $response .= "To reschedule an appointment:\n\n";
    $response .= "1️⃣ Login to your patient portal\n";
    $response .= "2️⃣ Go to 'My Appointments'\n";
    $response .= "3️⃣ Click 'Reschedule' next to the appointment\n";
    $response .= "4️⃣ Choose a new date and time\n\n";
    $response .= "🔗 Portal: https://shifacenter.me/patient/dashboard.php\n\n";
    $response .= "_Note: Appointments can only be rescheduled online._";
    
    sendMessage($chat_id, $response, $bot_token);
    exit();
}

// /cancel - Cancel instruction
if ($text === '/cancel') {
    $response = "❌ *How to Cancel*\n\n";
    $response .= "To cancel an appointment:\n\n";
    $response .= "1️⃣ Login to your patient portal\n";
    $response .= "2️⃣ Go to 'My Appointments'\n";
    $response .= "3️⃣ Click 'Cancel' next to the appointment\n";
    $response .= "4️⃣ Confirm cancellation\n\n";
    $response .= "🔗 Portal: https://shifacenter.me/patient/dashboard.php\n\n";
    $response .= "_Note: Please cancel at least 24 hours in advance._";
    
    sendMessage($chat_id, $response, $bot_token);
    exit();
}

// ========== DEFAULT: Unknown command ==========
$response = "🤖 *I didn't understand that.*\n\n";
$response .= "Send /help to see all available commands.\n\n";
$response .= "Or visit our website: https://shifacenter.me";

sendMessage($chat_id, $response, $bot_token);

// ========== HELPER FUNCTIONS ==========

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
