<?php
require_once 'includes/config.php';
// Database configuration
define('DB_HOST', 'db-mysql-nyc3-10499-do-user-36185384-0.e.db.ondigitalocean.com');
define('DB_PORT', '25060');
define('DB_NAME', 'if0_41555171_medical_practice');
define('DB_USER', 'doadmin');
define('DB_PASS', 'AVNS_xAlHu7MeZoKMxKJ7Esn'); 
define('DB_SSL_CA', __DIR__ . '/ca-certificate.crt');

// Telegram Bot Token
define('BOT_TOKEN', '8330456846:AAHSmyKZrvCL5yLqpHjynBMqC6tM2u9k6N8'); // CHANGE THIS

// Webhook secret token
define('SECRET_TOKEN', 'nadia'); // CHANGE THIS


try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_SSL_CA => DB_SSL_CA,
            PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true
        ]
    );
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    http_response_code(500);
    exit();
}

// Verify webhook security
$headers = getallheaders();
if (!isset($headers['X-Telegram-Bot-Api-Secret-Token']) || $headers['X-Telegram-Bot-Api-Secret-Token'] !== SECRET_TOKEN) {
    http_response_code(403);
    exit();
}

// Get update
$input = file_get_contents('php://input');
if (empty($input)) {
    http_response_code(200);
    exit();
}

$update = json_decode($input, true);
if (!$update) {
    http_response_code(200);
    exit();
}

// Process update
try {
    processUpdate($update, $pdo);
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
}

http_response_code(200);
exit();

function processUpdate($update, $pdo) {
    if (!isset($update['message'])) return;
    
    $message = $update['message'];
    $chat_id = $message['chat']['id'];
    $text = trim($message['text'] ?? '');
    $user_id = $message['from']['id'];
    $username = $message['from']['username'] ?? '';
    
    // Check if user is already linked
    $stmt = $pdo->prepare("
        SELECT patient_id, first_name, last_name, email 
        FROM patients 
        WHERE telegram_chat_id = ? OR telegram_user_id = ? OR telegram_id = ?
    ");
    $stmt->execute([$chat_id, $chat_id, $chat_id]);
    $linked_patient = $stmt->fetch();
    
    // If already linked, handle commands normally
    if ($linked_patient) {
        // Handle commands
        if (strpos($text, '/') === 0) {
            switch ($text) {
                case '/start':
                    sendWelcomeLinked($chat_id, $linked_patient['first_name']);
                    break;
                case '/help':
                    sendHelp($chat_id);
                    break;
                case '/status':
                    sendStatus($chat_id, $pdo);
                    break;
                case '/next':
                    sendNextAppointment($chat_id, $pdo);
                    break;
                default:
                    sendMessage($chat_id, "❌ Unknown command. Type /help for options.");
            }
        }
        return;
    }
    
    // Check if user is in the process of linking (waiting for email)
    session_start();
    $waiting_for_email = $_SESSION['waiting_email_' . $chat_id] ?? false;
    
    if ($waiting_for_email) {
        // User just sent their email - link them!
        unset($_SESSION['waiting_email_' . $chat_id]);
        handleEmailLinking($chat_id, $text, $user_id, $username, $pdo);
        return;
    }
    
    // Handle commands for unlinked users
    if (strpos($text, '/') === 0) {
        switch ($text) {
            case '/start':
                askForEmail($chat_id);
                break;
            case '/help':
                sendHelp($chat_id);
                break;
            default:
                sendMessage($chat_id, "❌ Please type /start to link your account.");
        }
    }
}

function askForEmail($chat_id) {
    session_start();
    $_SESSION['waiting_email_' . $chat_id] = true;
    
    $response = "🏥 *Welcome to Shifa Medical Center Bot!*\n\n";
    $response .= "To get started, please enter the email address you used when registering at our clinic.\n\n";
    $response .= "📧 *Example:* john.doe@email.com\n\n";
    $response .= "_Don't worry, this is a one-time setup!_";
    
    sendMessage($chat_id, $response);
}

function handleEmailLinking($chat_id, $email, $user_id, $username, $pdo) {
    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sendMessage($chat_id, "❌ *Invalid Email Format*\n\nPlease enter a valid email address.\n\nType /start to try again.");
        return;
    }
    
    // Search for patient with this email
    $stmt = $pdo->prepare("
        SELECT patient_id, first_name, last_name, email, phone 
        FROM patients 
        WHERE email = ?
    ");
    $stmt->execute([$email]);
    $patient = $stmt->fetch();
    
    if (!$patient) {
        sendMessage($chat_id, "❌ *Email Not Found*\n\nWe couldn't find '{$email}' in our records.\n\nPlease use the email you provided when registering at the clinic.\n\nType /start to try again.");
        return;
    }
    
    // Email found! Link the account automatically
    $stmt = $pdo->prepare("
        UPDATE patients 
        SET telegram_chat_id = ?, 
            telegram_user_id = ?, 
            telegram_username = ?,
            telegram_id = ?,
            telegram_linked_at = NOW()
        WHERE patient_id = ?
    ");
    $stmt->execute([$chat_id, $user_id, $username, $chat_id, $patient['patient_id']]);
    
    // Send welcome message
    sendWelcomeNew($chat_id, $patient['first_name'], $patient['last_name']);
}

function sendWelcomeNew($chat_id, $first_name, $last_name) {
    $response = "✅ *Account Linked Successfully!*\n\n";
    $response .= "Welcome back, *{$first_name} {$last_name}*! 🎉\n\n";
    $response .= "Your Telegram account is now connected to your patient record.\n\n";
    $response .= "📱 *What you can do:*\n";
    $response .= "🔹 /next - View your next appointment\n";
    $response .= "🔹 /status - Check your account status\n";
    $response .= "🔹 /help - See all commands\n\n";
    $response .= "_You'll also receive automatic appointment reminders here!_";
    
    sendMessage($chat_id, $response);
}

function sendWelcomeLinked($chat_id, $first_name) {
    $response = "🏥 *Welcome back to Shifa Medical Center!*\n\n";
    $response .= "Hello *{$first_name}*! 👋\n\n";
    $response .= "📱 *Quick Commands:*\n";
    $response .= "/next - Your next appointment\n";
    $response .= "/status - Account information\n";
    $response .= "/help - All commands";
    
    sendMessage($chat_id, $response);
}

function sendHelp($chat_id) {
    $response = "🤖 *Shifa Medical Center Bot*\n\n";
    $response .= "*Commands:*\n";
    $response .= "/start - Connect your account\n";
    $response .= "/status - View your account info\n";
    $response .= "/next - See your next appointment\n";
    $response .= "/help - Show this menu\n\n";
    $response .= "*Need help?* Call our reception at (555) 123-4567";
    
    sendMessage($chat_id, $response);
}

function sendStatus($chat_id, $pdo) {
    $stmt = $pdo->prepare("
        SELECT first_name, last_name, email, phone, telegram_linked_at
        FROM patients 
        WHERE telegram_chat_id = ? OR telegram_user_id = ? OR telegram_id = ?
    ");
    $stmt->execute([$chat_id, $chat_id, $chat_id]);
    $patient = $stmt->fetch();
    
    if ($patient) {
        $response = "👤 *Your Account Information*\n\n";
        $response .= "📛 *Name:* {$patient['first_name']} {$patient['last_name']}\n";
        $response .= "📧 *Email:* {$patient['email']}\n";
        $response .= "📞 *Phone:* " . ($patient['phone'] ?? 'Not provided') . "\n";
        $response .= "🔗 *Linked:* " . date('M d, Y', strtotime($patient['telegram_linked_at'])) . "\n\n";
        $response .= "✅ Your account is active and ready!";
    } else {
        $response = "❌ *Account Not Linked*\n\nType /start to connect your account.";
    }
    
    sendMessage($chat_id, $response);
}

function sendNextAppointment($chat_id, $pdo) {
    // Get patient
    $stmt = $pdo->prepare("
        SELECT patient_id, first_name 
        FROM patients 
        WHERE telegram_chat_id = ? OR telegram_user_id = ? OR telegram_id = ?
    ");
    $stmt->execute([$chat_id, $chat_id, $chat_id]);
    $patient = $stmt->fetch();
    
    if (!$patient) {
        sendMessage($chat_id, "❌ Please link your account first. Type /start");
        return;
    }
    
    // Get next appointment
    $stmt = $pdo->prepare("
        SELECT 
            a.appointment_date,
            a.appointment_time,
            a.queue_number,
            a.status,
            CONCAT(d.first_name, ' ', d.last_name) as doctor_name,
            d.specialization
        FROM appointments a
        JOIN doctors d ON a.doctor_id = d.doctor_id
        WHERE a.patient_id = ? 
            AND a.appointment_date >= CURDATE() 
            AND a.status NOT IN ('cancelled')
        ORDER BY a.appointment_date ASC, a.appointment_time ASC
        LIMIT 1
    ");
    $stmt->execute([$patient['patient_id']]);
    $apt = $stmt->fetch();
    
    if ($apt) {
        $status_emoji = [
            'scheduled' => '📅',
            'confirmed' => '✅',
            'completed' => '✔️'
        ];
        $emoji = $status_emoji[$apt['status']] ?? '📅';
        
        $response = "$emoji *Your Next Appointment*\n\n";
        $response .= "📆 *Date:* " . date('l, F j, Y', strtotime($apt['appointment_date'])) . "\n";
        $response .= "⏰ *Time:* " . date('g:i A', strtotime($apt['appointment_time'])) . "\n";
        $response .= "👨‍⚕️ *Doctor:* Dr. {$apt['doctor_name']}\n";
        
        if ($apt['specialization']) {
            $response .= "🏥 *Department:* {$apt['specialization']}\n";
        }
        if ($apt['queue_number']) {
            $response .= "🔢 *Queue #:* {$apt['queue_number']}\n";
        }
        
        $response .= "\n⚠️ Please arrive 15 minutes early.";
        
        if ($apt['appointment_date'] == date('Y-m-d')) {
            $response .= "\n\n🔔 *TODAY!* Your appointment is today!";
        }
    } else {
        $response = "📅 *No Upcoming Appointments*\n\nYou have no upcoming appointments scheduled.\n\n📞 Call the clinic to book an appointment.";
    }
    
    sendMessage($chat_id, $response);
}

function sendMessage($chat_id, $message) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendMessage";
    
    $data = [
        'chat_id' => $chat_id,
        'text' => $message,
        'parse_mode' => 'Markdown'
    ];
    
    $options = [
        'http' => [
            'header' => "Content-Type: application/json\r\n",
            'method' => 'POST',
            'content' => json_encode($data),
            'timeout' => 5
        ]
    ];
    
    $context = stream_context_create($options);
    @file_get_contents($url, false, $context);
}
?>
