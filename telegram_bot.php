<?php
require_once 'includes/config.php';

define('DB_HOST', 'db-mysql-nyc3-10499-do-user-36185384-0.e.db.ondigitalocean.com');
define('DB_PORT', '25060');
define('DB_NAME', 'if0_41555171_medical_practice');
define('DB_USER', 'doadmin');
define('DB_PASS', 'AVNS_xAlHu7MeZoKMxKJ7Esn');
define('DB_SSL_CA', __DIR__ . '/ca-certificate.crt');
define('BOT_TOKEN', '8330456846:AAHSmyKZrvCL5yLqpHjynBMqC6tM2u9k6N8');

$pdo = new PDO(
    "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME,
    DB_USER,
    DB_PASS,
    [PDO::MYSQL_ATTR_SSL_CA => DB_SSL_CA]
);

// Get appointments for tomorrow
$stmt = $pdo->prepare("
    SELECT 
        a.*,
        p.first_name,
        p.last_name,
        p.telegram_chat_id,
        CONCAT(d.first_name, ' ', d.last_name) as doctor_name
    FROM appointments a
    JOIN patients p ON a.patient_id = p.patient_id
    JOIN doctors d ON a.doctor_id = d.doctor_id
    WHERE a.appointment_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY)
        AND a.status NOT IN ('cancelled', 'completed')
        AND p.telegram_chat_id IS NOT NULL
");
$stmt->execute();
$appointments = $stmt->fetchAll();

foreach ($appointments as $apt) {
    $message = "🔔 *Appointment Reminder*\n\n";
    $message .= "Dear *{$apt['first_name']}*,\n\n";
    $message .= "This is a reminder of your appointment tomorrow:\n\n";
    $message .= "📅 *Date:* " . date('l, F j', strtotime($apt['appointment_date'])) . "\n";
    $message .= "⏰ *Time:* " . date('g:i A', strtotime($apt['appointment_time'])) . "\n";
    $message .= "👨‍⚕️ *Doctor:* Dr. {$apt['doctor_name']}\n\n";
    $message .= "Please arrive 15 minutes early.\n";
    $message .= "Use /next to see details.";
    
    sendTelegramMessage($apt['telegram_chat_id'], $message);
}

function sendTelegramMessage($chat_id, $message) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendMessage";
    $data = ['chat_id' => $chat_id, 'text' => $message, 'parse_mode' => 'Markdown'];
    
    $options = ['http' => [
        'header' => "Content-Type: application/json\r\n",
        'method' => 'POST',
        'content' => json_encode($data)
    ]];
    
    file_get_contents($url, false, stream_context_create($options));
}
?>
