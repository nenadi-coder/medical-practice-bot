<?php
// ---------------------------------------------------------------------------
// Acknowledge the webhook to Telegram IMMEDIATELY so Apache/PHP-FPM never
// times out waiting for us.  fastcgi_finish_request() closes the HTTP
// connection while the script continues executing in the background.
// ---------------------------------------------------------------------------
http_response_code(200);
header('Content-Type: application/json');
header('Content-Length: 2');
echo '{}';
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    // Fallback for non-FPM environments (e.g. mod_php).
    if (ob_get_level()) {
        ob_end_flush();
    }
    flush();
}

// ---------------------------------------------------------------------------
// Now load config + process the update.  If the DB is unreachable the
// connection will time out quickly (PDO::MYSQL_ATTR_CONNECT_TIMEOUT = 5 s)
// rather than hanging for 30+ seconds.
// ---------------------------------------------------------------------------
require_once 'includes/config.php';

// Bot token — read from config (set TELEGRAM_BOT_TOKEN env variable).
$bot_token = TELEGRAM_BOT_TOKEN;

if (empty($bot_token)) {
    exit();
}

$content = file_get_contents('php://input');
$update  = json_decode($content, true);

if (!$update || !isset($update['message'])) {
    exit();
}

// Guard: if DB connection failed (config.php exited), stop here.
if (!isset($pdo)) {
    exit();
}

$message    = $update['message'];
$chat_id    = $message['chat']['id'];
$user_id    = $message['from']['id'];
$text       = trim($message['text'] ?? '');
$first_name = $message['from']['first_name'] ?? '';

$stmt = $pdo->prepare(
    "SELECT * FROM patients
     WHERE telegram_chat_id = ? OR telegram_user_id = ?
     LIMIT 1"
);
$stmt->execute([$chat_id, $user_id]);
$patient = $stmt->fetch();

if ($patient && empty($patient['telegram_user_id'])) {
    $pdo->prepare("UPDATE patients SET telegram_user_id = ? WHERE patient_id = ?")
        ->execute([$user_id, $patient['patient_id']]);
    $patient['telegram_user_id'] = $user_id;
}

function sendMessage(int $chat_id, string $message, string $bot_token): void
{
    $url  = "https://api.telegram.org/bot{$bot_token}/sendMessage";
    $data = [
        'chat_id'    => $chat_id,
        'text'       => $message,
        'parse_mode' => 'Markdown',
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,   // seconds to establish connection
        CURLOPT_TIMEOUT        => 10,  // seconds for the whole request
    ]);
    @curl_exec($ch);
    curl_close($ch);
}

function getSession(PDO $pdo, int $user_id): ?array
{
    $stmt = $pdo->prepare(
        "SELECT step, data_json FROM telegram_sessions WHERE telegram_user_id = ?"
    );
    $stmt->execute([$user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function saveSession(PDO $pdo, int $user_id, string $step, array $data = []): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO telegram_sessions (telegram_user_id, step, data_json)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE step = VALUES(step), data_json = VALUES(data_json),
                                 updated_at = CURRENT_TIMESTAMP"
    );
    $stmt->execute([$user_id, $step, json_encode($data)]);
}

function clearSession(PDO $pdo, int $user_id): void
{
    $pdo->prepare("DELETE FROM telegram_sessions WHERE telegram_user_id = ?")
        ->execute([$user_id]);
}

$session     = getSession($pdo, $user_id);
$activeStep  = $session ? $session['step'] : '';
$sessionData = ($session && $session['data_json'])
    ? (json_decode($session['data_json'], true) ?? [])
    : [];

if ($text === '/cancel_booking') {
    clearSession($pdo, $user_id);
    sendMessage($chat_id, "Booking cancelled. Send /askappointment to start again.", $bot_token);
    http_response_code(200);
    exit();
}

if ($text === '/askappointment') {
    if (!$patient) {
        $response  = "Account Not Linked\n\nPlease login to our website and link your Telegram account first.\n\nPortal: https://shifacenter.me/patient/dashboard.php";
        sendMessage($chat_id, $response, $bot_token);
        http_response_code(200);
        exit();
    }
    saveSession($pdo, $user_id, 'awaiting_date');
    $response  = "Book an Appointment\n\nPlease enter the desired appointment date.\n\nFormat: YYYY-MM-DD (e.g. " . date('Y-m-d', strtotime('+1 day')) . ")\n\nSend /cancel_booking at any time to abort.";
    sendMessage($chat_id, $response, $bot_token);
    http_response_code(200);
    exit();
}

if ($activeStep === 'awaiting_date' && $patient && $text !== '') {
    $inputDate = $text;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $inputDate)) {
        sendMessage($chat_id, "Invalid format. Please enter the date as YYYY-MM-DD (e.g. " . date('Y-m-d', strtotime('+1 day')) . ").", $bot_token);
        http_response_code(200);
        exit();
    }
    $parsedDate = DateTime::createFromFormat('Y-m-d', $inputDate);
    if (!$parsedDate || $parsedDate->format('Y-m-d') !== $inputDate) {
        sendMessage($chat_id, "Invalid date. Please try again using YYYY-MM-DD format.", $bot_token);
        http_response_code(200);
        exit();
    }
    if ($parsedDate < new DateTime('today')) {
        sendMessage($chat_id, "The date must be today or in the future. Please enter a valid date.", $bot_token);
        http_response_code(200);
        exit();
    }
    saveSession($pdo, $user_id, 'awaiting_time', ['date' => $inputDate]);
    $response  = "Date set: {$inputDate}\n\nNow please enter the desired appointment time.\n\nFormat: HH:MM (e.g. 09:30 or 14:00)\n\nSend /cancel_booking to abort.";
    sendMessage($chat_id, $response, $bot_token);
    http_response_code(200);
    exit();
}

if ($activeStep === 'awaiting_time' && $patient && $text !== '') {
    $inputTime = $text;
    if (!preg_match('/^\d{2}:\d{2}$/', $inputTime)) {
        sendMessage($chat_id, "Invalid format. Please enter the time as HH:MM (e.g. 09:30).", $bot_token);
        http_response_code(200);
        exit();
    }
    [$hour, $minute] = array_map('intval', explode(':', $inputTime));
    if ($hour > 23 || $minute > 59) {
        sendMessage($chat_id, "Invalid time. Please use HH:MM format (e.g. 09:00).", $bot_token);
        http_response_code(200);
        exit();
    }
    $appointmentDate = $sessionData['date'];
    // Default doctor — override via DEFAULT_DOCTOR_ID environment variable.
    $doctor_id = (int) (getenv('DEFAULT_DOCTOR_ID') ?: 1);

    $avail_stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM appointments
         WHERE doctor_id = ?
           AND appointment_date = ?
           AND appointment_time = ?
           AND status IN ('scheduled', 'confirmed')"
    );
    $avail_stmt->execute([$doctor_id, $appointmentDate, $inputTime . ':00']);
    $slotTaken = (int) $avail_stmt->fetchColumn() > 0;

    if ($slotTaken) {
        $response  = "Slot Unavailable\n\nThe time {$inputTime} on {$appointmentDate} is already booked.\n\nPlease enter a different time (HH:MM):";
        sendMessage($chat_id, $response, $bot_token);
        http_response_code(200);
        exit();
    }

    $queue_stmt = $pdo->prepare(
        "SELECT COALESCE(MAX(queue_number), 0) + 1
         FROM appointments
         WHERE doctor_id = ?
           AND appointment_date = ?
           AND status IN ('scheduled', 'confirmed')"
    );
    $queue_stmt->execute([$doctor_id, $appointmentDate]);
    $queue_number = (int) $queue_stmt->fetchColumn();

    $ins_stmt = $pdo->prepare(
        "INSERT INTO appointments
             (patient_id, doctor_id, appointment_date, appointment_time,
              queue_number, status, send_sms, notes)
         VALUES (?, ?, ?, ?, ?, 'scheduled', 0, ?)"
    );
    $ins_stmt->execute([
        $patient['patient_id'],
        $doctor_id,
        $appointmentDate,
        $inputTime . ':00',
        $queue_number,
        'Booked via Telegram bot',
    ]);

    clearSession($pdo, $user_id);

    $response  = "Appointment Booked!\n\nDate: {$appointmentDate}\nTime: {$inputTime}\nQueue #: {$queue_number}\nStatus: Scheduled (awaiting nurse confirmation)\n\nYou will be notified once your appointment is confirmed.\n\nUse /next to check your upcoming appointment.";
    sendMessage($chat_id, $response, $bot_token);
    http_response_code(200);
    exit();
}

if ($text === '/start') {
    if ($patient) {
        $response  = "Welcome back, {$patient['first_name']}!\n\nYour account is already linked. Here's what you can do:\n\nCommands:\n/askappointment - Book an appointment\n/appointments - View your appointments\n/next - Your next appointment\n/queue - Check queue position\n/profile - View your profile\n/help - Show all commands\n\nYou will automatically receive appointment reminders.";
    } else {
        $response  = "Welcome to Shifa Medical Center, {$first_name}!\n\nI'm your health assistant. To get started:\n\n1. Login to your patient portal\n2. Click 'Telegram Bot'\n3. Your account will be automatically linked\n\nPortal: https://shifacenter.me/patient/dashboard.php\n\nAfter linking, you can use these commands:\n/askappointment - Book an appointment\n/appointments - View your appointments\n/next - Your next appointment\n/queue - Check your queue position\n/profile - View your profile\n/help - Show all commands\n\nYou will receive automatic reminders for your appointments.";
    }
    sendMessage($chat_id, $response, $bot_token);
    http_response_code(200);
    exit();
}

if ($text === '/help') {
    $response  = "Available Commands:\n\n/start - Welcome message\n/askappointment - Book a new appointment\n/appointments - View all your appointments\n/next - Show your next appointment\n/queue - Check your queue position\n/profile - View your profile information\n/reschedule - How to reschedule\n/cancel - How to cancel\n/cancel_booking - Abort an in-progress booking\n/help - Show this message\n\nNeed help? Visit our website: https://shifacenter.me";
    sendMessage($chat_id, $response, $bot_token);
    http_response_code(200);
    exit();
}

if ($text === '/profile') {
    if (!$patient) {
        sendMessage($chat_id, "Account Not Linked\n\nPlease login to our website and click 'Link Telegram Account' first.\n\nPortal: https://shifacenter.me/patient/dashboard.php", $bot_token);
        http_response_code(200);
        exit();
    }
    $response  = "Your Profile\n\nName: {$patient['first_name']} {$patient['last_name']}\nEmail: {$patient['email']}\nPhone: " . ($patient['phone'] ?? 'Not set') . "\nMember since: " . date('F j, Y', strtotime($patient['created_at'])) . "\n\nTo update your profile, please visit our website.";
    sendMessage($chat_id, $response, $bot_token);
    http_response_code(200);
    exit();
}

if ($text === '/next') {
    if (!$patient) {
        sendMessage($chat_id, "Account Not Linked\n\nPlease login to our website and click 'Link Telegram Account' first.\n\nPortal: https://shifacenter.me/patient/dashboard.php", $bot_token);
        http_response_code(200);
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
        $response  = "Your Next Appointment\n\nDate: " . date('l, F j, Y', strtotime($appointment['appointment_date'])) . "\nTime: " . date('g:i A', strtotime($appointment['appointment_time'])) . "\nDoctor: Dr. {$appointment['doctor_name']}\nQueue #: {$appointment['queue_number']}\nStatus: " . ucfirst($appointment['status']) . "\n\nPlease arrive 10 minutes early!";
    } else {
        $response  = "No Upcoming Appointments\n\nYou have no upcoming appointments scheduled.\n\nSend /askappointment to book one, or visit:\nhttps://shifacenter.me/patient/book_appointment.php";
    }
    sendMessage($chat_id, $response, $bot_token);
    http_response_code(200);
    exit();
}

if ($text === '/appointments') {
    if (!$patient) {
        sendMessage($chat_id, "Account Not Linked\n\nPlease login to our website and click 'Link Telegram Account' first.\n\nPortal: https://shifacenter.me/patient/dashboard.php", $bot_token);
        http_response_code(200);
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
        $response = "Your Appointments\n\n";
        foreach ($appointments as $apt) {
            $response .= date('M j, Y', strtotime($apt['appointment_date'])) . " - " . date('g:i A', strtotime($apt['appointment_time'])) . "\n";
            $response .= "Dr. {$apt['doctor_name']} | Queue #{$apt['queue_number']}\n";
            $response .= "Status: " . ucfirst($apt['status']) . "\n\n";
        }
        $response .= "Send /askappointment to book a new appointment.";
    } else {
        $response  = "No Appointments Found\n\nYou don't have any appointments yet.\n\nSend /askappointment to book one.";
    }
    sendMessage($chat_id, $response, $bot_token);
    http_response_code(200);
    exit();
}

if ($text === '/queue') {
    if (!$patient) {
        sendMessage($chat_id, "Account Not Linked\n\nPlease login to our website and click 'Link Telegram Account' first.\n\nPortal: https://shifacenter.me/patient/dashboard.php", $bot_token);
        http_response_code(200);
        exit();
    }
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
        $queue_stmt = $pdo->prepare("SELECT COUNT(*) as ahead FROM appointments WHERE appointment_date = CURDATE() AND queue_number < ? AND status IN ('scheduled', 'confirmed')");
        $queue_stmt->execute([$appointment['queue_number']]);
        $ahead = $queue_stmt->fetchColumn();
        $total_stmt = $pdo->prepare("SELECT COUNT(*) as total FROM appointments WHERE appointment_date = CURDATE() AND status IN ('scheduled', 'confirmed')");
        $total_stmt->execute();
        $total = $total_stmt->fetchColumn();
        $response  = "Queue Information\n\nDoctor: Dr. {$appointment['doctor_name']}\nYour Queue #: {$appointment['queue_number']}\nPosition: " . ($ahead + 1) . " of $total waiting\nPeople ahead: $ahead\n\n";
        if ($ahead == 0) {
            $response .= "You're NEXT! Please be ready when called.\n";
        } else {
            $response .= "Estimated wait: ~" . ($ahead * 15) . " minutes\nBased on 15 minutes per patient.\n";
        }
    } else {
        $response  = "No Active Queue\n\nYou don't have any appointments scheduled for today.\n\nSend /next to see your next appointment.";
    }
    sendMessage($chat_id, $response, $bot_token);
    http_response_code(200);
    exit();
}

if ($text === '/reschedule') {
    $response  = "How to Reschedule\n\nTo reschedule an appointment:\n\n1. Login to your patient portal\n2. Go to 'My Appointments'\n3. Click 'Reschedule' next to the appointment\n4. Choose a new date and time\n\nPortal: https://shifacenter.me/patient/dashboard.php\n\nNote: Appointments can only be rescheduled online.";
    sendMessage($chat_id, $response, $bot_token);
    http_response_code(200);
    exit();
}

if ($text === '/cancel') {
    $response  = "How to Cancel\n\nTo cancel an appointment:\n\n1. Login to your patient portal\n2. Go to 'My Appointments'\n3. Click 'Cancel' next to the appointment\n4. Confirm cancellation\n\nPortal: https://shifacenter.me/patient/dashboard.php\n\nNote: Please cancel at least 24 hours in advance.";
    sendMessage($chat_id, $response, $bot_token);
    http_response_code(200);
    exit();
}

if ($activeStep !== '') {
    $response  = "You have a pending booking in progress.\n\n";
    if ($activeStep === 'awaiting_date') {
        $response .= "Please send the appointment date as YYYY-MM-DD.";
    } elseif ($activeStep === 'awaiting_time') {
        $response .= "Please send the appointment time as HH:MM.";
    }
    $response .= "\n\nSend /cancel_booking to abort.";
    sendMessage($chat_id, $response, $bot_token);
    http_response_code(200);
    exit();
}

sendMessage($chat_id, "I didn't understand that.\n\nSend /help to see all available commands.\n\nOr visit our website: https://shifacenter.me", $bot_token);
http_response_code(200);
