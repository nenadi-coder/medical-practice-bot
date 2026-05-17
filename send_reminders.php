<?php
echo "Job started at " . date('Y-m-d H:i:s') . "\n";
require_once 'includes/config.php';

// Get bot token from environment variable
$bot_token = getenv('TELEGRAM_BOT_TOKEN') ?: '';

// FALLBACK - SET THIS IF ENV VAR NOT AVAILABLE
// $bot_token = '8330456846:AAFYmkLZFCx1qw4n2sQa5eRCJBO26NV1QYM';

if (empty($bot_token)) {
    echo "ERROR: TELEGRAM_BOT_TOKEN not configured\n";
    exit(1);
}

// ✅ HELPER: Calculate dynamic queue position (matches cmd:queue in bot)
function calculateQueuePosition($pdo, $appointment_date, $appointment_time, $doctor_id) {
    try {
        // Count appointments ahead: same date, earlier time, same doctor, active status only
        // ✅ Excludes 'completed', 'cancelled', 'no-show' - matches bot logic exactly
        $stmt = $pdo->prepare("SELECT COUNT(*) + 1 FROM appointments WHERE appointment_date = ? AND appointment_time < ? AND doctor_id = ? AND status IN ('scheduled', 'confirmed')");
        $stmt->execute([$appointment_date, $appointment_time, $doctor_id]);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('[REMINDER] Queue calc error: ' . $e->getMessage());
        return 1; // Fallback to position 1 if calculation fails
    }
}

// ✅ HELPER: Send Telegram message with error handling
function sendTelegramMessage($chat_id, $message, $bot_token) {
    $url = "https://api.telegram.org/bot{$bot_token}/sendMessage";
    $data = [
        'chat_id' => $chat_id,
        'text' => $message,
        'parse_mode' => 'Markdown',
        'disable_web_page_preview' => true
    ];
    
    $ctx = stream_context_create([
        'http' => [
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'method' => 'POST',
            'content' => http_build_query($data),
            'timeout' => 15
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]
    ]);
    
    $response = @file_get_contents($url, false, $ctx);
    
    if ($response === false) {
        return false;
    }
    
    $result = json_decode($response, true);
    return isset($result['ok']) && $result['ok'] === true;
}

// Get appointments for tomorrow
$tomorrow = date('Y-m-d', strtotime('+1 day'));
echo "Fetching appointments for: $tomorrow\n";

// FIXED: Use telegram_user_id instead of telegram_chat_id
$sql = "SELECT a.*, 
        p.first_name, p.last_name, p.telegram_user_id,
        CONCAT(d.first_name, ' ', d.last_name) as doctor_name
        FROM appointments a
        JOIN patients p ON a.patient_id = p.patient_id
        JOIN doctors d ON a.doctor_id = d.doctor_id
        WHERE a.appointment_date = ? 
        AND a.status IN ('scheduled', 'confirmed')
        AND p.telegram_user_id IS NOT NULL
        ORDER BY a.appointment_time ASC";
        
$stmt = $pdo->prepare($sql);
$stmt->execute([$tomorrow]);
$appointments = $stmt->fetchAll();

echo "Found " . count($appointments) . " appointments to remind\n";

$sent_count = 0;
$failed_count = 0;

foreach ($appointments as $apt) {
    // ✅ DYNAMIC QUEUE: Calculate position on-the-fly (matches bot's cmd:queue exactly)
    $dyn_queue = calculateQueuePosition($pdo, $apt['appointment_date'], $apt['appointment_time'], $apt['doctor_id']);
    
    $message = "🏥 *Appointment Reminder*\n\n";
    $message .= "Hello {$apt['first_name']}!\n";
    $message .= "You have an appointment TOMORROW:\n\n";
    $message .= "📅 Date: " . date('l, F j', strtotime($apt['appointment_date'])) . "\n";
    $message .= "🕒 Time: " . date('g:i A', strtotime($apt['appointment_time'])) . "\n";
    $message .= "👨‍⚕️ Doctor: Dr. {$apt['doctor_name']}\n";
    $message .= "🎫 Queue #: {$dyn_queue}\n\n";  // ✅ Dynamic value calculated live
    $message .= "📌 Please arrive 10 minutes early.\n";
    $message .= "Need to cancel? Reply to this bot or call the clinic.";
    
    $result = sendTelegramMessage($apt['telegram_user_id'], $message, $bot_token);
    
    if ($result) {
        $sent_count++;
        echo "✓ Sent to {$apt['first_name']} (Queue #{$dyn_queue})\n";
    } else {
        $failed_count++;
        echo "✗ Failed to send to telegram_user_id: {$apt['telegram_user_id']}\n";
        error_log("[REMINDER] Failed to send to user {$apt['telegram_user_id']} for appointment {$apt['appointment_id']}");
    }
    
    // Small delay to avoid Telegram rate limits
    usleep(100000); // 100ms
}

echo "\n" . str_repeat("=", 40) . "\n";
echo "Job completed at " . date('Y-m-d H:i:s') . "\n";
echo "Sent: $sent_count | Failed: $failed_count\n";
echo str_repeat("=", 40) . "\n";
?>
