<?php
require_once 'includes/config.php';

$bot_token = '8330456846:AAFJFM3cy7rbKr5diPbcYi8QaIDDIhktpVU';

$content = file_get_contents('php://input');
$update = json_decode($content, true);

if (!$update) {
    exit();
}

// Handle messages
if (isset($update['message'])) {
    $message = $update['message'];
    $chat_id = $message['chat']['id'];
    $text = trim($message['text'] ?? '');
    $username = $message['from']['username'] ?? '';
    $first_name = $message['from']['first_name'] ?? '';
    
    // ========== FILE-BASED STORAGE FOR BOOKING ==========
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
        $data = ['step' => $step, 'data' => getBookingData($chat_id)];
        file_put_contents($file, json_encode($data));
    }
    
    function setBookingData($chat_id, $data) {
        global $booking_storage_dir;
        $file = $booking_storage_dir . '/' . $chat_id . '.json';
        $current_step = getBookingStep($chat_id);
        $full_data = ['step' => $current_step, 'data' => $data];
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
        if (file_exists($file)) unlink($file);
    }
    
    // Check if user is linked
    $stmt = $pdo->prepare("SELECT * FROM patients WHERE telegram_chat_id = ? OR telegram_user_id = ?");
    $stmt->execute([$chat_id, $chat_id]);
    $patient = $stmt->fetch();
    
    // ========== COMMAND HANDLERS ==========
    
    // /start
    if ($text == '/start') {
        if ($patient) {
            $response = "👋 *Welcome back, {$patient['first_name']}!*\n\n";
            $response .= "📋 *Commands:*\n";
            $response .= "🔹 /askappointment - Book appointment\n";
            $response .= "🔹 /appointments - View appointments\n";
            $response .= "🔹 /next - Next appointment\n";
            $response .= "🔹 /queue - Check queue\n";
            $response .= "🔹 /profile - Your profile\n";
            $response .= "🔹 /status - Account status\n";
            $response .= "🔹 /help - All commands";
        } else {
            $response = "👋 *Welcome to Shifa Medical Center, $first_name!*\n\n";
            $response .= "To get started:\n";
            $response .= "1️⃣ Login: https://shifacenter.me/patient/dashboard.php\n";
            $response .= "2️⃣ Click 'Link Telegram Account'\n\n";
            $response .= "*Commands after linking:*\n";
            $response .= "• /askappointment - Book now\n";
            $response .= "• /appointments - View all\n";
            $response .= "• /next - Next appointment\n";
            $response .= "• /queue - Queue position\n";
            $response .= "• /profile - Your profile";
        }
        sendMessage($chat_id, $response, $bot_token);
    }
    
    // /help
    elseif ($text == '/help') {
        $response = "🤖 *Available Commands:*\n\n";
        $response .= "*/start* - Welcome message\n";
        $response .= "*/askappointment* - Book a new appointment\n";
        $response .= "*/appointments* - View all your appointments\n";
        $response .= "*/next* - Show your next appointment\n";
        $response .= "*/queue* - Check your queue position\n";
        $response .= "*/profile* - View your profile\n";
        $response .= "*/status* - Check account status\n";
        $response .= "*/help* - Show this menu\n\n";
        $response .= "🌐 Website: https://shifacenter.me";
        sendMessage($chat_id, $response, $bot_token);
    }
    
    // /status
    elseif ($text == '/status') {
        if ($patient) {
            $response = "✅ *Account Linked!*\n\n";
            $response .= "Name: {$patient['first_name']} {$patient['last_name']}\n";
            $response .= "Email: {$patient['email']}\n";
            $response .= "You will receive appointment reminders here.";
        } else {
            $response = "❌ *Account Not Linked*\n\n";
            $response .= "Please login to your patient portal and link your Telegram account.\n";
            $response .= "Portal: https://shifacenter.me/patient/dashboard.php";
        }
        sendMessage($chat_id, $response, $bot_token);
    }
    
    // /profile
    elseif ($text == '/profile') {
        if (!$patient) {
            sendMessage($chat_id, "❌ Account not linked. Send /status for help.", $bot_token);
        } else {
            $response = "👤 *Your Profile*\n\n";
            $response .= "*Name:* {$patient['first_name']} {$patient['last_name']}\n";
            $response .= "*Email:* {$patient['email']}\n";
            $response .= "*Phone:* " . ($patient['phone'] ?? 'Not set') . "\n";
            $response .= "*Member since:* " . date('M Y', strtotime($patient['created_at'])) . "\n\n";
            $response .= "_Update on website._";
            sendMessage($chat_id, $response, $bot_token);
        }
    }
    
    // /next
    elseif ($text == '/next') {
        if (!$patient) {
            sendMessage($chat_id, "❌ Account not linked. Send /status for help.", $bot_token);
        } else {
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
                $status_display = ($appointment['status'] == 'confirmed') ? '✅ Confirmed' : '⏳ Pending';
                $response = "📅 *Your Next Appointment*\n\n";
                $response .= "📆 Date: " . date('l, F j, Y', strtotime($appointment['appointment_date'])) . "\n";
                $response .= "⏰ Time: " . date('g:i A', strtotime($appointment['appointment_time'])) . "\n";
                $response .= "👨‍⚕️ Doctor: Dr. {$appointment['doctor_name']}\n";
                $response .= "🎫 Queue #: {$appointment['queue_number']}\n";
                $response .= "📌 Status: {$status_display}\n\n";
                if ($appointment['status'] == 'scheduled') {
                    $response .= "_⚠️ Pending nurse confirmation._";
                } else {
                    $response .= "_Please arrive 10 minutes early!_";
                }
            } else {
                $response = "📅 *No Upcoming Appointments*\n\nBook using /askappointment";
            }
            sendMessage($chat_id, $response, $bot_token);
        }
    }
    
    // /appointments
    elseif ($text == '/appointments') {
        if (!$patient) {
            sendMessage($chat_id, "❌ Account not linked. Send /status for help.", $bot_token);
        } else {
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
                $response .= "_⏳=Pending ✅=Confirmed ✔️=Completed ❌=Cancelled_\n\n";
                foreach ($appointments as $apt) {
                    $emoji = match($apt['status']) {
                        'scheduled' => '⏳', 'confirmed' => '✅',
                        'completed' => '✔️', 'cancelled' => '❌',
                        default => '📌'
                    };
                    $response .= "{$emoji} *" . date('M j, Y', strtotime($apt['appointment_date'])) . "* - " . date('g:i A', strtotime($apt['appointment_time'])) . "\n";
                    $response .= "   Dr. {$apt['doctor_name']} | #{$apt['queue_number']}\n\n";
                }
                $response .= "_Book new: /askappointment_";
            } else {
                $response = "📋 *No Appointments*\n\nBook using /askappointment";
            }
            sendMessage($chat_id, $response, $bot_token);
        }
    }
    
    // /queue
    elseif ($text == '/queue') {
        if (!$patient) {
            sendMessage($chat_id, "❌ Account not linked. Send /status for help.", $bot_token);
        } else {
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
                $queue_stmt = $pdo->prepare("SELECT COUNT(*) as ahead FROM appointments WHERE appointment_date = CURDATE() AND queue_number < ? AND appointment_time > CURTIME() AND status = 'confirmed'");
                $queue_stmt->execute([$appointment['queue_number']]);
                $ahead = $queue_stmt->fetchColumn();
                
                $total_stmt = $pdo->prepare("SELECT COUNT(*) as total FROM appointments WHERE appointment_date = CURDATE() AND appointment_time > CURTIME() AND status = 'confirmed'");
                $total_stmt->execute();
                $total = $total_stmt->fetchColumn();
                
                $response = "🎫 *Queue Information*\n\n";
                $response .= "👨‍⚕️ Doctor: Dr. {$appointment['doctor_name']}\n";
                $response .= "🎟️ Your Queue #: *{$appointment['queue_number']}*\n";
                $response .= "📊 Position: " . ($ahead + 1) . " of $total waiting\n";
                $response .= "👥 People ahead: $ahead\n\n";
                $response .= ($ahead == 0) ? "🔔 *You're NEXT!*" : "⏱️ Est. wait: ~" . ($ahead * 15) . " min";
            } else {
                $response = "🎫 *No Active Queue*\n\nNo confirmed appointments for today.\nSend /next to see your next appointment.";
            }
            sendMessage($chat_id, $response, $bot_token);
        }
    }
    
    // /askappointment - Start booking
    elseif ($text == '/askappointment') {
        if (!$patient) {
            sendMessage($chat_id, "❌ Account not linked. Send /status for help.", $bot_token);
        } else {
            resetUserBookingSession($chat_id);
            setBookingStep($chat_id, 'select_doctor');
            setBookingData($chat_id, ['patient_id' => $patient['patient_id']]);
            
            $doctors_stmt = $pdo->prepare("SELECT doctor_id, first_name, last_name, specialization FROM doctors ORDER BY first_name");
            $doctors_stmt->execute();
            $doctors = $doctors_stmt->fetchAll();
            
            if (count($doctors) == 0) {
                sendMessage($chat_id, "❌ No doctors available. Please try again later.", $bot_token);
            } else {
                $response = "🏥 *Book Appointment*\n\nStep 1: Select doctor\n\n";
                foreach ($doctors as $index => $doctor) {
                    $response .= ($index + 1) . ". Dr. {$doctor['first_name']} {$doctor['last_name']} ({$doctor['specialization']})\n";
                }
                $response .= "\n*Enter number*";
                sendMessage($chat_id, $response, $bot_token);
            }
        }
    }
    
    // Handle booking conversation
    else {
        $current_step = getBookingStep($chat_id);
        if ($current_step !== null && $current_step !== '') {
            handleBookingConversation($chat_id, $text, $pdo, $bot_token);
        } else {
            sendMessage($chat_id, "🤖 Send /help for commands", $bot_token);
        }
    }
}

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
                    $response .= "Please enter the appointment date in any format:\n";
                    $response .= "• YYYY-MM-DD (2026-04-25)\n";
                    $response .= "• DD-MM-YYYY (25-04-2026)\n";
                    $response .= "• DD/MM/YYYY (25/04/2026)\n\n";
                    $response .= "_Note: Date must be at least tomorrow._";
                    sendMessage($chat_id, $response, $bot_token);
                } else {
                    sendMessage($chat_id, "❌ Invalid selection. Enter a number from the list.", $bot_token);
                }
            } else {
                sendMessage($chat_id, "❌ Please enter the number of the doctor.", $bot_token);
            }
            break;
            
        case 'select_date':
            // Convert date formats
            if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $text)) {
                $parts = explode('-', $text);
                $text = $parts[2] . '-' . $parts[1] . '-' . $parts[0];
            } elseif (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $text)) {
                $parts = explode('/', $text);
                $text = $parts[2] . '-' . $parts[1] . '-' . $parts[0];
            } elseif (preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $text)) {
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
                    $response .= "Step 3: Select a time\n\nAvailable time slots:\n";
                    
                    $time_slots = ['08:00', '08:30', '09:00', '09:30', '10:00', '10:30', '11:00', '11:30', '13:00', '13:30', '14:00', '14:30', '15:00', '15:30', '16:00', '16:30'];
                    $available_slots = [];
                    $booking_data = getBookingData($chat_id);
                    $current_time = date('H:i');
                    
                    foreach ($time_slots as $slot) {
                        if ($selected_date == date('Y-m-d') && $slot <= $current_time) continue;
                        
                        $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE doctor_id = ? AND appointment_date = ? AND appointment_time = ? AND status IN ('scheduled', 'confirmed')");
                        $check_stmt->execute([$booking_data['doctor_id'], $selected_date, $slot . ':00']);
                        if ($check_stmt->fetchColumn() == 0) $available_slots[] = $slot;
                    }
                    
                    if (count($available_slots) > 0) {
                        foreach ($available_slots as $i => $slot) $response .= ($i + 1) . ". " . date('g:i A', strtotime($slot)) . "\n";
                        $response .= "\nEnter the number of your preferred time.";
                        updateBookingData($chat_id, 'available_slots', $available_slots);
                    } else {
                        $response = "❌ No available slots for this date.\nPlease select another date (DD-MM-YYYY):";
                        setBookingStep($chat_id, 'select_date');
                    }
                    sendMessage($chat_id, $response, $bot_token);
                } else {
                    sendMessage($chat_id, "❌ Please enter a future date (tomorrow or later).\nExample: 25-04-2026", $bot_token);
                }
            } else {
                sendMessage($chat_id, "❌ Invalid date format.\nUse: YYYY-MM-DD, DD-MM-YYYY, or DD/MM/YYYY", $bot_token);
            }
            break;
            
        case 'select_time':
            if (is_numeric($text)) {
                $booking_data = getBookingData($chat_id);
                $index = (int)$text - 1;
                if (isset($booking_data['available_slots'][$index])) {
                    $selected_time = $booking_data['available_slots'][$index] . ':00';
                    updateBookingData($chat_id, 'appointment_time', $selected_time);
                    setBookingStep($chat_id, 'confirm');
                    
                    $booking_data = getBookingData($chat_id);
                    $response = "⏰ Time selected: " . date('g:i A', strtotime($selected_time)) . "\n\n";
                    $response .= "Step 4: Confirm your appointment\n\n";
                    $response .= "📋 *Appointment Details:*\n";
                    $response .= "👨‍⚕️ Doctor: Dr. {$booking_data['doctor_name']}\n";
                    $response .= "📆 Date: " . date('l, F j, Y', strtotime($booking_data['appointment_date'])) . "\n";
                    $response .= "⏰ Time: " . date('g:i A', strtotime($booking_data['appointment_time'])) . "\n\n";
                    $response .= "✅ Type 'confirm' to submit\n";
                    $response .= "❌ Type 'cancel' to cancel\n";
                    $response .= "🔄 Type a new date (DD-MM-YYYY) to change";
                    sendMessage($chat_id, $response, $bot_token);
                } else {
                    sendMessage($chat_id, "❌ Invalid selection. Enter a number from the list.", $bot_token);
                }
            } else {
                sendMessage($chat_id, "❌ Enter the number of your preferred time.", $bot_token);
            }
            break;
            
        case 'confirm':
            $booking_data = getBookingData($chat_id);
            
            if (strtolower($text) === 'confirm') {
                $queue_stmt = $pdo->prepare("SELECT COALESCE(MAX(queue_number), 0) + 1 FROM appointments WHERE appointment_date = ? AND doctor_id = ?");
                $queue_stmt->execute([$booking_data['appointment_date'], $booking_data['doctor_id']]);
                $queue_number = $queue_stmt->fetchColumn();
                
                $insert_stmt = $pdo->prepare("INSERT INTO appointments (patient_id, doctor_id, appointment_date, appointment_time, queue_number, status, created_at) VALUES (?, ?, ?, ?, ?, 'scheduled', NOW())");
                $result = $insert_stmt->execute([
                    $booking_data['patient_id'], $booking_data['doctor_id'],
                    $booking_data['appointment_date'], $booking_data['appointment_time'], $queue_number
                ]);
                
                if ($result) {
                    $response = "✅ *Appointment Request Submitted!*\n\n";
                    $response .= "📋 *Details:*\n";
                    $response .= "👨‍⚕️ Dr. {$booking_data['doctor_name']}\n";
                    $response .= "📆 " . date('l, F j, Y', strtotime($booking_data['appointment_date'])) . "\n";
                    $response .= "⏰ " . date('g:i A', strtotime($booking_data['appointment_time'])) . "\n";
                    $response .= "🎫 Queue #{$queue_number}\n\n";
                    $response .= "⏳ *Pending Confirmation*\n";
                    $response .= "Use /appointments to check status.";
                } else {
                    $response = "❌ Failed to book. Please try again.";
                }
                resetUserBookingSession($chat_id);
                sendMessage($chat_id, $response, $bot_token);
                
            } elseif (strtolower($text) === 'cancel') {
                resetUserBookingSession($chat_id);
                sendMessage($chat_id, "❌ Booking cancelled. Type /askappointment to start over.", $bot_token);
                
            } elseif (preg_match('/^\d{2}-\d{2}-\d{4}$/', $text)) {
                $parts = explode('-', $text);
                $selected_date = $parts[2] . '-' . $parts[1] . '-' . $parts[0];
                $min_date = (new DateTime())->modify('+1 day');
                
                if ($selected_date >= $min_date->format('Y-m-d')) {
                    updateBookingData($chat_id, 'appointment_date', $selected_date);
                    setBookingStep($chat_id, 'select_time');
                    
                    $response = "📅 Date changed to: " . date('l, F j, Y', strtotime($selected_date)) . "\n\n";
                    $response .= "Available time slots:\n";
                    
                    $time_slots = ['08:00', '08:30', '09:00', '09:30', '10:00', '10:30', '11:00', '11:30', '13:00', '13:30', '14:00', '14:30', '15:00', '15:30', '16:00', '16:30'];
                    $available_slots = [];
                    $booking_data = getBookingData($chat_id);
                    $current_time = date('H:i');
                    
                    foreach ($time_slots as $slot) {
                        if ($selected_date == date('Y-m-d') && $slot <= $current_time) continue;
                        $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE doctor_id = ? AND appointment_date = ? AND appointment_time = ? AND status IN ('scheduled', 'confirmed')");
                        $check_stmt->execute([$booking_data['doctor_id'], $selected_date, $slot . ':00']);
                        if ($check_stmt->fetchColumn() == 0) $available_slots[] = $slot;
                    }
                    
                    if (count($available_slots) > 0) {
                        foreach ($available_slots as $i => $slot) $response .= ($i + 1) . ". " . date('g:i A', strtotime($slot)) . "\n";
                        $response .= "\nEnter the number of your preferred time.";
                        updateBookingData($chat_id, 'available_slots', $available_slots);
                    } else {
                        $response = "❌ No available slots. Please select another date (DD-MM-YYYY):";
                        setBookingStep($chat_id, 'select_date');
                    }
                    sendMessage($chat_id, $response, $bot_token);
                } else {
                    sendMessage($chat_id, "❌ Enter a future date (DD-MM-YYYY)", $bot_token);
                }
            } else {
                sendMessage($chat_id, "❌ Type 'confirm' to book, 'cancel' to cancel, or enter a new date (DD-MM-YYYY)", $bot_token);
            }
            break;
            
        default:
            resetUserBookingSession($chat_id);
            sendMessage($chat_id, "❌ Something went wrong. Start over with /askappointment", $bot_token);
            break;
    }
}
?>
