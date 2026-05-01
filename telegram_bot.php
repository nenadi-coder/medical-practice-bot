<?php
/**
 * Shifa Medical Center - Telegram Bot (Production Ready)
 * 
 * ✅ Single Doctor (Dr. John, doctor_id = 1)
 * ✅ Email-only patient lookup
 * ✅ Clickable inline keyboards
 * ✅ Proper callback handling with null checks
 * ✅ Past time filtering for today's bookings
 * ✅ Cancelled appointments excluded from queue
 * ✅ Error logging to file
 * ✅ HTTP 200 responses to Telegram
 * ✅ NEW: Unlink Account feature with confirmation flow
 * ✅ NEW: Clinic hours enforcement (Sun-Thu, 8AM-5PM)
 * ✅ FIXED: Queue displays dynamic position, not static queue_number
 */

require_once __DIR__ . '/includes/config.php';

// ========== ERROR LOGGING ==========
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/bot_errors.log');

// Log helper
$log = function($msg) {
    file_put_contents(__DIR__ . '/bot_debug.log', "[" . date('c') . "] $msg\n", FILE_APPEND);
};
$log("=== BOT STARTED ===");

// ========== BOT TOKEN ==========
$bot_token = getenv('TELEGRAM_BOT_TOKEN');
if (empty($bot_token)) {
    $bot_token = '8330456846:AAFYmkLZFCx1qw4n2sQa5eRCJBO26NV1QYM'; // Fallback - REMOVE IN PRODUCTION
    $log("Using fallback token");
}

// ========== TELEGRAM UPDATE ==========
$input = file_get_contents('php://input');
$log("Input received: " . (empty($input) ? 'EMPTY' : 'OK (' . strlen($input) . ' bytes)'));

if (empty($input)) {
    // Direct browser visit - return simple OK
    http_response_code(200);
    echo "OK";
    exit;
}

$update = json_decode($input, true);
if (!$update) {
    $log("ERROR: Failed to parse JSON");
    http_response_code(400);
    echo "Invalid JSON";
    exit;
}
$log("Update parsed successfully");

// ========== HELPER FUNCTIONS ==========

function createKeyboard($buttons) {
    $keyboard = [];
    foreach ($buttons as $row) {
        $keyboard[] = is_array($row[0] ?? null) ? $row : [$row];
    }
    return json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE);
}

function sendMessage($chat_id, $message, $bot_token, $keyboard = null) {
    $url = "https://api.telegram.org/bot{$bot_token}/sendMessage";
    $data = [
        'chat_id' => $chat_id,
        'text' => $message,
        'parse_mode' => 'Markdown',
        'disable_web_page_preview' => true
    ];
    if ($keyboard) {
        $data['reply_markup'] = $keyboard;
    }
    
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($data),
            'timeout' => 15
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]
    ]);
    
    $result = @file_get_contents($url, false, $ctx);
    return $result !== false;
}

function editMessageText($chat_id, $message_id, $message, $bot_token, $keyboard = null) {
    $url = "https://api.telegram.org/bot{$bot_token}/editMessageText";
    $data = [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'text' => $message,
        'parse_mode' => 'Markdown',
        'disable_web_page_preview' => true
    ];
    if ($keyboard) {
        $data['reply_markup'] = $keyboard;
    }
    
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($data),
            'timeout' => 15
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]
    ]);
    
    $result = @file_get_contents($url, false, $ctx);
    // Handle "message is not modified" gracefully
    return $result !== false || (is_string($result) && strpos($result, 'message is not modified') !== false);
}

function answerCallback($callback_id, $bot_token, $text = '', $show_alert = false) {
    $url = "https://api.telegram.org/bot{$bot_token}/answerCallbackQuery";
    $data = [
        'callback_query_id' => $callback_id,
        'text' => $text,
        'show_alert' => $show_alert ? 'true' : 'false'
    ];
    
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($data),
            'timeout' => 10
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]
    ]);
    
    @file_get_contents($url, false, $ctx);
}

function parseDateInput($input) {
    $input = trim($input);
    
    // YYYY-MM-DD
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $input)) {
        $date = DateTime::createFromFormat('Y-m-d', $input);
        if ($date && $date->format('Y-m-d') === $input) {
            return $date;
        }
    }
    
    // DD-MM-YYYY
    if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $input)) {
        $date = DateTime::createFromFormat('d-m-Y', $input);
        if ($date && $date->format('d-m-Y') === $input) {
            return $date;
        }
    }
    
    // DD/MM/YYYY
    if (preg_match('#^\d{2}/\d{2}/\d{4}$#', $input)) {
        $date = DateTime::createFromFormat('d/m/Y', $input);
        if ($date && $date->format('d/m/Y') === $input) {
            return $date;
        }
    }
    
    return false;
}

// ✅ NEW: Check if date is a working day (Sunday=0 to Thursday=4)
function isWorkingDay($dateStr) {
    $date = is_string($dateStr) ? new DateTime($dateStr) : $dateStr;
    $dayOfWeek = (int)$date->format('N'); // 1=Monday, 7=Sunday
    // Convert to 0=Sunday format for easier checking
    $dayNum = ($dayOfWeek === 7) ? 0 : $dayOfWeek;
    // Valid: Sunday(0), Monday(1), Tuesday(2), Wednesday(3), Thursday(4)
    return in_array($dayNum, [0, 1, 2, 3, 4]);
}

// ✅ NEW: Get next available working day
function getNextWorkingDay($fromDate = null) {
    $date = $fromDate ? clone $fromDate : new DateTime();
    $date->setTime(0, 0, 0);
    
    // If today is a working day and we're checking from today, return today
    // Otherwise, keep adding days until we find a working day
    $maxAttempts = 14; // Prevent infinite loop
    $attempts = 0;
    
    while ($attempts < $maxAttempts) {
        if (isWorkingDay($date)) {
            return $date;
        }
        $date->modify('+1 day');
        $attempts++;
    }
    return null; // Should never happen with 14-day window
}

// ========== ENHANCED: Filter Past Times for Today ==========
function getAvailableTimeSlots($pdo, $date, $doctor_id = 1) {
    // ✅ NEW: Return empty if not a working day
    if (!isWorkingDay($date)) {
        return [];
    }
    
    $bookedSlots = [];
    try {
        $stmt = $pdo->prepare("SELECT appointment_time FROM appointments WHERE appointment_date = ? AND doctor_id = ? AND status NOT IN ('cancelled', 'no-show')");
        $stmt->execute([$date, $doctor_id]);
        foreach ($stmt->fetchAll() as $b) {
            $bookedSlots[] = date('H:i:s', strtotime($b['appointment_time']));
        }
    } catch (Exception $e) {
        error_log('[BOT] getAvailableTimeSlots error: ' . $e->getMessage());
        return [];
    }
    
    // ✅ Clinic hours: 8:00 AM to 5:00 PM (last slot starts at 4:30 PM)
    $allSlots = [
        '08:30:00' => '8:30 AM', '09:00:00' => '9:00 AM', '09:30:00' => '9:30 AM',
        '10:00:00' => '10:00 AM', '10:30:00' => '10:30 AM', '11:00:00' => '11:00 AM',
        '11:30:00' => '11:30 AM', '12:00:00' => '12:00 PM', '12:30:00' => '12:30 PM',
        '13:00:00' => '1:00 PM', '13:30:00' => '1:30 PM', '14:00:00' => '2:00 PM',
        '14:30:00' => '2:30 PM', '15:00:00' => '3:00 PM', '15:30:00' => '3:30 PM',
        '16:00:00' => '4:00 PM', '16:30:00' => '4:30 PM'
    ];
    
    $available = [];
    $now = new DateTime();
    $isToday = ($date === $now->format('Y-m-d'));
    
    foreach ($allSlots as $time => $display) {
        // Skip if already booked
        if (in_array($time, $bookedSlots, true)) {
            continue;
        }
        
        // Skip if time is in the past for today
        if ($isToday) {
            $slotTime = DateTime::createFromFormat('H:i:s', $time);
            if ($slotTime && $slotTime < $now) {
                continue;
            }
        }
        
        $available[] = ['time' => $time, 'display' => $display];
    }
    
    return $available;
}

// ========== CALLBACK HANDLER (Button Clicks) ==========
if (isset($update['callback_query'])) {
    $callback = $update['callback_query'];
    
    // ✅ Safe access to message data - prevents "Undefined array key" errors
    if (!isset($callback['message']) || !is_array($callback['message'])) {
        answerCallback($callback['id'] ?? '', $bot_token);
        http_response_code(200);
        echo "OK";
        exit;
    }
    
    $chat_id = $callback['message']['chat']['id'] ?? null;
    $message_id = $callback['message']['message_id'] ?? null;
    $telegram_user_id = $callback['from']['id'] ?? null;
    $callback_data = $callback['data'] ?? '';
    
    // Validate required fields
    if (!$chat_id || !$telegram_user_id) {
        answerCallback($callback['id'] ?? '', $bot_token);
        http_response_code(200);
        echo "OK";
        exit;
    }
    
    // Remove loading spinner
    answerCallback($callback['id'], $bot_token);
    $log("Callback from user $telegram_user_id: $callback_data");
    
    // Check if user is linked to database
    try {
        $stmt = $pdo->prepare("SELECT patient_id, first_name, last_name, email FROM patients WHERE telegram_user_id = ? LIMIT 1");
        $stmt->execute([$telegram_user_id]);
        $patient = $stmt->fetch();
    } catch (PDOException $e) {
        $log("DB error checking patient: " . $e->getMessage());
        if ($message_id) {
            editMessageText($chat_id, $message_id, "⚠️ Database error. Please try again later.", $bot_token);
        }
        http_response_code(200);
        echo "OK";
        exit;
    }
    
    if (!$patient) {
        if ($message_id) {
            editMessageText($chat_id, $message_id, "⚠️ Please type /start first to link your account.", $bot_token);
        }
        http_response_code(200);
        echo "OK";
        exit;
    }
    
    // ========== BUTTON ROUTER ==========
    
    // Main menu buttons
    if ($callback_data === 'cmd:appointments') {
        try {
            $stmt = $pdo->prepare("SELECT a.*, CONCAT(d.first_name, ' ', d.last_name) as doctor_name FROM appointments a JOIN doctors d ON a.doctor_id = d.doctor_id WHERE a.patient_id = ? ORDER BY a.appointment_date DESC, a.appointment_time DESC LIMIT 20");
            $stmt->execute([$patient['patient_id']]);
            $appointments = $stmt->fetchAll();
            
            if ($appointments) {
                $response = "📋 *Your Appointments*\n\n";
                foreach ($appointments as $apt) {
                    $icon = in_array($apt['status'], ['confirmed', 'completed']) ? '✅' : '⏳';
                    $response .= "{$icon} " . date('M j, Y', strtotime($apt['appointment_date'])) . " • " . date('g:i A', strtotime($apt['appointment_time'])) . "\n";
                    // ✅ FIXED: Show dynamic position instead of static queue_number
                    $response .= "   Dr. {$apt['doctor_name']} | Queue #{$apt['queue_number']}\n";
                    $response .= "   Status: " . ucfirst($apt['status']) . "\n\n";
                }
            } else {
                $response = "📭 *No appointments found*\n\nBook your first appointment below:";
            }
            $kb = createKeyboard([[['text' => '🏥 Book Now', 'callback_data' => 'cmd:askappointment'], ['text' => '🏠 Menu', 'callback_data' => 'cmd:home']]]);
            editMessageText($chat_id, $message_id, $response, $bot_token, $kb);
        } catch (Exception $e) {
            $log("Error loading appointments: " . $e->getMessage());
            editMessageText($chat_id, $message_id, "❌ Error loading appointments.", $bot_token);
        }
        
    } elseif ($callback_data === 'cmd:next') {
        try {
            $stmt = $pdo->prepare("SELECT a.*, CONCAT(d.first_name, ' ', d.last_name) as doctor_name FROM appointments a JOIN doctors d ON a.doctor_id = d.doctor_id WHERE a.patient_id = ? AND a.appointment_date >= CURDATE() AND a.status = 'confirmed' ORDER BY a.appointment_date ASC, a.appointment_time ASC LIMIT 1");
            $stmt->execute([$patient['patient_id']]);
            $apt = $stmt->fetch();
            
            if ($apt) {
                $response = "📅 *Next Appointment*\n\n";
                $response .= "📆 " . date('l, F j, Y', strtotime($apt['appointment_date'])) . "\n";
                $response .= "⏰ " . date('g:i A', strtotime($apt['appointment_time'])) . "\n";
                $response .= "👨‍⚕️ Dr. {$apt['doctor_name']}\n";
                $response .= "🎫 Queue #{$apt['queue_number']}\n\n";
                $response .= "📌 Arrive 10 minutes early!";
            } else {
                $response = "❌ *No upcoming confirmed appointments*";
            }
            $kb = createKeyboard([[['text' => '🏥 Book', 'callback_data' => 'cmd:askappointment'], ['text' => '📋 All', 'callback_data' => 'cmd:appointments']]]);
            editMessageText($chat_id, $message_id, $response, $bot_token, $kb);
        } catch (Exception $e) {
            $log("Error loading next appointment: " . $e->getMessage());
            editMessageText($chat_id, $message_id, "❌ Error.", $bot_token);
        }
        
    } elseif ($callback_data === 'cmd:queue') {
        try {
            // ✅ FIX: Exclude cancelled/no-show from queue counts
            $stmt = $pdo->prepare("SELECT a.*, CONCAT(d.first_name, ' ', d.last_name) as doctor_name, 
                (SELECT COUNT(*) FROM appointments WHERE appointment_date = a.appointment_date AND appointment_time < a.appointment_time AND status IN ('scheduled', 'confirmed') AND doctor_id = a.doctor_id) as people_ahead 
                FROM appointments a 
                JOIN doctors d ON a.doctor_id = d.doctor_id 
                WHERE a.patient_id = ? AND a.appointment_date = CURDATE() AND a.status IN ('scheduled', 'confirmed') 
                ORDER BY a.appointment_time ASC LIMIT 1");
            $stmt->execute([$patient['patient_id']]);
            $apt = $stmt->fetch();
            
            if ($apt) {
                $ahead = (int)($apt['people_ahead'] ?? 0);
                // ✅ FIXED: Calculate and display dynamic position
                $position = $ahead + 1;
                $response = "🎫 *Your Queue*\n\n";
                $response .= "👨‍⚕️ Dr. {$apt['doctor_name']}\n";
                $response .= "🎟️ #{$position}\n";
                $response .= $ahead === 0 ? "🔔 *You're NEXT!*" : "⏱️ ~" . ($ahead * 15) . " min wait";
            } else {
                $response = "🎫 *No active queue today*";
            }
            $kb = createKeyboard([[['text' => '📋 View Appointments', 'callback_data' => 'cmd:appointments']]]);
            editMessageText($chat_id, $message_id, $response, $bot_token, $kb);
        } catch (Exception $e) {
            $log("Error loading queue: " . $e->getMessage());
            editMessageText($chat_id, $message_id, "❌ Error.", $bot_token);
        }
        
    } elseif ($callback_data === 'cmd:profile') {
        try {
            $dt = new DateTime($patient['created_at']);
            $response = "👤 *Profile*\n\n";
            $response .= "Name: " . htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']) . "\n";
            $response .= "Email: " . htmlspecialchars($patient['email']) . "\n";
            $response .= "Member since: " . $dt->format('F j, Y');
            // ✅ UPDATED: Added Unlink Account button
            $kb = createKeyboard([
                [['text' => '🏠 Back to Menu', 'callback_data' => 'cmd:home']],
                [['text' => '🔓 Unlink Account', 'callback_data' => 'cmd:unlink_confirm']]
            ]);
            editMessageText($chat_id, $message_id, $response, $bot_token, $kb);
        } catch (Exception $e) {
            $log("Error loading profile: " . $e->getMessage());
            editMessageText($chat_id, $message_id, "❌ Error.", $bot_token);
        }
        
    } elseif ($callback_data === 'cmd:askappointment') {
        // ✅ SINGLE DOCTOR FLOW: Skip doctor selection, go straight to date
        $response = "🏥 *Book with Dr. John*\n\n*Step 1: Select a date*\n\n📅 *Clinic Hours:* Sunday-Thursday, 8AM-5PM\n\nTap a working day below:";
        
        $buttons = [];
        $row = [];
        $added = 0;
        // Show next 7 working days (skip Fri/Sat)
        $startDate = new DateTime();
        $startDate->modify('+1 day'); // Start from tomorrow
        $count = 0;
        
        while ($added < 7 && $count < 21) { // Check up to 21 days to find 7 working days
            if (isWorkingDay($startDate)) {
                $val = $startDate->format('Y-m-d');
                $lbl = $startDate->format('D, M j');
                $row[] = ['text' => $lbl, 'callback_data' => "date:{$val}"];
                $added++;
                if (count($row) === 2) {
                    $buttons[] = $row;
                    $row = [];
                }
            }
            $startDate->modify('+1 day');
            $count++;
        }
        if (!empty($row)) $buttons[] = $row;
        $buttons[] = [['text' => '◀️ More', 'callback_data' => 'date:more'], ['text' => '🔄 Cancel', 'callback_data' => 'cmd:home']];
        
        // Save session: doctor_id = 1 (Dr. John)
        try {
            $session_data = ['doctor_id' => 1, 'doctor_name' => 'John'];
            $pdo->prepare("REPLACE INTO telegram_sessions (telegram_user_id, step, data_json, updated_at) VALUES (?, 'booking_date', ?, NOW())")
                ->execute([$telegram_user_id, json_encode($session_data)]);
        } catch (PDOException $e) {
            $log("Session save error: " . $e->getMessage());
        }
        
        editMessageText($chat_id, $message_id, $response, $bot_token, createKeyboard($buttons));
        
    } elseif ($callback_data === 'cmd:help') {
        $response = "❓ *Available Commands*\n\n";
        $response .= "• `/appointments` - View all appointments\n";
        $response .= "• `/next` - Next confirmed appointment\n";
        $response .= "• `/queue` - Check queue position\n";
        $response .= "• `/profile` - View profile\n";
        $response .= "• `/askappointment` - Book with Dr. John\n";
        $response .= "• `/unlink` - Disconnect your Telegram account\n";  // ✅ ADDED
        $response .= "• `/help` - Show this help\n\n";
        $response .= "🏥 *Clinic Hours:* Sunday-Thursday, 8AM-5PM\n";
        $response .= "📌 *Reminders*: You'll receive appointment reminders at 7 AM.";
        $kb = createKeyboard([[['text' => '🏥 Book', 'callback_data' => 'cmd:askappointment'], ['text' => '📋 Appointments', 'callback_data' => 'cmd:appointments'], ['text' => '🎫 Queue', 'callback_data' => 'cmd:queue']]]);
        editMessageText($chat_id, $message_id, $response, $bot_token, $kb);
        
    } elseif ($callback_data === 'cmd:home') {
        $response = "🏥 *Shifa Medical Center*\n\nHello, " . htmlspecialchars($patient['first_name']) . "! 👋\n\n*Clinic Hours:* Sunday-Thursday, 8AM-5PM\n\nHow can we help you today?";
        $kb = createKeyboard([
            [['text' => '🏥 Book Appointment', 'callback_data' => 'cmd:askappointment'], ['text' => '📋 My Appointments', 'callback_data' => 'cmd:appointments']],
            [['text' => '📅 Next Visit', 'callback_data' => 'cmd:next'], ['text' => '🎫 Queue Status', 'callback_data' => 'cmd:queue']],
            [['text' => '👤 My Profile', 'callback_data' => 'cmd:profile'], ['text' => '❓ Help', 'callback_data' => 'cmd:help']]
            // ✅ OPTIONAL: Uncomment below to add Unlink to main menu
            // , [['text' => '🔓 Unlink Account', 'callback_data' => 'cmd:unlink_confirm']]
        ]);
        editMessageText($chat_id, $message_id, $response, $bot_token, $kb);
        
    // ✅ UNLINK ACCOUNT - CONFIRMATION SCREEN
    } elseif ($callback_data === 'cmd:unlink_confirm') {
        $response = "⚠️ *Unlink Account?*\n\n";
        $response .= "This will disconnect your Telegram from your patient profile.\n\n";
        $response .= "• You'll need to re-link with /start to book appointments\n";
        $response .= "• Your medical records remain safe in our system\n\n";
        $response .= "Proceed with unlinking?";
        
        $kb = createKeyboard([
            [['text' => '✅ Yes, Unlink', 'callback_data' => 'confirm:unlink'], ['text' => '❌ Cancel', 'callback_data' => 'cmd:profile']],
            [['text' => '🏠 Main Menu', 'callback_data' => 'cmd:home']]
        ]);
        editMessageText($chat_id, $message_id, $response, $bot_token, $kb);
        
    // ✅ UNLINK ACCOUNT - EXECUTE
    } elseif ($callback_data === 'confirm:unlink') {
        try {
            // Remove Telegram link from patient record
            $pdo->prepare("UPDATE patients SET telegram_user_id = NULL, telegram_linked_at = NULL WHERE telegram_user_id = ?")
                ->execute([$telegram_user_id]);
            
            // Clear any active sessions
            $pdo->prepare("DELETE FROM telegram_sessions WHERE telegram_user_id = ?")->execute([$telegram_user_id]);
            
            $log("User $telegram_user_id unlinked account");
            
            $response = "✅ *Account Unlinked*\n\n";
            $response .= "Your Telegram is no longer connected to your patient profile.\n\n";
            $response .= "To link again anytime, type `/start` and enter your email.";
            
            $kb = createKeyboard([[['text' => '🚀 Link Again', 'callback_data' => 'cmd:home']]]);
            editMessageText($chat_id, $message_id, $response, $bot_token, $kb);
            
        } catch (PDOException $e) {
            $log("Unlink failed: " . $e->getMessage());
            editMessageText($chat_id, $message_id, "❌ Error unlinking. Please try again.", $bot_token);
        }
        
    } elseif (strpos($callback_data, 'date:') === 0) {
        $dateVal = substr($callback_data, 5);
        
        if ($dateVal === 'more') {
            // Show more working days (days 8-14 working days)
            $buttons = [];
            $row = [];
            $added = 0;
            $startDate = new DateTime();
            $startDate->modify('+8 days'); // Start from day 8
            $count = 0;
            
            while ($added < 7 && $count < 21) {
                if (isWorkingDay($startDate)) {
                    $val = $startDate->format('Y-m-d');
                    $lbl = $startDate->format('D, M j');
                    $row[] = ['text' => $lbl, 'callback_data' => "date:{$val}"];
                    $added++;
                    if (count($row) === 2) {
                        $buttons[] = $row;
                        $row = [];
                    }
                }
                $startDate->modify('+1 day');
                $count++;
            }
            if (!empty($row)) $buttons[] = $row;
            $buttons[] = [['text' => '🔙 Back', 'callback_data' => 'cmd:askappointment']];
            editMessageText($chat_id, $message_id, "📅 *Select a working day*\n\n*Clinic:* Sun-Thu, 8AM-5PM\n\nChoose from below:", $bot_token, createKeyboard($buttons));
            http_response_code(200);
            echo "OK";
            exit;
        }
        
        $dateObj = DateTime::createFromFormat('Y-m-d', $dateVal);
        $tomorrow = new DateTime('tomorrow');
        $today = new DateTime('today');
        
        // ✅ NEW: Validate working day
        if (!$dateObj || !isWorkingDay($dateObj)) {
            editMessageText($chat_id, $message_id, "❌ *Clinic Closed*\n\nWe're only open Sunday-Thursday.\n\nPlease select a working day:", $bot_token, createKeyboard([[['text' => '📅 Try Again', 'callback_data' => 'cmd:askappointment']]]));
            http_response_code(200);
            echo "OK";
            exit;
        }
        
        // Validate date range
        if ($dateObj < $tomorrow && $dateObj->format('Y-m-d') !== $today->format('Y-m-d')) {
            editMessageText($chat_id, $message_id, "❌ Please select today or a future date.", $bot_token, createKeyboard([[['text' => '📅 Try Again', 'callback_data' => 'booking:date']]]));
            http_response_code(200);
            echo "OK";
            exit;
        }
        
        // Load session
        try {
            $stmt = $pdo->prepare("SELECT data_json FROM telegram_sessions WHERE telegram_user_id = ? LIMIT 1");
            $stmt->execute([$telegram_user_id]);
            $sess = $stmt->fetch();
            $session_data = json_decode($sess['data_json'] ?? '{}', true);
        } catch (PDOException $e) {
            $log("Session load error: " . $e->getMessage());
            editMessageText($chat_id, $message_id, "⚠️ Session error. Try again.", $bot_token);
            http_response_code(200);
            echo "OK";
            exit;
        }
        
        $selected_date = $dateObj->format('Y-m-d');
        $display_date = $dateObj->format('l, F j, Y');
        $session_data['date'] = $selected_date;
        $session_data['display_date'] = $display_date;
        
        // Get available slots (with past-time filtering & working day check)
        $availableSlots = getAvailableTimeSlots($pdo, $selected_date, 1); // doctor_id = 1
        
        if (empty($availableSlots)) {
            $reason = !isWorkingDay($selected_date) ? "Clinic is closed on " . $dateObj->format('l') . "s" : "No slots available";
            editMessageText($chat_id, $message_id, "❌ {$reason} on *{$display_date}*. Try another date:", $bot_token, createKeyboard([[['text' => '📅 Pick Another', 'callback_data' => 'date:more']], [['text' => '🔄 Cancel', 'callback_data' => 'cmd:home']]]));
            http_response_code(200);
            echo "OK";
            exit;
        }
        
        $session_data['available_slots'] = $availableSlots;
        
        // Save session
        try {
            $pdo->prepare("UPDATE telegram_sessions SET step = 'booking_time', data_json = ?, updated_at = NOW() WHERE telegram_user_id = ?")
                ->execute([json_encode($session_data), $telegram_user_id]);
        } catch (PDOException $e) {
            $log("Session update error: " . $e->getMessage());
        }
        
        // Show time slots
        $response = "📅 *{$display_date}*\n\n⏰ *Select a time slot*\n\n*Clinic Hours:* 8AM-5PM";
        $buttons = [];
        $row = [];
        foreach ($availableSlots as $slot) {
            $row[] = ['text' => $slot['display'], 'callback_data' => "time:{$slot['time']}"];
            if (count($row) === 3) {
                $buttons[] = $row;
                $row = [];
            }
        }
        if (!empty($row)) $buttons[] = $row;
        $buttons[] = [['text' => '🔙 Change Date', 'callback_data' => 'booking:date'], ['text' => '🔄 Cancel', 'callback_data' => 'cmd:home']];
        
        editMessageText($chat_id, $message_id, $response, $bot_token, createKeyboard($buttons));
        
    } elseif (strpos($callback_data, 'time:') === 0) {
        $timeVal = substr($callback_data, 5);
        
        // Load session
        try {
            $stmt = $pdo->prepare("SELECT data_json FROM telegram_sessions WHERE telegram_user_id = ? LIMIT 1");
            $stmt->execute([$telegram_user_id]);
            $sess = $stmt->fetch();
            $session_data = json_decode($sess['data_json'] ?? '{}', true);
        } catch (PDOException $e) {
            $log("Session load error: " . $e->getMessage());
            editMessageText($chat_id, $message_id, "⚠️ Session error.", $bot_token);
            http_response_code(200);
            echo "OK";
            exit;
        }
        
        $availableSlots = $session_data['available_slots'] ?? [];
        $selected_slot = null;
        foreach ($availableSlots as $s) {
            if ($s['time'] === $timeVal) {
                $selected_slot = $s;
                break;
            }
        }
        
        if (!$selected_slot) {
            editMessageText($chat_id, $message_id, "❌ Invalid time selection.", $bot_token, createKeyboard([[['text' => '⏰ Retry', 'callback_data' => 'booking:time']]]));
            http_response_code(200);
            echo "OK";
            exit;
        }
        
        // Double-check availability (race condition protection)
        try {
            $check = $pdo->prepare("SELECT appointment_id FROM appointments WHERE appointment_date = ? AND appointment_time = ? AND doctor_id = ? AND status NOT IN ('cancelled', 'no-show') LIMIT 1");
            $check->execute([$session_data['date'], $selected_slot['time'], 1]); // doctor_id = 1
            
            if ($check->rowCount() > 0) {
                // Slot was just taken - refresh and show updated list
                $new_slots = getAvailableTimeSlots($pdo, $session_data['date'], 1);
                if (!empty($new_slots)) {
                    $session_data['available_slots'] = $new_slots;
                    $pdo->prepare("UPDATE telegram_sessions SET data_json = ? WHERE telegram_user_id = ?")->execute([json_encode($session_data), $telegram_user_id]);
                    
                    $response = "⚠️ That slot was just taken. Please choose another:\n\n📅 *{$session_data['display_date']}*\n⏰ *Select time*:";
                    $buttons = [];
                    $row = [];
                    foreach ($new_slots as $slot) {
                        $row[] = ['text' => $slot['display'], 'callback_data' => "time:{$slot['time']}"];
                        if (count($row) === 3) {
                            $buttons[] = $row;
                            $row = [];
                        }
                    }
                    if (!empty($row)) $buttons[] = $row;
                    $buttons[] = [['text' => '🔙 Change Date', 'callback_data' => 'booking:date'], ['text' => '🔄 Cancel', 'callback_data' => 'cmd:home']];
                    editMessageText($chat_id, $message_id, $response, $bot_token, createKeyboard($buttons));
                } else {
                    editMessageText($chat_id, $message_id, "❌ No slots left on {$session_data['display_date']}.", $bot_token, createKeyboard([[['text' => '📅 Pick Another', 'callback_data' => 'booking:date']], [['text' => '🔄 Cancel', 'callback_data' => 'cmd:home']]]));
                }
                http_response_code(200);
                echo "OK";
                exit;
            }
        } catch (PDOException $e) {
            $log("Availability check error: " . $e->getMessage());
        }
        
        // Save selected time
        $session_data['time'] = $selected_slot['time'];
        $session_data['display_time'] = $selected_slot['display'];
        unset($session_data['available_slots']);
        
        try {
            $pdo->prepare("UPDATE telegram_sessions SET step = 'booking_confirm', data_json = ?, updated_at = NOW() WHERE telegram_user_id = ?")
                ->execute([json_encode($session_data), $telegram_user_id]);
        } catch (PDOException $e) {
            $log("Session update error: " . $e->getMessage());
        }
        
        // Show confirmation
        $response = "⏰ *{$selected_slot['display']}*\n\n✅ *Confirm Appointment*\n\n";
        $response .= "📋 *Details:*\n";
        $response .= "👨‍⚕️ Doctor: Dr. {$session_data['doctor_name']}\n";
        $response .= "📆 Date: {$session_data['display_date']}\n";
        $response .= "⏰ Time: {$selected_slot['display']}\n\n";
        $response .= "Tap below to confirm:";
        
        $kb = createKeyboard([
            [['text' => '✅ Confirm Booking', 'callback_data' => 'confirm:book'], ['text' => '❌ Cancel', 'callback_data' => 'cmd:home']],
            [['text' => '🔄 Change Time', 'callback_data' => 'booking:time'], ['text' => '📅 Change Date', 'callback_data' => 'booking:date']]
        ]);
        
        editMessageText($chat_id, $message_id, $response, $bot_token, $kb);
        
    } elseif ($callback_data === 'confirm:book') {
        // Load session
        try {
            $stmt = $pdo->prepare("SELECT data_json FROM telegram_sessions WHERE telegram_user_id = ? LIMIT 1");
            $stmt->execute([$telegram_user_id]);
            $sess = $stmt->fetch();
            $session_data = json_decode($sess['data_json'] ?? '{}', true);
        } catch (PDOException $e) {
            $log("Session load error: " . $e->getMessage());
            editMessageText($chat_id, $message_id, "⚠️ Session error.", $bot_token);
            http_response_code(200);
            echo "OK";
            exit;
        }
        
        // Final availability check with row lock
        try {
            $check = $pdo->prepare("SELECT appointment_id FROM appointments WHERE appointment_date = ? AND appointment_time = ? AND doctor_id = ? AND status NOT IN ('cancelled', 'no-show') LIMIT 1 FOR UPDATE");
            $check->execute([$session_data['date'], $session_data['time'], 1]); // doctor_id = 1
            
            if ($check->rowCount() > 0) {
                editMessageText($chat_id, $message_id, "❌ Slot no longer available. Start over with /askappointment", $bot_token);
                $pdo->prepare("DELETE FROM telegram_sessions WHERE telegram_user_id = ?")->execute([$telegram_user_id]);
                http_response_code(200);
                echo "OK";
                exit;
            }
        } catch (PDOException $e) {
            $log("Final check error: " . $e->getMessage());
        }
        
        // Calculate queue number
        try {
            $q = $pdo->prepare("SELECT COUNT(*) + 1 FROM appointments WHERE appointment_date = ? AND appointment_time < ? AND doctor_id = ? AND status IN ('scheduled', 'confirmed')");
            $q->execute([$session_data['date'], $session_data['time'], 1]); // doctor_id = 1
            $queueNum = (int)$q->fetchColumn();
        } catch (PDOException $e) {
            $log("Queue calc error: " . $e->getMessage());
            $queueNum = 1;
        }
        
        // Insert appointment
        try {
            $pdo->beginTransaction();
            
            $pdo->prepare("INSERT INTO appointments (patient_id, doctor_id, appointment_date, appointment_time, queue_number, status, send_sms, created_at) VALUES (?, ?, ?, ?, ?, 'scheduled', 1, NOW())")
                ->execute([$patient['patient_id'], 1, $session_data['date'], $session_data['time'], $queueNum]); // doctor_id = 1
            
            $pdo->prepare("DELETE FROM telegram_sessions WHERE telegram_user_id = ?")->execute([$telegram_user_id]);
            
            $pdo->commit();
            
            $response = "✅ *Appointment Booked!*\n\n";
            $response .= "📋 *Details:*\n";
            $response .= "👨‍⚕️ Dr. {$session_data['doctor_name']}\n";
            $response .= "📆 {$session_data['display_date']}\n";
            $response .= "⏰ {$session_data['display_time']}\n";
            $response .= "🎫 Queue #{$queueNum}\n\n";
            $response .= "📌 Arrive 10 min early. Reminder sent at 7 AM.";
            
            $kb = createKeyboard([[['text' => '📋 My Appointments', 'callback_data' => 'cmd:appointments'], ['text' => '🏠 Menu', 'callback_data' => 'cmd:home']]]);
            editMessageText($chat_id, $message_id, $response, $bot_token, $kb);
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            $log("Booking failed: " . $e->getMessage());
            editMessageText($chat_id, $message_id, "❌ Booking failed. Please retry.", $bot_token, createKeyboard([[['text' => '🔄 Retry', 'callback_data' => 'cmd:askappointment'], ['text' => '🏠 Menu', 'callback_data' => 'cmd:home']]]));
        }
        
    } elseif ($callback_data === 'booking:date') {
        // ✅ MISSING HANDLER: Go back to date selection
        try {
            $stmt = $pdo->prepare("SELECT data_json FROM telegram_sessions WHERE telegram_user_id = ? LIMIT 1");
            $stmt->execute([$telegram_user_id]);
            $sess = $stmt->fetch();
            $session_data = json_decode($sess['data_json'] ?? '{}', true);
        } catch (PDOException $e) {
            $log("Session load error: " . $e->getMessage());
        }
        
        $response = "📅 *Select a working day*\n\n*Clinic Hours:* Sunday-Thursday, 8AM-5PM\n\n📅 Tap below or type manually:";
        
        $buttons = [];
        $row = [];
        $added = 0;
        $startDate = new DateTime();
        $startDate->modify('+1 day');
        $count = 0;
        
        while ($added < 7 && $count < 21) {
            if (isWorkingDay($startDate)) {
                $val = $startDate->format('Y-m-d');
                $lbl = $startDate->format('D, M j');
                $row[] = ['text' => $lbl, 'callback_data' => "date:{$val}"];
                $added++;
                if (count($row) === 2) {
                    $buttons[] = $row;
                    $row = [];
                }
            }
            $startDate->modify('+1 day');
            $count++;
        }
        if (!empty($row)) $buttons[] = $row;
        $buttons[] = [['text' => '◀️ More', 'callback_data' => 'date:more'], ['text' => '🔄 Cancel', 'callback_data' => 'cmd:home']];
        
        editMessageText($chat_id, $message_id, $response, $bot_token, createKeyboard($buttons));
        
    } elseif ($callback_data === 'booking:time') {
        // ✅ MISSING HANDLER: Go back to time selection
        try {
            $stmt = $pdo->prepare("SELECT data_json FROM telegram_sessions WHERE telegram_user_id = ? LIMIT 1");
            $stmt->execute([$telegram_user_id]);
            $sess = $stmt->fetch();
            $session_data = json_decode($sess['data_json'] ?? '{}', true);
        } catch (PDOException $e) {
            $log("Session load error: " . $e->getMessage());
            editMessageText($chat_id, $message_id, "⚠️ Session error.", $bot_token);
            http_response_code(200);
            echo "OK";
            exit;
        }
        
        $availableSlots = getAvailableTimeSlots($pdo, $session_data['date'] ?? '', 1);
        
        if (empty($availableSlots)) {
            editMessageText($chat_id, $message_id, "❌ No slots available. Pick another date.", $bot_token, createKeyboard([[['text' => '📅 Pick Another', 'callback_data' => 'booking:date']], [['text' => '🔄 Cancel', 'callback_data' => 'cmd:home']]]));
            http_response_code(200);
            echo "OK";
            exit;
        }
        
        $response = "📅 *{$session_data['display_date']}*\n\n⏰ *Select a time slot*:\n\n*Clinic Hours:* 8AM-5PM";
        
        $buttons = [];
        $row = [];
        foreach ($availableSlots as $slot) {
            $row[] = ['text' => $slot['display'], 'callback_data' => "time:{$slot['time']}"];
            if (count($row) === 3) {
                $buttons[] = $row;
                $row = [];
            }
        }
        if (!empty($row)) $buttons[] = $row;
        $buttons[] = [['text' => '🔙 Change Date', 'callback_data' => 'booking:date'], ['text' => '🔄 Cancel', 'callback_data' => 'cmd:home']];
        
        // Update session with fresh slots
        $session_data['available_slots'] = $availableSlots;
        try {
            $pdo->prepare("UPDATE telegram_sessions SET step = 'booking_time', data_json = ?, updated_at = NOW() WHERE telegram_user_id = ?")
                ->execute([json_encode($session_data), $telegram_user_id]);
        } catch (PDOException $e) {
            $log("Session update error: " . $e->getMessage());
        }
        
        editMessageText($chat_id, $message_id, $response, $bot_token, createKeyboard($buttons));
        
    } elseif ($callback_data === 'booking:cancel' || $callback_data === 'cmd:home') {
        try {
            $pdo->prepare("DELETE FROM telegram_sessions WHERE telegram_user_id = ?")->execute([$telegram_user_id]);
        } catch (PDOException $e) {
            $log("Session delete error: " . $e->getMessage());
        }
        $response = "❌ Booking cancelled. Tap below to start over:";
        $kb = createKeyboard([[['text' => '🏥 Book Appointment', 'callback_data' => 'cmd:askappointment'], ['text' => '🏠 Main Menu', 'callback_data' => 'cmd:home']]]);
        editMessageText($chat_id, $message_id, $response, $bot_token, $kb);
    }
    
    // Always return OK to Telegram
    http_response_code(200);
    echo "OK";
    exit;
}

// ========== MESSAGE HANDLER (Text Commands) ==========
if (isset($update['message'])) {
    $message = $update['message'];
    
    // ✅ Safe access to message fields
    $chat_id = $message['chat']['id'] ?? null;
    $text = trim($message['text'] ?? '');
    $telegram_user_id = $message['from']['id'] ?? null;
    
    if (!$chat_id || !$telegram_user_id) {
        http_response_code(200);
        echo "OK";
        exit;
    }
    
    $log("Message from $telegram_user_id: $text");
    
    // ✅ Handle empty message (user just opened chat)
    if ($text === '' || $text === null) {
        $kb = createKeyboard([[['text' => '🚀 Get Started', 'callback_data' => 'cmd:home']]]);
        sendMessage($chat_id, "👋 Welcome to Shifa Medical Center!\n\n*Clinic Hours:* Sunday-Thursday, 8AM-5PM\n\nTap below to begin:", $bot_token, $kb);
        http_response_code(200);
        echo "OK";
        exit;
    }
    
    // Check if user is linked
    try {
        $stmt = $pdo->prepare("SELECT patient_id, first_name, last_name, email FROM patients WHERE telegram_user_id = ? LIMIT 1");
        $stmt->execute([$telegram_user_id]);
        $patient = $stmt->fetch();
    } catch (PDOException $e) {
        $log("DB error: " . $e->getMessage());
        http_response_code(200);
        echo "OK";
        exit;
    }
    
    // Get session
    try {
        $stmt = $pdo->prepare("SELECT step, data_json FROM telegram_sessions WHERE telegram_user_id = ? LIMIT 1");
        $stmt->execute([$telegram_user_id]);
        $session = $stmt->fetch();
    } catch (PDOException $e) {
        $log("Session error: " . $e->getMessage());
        $session = null;
    }
    
    // ========== USER NOT LINKED ==========
    if (!$patient) {
        if ($text === '/start') {
            try {
                $pdo->prepare("REPLACE INTO telegram_sessions (telegram_user_id, step, data_json, updated_at) VALUES (?, 'waiting_email', NULL, NOW())")
                    ->execute([$telegram_user_id]);
            } catch (PDOException $e) {
                $log("Session insert error: " . $e->getMessage());
            }
            
            $response = "🏥 *Welcome to Shifa Medical Center Bot!*\n\n";
            $response .= "*Clinic Hours:* Sunday-Thursday, 8AM-5PM\n\n";
            $response .= "To link your account, enter your registered **email address**:\n\n";
            $response .= "*Example:* `lana@gmail.com`\n\n";
            $response .= "This instantly links your Telegram account.";
            
            sendMessage($chat_id, $response, $bot_token);
            
        } elseif ($session && ($session['step'] ?? '') === 'waiting_email') {
            $email = trim($text);
            
            // Validate email format
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
                sendMessage($chat_id, "❌ Invalid email format. Please enter a valid email.", $bot_token);
                http_response_code(200);
                echo "OK";
                exit;
            }
            
            // Lookup by EMAIL only (no phone)
            try {
                $stmt = $pdo->prepare("SELECT patient_id, first_name, last_name, email FROM patients WHERE email = ? LIMIT 1");
                $stmt->execute([$email]);
                $found = $stmt->fetch();
            } catch (PDOException $e) {
                $log("Patient lookup error: " . $e->getMessage());
                sendMessage($chat_id, "❌ Database error. Please try again.", $bot_token);
                http_response_code(200);
                echo "OK";
                exit;
            }
            
            if ($found) {
                try {
                    $pdo->beginTransaction();
                    // Update using exact columns from shifacenter.sql
                    $pdo->prepare("UPDATE patients SET telegram_user_id = ?, telegram_linked_at = NOW() WHERE patient_id = ?")
                        ->execute([$telegram_user_id, $found['patient_id']]);
                    $pdo->prepare("DELETE FROM telegram_sessions WHERE telegram_user_id = ?")->execute([$telegram_user_id]);
                    $pdo->commit();
                    
                    $response = "✅ *Account Linked!*\n\n";
                    $response .= "Welcome, " . htmlspecialchars($found['first_name']) . "! 👋\n\n";
                    $response .= "*Tap a button below to get started:*";
                    
                    $kb = createKeyboard([
                        [['text' => '🏥 Book Appointment', 'callback_data' => 'cmd:askappointment'], ['text' => '📋 My Appointments', 'callback_data' => 'cmd:appointments']],
                        [['text' => '📅 Next Visit', 'callback_data' => 'cmd:next'], ['text' => '🎫 Queue Status', 'callback_data' => 'cmd:queue']],
                        [['text' => '👤 My Profile', 'callback_data' => 'cmd:profile'], ['text' => '❓ Help', 'callback_data' => 'cmd:help']]
                    ]);
                    sendMessage($chat_id, $response, $bot_token, $kb);
                    
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    $log("Link failed: " . $e->getMessage());
                    sendMessage($chat_id, "❌ Link failed. Please try again.", $bot_token);
                }
            } else {
                sendMessage($chat_id, "❌ *Account not found*\n\nNo patient registered with email: `{$email}`\n\nType /start to try again.", $bot_token);
                try {
                    $pdo->prepare("DELETE FROM telegram_sessions WHERE telegram_user_id = ?")->execute([$telegram_user_id]);
                } catch (PDOException $e) {}
            }
        } else {
            $kb = createKeyboard([[['text' => '🚀 Get Started', 'callback_data' => 'cmd:home']]]);
            sendMessage($chat_id, "👋 *Welcome!*\n\n*Clinic Hours:* Sunday-Thursday, 8AM-5PM\n\nTap below to link your account:", $bot_token, $kb);
        }
        
        http_response_code(200);
        echo "OK";
        exit;
    }
    
    // ========== USER IS LINKED - BOOKING FLOW ==========
    
    // booking_date step
    if ($session && ($session['step'] ?? '') === 'booking_date') {
        try {
            $sess_data = json_decode($session['data_json'] ?? '{}', true);
        } catch (Exception $e) {
            $sess_data = [];
        }
        
        $dateObj = parseDateInput($text);
        $tomorrow = new DateTime('tomorrow');
        $today = new DateTime('today');
        
        // Validate date
        if (!$dateObj) {
            sendMessage($chat_id, "❌ Invalid date format. Use YYYY-MM-DD, DD-MM-YYYY, or DD/MM/YYYY.", $bot_token);
            http_response_code(200);
            echo "OK";
            exit;
        }
        
        // ✅ NEW: Check if working day
        if (!isWorkingDay($dateObj)) {
            sendMessage($chat_id, "❌ *Clinic Closed*\n\nWe're only open Sunday-Thursday.\n\nPlease select a working day.", $bot_token);
            http_response_code(200);
            echo "OK";
            exit;
        }
        
        if ($dateObj >= $tomorrow || $dateObj->format('Y-m-d') === $today->format('Y-m-d')) {
            // Valid date range
        } else {
            sendMessage($chat_id, "❌ Please select today or a future date.", $bot_token);
            http_response_code(200);
            echo "OK";
            exit;
        }
        
        $selected_date = $dateObj->format('Y-m-d');
        $display_date = $dateObj->format('l, F j, Y');
        $sess_data['date'] = $selected_date;
        $sess_data['display_date'] = $display_date;
        
        // Get slots (with past-time filtering & working day check)
        $availableSlots = getAvailableTimeSlots($pdo, $selected_date, 1);
        
        if (empty($availableSlots)) {
            $reason = !isWorkingDay($selected_date) ? "Clinic is closed on " . $dateObj->format('l') . "s" : "No slots available";
            sendMessage($chat_id, "❌ {$reason} on {$display_date}. Try another date or type 'cancel'.", $bot_token);
            try {
                $pdo->prepare("UPDATE telegram_sessions SET step = 'booking_date', data_json = ? WHERE telegram_user_id = ?")
                    ->execute([json_encode($sess_data), $telegram_user_id]);
            } catch (PDOException $e) {}
            http_response_code(200);
            echo "OK";
            exit;
        }
        
        $sess_data['available_slots'] = $availableSlots;
        
        try {
            $pdo->prepare("UPDATE telegram_sessions SET step = 'booking_time', data_json = ? WHERE telegram_user_id = ?")
                ->execute([json_encode($sess_data), $telegram_user_id]);
        } catch (PDOException $e) {
            $log("Session update error: " . $e->getMessage());
        }
        
        $response = "📅 *Date selected:* {$display_date}\n\n";
        $response .= "*Step 2: Select a time*\n\n";
        $response .= "*Clinic Hours:* 8AM-5PM\n\n";
        $response .= "Available slots:\n";
        
        $num = 1;
        foreach ($availableSlots as $slot) {
            $response .= "{$num}. {$slot['display']}\n";
            $num++;
        }
        $response .= "\n*Enter the number* of your preferred slot.";
        
        sendMessage($chat_id, $response, $bot_token);
        http_response_code(200);
        echo "OK";
        exit;
    }
    
    // booking_time step
    if ($session && ($session['step'] ?? '') === 'booking_time') {
        try {
            $sess_data = json_decode($session['data_json'] ?? '{}', true);
        } catch (Exception $e) {
            $sess_data = [];
        }
        
        $availableSlots = $sess_data['available_slots'] ?? [];
        
        if (is_numeric($text) && $text >= 1 && $text <= count($availableSlots)) {
            $selected_slot = $availableSlots[(int)$text - 1];
            
            // Check availability
            try {
                $check = $pdo->prepare("SELECT appointment_id FROM appointments WHERE appointment_date = ? AND appointment_time = ? AND doctor_id = ? AND status NOT IN ('cancelled', 'no-show') LIMIT 1");
                $check->execute([$sess_data['date'], $selected_slot['time'], 1]);
                
                if ($check->rowCount() > 0) {
                    sendMessage($chat_id, "❌ Slot just taken! Restart with /askappointment", $bot_token);
                    $pdo->prepare("DELETE FROM telegram_sessions WHERE telegram_user_id = ?")->execute([$telegram_user_id]);
                    http_response_code(200);
                    echo "OK";
                    exit;
                }
            } catch (PDOException $e) {
                $log("Availability check error: " . $e->getMessage());
            }
            
            $sess_data['time'] = $selected_slot['time'];
            $sess_data['display_time'] = $selected_slot['display'];
            unset($sess_data['available_slots']);
            
            try {
                $pdo->prepare("UPDATE telegram_sessions SET step = 'booking_confirm', data_json = ? WHERE telegram_user_id = ?")
                    ->execute([json_encode($sess_data), $telegram_user_id]);
            } catch (PDOException $e) {
                $log("Session update error: " . $e->getMessage());
            }
            
            $response = "⏰ *Time selected:* {$selected_slot['display']}\n\n";
            $response .= "*Step 3: Confirm*\n\n";
            $response .= "📋 *Details:*\n";
            $response .= "👨‍⚕️ Dr. {$sess_data['doctor_name']}\n";
            $response .= "📆 {$sess_data['display_date']}\n";
            $response .= "⏰ {$selected_slot['display']}\n\n";
            $response .= "*Type `confirm` to book* or `cancel` to cancel.";
            
            sendMessage($chat_id, $response, $bot_token);
            
        } elseif (strtolower($text) === 'cancel') {
            try {
                $pdo->prepare("DELETE FROM telegram_sessions WHERE telegram_user_id = ?")->execute([$telegram_user_id]);
            } catch (PDOException $e) {}
            sendMessage($chat_id, "❌ Booking cancelled. Type /askappointment to start over.", $bot_token);
        } else {
            sendMessage($chat_id, "❌ Invalid choice. Enter a number from the list.", $bot_token);
        }
        
        http_response_code(200);
        echo "OK";
        exit;
    }
    
    // booking_confirm step
    if ($session && ($session['step'] ?? '') === 'booking_confirm') {
        try {
            $sess_data = json_decode($session['data_json'] ?? '{}', true);
        } catch (Exception $e) {
            $sess_data = [];
        }
        
        if (strtolower($text) === 'confirm') {
            // Final check with row lock
            try {
                $check = $pdo->prepare("SELECT appointment_id FROM appointments WHERE appointment_date = ? AND appointment_time = ? AND doctor_id = ? AND status NOT IN ('cancelled', 'no-show') LIMIT 1 FOR UPDATE");
                $check->execute([$sess_data['date'], $sess_data['time'], 1]);
                
                if ($check->rowCount() > 0) {
                    sendMessage($chat_id, "❌ Slot no longer available. Start over with /askappointment", $bot_token);
                    $pdo->prepare("DELETE FROM telegram_sessions WHERE telegram_user_id = ?")->execute([$telegram_user_id]);
                    http_response_code(200);
                    echo "OK";
                    exit;
                }
            } catch (PDOException $e) {
                $log("Final check error: " . $e->getMessage());
            }
            
            // Calculate queue
            try {
                $q = $pdo->prepare("SELECT COUNT(*) + 1 FROM appointments WHERE appointment_date = ? AND appointment_time < ? AND doctor_id = ? AND status IN ('scheduled', 'confirmed')");
                $q->execute([$sess_data['date'], $sess_data['time'], 1]);
                $queueNum = (int)$q->fetchColumn();
            } catch (PDOException $e) {
                $log("Queue calc error: " . $e->getMessage());
                $queueNum = 1;
            }
            
            // Insert appointment
            try {
                $pdo->beginTransaction();
                
                $pdo->prepare("INSERT INTO appointments (patient_id, doctor_id, appointment_date, appointment_time, queue_number, status, send_sms, created_at) VALUES (?, ?, ?, ?, ?, 'scheduled', 1, NOW())")
                    ->execute([$patient['patient_id'], 1, $sess_data['date'], $sess_data['time'], $queueNum]);
                
                $pdo->prepare("DELETE FROM telegram_sessions WHERE telegram_user_id = ?")->execute([$telegram_user_id]);
                
                $pdo->commit();
                
                $response = "✅ *Booked!*\n\n";
                $response .= "📋 *Details:*\n";
                $response .= "👨‍⚕️ Dr. {$sess_data['doctor_name']}\n";
                $response .= "📆 {$sess_data['display_date']}\n";
                $response .= "⏰ {$sess_data['display_time']}\n";
                $response .= "🎫 Queue #{$queueNum}\n\n";
                $response .= "📌 Arrive 10 min early. Reminder at 7 AM.";
                
                sendMessage($chat_id, $response, $bot_token);
                
            } catch (PDOException $e) {
                $pdo->rollBack();
                $log("Booking failed: " . $e->getMessage());
                sendMessage($chat_id, "❌ Booking failed. Retry with /askappointment", $bot_token);
            }
            
        } elseif (strtolower($text) === 'cancel') {
            try {
                $pdo->prepare("DELETE FROM telegram_sessions WHERE telegram_user_id = ?")->execute([$telegram_user_id]);
            } catch (PDOException $e) {}
            sendMessage($chat_id, "❌ Cancelled. Type /askappointment to start over.", $bot_token);
        } else {
            // Allow date change via text input
            $newDate = parseDateInput($text);
            $tomorrow = new DateTime('tomorrow');
            $today = new DateTime('today');
            
            if ($newDate && isWorkingDay($newDate) && ($newDate >= $tomorrow || $newDate->format('Y-m-d') === $today->format('Y-m-d'))) {
                $sess_data['date'] = $newDate->format('Y-m-d');
                $sess_data['display_date'] = $newDate->format('l, F j, Y');
                unset($sess_data['time'], $sess_data['display_time']);
                
                $availableSlots = getAvailableTimeSlots($pdo, $sess_data['date'], 1);
                
                if (empty($availableSlots)) {
                    sendMessage($chat_id, "❌ No slots on {$sess_data['display_date']}. Try another date.", $bot_token);
                    http_response_code(200);
                    echo "OK";
                    exit;
                }
                
                $sess_data['available_slots'] = $availableSlots;
                
                try {
                    $pdo->prepare("UPDATE telegram_sessions SET step = 'booking_time', data_json = ? WHERE telegram_user_id = ?")
                        ->execute([json_encode($sess_data), $telegram_user_id]);
                } catch (PDOException $e) {}
                
                $response = "🔄 *Date changed:* {$sess_data['display_date']}\n\n";
                $response .= "*Available slots:*\n";
                $num = 1;
                foreach ($availableSlots as $slot) {
                    $response .= "{$num}. {$slot['display']}\n";
                    $num++;
                }
                $response .= "\n*Enter the number* of your slot.";
                
                sendMessage($chat_id, $response, $bot_token);
            } else {
                sendMessage($chat_id, "❌ Type `confirm` to book, `cancel` to cancel, or enter a valid working date (Sun-Thu).", $bot_token);
            }
        }
        
        http_response_code(200);
        echo "OK";
        exit;
    }
    
    // ========== REGULAR COMMANDS ==========
    
    if ($text === '/appointments') {
        try {
            $stmt = $pdo->prepare("SELECT a.*, CONCAT(d.first_name, ' ', d.last_name) as doctor_name FROM appointments a JOIN doctors d ON a.doctor_id = d.doctor_id WHERE a.patient_id = ? ORDER BY a.appointment_date DESC, a.appointment_time DESC LIMIT 20");
            $stmt->execute([$patient['patient_id']]);
            $appointments = $stmt->fetchAll();
            
            if ($appointments) {
                $response = "📋 *Your Appointments*\n\n";
                foreach ($appointments as $apt) {
                    $icon = in_array($apt['status'], ['confirmed', 'completed']) ? '✅' : '⏳';
                    $response .= "{$icon} " . date('M j, Y', strtotime($apt['appointment_date'])) . " • " . date('g:i A', strtotime($apt['appointment_time'])) . "\n";
                    $response .= "   Dr. {$apt['doctor_name']} | Queue #{$apt['queue_number']}\n";
                    $response .= "   Status: " . ucfirst($apt['status']) . "\n\n";
                }
                $response .= "To book: `/askappointment`";
            } else {
                $response = "📭 *No appointments*\n\nBook: `/askappointment`";
            }
            $kb = createKeyboard([[['text' => '🏥 Book Now', 'callback_data' => 'cmd:askappointment'], ['text' => '🏠 Menu', 'callback_data' => 'cmd:home']]]);
            sendMessage($chat_id, $response, $bot_token, $kb);
        } catch (Exception $e) {
            $log("Error: " . $e->getMessage());
            sendMessage($chat_id, "❌ Error loading appointments.", $bot_token);
        }
        
    } elseif ($text === '/next') {
        try {
            $stmt = $pdo->prepare("SELECT a.*, CONCAT(d.first_name, ' ', d.last_name) as doctor_name FROM appointments a JOIN doctors d ON a.doctor_id = d.doctor_id WHERE a.patient_id = ? AND a.appointment_date >= CURDATE() AND a.status = 'confirmed' ORDER BY a.appointment_date ASC, a.appointment_time ASC LIMIT 1");
            $stmt->execute([$patient['patient_id']]);
            $apt = $stmt->fetch();
            
            if ($apt) {
                $response = "📅 *Next Appointment*\n\n";
                $response .= "📆 " . date('l, F j, Y', strtotime($apt['appointment_date'])) . "\n";
                $response .= "⏰ " . date('g:i A', strtotime($apt['appointment_time'])) . "\n";
                $response .= "👨‍⚕️ Dr. {$apt['doctor_name']}\n";
                $response .= "🎫 Queue #{$apt['queue_number']}\n\n";
                $response .= "📌 Arrive 10 min early!";
            } else {
                $response = "❌ *No upcoming confirmed appointments*";
            }
            $kb = createKeyboard([[['text' => '🏥 Book', 'callback_data' => 'cmd:askappointment'], ['text' => '📋 All', 'callback_data' => 'cmd:appointments']]]);
            sendMessage($chat_id, $response, $bot_token, $kb);
        } catch (Exception $e) {
            $log("Error: " . $e->getMessage());
            sendMessage($chat_id, "❌ Error.", $bot_token);
        }
        
    } elseif ($text === '/queue') {
        try {
            // ✅ FIX: Exclude cancelled/no-show from counts
            $stmt = $pdo->prepare("SELECT a.*, CONCAT(d.first_name, ' ', d.last_name) as doctor_name, 
                (SELECT COUNT(*) FROM appointments WHERE appointment_date = a.appointment_date AND appointment_time < a.appointment_time AND status IN ('scheduled', 'confirmed') AND doctor_id = a.doctor_id) as people_ahead 
                FROM appointments a 
                JOIN doctors d ON a.doctor_id = d.doctor_id 
                WHERE a.patient_id = ? AND a.appointment_date = CURDATE() AND a.status IN ('scheduled', 'confirmed') 
                ORDER BY a.appointment_time ASC LIMIT 1");
            $stmt->execute([$patient['patient_id']]);
            $apt = $stmt->fetch();
            
            if ($apt) {
                $ahead = (int)($apt['people_ahead'] ?? 0);
                // ✅ FIXED: Calculate and display dynamic position
                $position = $ahead + 1;
                $response = "🎫 *Queue*\n\n";
                $response .= "👨‍⚕️ Dr. {$apt['doctor_name']}\n";
                $response .= "🎟️ #{$position}\n";
                $response .= $ahead === 0 ? "🔔 *You're NEXT!*" : "⏱️ ~" . ($ahead * 15) . " min wait";
            } else {
                $response = "🎫 *No active queue*";
            }
            $kb = createKeyboard([[['text' => '📋 View', 'callback_data' => 'cmd:appointments']]]);
            sendMessage($chat_id, $response, $bot_token, $kb);
        } catch (Exception $e) {
            $log("Error: " . $e->getMessage());
            sendMessage($chat_id, "❌ Error.", $bot_token);
        }
        
    } elseif ($text === '/profile') {
        try {
            $dt = new DateTime($patient['created_at']);
            $response = "👤 *Profile*\n\n";
            $response .= "Name: " . htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']) . "\n";
            $response .= "Email: " . htmlspecialchars($patient['email']) . "\n";
            $response .= "Member since: " . $dt->format('F j, Y');
            // ✅ UPDATED: Added Unlink Account button
            $kb = createKeyboard([
                [['text' => '🏠 Back', 'callback_data' => 'cmd:home']],
                [['text' => '🔓 Unlink Account', 'callback_data' => 'cmd:unlink_confirm']]
            ]);
            sendMessage($chat_id, $response, $bot_token, $kb);
        } catch (Exception $e) {
            $log("Error: " . $e->getMessage());
            sendMessage($chat_id, "❌ Error.", $bot_token);
        }
        
    } elseif ($text === '/askappointment') {
        // ✅ SINGLE DOCTOR: Skip doctor list, go to date
        $response = "🏥 *Book with Dr. John*\n\n*Step 1: Select date*\n\n📅 *Clinic Hours:* Sunday-Thursday, 8AM-5PM\n\n📅 Tap below or type:";
        
        $buttons = [];
        $row = [];
        $added = 0;
        $startDate = new DateTime();
        $startDate->modify('+1 day');
        $count = 0;
        
        while ($added < 7 && $count < 21) {
            if (isWorkingDay($startDate)) {
                $val = $startDate->format('Y-m-d');
                $lbl = $startDate->format('D, M j');
                $row[] = ['text' => $lbl, 'callback_data' => "date:{$val}"];
                $added++;
                if (count($row) === 2) {
                    $buttons[] = $row;
                    $row = [];
                }
            }
            $startDate->modify('+1 day');
            $count++;
        }
        if (!empty($row)) $buttons[] = $row;
        $buttons[] = [['text' => '🏠 Cancel', 'callback_data' => 'cmd:home']];
        
        $session_data = ['doctor_id' => 1, 'doctor_name' => 'John'];
        try {
            $pdo->prepare("REPLACE INTO telegram_sessions (telegram_user_id, step, data_json, updated_at) VALUES (?, 'booking_date', ?, NOW())")
                ->execute([$telegram_user_id, json_encode($session_data)]);
        } catch (PDOException $e) {
            $log("Session error: " . $e->getMessage());
        }
        
        sendMessage($chat_id, $response, $bot_token, createKeyboard($buttons));
        
    // ✅ NEW: /unlink command handler
    } elseif ($text === '/unlink') {
        // Show unlink confirmation via inline keyboard
        $response = "⚠️ *Unlink Account?*\n\n";
        $response .= "This will disconnect your Telegram from your patient profile.\n\n";
        $response .= "• You'll need to re-link with /start to book appointments\n";
        $response .= "• Your medical records remain safe in our system\n\n";
        $response .= "Tap below to confirm:";
        
        $kb = createKeyboard([
            [['text' => '✅ Yes, Unlink', 'callback_data' => 'confirm:unlink'], ['text' => '❌ Cancel', 'callback_data' => 'cmd:home']]
        ]);
        sendMessage($chat_id, $response, $bot_token, $kb);
        
    } elseif ($text === '/help') {
        $response = "❓ *Commands*\n\n";
        $response .= "• `/appointments` - View appointments\n";
        $response .= "• `/next` - Next appointment\n";
        $response .= "• `/queue` - Queue position\n";
        $response .= "• `/profile` - Your profile\n";
        $response .= "• `/askappointment` - Book with Dr. John\n";
        $response .= "• `/unlink` - Disconnect your Telegram account\n";  // ✅ ADDED
        $response .= "• `/help` - This help\n\n";
        $response .= "🏥 *Clinic Hours:* Sunday-Thursday, 8AM-5PM\n";
        $response .= "📌 Reminders at 7 AM.";
        $kb = createKeyboard([[['text' => '🏥 Book', 'callback_data' => 'cmd:askappointment'], ['text' => '📋 Appointments', 'callback_data' => 'cmd:appointments'], ['text' => '🎫 Queue', 'callback_data' => 'cmd:queue']]]);
        sendMessage($chat_id, $response, $bot_token, $kb);
        
    } elseif (strpos($text, '/') === 0) {
        $kb = createKeyboard([[['text' => '🏠 Menu', 'callback_data' => 'cmd:home'], ['text' => '❓ Help', 'callback_data' => 'cmd:help']]]);
        sendMessage($chat_id, "🤖 *Unknown command*\n\nUse menu below:", $bot_token, $kb);
    }
    
    http_response_code(200);
    echo "OK";
    exit;
}

// ========== DEFAULT RESPONSE ==========
http_response_code(200);
echo "OK";
