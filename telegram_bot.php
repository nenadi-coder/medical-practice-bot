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

// ========== FILE-BASED STORAGE (No sessions) ==========

$booking_storage_dir = '/tmp/booking_data';
if (!file_exists($booking_storage_dir)) {
    mkdir($booking_storage_dir, 0777, true);
}

function getBookingStep($chat_id) {
    global $booking_storage_dir;
    $file = $booking_storage_dir . '/' . $chat_id . '.json';
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true);
        return $data['step'] ?? null;
    }
    return null;
}

function getBookingData($chat_id) {
    global $booking_storage_dir;
    $file = $booking_storage_dir . '/' . $chat_id . '.json';
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true);
        return $data['data'] ?? [];
    }
    return [];
}

function setBookingStep($chat_id, $step) {
    global $booking_storage_dir;
    $file = $booking_storage_dir . '/' . $chat_id . '.json';
    $data = [
        'step' => $step,
        'data' => getBookingData($chat_id)
    ];
    file_put_contents($file, json_encode($data));
}

function setBookingData($chat_id, $data) {
    global $booking_storage_dir;
    $file = $booking_storage_dir . '/' . $chat_id . '.json';
    $current_step = getBookingStep($chat_id);
    $full_data = [
        'step' => $current_step,
        'data' => $data
    ];
    file_put_contents($file, json_encode($full_data));
}

function updateBookingData($chat_id, $key, $value) {
    $data = getBookingData($chat_id);
    $data[$key] = $value;
    setBookingData($chat_id, $data);
}

function resetUserBookingSession($chat_id) {
    global $booking_storage_dir;
    $file = $booking_storage_dir . '/' . $chat_id . '.json';
    if (file_exists($file)) {
        unlink($file);
    }
}

// Check if user is already linked and update telegram_chat_id
$stmt = $pdo->prepare("SELECT * FROM patients WHERE telegram_chat_id = ? OR telegram_user_id = ? OR email = ?");
$stmt->execute([$chat_id, $chat_id, $text]);
$patient = $stmt->fetch();

// If patient found but telegram_chat_id is empty, update it
if ($patient && empty($patient['telegram_chat_id'])) {
    $update_stmt = $pdo->prepare("UPDATE patients SET telegram_chat_id = ?, telegram_user_id = ?, telegram_linked_at = NOW() WHERE patient_id = ?");
    $update_stmt->execute([$chat_id, $chat_id, $patient['patient_id']]);
    // Refresh patient data
    $stmt = $pdo->prepare("SELECT * FROM patients WHERE patient_id = ?");
    $stmt->execute([$patient['patient_id']]);
    $patient = $stmt->fetch();
}

// ========== COMMAND HANDLERS ==========

// /start - Welcome message
if ($text === '/start') {
    if ($patient) {
        $response = "👋 *Welcome back, {$patient['first_name']}!*\n\n";
        $response .= "Your account is already linked. Here's what you can do:\n\n";
        $response .= "📋 *Commands:*\n";
        $response .= "🔹 /appointments - View your appointments\n";
        $response .= "🔹 /next - Your next upcoming appointment\n";
        $response .= "🔹 /queue - Check queue position\n";
        $response .= "🔹 /profile - View your profile\n";
        $response .= "🔹 /askappointment - Book a new appointment\n";
        $response .= "🔹 /check - Test database connection\n";
        $response .= "🔹 /help - Show all commands\n\n";
        $response .= "_Use /askappointment to book a new appointment._";
    } else {
        $response = "👋 *Welcome to Shifa Medical Center, $first_name!*\n\n";
        $response .= "I'm your health assistant. To get started:\n\n";
        $response .= "1️⃣ *Login to your patient portal*\n";
        $response .= "2️⃣ *Click 'Telegram Bot'*\n";
        $response .= "3️⃣ *Your account will be automatically linked*\n\n";
        $response .= "🔗 Portal: https://shifacenter.me/patient/dashboard.php\n\n";
        $response .= "*After linking, you can use these commands:*\n";
        $response .= "• /appointments - View your appointments\n";
        $response .= "• /next - Your next upcoming appointment\n";
        $response .= "• /queue - Check your queue position\n";
        $response .= "• /profile - View your profile\n";
        $response .= "• /askappointment - Book a new appointment\n";
        $response .= "• /check - Test database connection\n";
        $response .= "• /help - Show all commands\n\n";
        $response .= "_Use /askappointment to book a new appointment._";
    }
    
    resetUserBookingSession($chat_id);
    sendMessage($chat_id, $response, $bot_token);
    exit();
}

// /help - Show all commands
if ($text === '/help') {
    $response = "🤖 *Available Commands:*\n\n";
    $response .= "*/start* - Welcome message\n";
    $response .= "*/appointments* - View all your appointments\n";
    $response .= "*/next* - Show your next upcoming appointment\n";
    $response .= "*/queue* - Check your queue position\n";
    $response .= "*/profile* - View your profile information\n";
    $response .= "*/askappointment* - Book a new appointment\n";
    $response .= "*/check* - Test database connection\n";
    $response .= "*/help* - Show this message\n\n";
    $response .= "*Need help?* Visit our website: https://shifacenter.me";
    
    sendMessage($chat_id, $response, $bot_token);
    exit();
}

// /askappointment - Start appointment booking process
if ($text === '/askappointment') {
    if (!$patient) {
        $response = "❌ *Account Not Linked*\n\n";
        $response .= "Please login to our website and click 'Link Telegram Account' first.\n\n";
        $response .= "Portal: https://shifacenter.me/patient/dashboard.php";
        sendMessage($chat_id, $response, $bot_token);
        exit();
    }
    
    resetUserBookingSession($chat_id);
    setBookingStep($chat_id, 'select_doctor');
    setBookingData($chat_id, ['patient_id' => $patient['patient_id']]);
    
    $doctors_stmt = $pdo->prepare("SELECT doctor_id, first_name, last_name, specialization FROM doctors ORDER BY first_name");
    $doctors_stmt->execute();
    $doctors = $doctors_stmt->fetchAll();
    
    if (count($doctors) == 0) {
        $response = "❌ *No doctors available*\n\n";
        $response .= "Please try again later or contact the clinic directly.";
        sendMessage($chat_id, $response, $bot_token);
        resetUserBookingSession($chat_id);
        exit();
    }
    
    $response = "🏥 *Book a New Appointment*\n\n";
    $response .= "Step 1: Select a doctor\n\n";
    
    foreach ($doctors as $index => $doctor) {
        $response .= ($index + 1) . ". Dr. {$doctor['first_name']} {$doctor['last_name']} - {$doctor['specialization']}\n";
    }
    
    $response .= "\n_Please enter the number of your choice._";
    
    sendMessage($chat_id, $response, $bot_token);
    exit();
}

// ========== BOOKING CONVERSATION HANDLER ==========
$current_step = getBookingStep($chat_id);

if ($current_step !== null && $current_step !== '' && $text !== '/start' && $text !== '/help' && $text !== '/askappointment' && $text !== '/check') {
    handleBookingConversation($chat_id, $text, $pdo, $bot_token);
    exit();
}

// /check - Simple test to see if database is working
if ($text === '/check') {
    if (!$patient) {
        sendMessage($chat_id, "❌ Account not linked. Send /start first.", $bot_token);
        exit();
    }
    
    try {
        $test_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM appointments WHERE patient_id = ?");
        $test_stmt->execute([$patient['patient_id']]);
        $count = $test_stmt->fetchColumn();
        
        $response = "✅ *Database Connection: WORKING*\n\n";
        $response .= "📊 You have $count appointment(s) in the database.\n\n";
        
        $apt_stmt = $pdo->prepare("
            SELECT appointment_id, appointment_date, appointment_time, status 
            FROM appointments 
            WHERE patient_id = ? 
            ORDER BY appointment_id DESC 
            LIMIT 5
        ");
        $apt_stmt->execute([$patient['patient_id']]);
        $appointments = $apt_stmt->fetchAll();
        
        if (count($appointments) > 0) {
            $response .= "*Recent appointments:*\n";
            foreach ($appointments as $apt) {
                $response .= "• ID: {$apt['appointment_id']} | {$apt['appointment_date']} {$apt['appointment_time']} | Status: {$apt['status']}\n";
            }
        } else {
            $response .= "No appointments found. Try booking with /askappointment";
        }
        
        sendMessage($chat_id, $response, $bot_token);
    } catch (Exception $e) {
        sendMessage($chat_id, "❌ *Database ERROR:* " . $e->getMessage(), $bot_token);
    }
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

// /next - Show next upcoming appointment
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
        WHERE a.patient_id = ? 
        AND a.status IN ('scheduled', 'confirmed')
        AND (
            a.appointment_date > CURDATE() 
            OR (a.appointment_date = CURDATE() AND a.appointment_time > CURTIME())
        )
        ORDER BY a.appointment_date ASC, a.appointment_time ASC
        LIMIT 1
    ");
    $apt_stmt->execute([$patient['patient_id']]);
    $appointment = $apt_stmt->fetch();
    
    if ($appointment) {
        $status_display = ($appointment['status'] == 'confirmed') ? '✅ Confirmed' : '⏳ Pending';
        
        $response = "📅 *Your Next Appointment*\n\n";
        $response .= "📆 Date: " . date('l, F j, Y', strtotime($appointment['appointment_date'])) . "\n";
        $response .= "⏰ Time: " . date('g:i A', strtotime($appointment['appointment_time'])) . "\n";
        $response .= "👨‍⚕️ Doctor: Dr. {$appointment['doctor_name']}\n";
        $response .= "🎫 Queue #: {$appointment['queue_number']}\n";
        $response .= "📌 Status: {$status_display}\n\n";
        
        if ($appointment['status'] == 'scheduled') {
            $response .= "_⚠️ This appointment is pending nurse confirmation._\n";
        } else {
            $response .= "_Please arrive 10 minutes early!_";
        }
    } else {
        $response = "📅 *No Upcoming Appointments*\n\n";
        $response .= "You have no upcoming appointments scheduled.\n\n";
        $response .= "Book one using /askappointment";
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
        $response .= "_⏳ = Pending | ✅ = Confirmed | ✔️ = Completed | ❌ = Cancelled_\n\n";
        
        foreach ($appointments as $apt) {
            $status_emoji = match($apt['status']) {
                'scheduled' => '⏳',
                'confirmed' => '✅',
                'completed' => '✔️',
                'cancelled' => '❌',
                default => '📌'
            };
            $response .= "{$status_emoji} *" . date('M j, Y', strtotime($apt['appointment_date'])) . "* - " . date('g:i A', strtotime($apt['appointment_time'])) . "\n";
            $response .= "   Dr. {$apt['doctor_name']} | Queue #{$apt['queue_number']}\n\n";
        }
        $response .= "_To book a new appointment, use /askappointment_";
    } else {
        $response = "📋 *No Appointments Found*\n\n";
        $response .= "You don't have any appointments yet.\n\n";
        $response .= "Book one using /askappointment";
    }
    
    sendMessage($chat_id, $response, $bot_token);
    exit();
}

// /queue - Check queue position (ONLY confirmed appointments)
if ($text === '/queue') {
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
        WHERE a.patient_id = ? AND a.appointment_date = CURDATE() 
        AND a.appointment_time > CURTIME()
        AND a.status = 'confirmed'
        ORDER BY a.appointment_time ASC
        LIMIT 1
    ");
    $apt_stmt->execute([$patient['patient_id']]);
    $appointment = $apt_stmt->fetch();
    
    if ($appointment) {
        $queue_stmt = $pdo->prepare("
            SELECT COUNT(*) as ahead FROM appointments 
            WHERE appointment_date = CURDATE() 
            AND queue_number < ? 
            AND appointment_time > CURTIME()
            AND status = 'confirmed'
        ");
        $queue_stmt->execute([$appointment['queue_number']]);
        $ahead = $queue_stmt->fetchColumn();
        
        $total_stmt = $pdo->prepare("
            SELECT COUNT(*) as total FROM appointments 
            WHERE appointment_date = CURDATE() 
            AND appointment_time > CURTIME()
            AND status = 'confirmed'
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
        }
    } else {
        $response = "🎫 *No Active Queue*\n\n";
        $response .= "You don't have any confirmed appointments scheduled for today.\n\n";
        $response .= "Send /next to see your next appointment.";
    }
    
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
    @file_get_contents($url, false, $context);
}

function handleBookingConversation($chat_id, $text, $pdo, $bot_token) {
    $current_step = getBookingStep($chat_id);
    $booking_data = getBookingData($chat_id);
    
    switch ($current_step) {
        case 'select_doctor':
            if (is_numeric($text)) {
                $doctors_stmt = $pdo->prepare("SELECT doctor_id, first_name, last_name, specialization FROM doctors");
                $doctors_stmt->execute();
                $doctors = $doctors_stmt->fetchAll();
                
                $index = (int)$text - 1;
                if (isset($doctors[$index])) {
                    updateBookingData($chat_id, 'doctor_id', $doctors[$index]['doctor_id']);
                    updateBookingData($chat_id, 'doctor_name', $doctors[$index]['first_name'] . ' ' . $doctors[$index]['last_name']);
                    setBookingStep($chat_id, 'select_date');
                    
                    $response = "✅ Doctor selected: Dr. " . $doctors[$index]['first_name'] . ' ' . $doctors[$index]['last_name'] . "\n\n";
                    $response .= "Step 2: Select a date\n\n";
                    $response .= "Please enter the appointment date in any of these formats:\n";
                    $response .= "• YYYY-MM-DD (example: 2026-04-25)\n";
                    $response .= "• DD-MM-YYYY (example: 25-04-2026)\n";
                    $response .= "• DD/MM/YYYY (example: 25/04/2026)\n\n";
                    $response .= "_Note: Date must be at least tomorrow._";
                    sendMessage($chat_id, $response, $bot_token);
                } else {
                    sendMessage($chat_id, "❌ Invalid selection. Please enter a valid number from the list.", $bot_token);
                }
            } else {
                sendMessage($chat_id, "❌ Please enter the number of the doctor you want to select.", $bot_token);
            }
            break;
            
        case 'select_date':
            // Convert common date formats to YYYY-MM-DD
            if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $text)) {
                $parts = explode('-', $text);
                $text = $parts[2] . '-' . $parts[1] . '-' . $parts[0];
            }
            elseif (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $text)) {
                $parts = explode('/', $text);
                $text = $parts[2] . '-' . $parts[1] . '-' . $parts[0];
            }
            elseif (preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $text)) {
                $parts = explode('.', $text);
                $text = $parts[2] . '-' . $parts[1] . '-' . $parts[0];
            }
            
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text)) {
                $selected_date = $text;
                $min_date = (new DateTime())->modify('+1 day');
                
                if ($selected_date >= $min_date->format('Y-m-d')) {
                    updateBookingData($chat_id, 'appointment_date', $selected_date);
                    setBookingStep($chat_id, 'select_time');
                    
                    $response = "📅 Date selected: " . date('l, F j, Y', strtotime($selected_date)) . "\n\n";
                    $response .= "Step 3: Select a time\n\n";
                    $response .= "Available time slots:\n";
                    
                    $time_slots = [
                        '08:00:00', '08:30:00', '09:00:00', '09:30:00', 
                        '10:00:00', '10:30:00', '11:00:00', '11:30:00',
                        '13:00:00', '13:30:00', '14:00:00', '14:30:00', 
                        '15:00:00', '15:30:00', '16:00:00', '16:30:00'
                    ];
                    
                    $available_slots = [];
                    $booking_data = getBookingData($chat_id);
                    $current_time = date('H:i:s');
                    
                    foreach ($time_slots as $slot) {
                        if ($selected_date == date('Y-m-d') && $slot <= $current_time) {
                            continue;
                        }
                        
                        $check_stmt = $pdo->prepare("
                            SELECT COUNT(*) FROM appointments 
                            WHERE doctor_id = ? AND appointment_date = ? AND appointment_time = ?
                            AND status IN ('scheduled', 'confirmed')
                        ");
                        $check_stmt->execute([$booking_data['doctor_id'], $selected_date, $slot]);
                        $count = $check_stmt->fetchColumn();
                        
                        if ($count == 0) {
                            $available_slots[] = $slot;
                        }
                    }
                    
                    if (count($available_slots) > 0) {
                        for ($i = 0; $i < count($available_slots); $i++) {
                            $response .= ($i + 1) . ". " . date('g:i A', strtotime($available_slots[$i])) . "\n";
                        }
                        $response .= "\nPlease enter the number of your preferred time slot.";
                        updateBookingData($chat_id, 'available_slots', $available_slots);
                    } else {
                        $response = "❌ No available time slots for this date.\n\n";
                        $response .= "Please select another date in any format (YYYY-MM-DD, DD-MM-YYYY, or DD/MM/YYYY):";
                        setBookingStep($chat_id, 'select_date');
                    }
                    
                    sendMessage($chat_id, $response, $bot_token);
                } else {
                    sendMessage($chat_id, "❌ Please enter a future date (tomorrow or later).\nExamples: 2026-04-25 or 25-04-2026", $bot_token);
                }
            } else {
                sendMessage($chat_id, "❌ Invalid date format.\nPlease use: YYYY-MM-DD, DD-MM-YYYY, or DD/MM/YYYY\nExample: 25-04-2026", $bot_token);
            }
            break;
            
        case 'select_time':
            if (is_numeric($text)) {
                $booking_data = getBookingData($chat_id);
                if (isset($booking_data['available_slots'])) {
                    $index = (int)$text - 1;
                    if (isset($booking_data['available_slots'][$index])) {
                        $selected_time = $booking_data['available_slots'][$index];
                        
                        updateBookingData($chat_id, 'appointment_time', $selected_time);
                        setBookingStep($chat_id, 'confirm');
                        
                        $booking_data = getBookingData($chat_id);
                        $response = "⏰ Time selected: " . date('g:i A', strtotime($selected_time)) . "\n\n";
                        $response .= "Step 4: Confirm your appointment\n\n";
                        $response .= "📋 *Appointment Details:*\n";
                        $response .= "👨‍⚕️ Doctor: Dr. {$booking_data['doctor_name']}\n";
                        $response .= "📆 Date: " . date('l, F j, Y', strtotime($booking_data['appointment_date'])) . "\n";
                        $response .= "⏰ Time: " . date('g:i A', strtotime($booking_data['appointment_time'])) . "\n\n";
                        $response .= "✅ Type 'confirm' to submit this appointment request\n";
                        $response .= "❌ Type 'cancel' to cancel\n";
                        $response .= "🔄 Type a new date (DD-MM-YYYY) to change the date\n\n";
                        $response .= "_Note: Your appointment will be pending nurse confirmation._";
                        
                        sendMessage($chat_id, $response, $bot_token);
                    } else {
                        sendMessage($chat_id, "❌ Invalid selection. Please enter a valid number from the list.", $bot_token);
                    }
                } else {
                    sendMessage($chat_id, "❌ No time slots available. Please start over with /askappointment", $bot_token);
                    resetUserBookingSession($chat_id);
                }
            } else {
                sendMessage($chat_id, "❌ Please enter the number of your preferred time slot.", $bot_token);
            }
            break;
            
        case 'confirm':
            $booking_data = getBookingData($chat_id);
            
            if (strtolower($text) === 'confirm') {
                $queue_stmt = $pdo->prepare("
                    SELECT COALESCE(MAX(queue_number), 0) + 1 as next_queue 
                    FROM appointments 
                    WHERE appointment_date = ? AND doctor_id = ?
                ");
                $queue_stmt->execute([$booking_data['appointment_date'], $booking_data['doctor_id']]);
                $queue_number = $queue_stmt->fetchColumn();
                
                $insert_stmt = $pdo->prepare("
                    INSERT INTO appointments (patient_id, doctor_id, appointment_date, appointment_time, queue_number, status, created_at)
                    VALUES (?, ?, ?, ?, ?, 'scheduled', NOW())
                ");
                
                $result = $insert_stmt->execute([
                    $booking_data['patient_id'],
                    $booking_data['doctor_id'],
                    $booking_data['appointment_date'],
                    $booking_data['appointment_time'],
                    $queue_number
                ]);
                
                if ($result) {
                    $response = "✅ *Appointment Request Submitted!*\n\n";
                    $response .= "📋 *Appointment Details:*\n";
                    $response .= "👨‍⚕️ Doctor: Dr. {$booking_data['doctor_name']}\n";
                    $response .= "📆 Date: " . date('l, F j, Y', strtotime($booking_data['appointment_date'])) . "\n";
                    $response .= "⏰ Time: " . date('g:i A', strtotime($booking_data['appointment_time'])) . "\n";
                    $response .= "🎫 Queue Number: {$queue_number}\n\n";
                    $response .= "⏳ *Pending Confirmation*\n";
                    $response .= "_Your appointment request has been sent. A nurse will confirm it soon._\n\n";
                    $response .= "Use /appointments to check status.";
                    
                    sendMessage($chat_id, $response, $bot_token);
                } else {
                    $error = $insert_stmt->errorInfo();
                    sendMessage($chat_id, "❌ Failed to book appointment. Error: " . $error[2], $bot_token);
                }
                
                resetUserBookingSession($chat_id);
                
            } elseif (strtolower($text) === 'cancel') {
                resetUserBookingSession($chat_id);
                sendMessage($chat_id, "❌ Appointment booking cancelled. Type /askappointment to start over.", $bot_token);
                
            } elseif (preg_match('/^\d{2}-\d{2}-\d{4}$/', $text) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $text)) {
                if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $text)) {
                    $parts = explode('-', $text);
                    $selected_date = $parts[2] . '-' . $parts[1] . '-' . $parts[0];
                } else {
                    $selected_date = $text;
                }
                
                $min_date = (new DateTime())->modify('+1 day');
                
                if ($selected_date >= $min_date->format('Y-m-d')) {
                    updateBookingData($chat_id, 'appointment_date', $selected_date);
                    setBookingStep($chat_id, 'select_time');
                    
                    $time_slots = [
                        '08:00:00', '08:30:00', '09:00:00', '09:30:00', 
                        '10:00:00', '10:30:00', '11:00:00', '11:30:00',
                        '13:00:00', '13:30:00', '14:00:00', '14:30:00', 
                        '15:00:00', '15:30:00', '16:00:00', '16:30:00'
                    ];
                    $available_slots = [];
                    $booking_data = getBookingData($chat_id);
                    $current_time = date('H:i:s');
                    
                    foreach ($time_slots as $slot) {
                        if ($selected_date == date('Y-m-d') && $slot <= $current_time) {
                            continue;
                        }
                        
                        $check_stmt = $pdo->prepare("
                            SELECT COUNT(*) FROM appointments 
                            WHERE doctor_id = ? AND appointment_date = ? AND appointment_time = ?
                            AND status IN ('scheduled', 'confirmed')
                        ");
                        $check_stmt->execute([$booking_data['doctor_id'], $selected_date, $slot]);
                        $count = $check_stmt->fetchColumn();
                        
                        if ($count == 0) {
                            $available_slots[] = $slot;
                        }
                    }
                    
                    if (count($available_slots) > 0) {
                        $response = "📅 Date changed to: " . date('l, F j, Y', strtotime($selected_date)) . "\n\n";
                        $response .= "Available time slots:\n";
                        
                        for ($i = 0; $i < count($available_slots); $i++) {
                            $response .= ($i + 1) . ". " . date('g:i A', strtotime($available_slots[$i])) . "\n";
                        }
                        $response .= "\nPlease enter the number of your preferred time slot.";
                        
                        updateBookingData($chat_id, 'available_slots', $available_slots);
                        sendMessage($chat_id, $response, $bot_token);
                    } else {
                        sendMessage($chat_id, "❌ No available time slots for this date. Please select another date (DD-MM-YYYY):", $bot_token);
                    }
                } else {
                    sendMessage($chat_id, "❌ Please enter a future date (tomorrow or later) in format DD-MM-YYYY", $bot_token);
                }
            } else {
                sendMessage($chat_id, "❌ Please type 'confirm' to book, 'cancel' to cancel, or enter a new date (DD-MM-YYYY)", $bot_token);
            }
            break;
            
        default:
            sendMessage($chat_id, "❌ Something went wrong. Please start over with /askappointment", $bot_token);
            resetUserBookingSession($chat_id);
            break;
    }
}
?>
