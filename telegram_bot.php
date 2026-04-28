<?php
require_once 'includes/config.php';

// Get bot token from environment variable
$bot_token = getenv('TELEGRAM_BOT_TOKEN') ?: '';

// FALLBACK - REMOVE IN PRODUCTION
if (empty($bot_token)) {
    $bot_token = '8330456846:AAHSmyKZrvCL5yLqpHjynBMqC6tM2u9k6N8';
}

$content = file_get_contents('php://input');
$update = json_decode($content, true);

if (!$update) {
    exit();
}

// ========== NEW: Inline Keyboard Helper ==========
function createKeyboard($buttons) {
    $keyboard = [];
    foreach ($buttons as $row) {
        $keyboard[] = is_array($row[0]) ? $row : [$row];
    }
    return json_encode(['inline_keyboard' => $keyboard]);
}

// ========== NEW: Send with Buttons ==========
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

// ========== NEW: Edit Message (for pro UI) ==========
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
    // Handle "message is not modified" gracefully
    return $result !== false || strpos($result, 'message is not modified') !== false;
}

// ========== NEW: Answer Callback Query (remove loading spinner) ==========
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

function getAvailableTimeSlots($pdo, $date, $doctor_id = 1) {
    $bookedSlots = [];
    $stmt = $pdo->prepare("SELECT appointment_time FROM appointments WHERE appointment_date = ? AND doctor_id = ? AND status NOT IN ('cancelled')");
    $stmt->execute([$date, $doctor_id]);
    $booked = $stmt->fetchAll();
    
    foreach ($booked as $b) {
        $bookedSlots[] = date('H:i:s', strtotime($b['appointment_time']));
    }
    
    $allSlots = [
        '08:30:00' => '8:30 AM',
        '09:00:00' => '9:00 AM',
        '09:30:00' => '9:30 AM',
        '10:00:00' => '10:00 AM',
        '10:30:00' => '10:30 AM',
        '11:00:00' => '11:00 AM',
        '11:30:00' => '11:30 AM',
        '12:00:00' => '12:00 PM',
        '12:30:00' => '12:30 PM',
        '13:00:00' => '1:00 PM',
        '13:30:00' => '1:30 PM',
        '14:00:00' => '2:00 PM',
        '14:30:00' => '2:30 PM',
        '15:00:00' => '3:00 PM',
        '15:30:00' => '3:30 PM',
        '16:00:00' => '4:00 PM',
        '16:30:00' => '4:30 PM'
    ];
    
    $available = [];
    foreach ($allSlots as $time => $display) {
        if (!in_array($time, $bookedSlots)) {
            $available[] = ['time' => $time, 'display' => $display];
        }
    }
    return $available;
}

function getDoctors($pdo) {
    $stmt = $pdo->query("SELECT doctor_id, first_name, last_name, specialization FROM doctors");
    return $stmt->fetchAll();
}

// ========== NEW: Handle Callback Queries (Button Clicks) ==========
if (isset($update['callback_query'])) {
    $callback = $update['callback_query'];
    $chat_id = $callback['message']['chat']['id'];
    $message_id = $callback['message']['message_id'];
    $telegram_user_id = $callback['from']['id'];
    $data = $callback['data'] ?? '';
    
    // Remove loading spinner
    answerCallback($callback['id'], $bot_token);
    
    // Check if user is linked
    $stmt = $pdo->prepare("SELECT patient_id, first_name, last_name, email, phone, created_at FROM patients WHERE telegram_user_id = ?");
    $stmt->execute([$telegram_user_id]);
    $patient = $stmt->fetch();
    
    if (!$patient) {
        editMessageText($chat_id, $message_id, "⚠️ Please type /start first to link your account.", $bot_token);
        exit();
    }
    
    // ========== BUTTON ROUTER - Triggers your existing commands ==========
    if ($data === 'cmd:appointments') {
        // Show appointments (same logic as /appointments)
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
        
    } elseif ($data === 'cmd:next') {
        // Show next appointment (same logic as /next)
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
        
    } elseif ($data === 'cmd:queue') {
        // Show queue (same logic as /queue)
        $stmt = $pdo->prepare("SELECT a.*, CONCAT(d.first_name, ' ', d.last_name) as doctor_name, (SELECT COUNT(*) FROM appointments WHERE appointment_date = a.appointment_date AND appointment_time < a.appointment_time AND status IN ('scheduled', 'confirmed') AND doctor_id = a.doctor_id) as people_ahead FROM appointments a JOIN doctors d ON a.doctor_id = d.doctor_id WHERE a.patient_id = ? AND a.appointment_date = CURDATE() AND a.status IN ('scheduled', 'confirmed') ORDER BY a.appointment_time ASC LIMIT 1");
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
        
    } elseif ($data === 'cmd:profile') {
        // Show profile (same logic as /profile)
        $memberDate = new DateTime($patient['created_at']);
        $response = "👤 *Your Profile*\n\n";
        $response .= "Name: {$patient['first_name']} {$patient['last_name']}\n";
        $response .= "Email: {$patient['email']}\n";
        $response .= "Phone: {$patient['phone']}\n";
        $response .= "Member since: " . $memberDate->format('F j, Y');
        $kb = createKeyboard([[['text' => '🏠 Back to Menu', 'callback_data' => 'cmd:home']]]);
        editMessageText($chat_id, $message_id, $response, $bot_token, $kb);
        
    } elseif ($data === 'cmd:askappointment') {
        // Start booking flow (same logic as /askappointment) - with doctor buttons
        $doctors = getDoctors($pdo);
        $response = "🏥 *Book a New Appointment*\n\n*Step 1: Select a doctor*:";
        
        $buttons = [];
        $row = [];
        foreach ($doctors as $doc) {
            $row[] = ['text' => "👨‍⚕️ {$doc['first_name']}", 'callback_data' => "doc:{$doc['doctor_id']}"];
            if (count($row) == 2) {
                $buttons[] = $row;
                $row = [];
            }
        }
        if (!empty($row)) $buttons[] = $row;
        $buttons[] = [['text' => '🏠 Cancel', 'callback_data' => 'cmd:home']];
        
        $pdo->prepare("REPLACE INTO telegram_sessions (telegram_user_id, step, data_json, updated_at) VALUES (?, 'booking_doctor', '{}', NOW())")->execute([$telegram_user_id]);
        
        editMessageText($chat_id, $message_id, $response, $bot_token, createKeyboard($buttons));
        
    } elseif ($data === 'cmd:help') {
        // Show help (same logic as /help)
        $response = "❓ *Available Commands*\n\n";
        $response .= "• `/appointments` - View all your appointments\n";
        $response .= "• `/next` - Show your next confirmed appointment\n";
        $response .= "• `/queue` - Check your queue position\n";
        $response .= "• `/profile` - View your profile information\n";
        $response .= "• `/askappointment` - Book a new appointment\n";
        $response .= "• `/help` - Show this help message\n\n";
        $response .= "📌 *Automatic Reminders*\n";
        $response .= "You will receive appointment reminders automatically at 7 AM on the day of your appointment.";
        $kb = createKeyboard([[['text' => '🏥 Book', 'callback_data' => 'cmd:askappointment'], ['text' => '📋 Appointments', 'callback_data' => 'cmd:appointments'], ['text' => '🎫 Queue', 'callback_data' => 'cmd:queue']]]);
        editMessageText($chat_id, $message_id, $response, $bot_token, $kb);
        
    } elseif ($data === 'cmd:home') {
        // ========== MAIN MENU BUTTONS ==========
        $response = "🏥 *Shifa Medical Center*\n\nHello, {$patient['first_name']}! 👋\n\nHow can we help you today?";
        $kb = createKeyboard([
            [['text' => '🏥 Book Appointment', 'callback_data' => 'cmd:askappointment'], ['text' => '📋 My Appointments', 'callback_data' => 'cmd:appointments']],
            [['text' => '📅 Next Visit', 'callback_data' => 'cmd:next'], ['text' => '🎫 Queue Status', 'callback_data' => 'cmd:queue']],
            [['text' => '👤 My Profile', 'callback_data' => 'cmd:profile'], ['text' => '❓ Help', 'callback_data' => 'cmd:help']]
        ]);
        editMessageText($chat_id, $message_id, $response, $bot_token, $kb);
        
    } elseif (strpos($data, 'doc:') === 0) {
        // Doctor selected via button
        $doctor_id = (int)str_replace('doc:', '', $data);
        $doctors = getDoctors($pdo);
        $selected = null;
        foreach ($doctors as $d) {
            if ($d['doctor_id'] == $doctor_id) {
                $selected = $d;
                break;
            }
        }
        
        if ($selected) {
            $pdo->prepare("UPDATE telegram_sessions SET step = 'booking_date', data_json = ? WHERE telegram_user_id = ?")
                ->execute([json_encode(['doctor_id' => $selected['doctor_id'], 'doctor_name' => $selected['first_name'] . ' ' . $selected['last_name']]), $telegram_user_id]);
            
            $response = "✅ *Doctor selected:* Dr. {$selected['first_name']} {$selected['last_name']}\n\n*Step 2: Select a date*\n\n📅 Tap a date below or type manually:";
            
            // Date buttons (next 7 days)
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
        }
        
    } elseif (strpos($data, 'date:') === 0) {
        // Date selected via button
        $dateVal = str_replace('date:', '', $data);
        
        if ($dateVal === 'more') {
            // Show more dates
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
            // Get session
            $session_stmt = $pdo->prepare("SELECT step, data_json FROM telegram_sessions WHERE telegram_user_id = ?");
            $session_stmt->execute([$telegram_user_id]);
            $session = $session_stmt->fetch();
            $data = json_decode($session['data_json'], true);
            
            $selected_date = $dateObj->format('Y-m-d');
            $display_date = $dateObj->format('l, F j, Y');
            
            $data['date'] = $selected_date;
            $data['display_date'] = $display_date;
            
            $availableSlots = getAvailableTimeSlots($pdo, $selected_date, $data['doctor_id']);
            
            if (empty($availableSlots)) {
                editMessageText($chat_id, $message_id, "❌ No slots on *{$display_date}*. Try another date:", $bot_token, createKeyboard([[['text' => '📅 Pick Another', 'callback_data' => 'date:more']], [['text' => '🔄 Cancel', 'callback_data' => 'cmd:home']]]));
                exit();
            }
            
            $data['available_slots'] = $availableSlots;
            $pdo->prepare("UPDATE telegram_sessions SET step = 'booking_time', data_json = ? WHERE telegram_user_id = ?")->execute([json_encode($data), $telegram_user_id]);
            
            $response = "📅 *{$display_date}*\n\n⏰ *Select a time slot*:";
            
            // Time slot buttons
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
        }
        
    } elseif (strpos($data, 'time:') === 0) {
        // Time selected via button
        $timeVal = str_replace('time:', '', $data);
        
        // Get session
        $session_stmt = $pdo->prepare("SELECT step, data_json FROM telegram_sessions WHERE telegram_user_id = ?");
        $session_stmt->execute([$telegram_user_id]);
        $session = $session_stmt->fetch();
        $data = json_decode($session['data_json'], true);
        $availableSlots = $data['available_slots'];
        
        $selected_slot = null;
        foreach ($availableSlots as $s) {
            if ($s['time'] === $timeVal) {
                $selected_slot = $s;
                break;
            }
        }
        
        if ($selected_slot) {
            // Double-check availability
            $check = $pdo->prepare("SELECT appointment_id FROM appointments WHERE appointment_date = ? AND appointment_time = ? AND doctor_id = ? AND status NOT IN ('cancelled')");
            $check->execute([$data['date'], $selected_slot['time'], $data['doctor_id']]);
            
            if ($check->rowCount() > 0) {
                editMessageText($chat_id, $message_id, "❌ Slot just taken! Restart with /askappointment", $bot_token);
                $pdo->prepare("DELETE FROM telegram_sessions WHERE telegram_user_id = ?")->execute([$telegram_user_id]);
                exit();
            }
            
            $data['time'] = $selected_slot['time'];
            $data['display_time'] = $selected_slot['display'];
            unset($data['available_slots']);
            
            $pdo->prepare("UPDATE telegram_sessions SET step = 'booking_confirm', data_json = ? WHERE telegram_user_id = ?")->execute([json_encode($data), $telegram_user_id]);
            
            $response = "⏰ *Time selected:* {$data['display_time']}\n\n*✅ Confirm Appointment*\n\n";
            $response .= "📋 *Details:*\n";
            $response .= "👨‍⚕️ Doctor: Dr. {$data['doctor_name']}\n";
            $response .= "📆 Date: {$data['display_date']}\n";
            $response .= "⏰ Time: {$data['display_time']}\n\n";
            $response .= "Tap below to confirm:";
            
            $kb = createKeyboard([
                [['text' => '✅ Confirm Booking', 'callback_data' => 'confirm:book'], ['text' => '❌ Cancel', 'callback_data' => 'cmd:home']],
                [['text' => '🔄 Change Time', 'callback_data' => 'booking:time'], ['text' => '📅 Change Date', 'callback_data' => 'booking:date']]
            ]);
            
            editMessageText($chat_id, $message_id, $response, $bot_token, $kb);
        }
        
    } elseif ($data === 'confirm:book') {
        // Confirm booking via button (same logic as text "confirm")
        $session_stmt = $pdo->prepare("SELECT step, data_json FROM telegram_sessions WHERE telegram_user_id = ?");
        $session_stmt->execute([$telegram_user_id]);
        $session = $session_stmt->fetch();
        $data = json_decode($session['data_json'], true);
        
        $check = $pdo->prepare("SELECT appointment_id FROM appointments WHERE appointment_date = ? AND appointment_time = ? AND doctor_id = ? AND status NOT IN ('cancelled')");
        $check->execute([$data['date'], $data['time'], $data['doctor_id']]);
        
        if ($check->rowCount() > 0) {
            editMessageText($chat_id, $message_id, "❌ Slot no longer available. Start over with /askappointment", $bot_token);
            $pdo->prepare("DELETE FROM telegram_sessions WHERE telegram_user_id = ?")->execute([$telegram_user_id]);
            exit();
        }
        
        $queue_stmt = $pdo->prepare("SELECT COUNT(*) + 1 as next_queue FROM appointments WHERE appointment_date = ? AND appointment_time < ?");
        $queue_stmt->execute([$data['date'], $data['time']]);
        $queueNum = $queue_stmt->fetchColumn();
        
        $insert = $pdo->prepare("INSERT INTO appointments (patient_id, doctor_id, appointment_date, appointment_time, queue_number, status, send_sms, created_at) VALUES (?, ?, ?, ?, ?, 'scheduled', 1, NOW())");
        $insert->execute([$patient['patient_id'], $data['doctor_id'], $data['date'], $data['time'], $queueNum]);
        
        $pdo->prepare("DELETE FROM telegram_sessions WHERE telegram_user_id = ?")->execute([$telegram_user_id]);
        
        $response = "✅ *Appointment Booked Successfully!*\n\n";
        $response .= "📋 *Appointment Details:*\n";
        $response .= "👨‍⚕️ Doctor: Dr. {$data['doctor_name']}\n";
        $response .= "📆 Date: {$data['display_date']}\n";
        $response .= "⏰ Time: {$data['display_time']}\n";
        $response .= "🎫 Queue Number: {$queueNum}\n\n";
        $response .= "📌 Please arrive 10 minutes before your appointment time.\n";
        $response .= "You will receive a reminder before your appointment.\n\n";
        $response .= "Use `/appointments` to view all your appointments or `/next` to see your next one.";
        
        $kb = createKeyboard([[['text' => '📋 My Appointments', 'callback_data' => 'cmd:appointments'], ['text' => '🏠 Main Menu', 'callback_data' => 'cmd:home']]]);
        editMessageText($chat_id, $message_id, $response, $bot_token, $kb);
        
    } elseif ($data === 'booking:cancel' || $data === 'cmd:home') {
        // Cancel booking
        $pdo->prepare("DELETE FROM telegram_sessions WHERE telegram_user_id = ?")->execute([$telegram_user_id]);
        $response = "❌ Booking cancelled. Tap below to start over:";
        $kb = createKeyboard([[['text' => '🏥 Book Appointment', 'callback_data' => 'cmd:askappointment'], ['text' => '🏠 Main Menu', 'callback_data' => 'cmd:home']]]);
        editMessageText($chat_id, $message_id, $response, $bot_token, $kb);
    }
    
    exit();
}

// ========== ORIGINAL MESSAGE HANDLER (UNCHANGED LOGIC) ==========
if (isset($update['message'])) {
    $message = $update['message'];
    $chat_id = $message['chat']['id'];
    $text = trim($message['text'] ?? '');
    $telegram_user_id = $message['from']['id'];
    
    // Check if user is already linked
    $stmt = $pdo->prepare("SELECT patient_id, first_name, last_name, email, phone, created_at FROM patients WHERE telegram_user_id = ?");
    $stmt->execute([$telegram_user_id]);
    $patient = $stmt->fetch();
    
    // Get session if exists
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
            
            // ========== ADD CLICKABLE /start BUTTON ==========
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
                $response .= "• `/askappointment` - Book an appointment\n";
                $response .= "• `/help` - Show all commands\n\n";
                $response .= "You will automatically receive appointment reminders.";
                
                // ========== ADD MAIN MENU BUTTONS AFTER LINKING ==========
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
    
    // ========== USER IS LINKED - YOUR ORIGINAL LOGIC (UNCHANGED) ==========
    
    // Check if in booking flow
    if ($session && $session['step'] == 'booking_doctor') {
        $data = json_decode($session['data_json'], true);
        
        if (is_numeric($text)) {
            $doctor_choice = (int)$text;
            $doctors = getDoctors($pdo);
            
            if ($doctor_choice >= 1 && $doctor_choice <= count($doctors)) {
                $selected_doctor = $doctors[$doctor_choice - 1];
                
                $pdo->prepare("UPDATE telegram_sessions SET step = 'booking_date', data_json = ? WHERE telegram_user_id = ?")
                    ->execute([json_encode(['doctor_id' => $selected_doctor['doctor_id'], 'doctor_name' => $selected_doctor['first_name'] . ' ' . $selected_doctor['last_name']]), $telegram_user_id]);
                
                $response = "✅ *Doctor selected:* Dr. {$selected_doctor['first_name']} {$selected_doctor['last_name']}\n\n";
                $response .= "*Step 2: Select a date*\n\n";
                $response .= "Please enter the appointment date in any of these formats:\n";
                $response .= "• `YYYY-MM-DD` (example: 2026-04-25)\n";
                $response .= "• `DD-MM-YYYY` (example: 25-04-2026)\n";
                $response .= "• `DD/MM/YYYY` (example: 25/04/2026)\n\n";
                $response .= "⚠️ Date must be at least tomorrow.";
                sendMessage($chat_id, $response, $bot_token);
            } else {
                sendMessage($chat_id, "❌ Invalid choice. Please enter a number from the list.", $bot_token);
            }
        } else {
            sendMessage($chat_id, "❌ Please enter the number of your chosen doctor.", $bot_token);
        }
        exit();
    }
    
    if ($session && $session['step'] == 'booking_date') {
        $data = json_decode($session['data_json'], true);
        $dateObj = parseDateInput($text);
        $tomorrow = new DateTime('tomorrow');
        
        if ($dateObj && $dateObj >= $tomorrow) {
            $selected_date = $dateObj->format('Y-m-d');
            $display_date = $dateObj->format('l, F j, Y');
            
            $data['date'] = $selected_date;
            $data['display_date'] = $display_date;
            
            $availableSlots = getAvailableTimeSlots($pdo, $selected_date, $data['doctor_id']);
            
            if (empty($availableSlots)) {
                sendMessage($chat_id, "❌ No available slots on " . $display_date . ". Please choose another date.\n\nType a new date or 'cancel' to cancel.", $bot_token);
                $pdo->prepare("UPDATE telegram_sessions SET step = 'booking_date', data_json = ? WHERE telegram_user_id = ?")->execute([json_encode($data), $telegram_user_id]);
                exit();
            }
            
            $data['available_slots'] = $availableSlots;
            $pdo->prepare("UPDATE telegram_sessions SET step = 'booking_time', data_json = ? WHERE telegram_user_id = ?")->execute([json_encode($data), $telegram_user_id]);
            
            $response = "📅 *Date selected:* {$display_date}\n\n";
            $response .= "*Step 3: Select a time*\n\n";
            $response .= "Available time slots:\n";
            
            $slot_num = 1;
            foreach ($availableSlots as $slot) {
                $response .= "{$slot_num}. {$slot['display']}\n";
                $slot_num++;
            }
            $response .= "\nPlease enter the number of your preferred time slot.";
            
            sendMessage($chat_id, $response, $bot_token);
        } 
        elseif ($text == 'cancel') {
            $pdo->prepare("DELETE FROM telegram_sessions WHERE telegram_user_id = ?")->execute([$telegram_user_id]);
            sendMessage($chat_id, "❌ Booking cancelled. Type /askappointment to start over.", $bot_token);
        }
        else {
            sendMessage($chat_id, "❌ Invalid date format or date is in the past.\n\nPlease use:\n• `YYYY-MM-DD`\n• `DD-MM-YYYY`\n• `DD/MM/YYYY`\n\nDate must be at least tomorrow.", $bot_token);
        }
        exit();
    }
    
    if ($session && $session['step'] == 'booking_time') {
        $data = json_decode($session['data_json'], true);
        $availableSlots = $data['available_slots'];
        
        if (is_numeric($text) && $text >= 1 && $text <= count($availableSlots)) {
            $selected_slot = $availableSlots[$text - 1];
            
            $check = $pdo->prepare("SELECT appointment_id FROM appointments WHERE appointment_date = ? AND appointment_time = ? AND doctor_id = ? AND status NOT IN ('cancelled')");
            $check->execute([$data['date'], $selected_slot['time'], $data['doctor_id']]);
            
            if ($check->rowCount() > 0) {
                sendMessage($chat_id, "❌ Sorry, that time slot was just taken. Please restart booking with /askappointment.", $bot_token);
                $pdo->prepare("DELETE FROM telegram_sessions WHERE telegram_user_id = ?")->execute([$telegram_user_id]);
                exit();
            }
            
            $data['time'] = $selected_slot['time'];
            $data['display_time'] = $selected_slot['display'];
            unset($data['available_slots']);
            
            $pdo->prepare("UPDATE telegram_sessions SET step = 'booking_confirm', data_json = ? WHERE telegram_user_id = ?")->execute([json_encode($data), $telegram_user_id]);
            
            $response = "⏰ *Time selected:* {$data['display_time']}\n\n";
            $response .= "*Step 4: Confirm your appointment*\n\n";
            $response .= "📋 *Appointment Details:*\n";
            $response .= "👨‍⚕️ Doctor: Dr. {$data['doctor_name']}\n";
            $response .= "📆 Date: {$data['display_date']}\n";
            $response .= "⏰ Time: {$data['display_time']}\n\n";
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
        $data = json_decode($session['data_json'], true);
        
        if (strtolower($text) == 'confirm') {
            $check = $pdo->prepare("SELECT appointment_id FROM appointments WHERE appointment_date = ? AND appointment_time = ? AND doctor_id = ? AND status NOT IN ('cancelled')");
            $check->execute([$data['date'], $data['time'], $data['doctor_id']]);
            
            if ($check->rowCount() > 0) {
                sendMessage($chat_id, "❌ Sorry, this slot is no longer available. Please start over with /askappointment.", $bot_token);
                $pdo->prepare("DELETE FROM telegram_sessions WHERE telegram_user_id = ?")->execute([$telegram_user_id]);
                exit();
            }
            
            $queue_stmt = $pdo->prepare("SELECT COUNT(*) + 1 as next_queue FROM appointments WHERE appointment_date = ? AND appointment_time < ?");
            $queue_stmt->execute([$data['date'], $data['time']]);
            $queueNum = $queue_stmt->fetchColumn();
            
            $insert = $pdo->prepare("INSERT INTO appointments (patient_id, doctor_id, appointment_date, appointment_time, queue_number, status, send_sms, created_at) VALUES (?, ?, ?, ?, ?, 'scheduled', 1, NOW())");
            $insert->execute([$patient['patient_id'], $data['doctor_id'], $data['date'], $data['time'], $queueNum]);
            
            $pdo->prepare("DELETE FROM telegram_sessions WHERE telegram_user_id = ?")->execute([$telegram_user_id]);
            
            $response = "✅ *Appointment Booked Successfully!*\n\n";
            $response .= "📋 *Appointment Details:*\n";
            $response .= "👨‍⚕️ Doctor: Dr. {$data['doctor_name']}\n";
            $response .= "📆 Date: {$data['display_date']}\n";
            $response .= "⏰ Time: {$data['display_time']}\n";
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
                $data['date'] = $newDate->format('Y-m-d');
                $data['display_date'] = $newDate->format('l, F j, Y');
                unset($data['time'], $data['display_time']);
                
                $availableSlots = getAvailableTimeSlots($pdo, $data['date'], $data['doctor_id']);
                
                if (empty($availableSlots)) {
                    sendMessage($chat_id, "❌ No available slots on {$data['display_date']}. Please try another date or type 'cancel'.", $bot_token);
                    exit();
                }
                
                $data['available_slots'] = $availableSlots;
                $pdo->prepare("UPDATE telegram_sessions SET step = 'booking_time', data_json = ? WHERE telegram_user_id = ?")->execute([json_encode($data), $telegram_user_id]);
                
                $response = "🔄 *Date changed to:* {$data['display_date']}\n\n";
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
    
    // ========== REGULAR COMMANDS - ADD BUTTONS ==========
    
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
        // ========== ADD BUTTONS ==========
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
        // ========== ADD BUTTONS ==========
        $kb = createKeyboard([[['text' => '🏥 Book', 'callback_data' => 'cmd:askappointment'], ['text' => '📋 All', 'callback_data' => 'cmd:appointments']]]);
        sendMessage($chat_id, $response, $bot_token, $kb);
        
    } elseif ($text == '/queue') {
        $stmt = $pdo->prepare("SELECT a.*, CONCAT(d.first_name, ' ', d.last_name) as doctor_name, (SELECT COUNT(*) FROM appointments WHERE appointment_date = a.appointment_date AND appointment_time < a.appointment_time AND status IN ('scheduled', 'confirmed') AND doctor_id = a.doctor_id) as people_ahead, (SELECT COUNT(*) FROM appointments WHERE appointment_date = a.appointment_date AND status IN ('scheduled', 'confirmed') AND doctor_id = a.doctor_id) as total_waiting FROM appointments a JOIN doctors d ON a.doctor_id = d.doctor_id WHERE a.patient_id = ? AND a.appointment_date = CURDATE() AND a.status IN ('scheduled', 'confirmed') ORDER BY a.appointment_time ASC LIMIT 1");
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
        // ========== ADD BUTTONS ==========
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
        // ========== ADD BUTTONS ==========
        $kb = createKeyboard([[['text' => '🏠 Back to Menu', 'callback_data' => 'cmd:home']]]);
        sendMessage($chat_id, $response, $bot_token, $kb);
        
    } elseif ($text == '/askappointment') {
        $doctors = getDoctors($pdo);
        
        $response = "🏥 *Book a New Appointment*\n\n";
        $response .= "*Step 1: Select a doctor*\n\n";
        
        // ========== ADD DOCTOR BUTTONS ==========
        $buttons = [];
        $row = [];
        foreach ($doctors as $doctor) {
            $row[] = ['text' => "👨‍⚕️ {$doctor['first_name']}", 'callback_data' => "doc:{$doctor['doctor_id']}"];
            if (count($row) == 2) {
                $buttons[] = $row;
                $row = [];
            }
        }
        if (!empty($row)) $buttons[] = $row;
        $buttons[] = [['text' => '🏠 Cancel', 'callback_data' => 'cmd:home']];
        
        $response .= "\n*Or tap a doctor below:*";
        
        $pdo->prepare("REPLACE INTO telegram_sessions (telegram_user_id, step, data_json, updated_at) VALUES (?, 'booking_doctor', '{}', NOW())")->execute([$telegram_user_id]);
        
        sendMessage($chat_id, $response, $bot_token, createKeyboard($buttons));
        
    } elseif ($text == '/help') {
        $response = "❓ *Available Commands*\n\n";
        $response .= "• `/appointments` - View all your appointments\n";
        $response .= "• `/next` - Show your next confirmed appointment\n";
        $response .= "• `/queue` - Check your queue position\n";
        $response .= "• `/profile` - View your profile information\n";
        $response .= "• `/askappointment` - Book a new appointment\n";
        $response .= "• `/help` - Show this help message\n\n";
        $response .= "📌 *Automatic Reminders*\n";
        $response .= "You will receive appointment reminders automatically at 7 AM on the day of your appointment.\n\n";
        $response .= "For urgent matters, please call the clinic directly.";
        // ========== ADD BUTTONS ==========
        $kb = createKeyboard([[['text' => '🏥 Book', 'callback_data' => 'cmd:askappointment'], ['text' => '📋 Appointments', 'callback_data' => 'cmd:appointments'], ['text' => '🎫 Queue', 'callback_data' => 'cmd:queue']]]);
        sendMessage($chat_id, $response, $bot_token, $kb);
        
    } elseif (strpos($text, '/') === 0) {
        // ========== ADD BUTTONS FOR UNKNOWN COMMAND ==========
        $kb = createKeyboard([[['text' => '🏠 Main Menu', 'callback_data' => 'cmd:home'], ['text' => '❓ Help', 'callback_data' => 'cmd:help']]]);
        sendMessage($chat_id, "🤖 *Unknown command*\n\nUse the menu below:", $bot_token, $kb);
    }
}
?>
