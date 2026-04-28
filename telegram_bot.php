<?php
require_once 'includes/config.php';

// ========== ERROR LOGGING FOR DEBUGGING ==========
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/bot_errors.log');
error_reporting(E_ALL);

// Get bot token from environment variable
$bot_token = getenv('TELEGRAM_BOT_TOKEN') ?: '';

// FALLBACK - REMOVE IN PRODUCTION
if (empty($bot_token)) {
    $bot_token = '8330456846:AAFYmkLZFCx1qw4n2sQa5eRCJBO26NV1QYM';
}

$content = file_get_contents('php://input');
$update = json_decode($content, true);

if (!$update) {
    exit();
}

// ========== Inline Keyboard Helper ==========
function createKeyboard($buttons) {
    $keyboard = [];
    foreach ($buttons as $row) {
        $keyboard[] = is_array($row[0]) ? $row : [$row];
    }
    return json_encode(['inline_keyboard' => $keyboard]);
}

// ========== Send with Buttons ==========
function sendMessage($chat_id, $message, $bot_token, $keyboard = null) {
    $url = "https://api.telegram.org/bot{$bot_token}/sendMessage";
    $data = [
        'chat_id' => $chat_id,
        'text' => $message,
        'parse_mode' => 'Markdown'
    ];
    if ($keyboard) {
        $data['reply_markup'] = $keyboard;
    }
    
    $options = [
        'http' => [
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'method' => 'POST',
            'content' => http_build_query($data)
        ]
    ];
    
    $context = stream_context_create($options);
    return file_get_contents($url, false, $context);
}

// ========== Edit Message (for pro UI) ==========
function editMessageText($chat_id, $message_id, $message, $bot_token, $keyboard = null) {
    $url = "https://api.telegram.org/bot{$bot_token}/editMessageText";
    $data = [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'text' => $message,
        'parse_mode' => 'Markdown'
    ];
    if ($keyboard) {
        $data['reply_markup'] = $keyboard;
    }
    
    $options = [
        'http' => [
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'method' => 'POST',
            'content' => http_build_query($data)
        ]
    ];
    
    $context = stream_context_create($options);
    $result = file_get_contents($url, false, $context);
    return $result !== false || strpos($result, 'message is not modified') !== false;
}

// ========== Answer Callback Query ==========
function answerCallback($callback_id, $bot_token) {
    $url = "https://api.telegram.org/bot{$bot_token}/answerCallbackQuery";
    $data = ['callback_query_id' => $callback_id];
    $options = [
        'http' => [
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'method' => 'POST',
            'content' => http_build_query($data)
        ]
    ];
    $context = stream_context_create($options);
    file_get_contents($url, false, $context);
}

function parseDateInput($input) {
    $input = trim($input);
    
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $input)) {
        $date = DateTime::createFromFormat('Y-m-d', $input);
        if ($date && $date->format('Y-m-d') == $input) {
            return $date;
        }
    }
    
    if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $input)) {
        $date = DateTime::createFromFormat('d-m-Y', $input);
        if ($date && $date->format('d-m-Y') == $input) {
            return $date;
        }
    }
    
    if (preg_match('#^\d{2}/\d{2}/\d{4}$#', $input)) {
        $date = DateTime::createFromFormat('d/m/Y', $input);
        if ($date && $date->format('d/m/Y') == $input) {
            return $date;
        }
    }
    
    return false;
}

// ========== ENHANCED: Filter Past Times for Today ==========
function getAvailableTimeSlots($pdo, $date, $doctor_id = 1) {
    $bookedSlots = [];
    $stmt = $pdo->prepare("SELECT appointment_time FROM appointments WHERE appointment_date = ? AND doctor_id = ? AND status NOT IN ('cancelled', 'no-show')");
    $stmt->execute([$date, $doctor_id]);
    $booked = $stmt->fetchAll();
    
    foreach ($booked as $b) {
        $bookedSlots[] = date('H:i:s', strtotime($b['appointment_time']));
    }
    
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
    $isToday = ($date == $now->format('Y-m-d'));
    
    foreach ($allSlots as $time => $display) {
        // Skip if booked
        if (in_array($time, $bookedSlots)) {
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
    $chat_id = $callback['message']['chat']['id'];
    $message_id = $callback['message']['message_id'];
    $telegram_user_id = $callback['from']['id'];
    
    $callback_data = $callback['data'] ?? '';
    
    answerCallback($callback['id'], $bot_token);
    
    $stmt = $pdo->prepare("SELECT patient_id, first_name, last_name, email, phone, created_at FROM patients WHERE telegram_user_id = ?");
    $stmt->execute([$telegram_user_id]);
    $patient = $stmt->fetch();
    
    if (!$patient) {
        editMessageText($chat_id, $message_id, "⚠️ Please type /start first to link your account.", $bot_token);
        exit();
    }
    
    // ========== BUTTON ROUTER ==========
    if ($callback_data === 'cmd:appointments') {
        $stmt = $pdo->prepare("SELECT a.*, CONCAT(d.first_name, ' ', d.last_name) as doctor_name FROM appointments a JOIN doctors d ON a.doctor_id = d.doctor_id WHERE a.patient_id = ? ORDER BY a.appointment_date DESC, a.appointment_time DESC");
        $stmt->execute([$patient['patient_id']]);
        $appointments = $stmt->fetchAll();
        
        if (count($appointments) > 0) {
            $response = "📋 *Your Appointments*\n\n";
            foreach ($appointments as $apt) {
                $statusIcon = ($apt['status'] == 'confirmed') ? '✅' : '⏳';
                $response .= "{$statusIcon} " . date('M j, Y', strtotime($apt['appointment_date'])) . " - " . date('g:i A', strtotime($apt['appointment_time'])) . "\n";
                $response .= "   Dr. {$apt['doctor_name']} | Queue #{$apt['queue_number']}\n";
                $response .= "   Status: " . ucfirst($apt['status']) . "\n\n";
            }
        } else {
            $response = "📭 *No appointments found*";
        }
        $kb = createKeyboard([[['text' => '🏥 Book New', 'callback_data' => 'cmd:askappointment'], ['text' => '🏠 Menu', 'callback_data' => 'cmd:home']]]);
        editMessageText($chat_id, $message_id, $response, $bot_token, $kb);
        
    } elseif ($callback_data === 'cmd:next') {
        $stmt = $pdo->prepare("SELECT a.*, CONCAT(d.first_name, ' ', d.last_name) as doctor_name FROM appointments a JOIN doctors d ON a.doctor_id = d.doctor_id WHERE a.patient_id = ? AND a.appointment_date >= CURDATE() AND a.status = 'confirmed' ORDER BY a.appointment_date ASC, a.appointment_time ASC LIMIT 1");
        $stmt->execute([$patient['patient_id']]);
        $apt = $stmt->fetch();
        
        if ($apt) {
            $response = "📅 *Next Appointment*\n\n";
            $response .= "📆 Date: " . date('l, F j, Y', strtotime($apt['appointment_date'])) . "\n";
            $response .= "⏰ Time: " . date('g:i A', strtotime($apt['appointment_time'])) . "\n";
            $response .= "👨‍⚕️ Doctor: Dr. {$apt['doctor_name']}\n";
            $response .= "🎫 Queue #: {$apt['queue_number']}\n";
            $response .= "📌 Status: " . ucfirst($apt['status']) . "\n\n";
            $response .= "Please arrive 10 minutes early!";
        } else {
            $response = "❌ *No confirmed appointments found*";
        }
        $kb = createKeyboard([[['text' => '🏥 Book', 'callback_data' => 'cmd:askappointment'], ['text' => '📋 All', 'callback_data' => 'cmd:appointments']]]);
        editMessageText($chat_id, $message_id, $response, $bot_token, $kb);
        
    } elseif ($callback_data === 'cmd:queue') {
        $stmt = $pdo->prepare("SELECT a.*, CONCAT(d.first_name, ' ', d.last_name) as doctor_name, 
            (SELECT COUNT(*) FROM appointments 
             WHERE appointment_date = a.appointment_date 
             AND appointment_time < a.appointment_time 
             AND status IN ('scheduled', 'confirmed') 
             AND doctor_id = a.doctor_id) as people_ahead, 
            (SELECT COUNT(*) FROM appointments 
             WHERE appointment_date = a.appointment_date 
             AND status IN ('scheduled', 'confirmed') 
             AND doctor_id = a.doctor_id) as total_waiting 
            FROM appointments a 
            JOIN doctors d ON a.doctor_id = d.doctor_id 
            WHERE a.patient_id = ? 
            AND a.appointment_date = CURDATE() 
            AND a.status IN ('scheduled', 'confirmed') 
            ORDER BY a.appointment_time ASC LIMIT 1");
        $stmt->execute([$patient['patient_id']]);
        $apt = $stmt->fetch();
        
        if ($apt) {
            $peopleAhead = $apt['people_ahead'];
            $response = "🎫 *Queue Information*\n\n";
            $response .= "👨‍⚕️ Doctor: Dr. {$apt['doctor_name']}\n";
            $response .= "🎟️ Your Queue #: {$apt['queue_number']}\n";
            $response .= "👥 People ahead: {$peopleAhead}\n\n";
            $response .= $peopleAhead == 0 ? "🔔 *You're NEXT!*" : "⏱️ Estimated wait: ~" . ($peopleAhead * 15) . " minutes";
        } else {
            $response = "🎫 *No Active Queue*";
        }
        $kb = createKeyboard([[['text' => '📋 View Appointments', 'callback_data' => 'cmd:appointments']]]);
        editMessageText($chat_id, $message_id, $response, $bot_token, $kb);
        
    } elseif ($callback_data === 'cmd:profile') {
        $memberDate = new DateTime($patient['created_at']);
        $response = "👤 *Your Profile*\n\n";
        $response .= "Name: {$patient['first_name']} {$patient['last_name']}\n";
        $response .= "Email: {$patient['email']}\n";
        $response .= "Phone: {$patient['phone']}\n";
        $response .= "Member since: " . $memberDate->format('F j, Y');
        $kb = createKeyboard([[['text' => '🏠 Back to Menu', 'callback_data' => 'cmd:home']]]);
        editMessageText($chat_id, $message_id, $response, $bot_token, $kb);
        
    } elseif ($callback_data === 'cmd:askappointment') {
        $response = "🏥 *Book with Dr. John*\n\n*Step 1: Select a date*\n\n📅 Tap a date below or type manually:";
        
        $buttons = [];
        $row = [];
        for ($i = 1; $i <= 7; $i++) {
            $date = new DateTime("+$i days");
            $val = $date->format('Y-m-d');
            $lbl = $date->format('D, M j');
            $row[] = ['text' => $lbl, 'callback_data' => "date:{$val}"];
            if (count($row) == 2) {
                $buttons[] = $row;
                $row = [];
            }
        }
        if (!empty($row)) $buttons[] = $row;
        $buttons[] = [['text' => '◀️ More', 'callback_data' => 'date:more'], ['text' => '🔄 Cancel', 'callback_data' => 'cmd:home']];
        
        $session_data = ['doctor_id' => 1, 'doctor_name' => 'John'];
        $pdo->prepare("REPLACE INTO telegram_sessions (telegram_user_id, step, data_json, updated_at) VALUES (?, 'booking_date', ?, NOW())")
            ->execute([$telegram_user_id, json_encode($session_data)]);
        
        editMessageText($chat_id, $message_id, $response, $bot_token, createKeyboard($buttons));
        
    } elseif ($callback_data === 'cmd:help') {
        $response = "❓ *Available Commands*\n\n";
        $response .= "• `/appointments` - View all your appointments\n";
        $response .= "• `/next` - Show your next confirmed appointment\n";
        $response .= "• `/queue` - Check your queue position\n";
        $response .= "• `/profile` - View your profile information\n";
        $response .= "• `/askappointment` - Book with Dr. John\n";
        $response .= "• `/help` - Show this help message\n\n";
        $response .= "📌 *Automatic Reminders*\n";
        $response .= "You will receive appointment reminders automatically at 7 AM on the day of your appointment.";
        $kb = createKeyboard([[['text' => '🏥 Book', 'callback_data' => 'cmd:askappointment'], ['text' => '📋 Appointments', 'callback_data' => 'cmd:appointments'], ['text' => '🎫 Queue', 'callback_data' => 'cmd:queue']]]);
        editMessageText($chat_id, $message_id, $response, $bot_token, $kb);
        
    } elseif ($callback_data === 'cmd:home') {
        $response = "🏥 *Shifa Medical Center*\n\nHello, {$patient['first_name']}! 👋\n\nHow can we help you today?";
        $kb = createKeyboard([
            [['text' => '🏥 Book Appointment', 'callback_data' => 'cmd:askappointment'], ['text' => '📋 My Appointments', 'callback_data' => 'cmd:appointments']],
            [['text' => '📅 Next Visit', 'callback_data' => 'cmd:next'], ['text' => '🎫 Queue Status', 'callback_data' => 'cmd:queue']],
            [['text' => '👤 My Profile', 'callback_data' => 'cmd:profile'], ['text' => '❓ Help', 'callback_data' => 'cmd:help']]
        ]);
        editMessageText($chat_id, $message_id, $response, $bot_token, $kb);
        
    } elseif (strpos($callback_data, 'date:') === 0) {
        $dateVal = str_replace('date:', '', $callback_data);
        
        if ($dateVal === 'more') {
            $buttons = [];
            $row = [];
            for ($i = 8; $i <= 14; $i++) {
                $date = new DateTime("+$i days");
                $val = $date->format('Y-m-d');
                $lbl = $date->format('D, M j');
                $row[] = ['text' => $lbl, 'callback_data' => "date:{$val}"];
                if (count($row) == 2) {
                    $buttons[] = $row;
                    $row = [];
                }
            }
            if (!empty($row)) $buttons[] = $row;
            $buttons[] = [['text' => '🔙 Back', 'callback_data' => 'cmd:askappointment']];
            editMessageText($chat_id, $message_id, "📅 *Select a date*\n\nChoose from below:", $bot_token, createKeyboard($buttons));
            exit();
        }
        
        $dateObj = DateTime::createFromFormat('Y-m-d', $dateVal);
        $tomorrow = new DateTime('tomorrow');
        
        if ($dateObj && $dateObj >= $tomorrow) {
            // Future date - proceed normally
        } else {
            $today = new DateTime('today');
            if ($dateObj && $dateObj->format('Y-m-d') == $today->format('Y-m-d')) {
                // Today - allow but filter out past times in getAvailableTimeSlots
            } else {
                editMessageText($chat_id, $message_id, "❌ Please select today or a future date.", $bot_token, createKeyboard([[['text' => '📅 Try Again', 'callback_data' => 'booking:date']]]));
                exit();
            }
        }
        
        $session_stmt = $pdo->prepare("SELECT step, data_json FROM telegram_sessions WHERE telegram_user_id = ?");
        $session_stmt->execute([$telegram_user_id]);
        $session = $session_stmt->fetch();
        $session_data = json_decode($session['data_json'], true);
        
        $selected_date = $dateObj->format('Y-m-d');
        $display_date = $dateObj->format('l, F j, Y');
        
        $session_data['date'] = $selected_date;
        $session_data['display_date'] = $display_date;
        
        $availableSlots = getAvailableTimeSlots($pdo, $selected_date, 1);
        
        if (empty($availableSlots)) {
            editMessageText($chat_id, $message_id, "❌ No slots on *{$display_date}*. Try another date:", $bot_token, createKeyboard([[['text' => '📅 Pick Another', 'callback_data' => 'date:more']], [['text' => '🔄 Cancel', 'callback_data' => 'cmd:home']]]));
            exit();
        }
        
        $session_data['available_slots'] = $availableSlots;
        $pdo->prepare("UPDATE telegram_sessions SET step = 'booking_time', data_json = ? WHERE telegram_user_id = ?")->execute([json_encode($session_data), $telegram_user_id]);
        
        $response = "📅 *{$display_date}*\n\n⏰ *Select a time slot*:";
        
        $buttons = [];
        $row = [];
        foreach ($availableSlots as $slot) {
            $row[] = ['text' => $slot['display'], 'callback_data' => "time:{$slot['time']}"];
            if (count($row) == 3) {
                $buttons[] = $row;
                $row = [];
            }
        }
        if (!empty($row)) $buttons[] = $row;
        $buttons[] = [['text' => '🔙 Change Date', 'callback_data' => 'booking:date'], ['text' => '🔄 Cancel', 'callback_data' => 'cmd:home']];
        
        editMessageText($chat_id, $message_id, $response, $bot_token, createKeyboard($buttons));
        
    } elseif (strpos($callback_data, 'time:') === 0) {
        $timeVal = str_replace('time:', '', $callback_data);
        
        $session_stmt = $pdo->prepare("SELECT step, data_json FROM telegram_sessions WHERE telegram_user_id = ?");
        $session_stmt->execute([$telegram_user_id]);
        $session = $session_stmt->fetch();
        $session_data = json_decode($session['data_json'], true);
        $availableSlots = $session_data['available_slots'];
        
        $selected_slot = null;
        foreach ($availableSlots as $s) {
            if ($s['time'] === $timeVal) {
                $selected_slot = $s;
                break;
            }
        }
        
        if ($selected_slot) {
            $check = $pdo->prepare("SELECT appointment_id FROM appointments WHERE appointment_date = ? AND appointment_time = ? AND doctor_id = ? AND status NOT IN ('cancelled', 'no-show')");
            $check->execute([$session_data['date'], $selected_slot['time'], 1]);
            
            if ($check->rowCount() > 0) {
                editMessageText($chat_id, $message_id, "❌ Slot just taken! Restart with /askappointment", $bot_token);
                $pdo->prepare("DELETE FROM telegram_sessions WHERE telegram_user_id = ?")->execute([$telegram_user_id]);
                exit();
            }
            
            $session_data['time'] = $selected_slot['time'];
            $session_data['display_time'] = $selected_slot['display'];
            unset($session_data['available_slots']);
            
            $pdo->prepare("UPDATE telegram_sessions SET step = 'booking_confirm', data_json = ? WHERE telegram_user_id = ?")->execute([json_encode($session_data), $telegram_user_id]);
            
            $response = "⏰ *Time selected:* {$session_data['display_time']}\n\n*✅ Confirm Appointment*\n\n";
            $response .= "📋 *Details:*\n";
            $response .= "👨‍⚕️ Doctor: Dr. {$session_data['doctor_name']}\n";
            $response .= "📆 Date: {$session_data['display_date']}\n";
            $response .= "⏰ Time: {$session_data['display_time']}\n\n";
            $response .= "Tap below to confirm:";
            
            $kb = createKeyboard([
                [['text' => '✅ Confirm Booking', 'callback_data' => 'confirm:book'], ['text' => '❌ Cancel', 'callback_data' => 'cmd:home']],
                [['text' => '🔄 Change Time', 'callback_data' => 'booking:time'], ['text' => '📅 Change Date', 'callback_data' => 'booking:date']]
            ]);
            
            editMessageText($chat_id, $message_id, $response, $bot_token, $kb);
        }
        
    } elseif ($callback_data === 'confirm:book') {
        $session_stmt = $pdo->prepare("SELECT step, data_json FROM telegram_sessions WHERE telegram_user_id = ?");
        $session_stmt->execute([$telegram_user_id]);
        $session = $session_stmt->fetch();
        $session_data = json_decode($session['data_json'], true);
        
        $check = $pdo->prepare("SELECT appointment_id FROM appointments WHERE appointment_date = ? AND appointment_time = ? AND doctor_id = ? AND status NOT IN ('cancelled', 'no-show')");
        $check->execute([$session_data['date'], $session_data['time'], 1]);
        
        if ($check->rowCount() > 0) {
            editMessageText($chat_id, $message_id, "❌ Slot no longer available. Start over with /askappointment", $bot_token);
            $pdo->prepare("DELETE FROM telegram_sessions WHERE telegram_user_id = ?")->execute([$telegram_user_id]);
            exit();
        }
        
        $queue_stmt = $pdo->prepare("SELECT COUNT(*) + 1 as next_queue FROM appointments WHERE appointment_date = ? AND appointment_time < ? AND doctor_id = ? AND status IN ('scheduled', 'confirmed')");
        $queue_stmt->execute([$session_data['date'], $session_data['time'], 1]);
        $queueNum = $queue_stmt->fetchColumn();
        
        $insert = $pdo->prepare("INSERT INTO appointments (patient_id, doctor_id, appointment_date, appointment_time, queue_number, status, send_sms, created_at) VALUES (?, ?, ?, ?, ?, 'scheduled', 1, NOW())");
        $insert->execute([$patient['patient_id'], 1, $session_data['date'], $session_data['time'], $queueNum]);
        
        $pdo->prepare("DELETE FROM telegram_sessions WHERE telegram_user_id = ?")->execute([$telegram_user_id]);
        
        $response = "✅ *Appointment Booked Successfully!*\n\n";
        $response .= "📋 *Appointment Details:*\n";
        $response .= "👨‍⚕️ Doctor: Dr. {$session_data['doctor_name']}\n";
        $response .= "📆 Date: {$session_data['display_date']}\n";
        $response .= "⏰ Time: {$session_data['display_time']}\n";
        $response .= "🎫 Queue Number: {$queueNum}\n\n";
        $response .= "📌 Please arrive 10 minutes before your appointment time.\n";
        $response .= "You will receive a reminder before your appointment.\n\n";
        $response .= "Use `/appointments` to view all your appointments or `/next` to see your next one.";
        
        $kb = createKeyboard([[['text' => '📋 My Appointments', 'callback_data' => 'cmd:appointments'], ['text' => '🏠 Main Menu', 'callback_data' => 'cmd:home']]]);
        editMessageText($chat_id, $message_id, $response, $bot_token, $kb);
        
    } elseif ($callback_data === 'booking:date') {
        $session_stmt = $pdo->prepare("SELECT step, data_json FROM telegram_sessions WHERE telegram_user_id = ?");
        $session_stmt->execute([$telegram_user_id]);
        $session = $session_stmt->fetch();
        $session_data = json_decode($session['data_json'], true);
        
        $response = "📅 *Select a date*\n\n📅 Tap a date below or type manually:";
        
        $buttons = [];
        $row = [];
        for ($i = 1; $i <= 7; $i++) {
            $date = new DateTime("+$i days");
            $val = $date->format('Y-m-d');
            $lbl = $date->format('D, M j');
            $row[] = ['text' => $lbl, 'callback_data' => "date:{$val}"];
            if (count($row) == 2) {
                $buttons[] = $row;
                $row = [];
            }
        }
        if (!empty($row)) $buttons[] = $row;
        $buttons[] = [['text' => '◀️ More', 'callback_data' => 'date:more'], ['text' => '🔄 Cancel', 'callback_data' => 'cmd:home']];
        
        editMessageText($chat_id, $message_id, $response, $bot_token, createKeyboard($buttons));
        
    } elseif ($callback_data === 'booking:time') {
        $session_stmt = $pdo->prepare("SELECT step, data_json FROM telegram_sessions WHERE telegram_user_id = ?");
        $session_stmt->execute([$telegram_user_id]);
        $session = $session_stmt->fetch();
        $session_data = json_decode($session['data_json'], true);
        
        $availableSlots = getAvailableTimeSlots($pdo, $session_data['date'], 1);
        
        if (empty($availableSlots)) {
            editMessageText($chat_id, $message_id, "❌ No slots available. Please pick another date.", $bot_token, createKeyboard([[['text' => '📅 Pick Another', 'callback_data' => 'booking:date']], [['text' => '🔄 Cancel', 'callback_data' => 'cmd:home']]]));
            exit();
        }
        
        $response = "📅 *{$session_data['display_date']}*\n\n⏰ *Select a time slot*:";
        
        $buttons = [];
        $row = [];
        foreach ($availableSlots as $slot) {
            $row[] = ['text' => $slot['display'], 'callback_data' => "time:{$slot['time']}"];
            if (count($row) == 3) {
                $buttons[] = $row;
                $row = [];
            }
        }
        if (!empty($row)) $buttons[] = $row;
        $buttons[] = [['text' => '🔙 Change Date', 'callback_data' => 'booking:date'], ['text' => '🔄 Cancel', 'callback_data' => 'cmd:home']];
        
        $session_data['available_slots'] = $availableSlots;
        $pdo->prepare("UPDATE telegram_sessions SET step = 'booking_time', data_json = ? WHERE telegram_user_id = ?")->execute([json_encode($session_data), $telegram_user_id]);
        
        editMessageText($chat_id, $message_id, $response, $bot_token, createKeyboard($buttons));
        
    } elseif ($callback_data === 'booking:cancel' || $callback_data === 'cmd:home') {
        $pdo->prepare("DELETE FROM telegram_sessions WHERE telegram_user_id = ?")->execute([$telegram_user_id]);
        $response = "❌ Booking cancelled. Tap below to start over:";
        $kb = createKeyboard([[['text' => '🏥 Book Appointment', 'callback_data' => 'cmd:askappointment'], ['text' => '🏠 Main Menu', 'callback_data' => 'cmd:home']]]);
        editMessageText($chat_id, $message_id, $response, $bot_token, $kb);
    }
    
    exit();
}

// ========== ORIGINAL MESSAGE HANDLER (Text Commands) ==========
if (isset($update['message'])) {
    $message = $update['message'];
    $chat_id = $message['chat']['id'];
    $text = trim($message['text'] ?? '');
    $telegram_user_id = $message['from']['id'];
    
    // Welcome message for empty text
    if ($text == '' || $text == null) {
        $kb = createKeyboard([[['text' => '🚀 Get Started', 'callback_data' => 'cmd:home']]]);
        sendMessage($chat_id, "👋 Welcome to Shifa Medical Center!\n\nTap below to begin:", $bot_token, $kb);
        exit();
    }
    
    $stmt = $pdo->prepare("SELECT patient_id, first_name, last_name, email, phone, created_at FROM patients WHERE telegram_user_id = ?");
    $stmt->execute([$telegram_user_id]);
    $patient = $stmt->fetch();
    
    $session_stmt = $pdo->prepare("SELECT step, data_json FROM telegram_sessions WHERE telegram_user_id = ?");
    $session_stmt->execute([$telegram_user_id]);
    $session = $session_stmt->fetch();
    
    // ========== USER NOT LINKED ==========
    if (!$patient) {
        if ($text == '/start') {
            $pdo->prepare("REPLACE INTO telegram_sessions (telegram_user_id, step, data_json, updated_at) VALUES (?, 'waiting_email', NULL, NOW())")->execute([$telegram_user_id]);
            
            $response = "🏥 *Welcome to Shifa Medical Center Bot!*\n\n";
            $response .= "To link your account, please enter your registered email address or phone number.\n\n";
            $response .= "*Example:* `lana@gmail.com` or `0556431565`\n\n";
            $response .= "This will instantly link your Telegram account.";
            
            $kb = createKeyboard([[['text' => '🚀 Get Started', 'callback_data' => 'cmd:home']]]);
            sendMessage($chat_id, $response, $bot_token, $kb);
        }
        elseif ($session && $session['step'] == 'waiting_email') {
            $input = trim($text);
            
            $stmt = $pdo->prepare("SELECT patient_id, first_name, last_name, email, phone, created_at FROM patients WHERE email = ? OR phone = ?");
            $stmt->execute([$input, $input]);
            $found = $stmt->fetch();
            
            if ($found) {
                $pdo->prepare("UPDATE patients SET telegram_user_id = ?, telegram_linked_at = NOW() WHERE patient_id = ?")->execute([$telegram_user_id, $found['patient_id']]);
                $pdo->prepare("DELETE FROM telegram_sessions WHERE telegram_user_id = ?")->execute([$telegram_user_id]);
                
                $response = "✅ *Account Linked Successfully!*\n\n";
                $response .= "Welcome {$found['first_name']} {$found['last_name']}!\n\n";
                $response .= "*Available Commands:*\n";
                $response .= "• `/appointments` - View your appointments\n";
                $response .= "• `/next` - Your next appointment\n";
                $response .= "• `/queue` - Check queue position\n";
                $response .= "• `/profile` - View your profile\n";
                $response .= "• `/askappointment` - Book with Dr. John\n";
                $response .= "• `/help` - Show all commands\n\n";
                $response .= "You will automatically receive appointment reminders.";
                
                $kb = createKeyboard([
                    [['text' => '🏥 Book Appointment', 'callback_data' => 'cmd:askappointment'], ['text' => '📋 My Appointments', 'callback_data' => 'cmd:appointments']],
                    [['text' => '📅 Next Visit', 'callback_data' => 'cmd:next'], ['text' => '🎫 Queue Status', 'callback_data' => 'cmd:queue']],
                    [['text' => '👤 My Profile', 'callback_data' => 'cmd:profile'], ['text' => '❓ Help', 'callback_data' => 'cmd:help']]
                ]);
                sendMessage($chat_id, $response, $bot_token, $kb);
            } else {
                sendMessage($chat_id, "❌ *Account not found*\n\nNo patient found with: `{$input}`\n\nType /start to try again.", $bot_token);
                $pdo->prepare("DELETE FROM telegram_sessions WHERE telegram_user_id = ?")->execute([$telegram_user_id]);
            }
        }
        else {
            $kb = createKeyboard([[['text' => '🚀 Get Started', 'callback_data' => 'cmd:home']]]);
            sendMessage($chat_id, "👋 *Welcome!*\n\nTap below to link your account:", $bot_token, $kb);
        }
        exit();
    }
    
    // ========== USER IS LINKED - BOOKING FLOW ==========
    
    if ($session && $session['step'] == 'booking_date') {
        $session_data = json_decode($session['data_json'], true);
        $dateObj = parseDateInput($text);
        $tomorrow = new DateTime('tomorrow');
        
        if ($dateObj && $dateObj >= $tomorrow) {
            // Future date - proceed normally
        } else {
            $today = new DateTime('today');
            if ($dateObj && $dateObj->format('Y-m-d') == $today->format('Y-m-d')) {
                // Today - allow but filter out past times in getAvailableTimeSlots
            } else {
                sendMessage($chat_id, "❌ Please select today or a future date.", $bot_token);
                exit();
            }
        }
        
        $selected_date = $dateObj->format('Y-m-d');
        $display_date = $dateObj->format('l, F j, Y');
        
        $session_data['date'] = $selected_date;
        $session_data['display_date'] = $display_date;
        
        $availableSlots = getAvailableTimeSlots($pdo, $selected_date, 1);
        
        if (empty($availableSlots)) {
            sendMessage($chat_id, "❌ No available slots on " . $display_date . ". Please choose another date.\n\nType a new date or 'cancel' to cancel.", $bot_token);
            $pdo->prepare("UPDATE telegram_sessions SET step = 'booking_date', data_json = ? WHERE telegram_user_id = ?")->execute([json_encode($session_data), $telegram_user_id]);
            exit();
        }
        
        $session_data['available_slots'] = $availableSlots;
        $pdo->prepare("UPDATE telegram_sessions SET step = 'booking_time', data_json = ? WHERE telegram_user_id = ?")->execute([json_encode($session_data), $telegram_user_id]);
        
        $response = "📅 *Date selected:* {$display_date}\n\n";
        $response .= "*Step 2: Select a time*\n\n";
        $response .= "Available time slots:\n";
        
        $slot_num = 1;
        foreach ($availableSlots as $slot) {
            $response .= "{$slot_num}. {$slot['display']}\n";
            $slot_num++;
        }
        $response .= "\nPlease enter the number of your preferred time slot.";
        
        sendMessage($chat_id, $response, $bot_token);
        
    // ========== FIX #1: Added exit() to prevent fall-through ==========
    } elseif ($text == 'cancel') {
        $pdo->prepare("DELETE FROM telegram_sessions WHERE telegram_user_id = ?")->execute([$telegram_user_id]);
        sendMessage($chat_id, "❌ Booking cancelled. Type /askappointment to start over.", $bot_token);
        exit(); // Prevents fall-through to booking_time section
    }
    
    if ($session && $session['step'] == 'booking_time') {
        $session_data = json_decode($session['data_json'], true);
        $availableSlots = $session_data['available_slots'];
        
        if (is_numeric($text) && $text >= 1 && $text <= count($availableSlots)) {
            $selected_slot = $availableSlots[$text - 1];
            
            $check = $pdo->prepare("SELECT appointment_id FROM appointments WHERE appointment_date = ? AND appointment_time = ? AND doctor_id = ? AND status NOT IN ('cancelled', 'no-show')");
            $check->execute([$session_data['date'], $selected_slot['time'], 1]);
            
            if ($check->rowCount() > 0) {
                sendMessage($chat_id, "❌ Sorry, that time slot was just taken. Please restart booking with /askappointment.", $bot_token);
                $pdo->prepare("DELETE FROM telegram_sessions WHERE telegram_user_id = ?")->execute([$telegram_user_id]);
                exit();
            }
            
            $session_data['time'] = $selected_slot['time'];
            $session_data['display_time'] = $selected_slot['display'];
            unset($session_data['available_slots']);
            
            $pdo->prepare("UPDATE telegram_sessions SET step = 'booking_confirm', data_json = ? WHERE telegram_user_id = ?")->execute([json_encode($session_data), $telegram_user_id]);
            
            $response = "⏰ *Time selected:* {$session_data['display_time']}\n\n";
            $response .= "*Step 3: Confirm your appointment*\n\n";
            $response .= "📋 *Appointment Details:*\n";
            $response .= "👨‍⚕️ Doctor: Dr. {$session_data['doctor_name']}\n";
            $response .= "📆 Date: {$session_data['display_date']}\n";
            $response .= "⏰ Time: {$session_data['display_time']}\n\n";
            $response .= "✅ Type `confirm` to book this appointment\n";
            $response .= "❌ Type `cancel` to cancel\n";
            $response .= "🔄 Type a new date (DD-MM-YYYY) to change the date";
            sendMessage($chat_id, $response, $bot_token);
        }
        elseif ($text == 'cancel') {
            $pdo->prepare("DELETE FROM telegram_sessions WHERE telegram_user_id = ?")->execute([$telegram_user_id]);
            sendMessage($chat_id, "❌ Booking cancelled. Type /askappointment to start over.", $bot_token);
        }
        else {
            sendMessage($chat_id, "❌ Invalid choice. Please enter the number from the list.", $bot_token);
        }
        exit();
    }
    
    if ($session && $session['step'] == 'booking_confirm') {
        $session_data = json_decode($session['data_json'], true);
        
        if (strtolower($text) == 'confirm') {
            $check = $pdo->prepare("SELECT appointment_id FROM appointments WHERE appointment_date = ? AND appointment_time = ? AND doctor_id = ? AND status NOT IN ('cancelled', 'no-show')");
            $check->execute([$session_data['date'], $session_data['time'], 1]);
            
            if ($check->rowCount() > 0) {
                sendMessage($chat_id, "❌ Sorry, this slot is no longer available. Please start over with /askappointment.", $bot_token);
                $pdo->prepare("DELETE FROM telegram_sessions WHERE telegram_user_id = ?")->execute([$telegram_user_id]);
                exit();
            }
            
            $queue_stmt = $pdo->prepare("SELECT COUNT(*) + 1 as next_queue FROM appointments WHERE appointment_date = ? AND appointment_time < ? AND doctor_id = ? AND status IN ('scheduled', 'confirmed')");
            $queue_stmt->execute([$session_data['date'], $session_data['time'], 1]);
            $queueNum = $queue_stmt->fetchColumn();
            
            $insert = $pdo->prepare("INSERT INTO appointments (patient_id, doctor_id, appointment_date, appointment_time, queue_number, status, send_sms, created_at) VALUES (?, ?, ?, ?, ?, 'scheduled', 1, NOW())");
            $insert->execute([$patient['patient_id'], 1, $session_data['date'], $session_data['time'], $queueNum]);
            
            $pdo->prepare("DELETE FROM telegram_sessions WHERE telegram_user_id = ?")->execute([$telegram_user_id]);
            
            $response = "✅ *Appointment Booked Successfully!*\n\n";
            $response .= "📋 *Appointment Details:*\n";
            $response .= "👨‍⚕️ Doctor: Dr. {$session_data['doctor_name']}\n";
            $response .= "📆 Date: {$session_data['display_date']}\n";
            $response .= "⏰ Time: {$session_data['display_time']}\n";
            $response .= "🎫 Queue Number: {$queueNum}\n\n";
            $response .= "📌 Please arrive 10 minutes before your appointment time.\n";
            $response .= "You will receive a reminder before your appointment.\n\n";
            $response .= "Use `/appointments` to view all your appointments or `/next` to see your next one.";
            sendMessage($chat_id, $response, $bot_token);
        }
        elseif (strtolower($text) == 'cancel') {
            $pdo->prepare("DELETE FROM telegram_sessions WHERE telegram_user_id = ?")->execute([$telegram_user_id]);
            sendMessage($chat_id, "❌ Booking cancelled. Type /askappointment to start over.", $bot_token);
        }
        else {
            $newDate = parseDateInput($text);
            $tomorrow = new DateTime('tomorrow');
            
            if ($newDate && $newDate >= $tomorrow) {
                $session_data['date'] = $newDate->format('Y-m-d');
                $session_data['display_date'] = $newDate->format('l, F j, Y');
                unset($session_data['time'], $session_data['display_time']);
                
                $availableSlots = getAvailableTimeSlots($pdo, $session_data['date'], 1);
                
                if (empty($availableSlots)) {
                    sendMessage($chat_id, "❌ No available slots on {$session_data['display_date']}. Please try another date or type 'cancel'.", $bot_token);
                    exit();
                }
                
                $session_data['available_slots'] = $availableSlots;
                $pdo->prepare("UPDATE telegram_sessions SET step = 'booking_time', data_json = ? WHERE telegram_user_id = ?")->execute([json_encode($session_data), $telegram_user_id]);
                
                $response = "🔄 *Date changed to:* {$session_data['display_date']}\n\n";
                $response .= "*Available time slots:*\n";
                $slot_num = 1;
                foreach ($availableSlots as $slot) {
                    $response .= "{$slot_num}. {$slot['display']}\n";
                    $slot_num++;
                }
                $response .= "\nPlease enter the number of your preferred time slot.";
                sendMessage($chat_id, $response, $bot_token);
            } else {
                sendMessage($chat_id, "❌ Invalid option. Type `confirm` to book, `cancel` to cancel, or enter a new date (DD-MM-YYYY).", $bot_token);
            }
        }
        exit();
    }
    
    // ========== REGULAR COMMANDS ==========
    
    if ($text == '/appointments') {
        $stmt = $pdo->prepare("SELECT a.*, CONCAT(d.first_name, ' ', d.last_name) as doctor_name FROM appointments a JOIN doctors d ON a.doctor_id = d.doctor_id WHERE a.patient_id = ? ORDER BY a.appointment_date DESC, a.appointment_time DESC");
        $stmt->execute([$patient['patient_id']]);
        $appointments = $stmt->fetchAll();
        
        if (count($appointments) > 0) {
            $response = "📋 *Your Appointments*\n\n";
            foreach ($appointments as $apt) {
                $statusIcon = ($apt['status'] == 'confirmed') ? '✅' : '⏳';
                $response .= "{$statusIcon} " . date('M j, Y', strtotime($apt['appointment_date'])) . " - " . date('g:i A', strtotime($apt['appointment_time'])) . "\n";
                $response .= "   Dr. {$apt['doctor_name']} | Queue #{$apt['queue_number']}\n";
                $response .= "   Status: " . ucfirst($apt['status']) . "\n\n";
            }
            $response .= "To book a new appointment, use `/askappointment`.";
        } else {
            $response = "📭 *No appointments found*\n\nUse `/askappointment` to book your first appointment.";
        }
        $kb = createKeyboard([[['text' => '🏥 Book Now', 'callback_data' => 'cmd:askappointment'], ['text' => '🏠 Menu', 'callback_data' => 'cmd:home']]]);
        sendMessage($chat_id, $response, $bot_token, $kb);
        
    } elseif ($text == '/next') {
        $stmt = $pdo->prepare("SELECT a.*, CONCAT(d.first_name, ' ', d.last_name) as doctor_name FROM appointments a JOIN doctors d ON a.doctor_id = d.doctor_id WHERE a.patient_id = ? AND a.appointment_date >= CURDATE() AND a.status = 'confirmed' ORDER BY a.appointment_date ASC, a.appointment_time ASC LIMIT 1");
        $stmt->execute([$patient['patient_id']]);
        $apt = $stmt->fetch();
        
        if ($apt) {
            $response = "📅 *Your Next Appointment*\n\n";
            $response .= "📆 Date: " . date('l, F j, Y', strtotime($apt['appointment_date'])) . "\n";
            $response .= "⏰ Time: " . date('g:i A', strtotime($apt['appointment_time'])) . "\n";
            $response .= "👨‍⚕️ Doctor: Dr. {$apt['doctor_name']}\n";
            $response .= "🎫 Queue #: {$apt['queue_number']}\n";
            $response .= "📌 Status: " . ucfirst($apt['status']) . "\n\n";
            $response .= "Please arrive 10 minutes early!";
        } else {
            $response = "❌ *No confirmed appointments found*\n\nYour appointments may be pending confirmation by the nurse, or you have no upcoming appointments.\n\nUse `/appointments` to check your requests.";
        }
        $kb = createKeyboard([[['text' => '🏥 Book', 'callback_data' => 'cmd:askappointment'], ['text' => '📋 All', 'callback_data' => 'cmd:appointments']]]);
        sendMessage($chat_id, $response, $bot_token, $kb);
        
    } elseif ($text == '/queue') {
        $stmt = $pdo->prepare("SELECT a.*, CONCAT(d.first_name, ' ', d.last_name) as doctor_name, 
            (SELECT COUNT(*) FROM appointments 
             WHERE appointment_date = a.appointment_date 
             AND appointment_time < a.appointment_time 
             AND status IN ('scheduled', 'confirmed') 
             AND doctor_id = a.doctor_id) as people_ahead, 
            (SELECT COUNT(*) FROM appointments 
             WHERE appointment_date = a.appointment_date 
             AND status IN ('scheduled', 'confirmed') 
             AND doctor_id = a.doctor_id) as total_waiting 
            FROM appointments a 
            JOIN doctors d ON a.doctor_id = d.doctor_id 
            WHERE a.patient_id = ? 
            AND a.appointment_date = CURDATE() 
            AND a.status IN ('scheduled', 'confirmed') 
            ORDER BY a.appointment_time ASC LIMIT 1");
        $stmt->execute([$patient['patient_id']]);
        $apt = $stmt->fetch();
        
        if ($apt) {
            $peopleAhead = $apt['people_ahead'];
            $totalWaiting = $apt['total_waiting'];
            $position = $peopleAhead + 1;
            
            $response = "🎫 *Queue Information*\n\n";
            $response .= "👨‍⚕️ Doctor: Dr. {$apt['doctor_name']}\n";
            $response .= "🎟️ Your Queue #: {$apt['queue_number']}\n";
            $response .= "📊 Position: {$position} of {$totalWaiting} waiting\n";
            $response .= "👥 People ahead: {$peopleAhead}\n\n";
            
            if ($peopleAhead == 0) {
                $response .= "🔔 *You're NEXT!* Please be ready when called.";
            } else {
                $waitTime = $peopleAhead * 15;
                $response .= "⏱️ Estimated wait: ~{$waitTime} minutes";
            }
        } else {
            $response = "🎫 *No Active Queue*\n\nYou don't have any confirmed appointments scheduled for today.\n\nCheck `/appointments` to see pending requests.\nSend `/next` to see your next confirmed appointment.";
        }
        $kb = createKeyboard([[['text' => '📋 View Appointments', 'callback_data' => 'cmd:appointments']]]);
        sendMessage($chat_id, $response, $bot_token, $kb);
        
    } elseif ($text == '/profile') {
        $memberDate = new DateTime($patient['created_at']);
        $response = "👤 *Your Profile*\n\n";
        $response .= "Name: {$patient['first_name']} {$patient['last_name']}\n";
        $response .= "Email: {$patient['email']}\n";
        $response .= "Phone: {$patient['phone']}\n";
        $response .= "Member since: " . $memberDate->format('F j, Y') . "\n\n";
        $response .= "To update your profile, please visit our website.";
        $kb = createKeyboard([[['text' => '🏠 Back to Menu', 'callback_data' => 'cmd:home']]]);
        sendMessage($chat_id, $response, $bot_token, $kb);
        
    } elseif ($text == '/askappointment') {
        $response = "🏥 *Book with Dr. John*\n\n*Step 1: Select a date*\n\n📅 Tap a date below or type manually:";
        
        $buttons = [];
        $row = [];
        for ($i = 1; $i <= 7; $i++) {
            $date = new DateTime("+$i days");
            $val = $date->format('Y-m-d');
            $lbl = $date->format('D, M j');
            $row[] = ['text' => $lbl, 'callback_data' => "date:{$val}"];
            if (count($row) == 2) {
                $buttons[] = $row;
                $row = [];
            }
        }
        if (!empty($row)) $buttons[] = $row;
        $buttons[] = [['text' => '🏠 Cancel', 'callback_data' => 'cmd:home']];
        
        $session_data = ['doctor_id' => 1, 'doctor_name' => 'John'];
        $pdo->prepare("REPLACE INTO telegram_sessions (telegram_user_id, step, data_json, updated_at) VALUES (?, 'booking_date', ?, NOW())")
            ->execute([$telegram_user_id, json_encode($session_data)]);
        
        sendMessage($chat_id, $response, $bot_token, createKeyboard($buttons));
        
    } elseif ($text == '/help') {
        $response = "❓ *Available Commands*\n\n";
        $response .= "• `/appointments` - View all your appointments\n";
        $response .= "• `/next` - Show your next confirmed appointment\n";
        $response .= "• `/queue` - Check your queue position\n";
        $response .= "• `/profile` - View your profile information\n";
        $response .= "• `/askappointment` - Book with Dr. John\n";
        $response .= "• `/help` - Show this help message\n\n";
        $response .= "📌 *Automatic Reminders*\n";
        $response .= "You will receive appointment reminders automatically at 7 AM on the day of your appointment.\n\n";
        $response .= "For urgent matters, please call the clinic directly.";
        $kb = createKeyboard([[['text' => '🏥 Book', 'callback_data' => 'cmd:askappointment'], ['text' => '📋 Appointments', 'callback_data' => 'cmd:appointments'], ['text' => '🎫 Queue', 'callback_data' => 'cmd:queue']]]);
        sendMessage($chat_id, $response, $bot_token, $kb);
        
    } elseif (strpos($text, '/') === 0) {
        $kb = createKeyboard([[['text' => '🏠 Main Menu', 'callback_data' => 'cmd:home'], ['text' => '❓ Help', 'callback_data' => 'cmd:help']]]);
        sendMessage($chat_id, "🤖 *Unknown command*\n\nUse the menu below:", $bot_token, $kb);
    }
}
?>
