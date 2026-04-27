<?php
echo "Job started at " . date('Y-m-d H:i:s') . "\n";
require_once 'includes/config.php';

// Get bot token from environment variable
$bot_token = getenv('TELEGRAM_BOT_TOKEN') ?: '';

// FALLBACK - SET THIS IF ENV VAR NOT AVAILABLE
// $bot_token = '8330456846:AAHSmyKZrvCL5yLqpHjynBMqC6tM2u9k6N8';

if (empty($bot_token)) {
    echo "ERROR: TELEGRAM_BOT_TOKEN not configured\n";
    exit(1);
}

// Get appointments for tomorrow
$tomorrow = date('Y-m-d', strtotime('+1 day'));

// FIXED: Use telegram_user_id instead of telegram_chat_id
$sql = "SELECT a.*, 
        p.first_name, p.last_name, p.telegram_user_id,
        CONCAT(d.first_name, ' ', d.last_name) as doctor_name
        FROM appointments a
        JOIN patients p ON a.patient_id = p.patient_id
        JOIN doctors d ON a.doctor_id = d.doctor_id
        WHERE a.appointment_date = ? 
        AND a.status IN ('scheduled', 'confirmed')
        AND p.telegram_user_id IS NOT NULL";
        
$stmt = $pdo->prepare($sql);
$stmt->execute([$tomorrow]);
$appointments = $stmt->fetchAll();

$sent_count = 0;

foreach ($appointments as $apt) {
    $message = "🏥 *Appointment Reminder*\n\n";
    $message .= "Hello {$apt['first_name']}!\n";
    $message .= "You have an appointment TOMORROW:\n\n";
    $message .= "📅 Date: " . date('l, F j', strtotime($apt['appointment_date'])) . "\n";
    $message .= "🕒 Time: " . date('g:i A', strtotime($apt['appointment_time'])) . "\n";
    $message .= "👨‍⚕️ Doctor: Dr. {$apt['doctor_name']}\n";
    $message .= "🎫 Queue #: {$apt['queue_number']}\n\n";
    $message .= "Please arrive 10 minutes early.";
    
    $result = sendTelegramMessage($apt['telegram_user_id'], $message, $bot_token);
    if ($result) {
        $sent_count++;
    } else {
        echo "Failed to send to telegram_user_id: {$apt['telegram_user_id']}\n";
    }
}

echo "$sent_count reminders sent.\n";

function sendTelegramMessage($chat_id, $message, $bot_token) {
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
    $response = file_get_contents($url, false, $context);
    
    if ($response === false) {
        return false;
    }
    
    $result = json_decode($response, true);
    return isset($result['ok']) && $result['ok'] === true;
}
?>
