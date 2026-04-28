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

function sendMessage($chat_id, $message, $bot_token) {
    $url = "https://api.telegram.org/bot{$bot_token}/sendMessage";
    $data = [
        'chat_id' => $chat_id,
        'text' => $message,
        'parse_mode' => 'Markdown'
    ];
    
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
            sendMessage($chat_id, $response, $bot_token);
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
                sendMessage($chat_id, $response, $bot_token);
            } else {
                sendMessage($chat_id, "❌ *Account not found*\n\nNo patient found with: `{$input}`\n\nType /start to try again.", $bot_token);
                $pdo->prepare("DELETE FROM telegram_sessions WHERE telegram_user_id = ?")->execute([$telegram_user_id]);
            }
        }
        else {
            sendMessage($chat_id, "👋 *Welcome!*\n\nType /start to link your account.", $bot_token);
        }
        exit();
    }
    
    // ========== USER IS LINKED ==========
    
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
    
    // ========== REGULAR COMMANDS ==========
    
    if ($text == '/appointments') {
        $stmt = $pdo->prepare("
            SELECT a.*, CONCAT(d.first_name, ' ', d.last_name) as doctor_name
            FROM appointments a
            JOIN doctors d ON a.doctor_id = d.doctor_id
            WHERE a.patient_id = ?
            ORDER BY a.appointment_date DESC, a.appointment_time DESC
        ");
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
            sendMessage($chat_id, $response, $bot_token);
        } else {
            sendMessage($chat_id, "📭 *No appointments found*\n\nUse `/askappointment` to book your first appointment.", $bot_token);
        }
    }
    
    elseif ($text == '/next') {
        $stmt = $pdo->prepare("
            SELECT a.*, CONCAT(d.first_name, ' ', d.last_name) as doctor_name
            FROM appointments a
            JOIN doctors d ON a.doctor_id = d.doctor_id
            WHERE a.patient_id = ? 
            AND a.appointment_date >= CURDATE()
            AND a.status = 'confirmed'
            ORDER BY a.appointment_date ASC, a.appointment_time ASC
            LIMIT 1
        ");
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
            sendMessage($chat_id, $response, $bot_token);
        } else {
            sendMessage($chat_id, "❌ *No confirmed appointments found*\n\nYour appointments may be pending confirmation by the nurse, or you have no upcoming appointments.\n\nUse `/appointments` to check your requests.", $bot_token);
        }
    }
    
    elseif ($text == '/queue') {
        $stmt = $pdo->prepare("
            SELECT a.*, CONCAT(d.first_name, ' ', d.last_name) as doctor_name,
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
            ORDER BY a.appointment_time ASC
            LIMIT 1
        ");
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
            sendMessage($chat_id, $response, $bot_token);
        } else {
            sendMessage($chat_id, "🎫 *No Active Queue*\n\nYou don't have any confirmed appointments scheduled for today.\n\nCheck `/appointments` to see pending requests.\nSend `/next` to see your next confirmed appointment.", $bot_token);
        }
    }
    
    elseif ($text == '/profile') {
        $memberDate = new DateTime($patient['created_at']);
        $response = "👤 *Your Profile*\n\n";
        $response .= "Name: {$patient['first_name']} {$patient['last_name']}\n";
        $response .= "Email: {$patient['email']}\n";
        $response .= "Phone: {$patient['phone']}\n";
        $response .= "Member since: " . $memberDate->format('F j, Y') . "\n\n";
        $response .= "To update your profile, please visit our website.";
        sendMessage($chat_id, $response, $bot_token);
    }
    
    elseif ($text == '/askappointment') {
        $doctors = getDoctors($pdo);
        
        $response = "🏥 *Book a New Appointment*\n\n";
        $response .= "*Step 1: Select a doctor*\n\n";
        
        $i = 1;
        foreach ($doctors as $doctor) {
            $response .= "{$i}. Dr. {$doctor['first_name']} {$doctor['last_name']} - {$doctor['specialization']}\n";
            $i++;
        }
        $response .= "\nPlease enter the number of your choice.";
        
        $pdo->prepare("REPLACE INTO telegram_sessions (telegram_user_id, step, data_json, updated_at) VALUES (?, 'booking_doctor', '{}', NOW())")->execute([$telegram_user_id]);
        
        sendMessage($chat_id, $response, $bot_token);
    }
    
    elseif ($text == '/help') {
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
        sendMessage($chat_id, $response, $bot_token);
    }
    
    elseif (strpos($text, '/') === 0) {
        sendMessage($chat_id, "🤖 *Unknown command*\n\nType `/help` to see available commands.", $bot_token);
    }
}
?>
