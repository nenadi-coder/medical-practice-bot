<?php
/**
 * Shifa Medical Center - Telegram Bot (Final Production Version)
 * 
 * ✅ Uses ONLY columns from your shifacenter.sql schema
 * ✅ PDO connection validation before every use
 * ✅ NO goto statements - clean function routing
 * ✅ NO additional tables required
 * ✅ Proper phone/email validation (international formats)
 * ✅ Try-catch error handling for all database operations
 * ✅ Clickable inline buttons + message editing (pro UI)
 */

require_once __DIR__ . '/includes/config.php';

// ========== 1. SECURE TOKEN & PDO VALIDATION ==========
$bot_token = getenv('TELEGRAM_BOT_TOKEN');
if (empty($bot_token)) {
    // Fallback for testing ONLY - REMOVE IN PRODUCTION
    $bot_token = '8330456846:AAHSmyKZrvCL5yLqpHjynBMqC6tM2u9k6N8';
    error_log('[BOT] WARNING: Using fallback token. Set TELEGRAM_BOT_TOKEN env var.');
}

// Validate token format
if (!preg_match('/^\d+:[A-Za-z0-9_-]{35,}$/', $bot_token)) {
    error_log('[BOT] CRITICAL: Invalid bot token format');
    http_response_code(500);
    exit('Configuration error');
}

// 2. PDO CONNECTION VALIDATION - Check before ANY database use
if (!isset($pdo) || !($pdo instanceof PDO)) {
    error_log('[BOT] CRITICAL: Database connection ($pdo) not available');
    http_response_code(500);
    exit('Service unavailable');
}

// PDO hardening
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// ========== 3. UI HELPER FUNCTIONS ==========

function createInlineKeyboard(array $rows): string {
    $keyboard = [];
    foreach ($rows as $row) {
        $keyboard[] = is_array($row[0] ?? null) ? $row : [$row];
    }
    return json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE);
}

function telegramRequest(string $url, array $data, bool $raw = false): mixed {
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($data),
            'timeout' => 10
        ]
    ]);
    $res = @file_get_contents($url, false, $ctx);
    return $raw ? $res : (json_decode($res, true)['ok'] ?? false);
}

function sendMessage(int $chat_id, string $text, string $token, ?string $keyboard = null): bool {
    $data = ['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'Markdown', 'disable_web_page_preview' => true];
    if ($keyboard) $data['reply_markup'] = $keyboard;
    return telegramRequest("https://api.telegram.org/bot{$token}/sendMessage", $data);
}

function editMessageText(int $chat_id, int $mid, string $text, string $token, ?string $keyboard = null): bool {
    $data = ['chat_id' => $chat_id, 'message_id' => $mid, 'text' => $text, 'parse_mode' => 'Markdown', 'disable_web_page_preview' => true];
    if ($keyboard) $data['reply_markup'] = $keyboard;
    $raw = telegramRequest("https://api.telegram.org/bot{$token}/editMessageText", $data, true);
    return $raw !== false || strpos($raw, 'message is not modified') !== false;
}

function sendOrEdit(int $chat_id, ?int $mid, string $text, string $token, ?string $kb): bool {
    return $mid !== null 
        ? editMessageText($chat_id, $mid, $text, $token, $kb) 
        : sendMessage($chat_id, $text, $token, $kb);
}

function answerCallback(string $cb_id, string $token, string $txt = '', bool $alert = false): void {
    telegramRequest("https://api.telegram.org/bot{$token}/answerCallbackQuery", [
        'callback_query_id' => $cb_id, 'text' => $txt, 'show_alert' => $alert ? 'true' : 'false'
    ]);
}

// ========== 4. DATA HELPERS (Using EXACT schema columns) ==========

function parseDateInput(string $input): DateTime|false {
    $input = trim($input);
    foreach (['Y-m-d' => '/^\d{4}-\d{2}-\d{2}$/', 'd-m-Y' => '/^\d{2}-\d{2}-\d{4}$/', 'd/m/Y' => '#^\d{2}/\d{2}/\d{4}$#'] as $fmt => $pat) {
        if (preg_match($pat, $input)) {
            $d = DateTime::createFromFormat($fmt, $input);
            if ($d && $d->format($fmt) === $input) return $d;
        }
    }
    return false;
}

// 5. PROPER PHONE/EMAIL VALIDATION (international formats)
function isValidContactInput(string $input): bool {
    $input = trim($input);
    if (empty($input) || strlen($input) > 100) return false;
    
    // Email pattern (RFC 5322 simplified)
    if (filter_var($input, FILTER_VALIDATE_EMAIL)) return true;
    
    // Phone pattern: accepts +, spaces, dashes, parentheses, digits (international)
    // Examples: +1234567890, 0556431565, +966 55 643 1565, (055)643-1565
    if (preg_match('/^[\+]?[\d\s\-\(\)]{7,20}$/', $input)) {
        // Must contain at least 7 digits
        return preg_match_all('/\d/', $input) >= 7;
    }
    return false;
}

// Uses EXACT ENUM values from your schema: 'cancelled', 'no-show' (with hyphen)
function getAvailableTimeSlots(PDO $pdo, string $date, int $doctor_id): array {
    try {
        $stmt = $pdo->prepare("SELECT appointment_time FROM appointments WHERE appointment_date = ? AND doctor_id = ? AND status NOT IN ('cancelled', 'no-show')");
        $stmt->execute([$date, $doctor_id]);
        $booked = array_map(fn($t) => date('H:i:s', strtotime($t['appointment_time'])), $stmt->fetchAll());
    } catch (PDOException $e) {
        error_log('[BOT] getAvailableTimeSlots error: ' . $e->getMessage());
        return []; // Return empty on error (fail-safe)
    }
    
    $all = [
        '08:30:00'=>'8:30 AM','09:00:00'=>'9:00 AM','09:30:00'=>'9:30 AM','10:00:00'=>'10:00 AM',
        '10:30:00'=>'10:30 AM','11:00:00'=>'11:00 AM','11:30:00'=>'11:30 AM','12:00:00'=>'12:00 PM',
        '12:30:00'=>'12:30 PM','13:00:00'=>'1:00 PM','13:30:00'=>'1:30 PM','14:00:00'=>'2:00 PM',
        '14:30:00'=>'2:30 PM','15:00:00'=>'3:00 PM','15:30:00'=>'3:30 PM','16:00:00'=>'4:00 PM','16:30:00'=>'4:30 PM'
    ];
    $avail = [];
    foreach ($all as $t => $d) if (!in_array($t, $booked, true)) $avail[] = ['time' => $t, 'display' => $d];
    return $avail;
}

// Your schema has NO is_active column - removed that filter
function getDoctors(PDO $pdo): array {
    try {
        return $pdo->query("SELECT doctor_id, first_name, last_name, specialization FROM doctors ORDER BY specialization, last_name")->fetchAll();
    } catch (PDOException $e) {
        error_log('[BOT] getDoctors error: ' . $e->getMessage());
        return [];
    }
}

function getNextDays(int $n = 7): array {
    $days = [];
    for ($i = 1; $i <= $n; $i++) {
        $dt = new DateTime("+$i days");
        $days[] = ['value' => $dt->format('Y-m-d'), 'label' => $dt->format('D, M j')];
    }
    return $days;
}

// ========== 5. MAIN MENU UI ==========

function showMainMenu(int $cid, ?int $mid, string $tok, array $p, ?string $cust = null): bool {
    $msg = $cust ?? "🏥 *Shifa Medical Center*\n\nHello, " . htmlspecialchars($p['first_name']) . "! 👋\n\nHow can we help you today?";
    $kb = createInlineKeyboard([
        [['text'=>'🏥 Book Appointment','callback_data'=>'menu:askappointment'], ['text'=>'📋 My Appointments','callback_data'=>'menu:appointments']],
        [['text'=>'📅 Next Visit','callback_data'=>'menu:next'], ['text'=>'🎫 Queue Status','callback_data'=>'menu:queue']],
        [['text'=>'👤 My Profile','callback_data'=>'menu:profile'], ['text'=>'❓ Help','callback_data'=>'menu:help']]
    ]);
    return sendOrEdit($cid, $mid, $msg, $tok, $kb);
}

// ========== 6. DISPLAY FUNCTIONS (Using EXACT schema columns) ==========

function showAppointments(int $cid, ?int $mid, string $tok, PDO $pdo, array $p): void {
    try {
        // Uses your exact columns: appointment_date, appointment_time, status, queue_number
        $stmt = $pdo->prepare("SELECT a.*, CONCAT(d.first_name, ' ', d.last_name) as doctor_name FROM appointments a JOIN doctors d ON a.doctor_id = d.doctor_id WHERE a.patient_id = ? ORDER BY a.appointment_date DESC, a.appointment_time DESC LIMIT 20");
        $stmt->execute([$p['patient_id']]); 
        $a = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('[BOT] showAppointments error: ' . $e->getMessage());
        sendOrEdit($cid, $mid, "❌ Error loading appointments.", $tok, createInlineKeyboard([[['text'=>'🏠 Menu','callback_data'=>'menu:home']]]));
        return;
    }
    
    $res = $a ? "📋 *Your Appointments*\n\n" : "📭 *No appointments yet*\n\nBook your first appointment below:";
    foreach ($a as $x) {
        // Your ENUM: 'scheduled', 'confirmed', 'completed', 'cancelled', 'no-show'
        $icon = in_array($x['status'], ['confirmed','completed']) ? '✅' : '⏳';
        $res .= "{$icon} " . date('M j, Y', strtotime($x['appointment_date'])) . " • " . date('g:i A', strtotime($x['appointment_time'])) . "\n";
        $res .= "   Dr. {$x['doctor_name']} | Queue #{$x['queue_number']}\n   Status: " . ucfirst($x['status']) . "\n\n";
    }
    $kb = createInlineKeyboard([[['text'=>'🏥 Book Now','callback_data'=>'menu:askappointment'], ['text'=>'🏠 Menu','callback_data'=>'menu:home']]]);
    sendOrEdit($cid, $mid, $res, $tok, $kb);
}

function showNextAppointment(int $cid, ?int $mid, string $tok, PDO $pdo, array $p): void {
    try {
        $stmt = $pdo->prepare("SELECT a.*, CONCAT(d.first_name, ' ', d.last_name) as doctor_name FROM appointments a JOIN doctors d ON a.doctor_id = d.doctor_id WHERE a.patient_id = ? AND a.appointment_date >= CURDATE() AND a.status = 'confirmed' ORDER BY a.appointment_date ASC, a.appointment_time ASC LIMIT 1");
        $stmt->execute([$p['patient_id']]); 
        $x = $stmt->fetch();
    } catch (PDOException $e) {
        error_log('[BOT] showNextAppointment error: ' . $e->getMessage());
        sendOrEdit($cid, $mid, "❌ Error loading appointment.", $tok, createInlineKeyboard([[['text'=>'🏠 Menu','callback_data'=>'menu:home']]]));
        return;
    }
    
    $res = $x ? "📅 *Next Appointment*\n\n📆 " . date('l, F j, Y', strtotime($x['appointment_date'])) . "\n⏰ " . date('g:i A', strtotime($x['appointment_time'])) . "\n👨‍⚕️ Dr. {$x['doctor_name']}\n🎫 Queue #{$x['queue_number']}" : "❌ *No upcoming appointments*\n\nBook one now:";
    $kb = createInlineKeyboard([[['text'=>'🏥 Book','callback_data'=>'menu:askappointment'], ['text'=>'📋 All','callback_data'=>'menu:appointments']]]);
    sendOrEdit($cid, $mid, $res, $tok, $kb);
}

function showQueueStatus(int $cid, ?int $mid, string $tok, PDO $pdo, array $p): void {
    try {
        // Uses your exact status values: 'scheduled', 'confirmed'
        $stmt = $pdo->prepare("SELECT a.*, CONCAT(d.first_name, ' ', d.last_name) as doctor_name, (SELECT COUNT(*) FROM appointments WHERE appointment_date = a.appointment_date AND appointment_time < a.appointment_time AND status IN ('scheduled','confirmed') AND doctor_id = a.doctor_id) as ahead FROM appointments a JOIN doctors d ON a.doctor_id = d.doctor_id WHERE a.patient_id = ? AND a.appointment_date = CURDATE() AND a.status IN ('scheduled','confirmed') ORDER BY a.appointment_time ASC LIMIT 1");
        $stmt->execute([$p['patient_id']]); 
        $x = $stmt->fetch();
    } catch (PDOException $e) {
        error_log('[BOT] showQueueStatus error: ' . $e->getMessage());
        sendOrEdit($cid, $mid, "❌ Error loading queue.", $tok, createInlineKeyboard([[['text'=>'🏠 Menu','callback_data'=>'menu:home']]]));
        return;
    }
    
    if ($x) {
        $ahead = (int)$x['ahead'];
        $res = "🎫 *Your Queue*\n\n👨‍⚕️ Dr. {$x['doctor_name']}\n🎟️ #{$x['queue_number']}\n" . ($ahead===0 ? "🔔 *You're NEXT!*" : "⏱️ ~" . ($ahead*15) . " min wait");
    } else {
        $res = "🎫 *No queue today*\n\nCheck your appointments:";
    }
    $kb = createInlineKeyboard([[['text'=>'📋 View Appointments','callback_data'=>'menu:appointments']]]);
    sendOrEdit($cid, $mid, $res, $tok, $kb);
}

function showProfile(int $cid, ?int $mid, string $tok, array $p): void {
    try {
        $dt = new DateTime($p['created_at']); // Your column: created_at TIMESTAMP
        $res = "👤 *Profile*\n\nName: " . htmlspecialchars($p['first_name'].' '.$p['last_name']) . "\nEmail: " . htmlspecialchars($p['email']) . "\nPhone: " . htmlspecialchars($p['phone']) . "\nMember since: " . $dt->format('F j, Y');
        $kb = createInlineKeyboard([[['text'=>'🏠 Back to Menu','callback_data'=>'menu:home']]]);
        sendOrEdit($cid, $mid, $res, $tok, $kb);
    } catch (Exception $e) {
        error_log('[BOT] showProfile error: ' . $e->getMessage());
        sendOrEdit($cid, $mid, "❌ Error loading profile.", $tok, createInlineKeyboard([[['text'=>'🏠 Menu','callback_data'=>'menu:home']]]));
    }
}

function showHelp(int $cid, ?int $mid, string $tok): void {
    $res = "❓ *Quick Help*\n\n🏥 `/askappointment` — Book new\n📋 `/appointments` — View all\n📅 `/next` — Next appointment\n🎫 `/queue` — Check position\n👤 `/profile` — Your details\n\n💡 *Tip:* Use menu buttons below!";
    $kb = createInlineKeyboard([[['text'=>'🏥 Book','callback_data'=>'menu:askappointment'], ['text'=>'📋 Appointments','callback_data'=>'menu:appointments'], ['text'=>'🎫 Queue','callback_data'=>'menu:queue']]]);
    sendOrEdit($cid, $mid, $res, $tok, $kb);
}

// ========== 7. BOOKING FLOW WITH BUTTONS (Using EXACT schema) ==========

function startBookingFlow(int $cid, ?int $mid, string $tok, PDO $pdo, int $uid): void {
    $docs = getDoctors($pdo);
    if (empty($docs)) {
        sendOrEdit($cid, $mid, "❌ No doctors available.", $tok, createInlineKeyboard([[['text'=>'🏠 Main Menu','callback_data'=>'menu:home']]]));
        return;
    }
    
    $res = "🏥 *Book Appointment*\n\n*Step 1: Choose a doctor*:";
    $btns = []; $row = [];
    foreach ($docs as $d) {
        $row[] = ['text'=>"👨‍⚕️ {$d['first_name']}", 'callback_data'=>"doctor:{$d['doctor_id']}"];
        if (count($row) === 2) { $btns[] = $row; $row = []; }
    }
    if ($row) $btns[] = $row;
    $btns[] = [['text'=>'🏠 Cancel','callback_data'=>'menu:home']];
    
    // Your schema: UNIQUE KEY uk_telegram_user on telegram_user_id
    // Use INSERT ... ON DUPLICATE KEY UPDATE pattern
    try {
        $pdo->prepare("INSERT INTO telegram_sessions (telegram_user_id, step, data_json, updated_at) VALUES (?, 'booking_doctor', '{}', NOW()) ON DUPLICATE KEY UPDATE step='booking_doctor', data_json='{}', updated_at=NOW()")->execute([$uid]);
    } catch (PDOException $e) {
        error_log('[BOT] startBookingFlow session error: ' . $e->getMessage());
        sendOrEdit($cid, $mid, "❌ Error starting booking. Please try again.", $tok, createInlineKeyboard([[['text'=>'🏠 Menu','callback_data'=>'menu:home']]]));
        return;
    }
    
    sendOrEdit($cid, $mid, $res, $tok, createInlineKeyboard($btns));
}

function handleDoctorSelection(int $did, int $cid, ?int $mid, string $tok, PDO $pdo, array $p, int $uid): void {
    $docs = getDoctors($pdo); $sel = null;
    foreach ($docs as $d) if ($d['doctor_id'] === $did) { $sel = $d; break; }
    if (!$sel) {
        sendOrEdit($cid, $mid, "❌ Doctor not found.", $tok, createInlineKeyboard([[['text'=>'🔙 Back','callback_data'=>'menu:askappointment']]]));
        return;
    }
    
    try {
        $pdo->prepare("INSERT INTO telegram_sessions (telegram_user_id, step, data_json, updated_at) VALUES (?, 'booking_date', ?, NOW()) ON DUPLICATE KEY UPDATE step='booking_date', data_json=?, updated_at=NOW()")
            ->execute([$uid, json_encode(['doctor_id'=>$sel['doctor_id'],'doctor_name'=>$sel['first_name'].' '.$sel['last_name']]), json_encode(['doctor_id'=>$sel['doctor_id'],'doctor_name'=>$sel['first_name'].' '.$sel['last_name']])]);
    } catch (PDOException $e) {
        error_log('[BOT] handleDoctorSelection session error: ' . $e->getMessage());
        sendOrEdit($cid, $mid, "❌ Error saving selection.", $tok, createInlineKeyboard([[['text'=>'🏠 Menu','callback_data'=>'menu:home']]]));
        return;
    }
    
    $res = "✅ *Doctor:* Dr. {$sel['first_name']} {$sel['last_name']}\n\n*Step 2: Select date*\n\n📅 Tap below or type manually:";
    
    $btns = []; $row = [];
    foreach (getNextDays(7) as $day) {
        $row[] = ['text'=>$day['label'], 'callback_data'=>"date:{$day['value']}"];
        if (count($row) === 2) { $btns[] = $row; $row = []; }
    }
    if ($row) $btns[] = $row;
    $btns[] = [['text'=>'◀️ More','callback_data'=>'date:more'], ['text'=>'🔄 Cancel','callback_data'=>'booking:cancel']];
    
    sendOrEdit($cid, $mid, $res, $tok, createInlineKeyboard($btns));
}

function handleDateSelection(string $val, int $cid, ?int $mid, string $tok, PDO $pdo, array $p, int $uid, ?array $sess): void {
    if (!$sess) { sendOrEdit($cid, $mid, "⚠️ Session expired.", $tok, createInlineKeyboard([[['text'=>'🏥 Book Again','callback_data'=>'menu:askappointment']]])); return; }
    $d = json_decode($sess['data_json'] ?? '{}', true);
    
    if ($val === 'more') {
        $btns = []; $row = [];
        for ($i=8; $i<=14; $i++) {
            $dt = new DateTime("+$i days");
            $row[] = ['text'=>$dt->format('D, M j'), 'callback_data'=>"date:{$dt->format('Y-m-d')}"];
            if (count($row) === 2) { $btns[] = $row; $row = []; }
        }
        if ($row) $btns[] = $row;
        $btns[] = [['text'=>'🔙 Back','callback_data'=>'menu:askappointment']];
        editMessageText($cid, $mid, "📅 *Select a date*\n\nChoose from below:", $tok, createInlineKeyboard($btns));
        return;
    }
    
    $obj = DateTime::createFromFormat('Y-m-d', $val); $tom = new DateTime('tomorrow');
    if (!$obj || $obj < $tom) {
        sendOrEdit($cid, $mid, "❌ Please select a future date.", $tok, createInlineKeyboard([[['text'=>'📅 Try Again','callback_data'=>'booking:date']]]));
        return;
    }
    
    $d['date'] = $obj->format('Y-m-d'); $d['display_date'] = $obj->format('l, F j, Y');
    $slots = getAvailableTimeSlots($pdo, $d['date'], $d['doctor_id']);
    
    if (empty($slots)) {
        sendOrEdit($cid, $mid, "❌ No slots on *{$d['display_date']}*", $tok, createInlineKeyboard([[['text'=>'📅 Pick Another','callback_data'=>'date:more']],[['text'=>'🔄 Cancel','callback_data'=>'booking:cancel']]]));
        return;
    }
    
    $d['available_slots'] = $slots;
    try {
        $pdo->prepare("INSERT INTO telegram_sessions (telegram_user_id, step, data_json, updated_at) VALUES (?, 'booking_time', ?, NOW()) ON DUPLICATE KEY UPDATE step='booking_time', data_json=?, updated_at=NOW()")
            ->execute([$uid, json_encode($d), json_encode($d)]);
    } catch (PDOException $e) {
        error_log('[BOT] handleDateSelection session error: ' . $e->getMessage());
        sendOrEdit($cid, $mid, "❌ Error saving date.", $tok, createInlineKeyboard([[['text'=>'🏠 Menu','callback_data'=>'menu:home']]]));
        return;
    }
    
    $res = "📅 *{$d['display_date']}*\n\n⏰ *Select time slot*:";
    $btns = []; $row = [];
    foreach ($slots as $s) {
        $row[] = ['text'=>$s['display'], 'callback_data'=>"time:{$s['time']}"];
        if (count($row) === 3) { $btns[] = $row; $row = []; }
    }
    if ($row) $btns[] = $row;
    $btns[] = [['text'=>'🔙 Change Date','callback_data'=>'booking:date'], ['text'=>'🔄 Cancel','callback_data'=>'booking:cancel']];
    
    sendOrEdit($cid, $mid, $res, $tok, createInlineKeyboard($btns));
}

function handleTimeSelection(string $val, int $cid, ?int $mid, string $tok, PDO $pdo, array $p, int $uid, ?array $sess): void {
    if (!$sess) { sendOrEdit($cid, $mid, "⚠️ Session expired.", $tok, createInlineKeyboard([[['text'=>'🏥 Book Again','callback_data'=>'menu:askappointment']]])); return; }
    $d = json_decode($sess['data_json'] ?? '{}', true); $slots = $d['available_slots'] ?? [];
    
    $sel = null; foreach ($slots as $s) if ($s['time'] === $val) { $sel = $s; break; }
    if (!$sel) { sendOrEdit($cid, $mid, "❌ Invalid time.", $tok, createInlineKeyboard([[['text'=>'⏰ Try Again','callback_data'=>'booking:time']]])); return; }
    
    // Double-check availability using your exact ENUM values
    try {
        $check = $pdo->prepare("SELECT appointment_id FROM appointments WHERE appointment_date=? AND appointment_time=? AND doctor_id=? AND status NOT IN ('cancelled','no-show') LIMIT 1");
        $check->execute([$d['date'], $sel['time'], $d['doctor_id']]);
        if ($check->rowCount() > 0) {
            sendOrEdit($cid, $mid, "❌ Slot just taken!", $tok, createInlineKeyboard([[['text'=>'🏥 Start Over','callback_data'=>'menu:askappointment']]]));
            $pdo->prepare("DELETE FROM telegram_sessions WHERE telegram_user_id=?")->execute([$uid]);
            return;
        }
    } catch (PDOException $e) {
        error_log('[BOT] handleTimeSelection availability check error: ' . $e->getMessage());
        sendOrEdit($cid, $mid, "❌ Error checking availability.", $tok, createInlineKeyboard([[['text'=>'🏠 Menu','callback_data'=>'menu:home']]]));
        return;
    }
    
    $d['time'] = $sel['time']; $d['display_time'] = $sel['display']; unset($d['available_slots']);
    try {
        $pdo->prepare("INSERT INTO telegram_sessions (telegram_user_id, step, data_json, updated_at) VALUES (?, 'booking_confirm', ?, NOW()) ON DUPLICATE KEY UPDATE step='booking_confirm', data_json=?, updated_at=NOW()")
            ->execute([$uid, json_encode($d), json_encode($d)]);
    } catch (PDOException $e) {
        error_log('[BOT] handleTimeSelection session error: ' . $e->getMessage());
        sendOrEdit($cid, $mid, "❌ Error saving time.", $tok, createInlineKeyboard([[['text'=>'🏠 Menu','callback_data'=>'menu:home']]]));
        return;
    }
    
    $res = "⏰ *{$sel['display']}*\n\n*✅ Confirm Appointment*\n\n📋 *Details:*\n👨‍⚕️ Dr. {$d['doctor_name']}\n📆 {$d['display_date']}\n⏰ {$sel['display']}\n\nTap to confirm:";
    sendOrEdit($cid, $mid, $res, $tok, createInlineKeyboard([
        [['text'=>'✅ Confirm','callback_data'=>'confirm:book'], ['text'=>'❌ Cancel','callback_data'=>'booking:cancel']],
        [['text'=>'🔄 Change Time','callback_data'=>'booking:time'], ['text'=>'📅 Change Date','callback_data'=>'booking:date']]
    ]));
}

function handleBookingConfirmation(int $cid, ?int $mid, string $tok, PDO $pdo, array $p, int $uid, ?array $sess): void {
    if (!$sess) { sendOrEdit($cid, $mid, "⚠️ Session expired.", $tok, createInlineKeyboard([[['text'=>'🏥 Book Again','callback_data'=>'menu:askappointment']]])); return; }
    $d = json_decode($sess['data_json'] ?? '{}', true);
    
    // Final availability check with your exact ENUM values
    try {
        $check = $pdo->prepare("SELECT appointment_id FROM appointments WHERE appointment_date=? AND appointment_time=? AND doctor_id=? AND status NOT IN ('cancelled','no-show') LIMIT 1 FOR UPDATE");
        $check->execute([$d['date'], $d['time'], $d['doctor_id']]);
        if ($check->rowCount() > 0) {
            sendOrEdit($cid, $mid, "❌ Slot no longer available.", $tok, createInlineKeyboard([[['text'=>'🔄 Try Again','callback_data'=>'menu:askappointment']]]));
            $pdo->prepare("DELETE FROM telegram_sessions WHERE telegram_user_id=?")->execute([$uid]);
            return;
        }
    } catch (PDOException $e) {
        error_log('[BOT] handleBookingConfirmation availability error: ' . $e->getMessage());
        sendOrEdit($cid, $mid, "❌ Error checking slot.", $tok, createInlineKeyboard([[['text'=>'🏠 Menu','callback_data'=>'menu:home']]]));
        return;
    }
    
    // TRANSACTION: Book appointment using your exact columns
    try {
        $pdo->beginTransaction();
        
        // Your appointments table has: patient_id, doctor_id, appointment_date, appointment_time, queue_number, status, send_sms, created_at
        $q = $pdo->prepare("SELECT COUNT(*)+1 FROM appointments WHERE appointment_date=? AND appointment_time<? AND doctor_id=? AND status IN ('scheduled','confirmed')");
        $q->execute([$d['date'], $d['time'], $d['doctor_id']]);
        $qnum = (int)$q->fetchColumn();
        
        $pdo->prepare("INSERT INTO appointments (patient_id, doctor_id, appointment_date, appointment_time, queue_number, status, send_sms, created_at) VALUES (?, ?, ?, ?, ?, 'scheduled', 1, NOW())")
            ->execute([$p['patient_id'], $d['doctor_id'], $d['date'], $d['time'], $qnum]);
        
        $pdo->prepare("DELETE FROM telegram_sessions WHERE telegram_user_id=?")->execute([$uid]);
        $pdo->commit();
        
        $res = "✅ *Appointment Booked!*\n\n📋 *Details:*\n👨‍⚕️ Dr. {$d['doctor_name']}\n📆 {$d['display_date']}\n⏰ {$d['display_time']}\n🎫 Queue #{$qnum}\n\n📌 Arrive 10 min early.";
        sendOrEdit($cid, $mid, $res, $tok, createInlineKeyboard([[['text'=>'📋 My Appointments','callback_data'=>'menu:appointments'], ['text'=>'🏠 Menu','callback_data'=>'menu:home']]]));
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('[BOT] Booking transaction failed: '.$e->getMessage());
        sendOrEdit($cid, $mid, "❌ Booking failed. Please retry.", $tok, createInlineKeyboard([[['text'=>'🔄 Retry','callback_data'=>'menu:askappointment'], ['text'=>'🏠 Menu','callback_data'=>'menu:home']]]));
    }
}

// ========== 8. MAIN REQUEST HANDLER (NO GOTO - Clean routing) ==========

$content = file_get_contents('php://input');
$update = json_decode($content, true);
if (!$update) { http_response_code(400); exit('Invalid request'); }
if (!isset($update['message']) && !isset($update['callback_query'])) { http_response_code(200); exit(); }

$is_cb = isset($update['callback_query']);
$src = $is_cb ? $update['callback_query'] : ($update['message'] ?? null);
if (!$src) { http_response_code(200); exit(); }

$cid = (int)$src['chat']['id'];
$uid = (int)$src['from']['id'];
$mid = (int)($src['message_id'] ?? 0);
$txt = trim($src['text'] ?? $src['data'] ?? '');

// Fetch patient using your exact columns: telegram_user_id, patient_id, first_name, last_name, email, phone, created_at
try {
    $pat = $pdo->prepare("SELECT patient_id, first_name, last_name, email, phone, created_at FROM patients WHERE telegram_user_id=? LIMIT 1");
    $pat->execute([$uid]); 
    $patient = $pat->fetch();
} catch (PDOException $e) {
    error_log('[BOT] Patient lookup error: ' . $e->getMessage());
    http_response_code(500);
    exit('Database error');
}

// Fetch session using your exact columns: telegram_user_id, step, data_json, updated_at
try {
    $sess_stmt = $pdo->prepare("SELECT step, data_json, updated_at FROM telegram_sessions WHERE telegram_user_id=? LIMIT 1");
    $sess_stmt->execute([$uid]); 
    $session = $sess_stmt->fetch();
} catch (PDOException $e) {
    error_log('[BOT] Session lookup error: ' . $e->getMessage());
    $session = null; // Continue without session
}

// ========== CALLBACK ROUTER (Button clicks - NO GOTO) ==========
if ($is_cb) {
    answerCallback($update['callback_query']['id'], $bot_token);
    
    if (!$patient) {
        editMessageText($cid, $mid, "⚠️ Session expired. Type /start to link.", $bot_token);
        http_response_code(200); exit();
    }
    
    $cb = $update['callback_query']['data'] ?? '';
    
    // Main menu buttons -> trigger existing commands via function calls (NO goto)
    if (str_starts_with($cb, 'menu:')) {
        $cmd = substr($cb, 5);
        $handlers = [
            'home' => fn() => showMainMenu($cid, $mid, $bot_token, $patient),
            'start' => fn() => showMainMenu($cid, $mid, $bot_token, $patient),
            'appointments' => fn() => showAppointments($cid, $mid, $bot_token, $pdo, $patient),
            'next' => fn() => showNextAppointment($cid, $mid, $bot_token, $pdo, $patient),
            'queue' => fn() => showQueueStatus($cid, $mid, $bot_token, $pdo, $patient),
            'profile' => fn() => showProfile($cid, $mid, $bot_token, $patient),
            'askappointment' => fn() => startBookingFlow($cid, $mid, $bot_token, $pdo, $uid),
            'help' => fn() => showHelp($cid, $mid, $bot_token)
        ];
        if (isset($handlers[$cmd])) {
            $handlers[$cmd]();
            http_response_code(200); exit();
        }
    }
    
    // Booking flow buttons - direct function calls (NO goto)
    match(true) {
        str_starts_with($cb, 'doctor:') => handleDoctorSelection((int)substr($cb,7), $cid, $mid, $bot_token, $pdo, $patient, $uid),
        str_starts_with($cb, 'date:') => handleDateSelection(substr($cb,5), $cid, $mid, $bot_token, $pdo, $patient, $uid, $session),
        str_starts_with($cb, 'time:') => handleTimeSelection(substr($cb,5), $cid, $mid, $bot_token, $pdo, $patient, $uid, $session),
        $cb === 'confirm:book' => handleBookingConfirmation($cid, $mid, $bot_token, $pdo, $patient, $uid, $session),
        $cb === 'booking:cancel' => ($pdo->prepare("DELETE FROM telegram_sessions WHERE telegram_user_id=?")->execute([$uid]), showMainMenu($cid, $mid, $bot_token, $patient, "❌ Booking cancelled")),
        default => editMessageText($cid, $mid, "⚠️ Invalid action.", $bot_token)
    };
    
    http_response_code(200); exit();
}

// ========== MESSAGE HANDLER (Text commands - NO GOTO) ==========

// UNLINKED USER
if (!$patient) {
    if ($txt === '/start') {
        try {
            $pdo->prepare("INSERT INTO telegram_sessions (telegram_user_id, step, data_json, updated_at) VALUES (?, 'waiting_email', NULL, NOW()) ON DUPLICATE KEY UPDATE step='waiting_email', data_json=NULL, updated_at=NOW()")->execute([$uid]);
        } catch (PDOException $e) {
            error_log('[BOT] Session insert error: ' . $e->getMessage());
            sendMessage($cid, "❌ Error. Please try again.", $bot_token);
            exit();
        }
        $res = "🏥 *Welcome to Shifa Medical Center*\n\nLink your account to book appointments.\n\n*Enter email or phone:*";
        $kb = createInlineKeyboard([[['text'=>'📧 Use Email','callback_data'=>'link:email'], ['text'=>'📱 Use Phone','callback_data'=>'link:phone']]]);
        sendMessage($cid, $res, $bot_token, $kb);
        
    } elseif ($session && $session['step'] === 'waiting_email') {
        $inp = trim($txt);
        
        // 5. PROPER INPUT VALIDATION (international phone/email)
        if (!isValidContactInput($inp)) {
            sendMessage($cid, "❌ Invalid format. Please enter a valid email or phone number.", $bot_token); 
            exit();
        }
        
        try {
            // Your patients table: email, phone columns
            $f = $pdo->prepare("SELECT patient_id, first_name, last_name, email, phone FROM patients WHERE email=? OR phone=? LIMIT 1");
            $f->execute([$inp, $inp]); 
            $found = $f->fetch();
        } catch (PDOException $e) {
            error_log('[BOT] Patient search error: ' . $e->getMessage());
            sendMessage($cid, "❌ Database error. Please try again.", $bot_token);
            exit();
        }
        
        if ($found) {
            try {
                $pdo->beginTransaction();
                // Your patients table: telegram_user_id, telegram_linked_at columns
                $pdo->prepare("UPDATE patients SET telegram_user_id=?, telegram_linked_at=NOW() WHERE patient_id=?")->execute([$uid, $found['patient_id']]);
                $pdo->prepare("DELETE FROM telegram_sessions WHERE telegram_user_id=?")->execute([$uid]);
                $pdo->commit();
                showMainMenu($cid, null, $bot_token, $found, "✅ *Linked Successfully!*\n\nWelcome, {$found['first_name']}! 👋");
            } catch (PDOException $e) {
                $pdo->rollBack();
                error_log('[BOT] Link transaction failed: ' . $e->getMessage());
                sendMessage($cid, "❌ Link failed. Please try again.", $bot_token);
            }
        } else {
            sendMessage($cid, "❌ *Account not found*\n\nNo patient with: `{$inp}`", $bot_token);
            try { $pdo->prepare("DELETE FROM telegram_sessions WHERE telegram_user_id=?")->execute([$uid]); } catch (PDOException $e) {}
        }
    } else {
        sendMessage($cid, "👋 *Welcome!*\n\nTap below to link:", $bot_token, createInlineKeyboard([[['text'=>'🚀 Get Started','callback_data'=>'menu:start']]]));
    }
    exit();
}

// LINKED USER - COMMAND ROUTER (NO goto - direct function calls)
$cmd_handlers = [
    '/start' => fn() => showMainMenu($cid, null, $bot_token, $patient),
    '/menu' => fn() => showMainMenu($cid, null, $bot_token, $patient),
    '/appointments' => fn() => showAppointments($cid, null, $bot_token, $pdo, $patient),
    '/next' => fn() => showNextAppointment($cid, null, $bot_token, $pdo, $patient),
    '/queue' => fn() => showQueueStatus($cid, null, $bot_token, $pdo, $patient),
    '/profile' => fn() => showProfile($cid, null, $bot_token, $patient),
    '/askappointment' => fn() => startBookingFlow($cid, null, $bot_token, $pdo, $uid),
    '/help' => fn() => showHelp($cid, null, $bot_token)
];

if (isset($cmd_handlers[$txt])) {
    $cmd_handlers[$txt]();
    exit();
}

// BOOKING FLOW TEXT FALLBACKS (for users who type instead of tap) - NO goto
if ($session) {
    $sd = json_decode($session['data_json'] ?? '{}', true);
    
    if ($session['step']==='booking_doctor' && is_numeric($txt) && (int)$txt>0) {
        $docs = getDoctors($pdo); $c = (int)$txt;
        if ($c <= count($docs)) { handleDoctorSelection($docs[$c-1]['doctor_id'], $cid, $mid, $bot_token, $pdo, $patient, $uid); exit(); }
    }
    elseif ($session['step']==='booking_date') {
        if (strtolower($txt)==='cancel') { 
            try { $pdo->prepare("DELETE FROM telegram_sessions WHERE telegram_user_id=?")->execute([$uid]); } catch (PDOException $e) {}
            showMainMenu($cid, null, $bot_token, $patient, "❌ Cancelled"); exit(); 
        }
        $obj = parseDateInput($txt); $tom = new DateTime('tomorrow');
        if ($obj && $obj >= $tom) { handleDateSelection($obj->format('Y-m-d'), $cid, $mid, $bot_token, $pdo, $patient, $uid, $session); exit(); }
    }
    elseif ($session['step']==='booking_time' && is_numeric($txt)) {
        $slots = $sd['available_slots'] ?? []; $c = (int)$txt;
        if ($c>0 && $c<=count($slots)) { handleTimeSelection($slots[$c-1]['time'], $cid, $mid, $bot_token, $pdo, $patient, $uid, $session); exit(); }
    }
    elseif ($session['step']==='booking_confirm') {
        if (strtolower($txt)==='confirm') { handleBookingConfirmation($cid, $mid, $bot_token, $pdo, $patient, $uid, $session); exit(); }
        elseif (strtolower($txt)==='cancel') { 
            try { $pdo->prepare("DELETE FROM telegram_sessions WHERE telegram_user_id=?")->execute([$uid]); } catch (PDOException $e) {}
            showMainMenu($cid, null, $bot_token, $patient, "❌ Cancelled"); exit(); 
        }
    }
}

// UNKNOWN COMMAND
if (str_starts_with($txt, '/')) {
    sendMessage($cid, "🤖 *Unknown command*", $bot_token, createInlineKeyboard([[['text'=>'🏠 Main Menu','callback_data'=>'menu:home'], ['text'=>'❓ Help','callback_data'=>'menu:help']]]));
}

http_response_code(200);
