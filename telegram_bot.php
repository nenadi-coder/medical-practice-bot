<?php
/**
 * Shifa Medical Center - Telegram Bot Handler
 * ✅ PHP 7.4+ Compatible | Email-only lookup | Uses ONLY shifacenter.sql columns
 * ✅ Fixes: cURL callbacks, unknown command feedback, JSON consistency, SSL verification, slot refresh
 */

// ========== PHP 8.0+ COMPATIBILITY ==========
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return $needle === '' || strpos($haystack, $needle) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}
// ========== END COMPATIBILITY ==========

// Clean output for Telegram compliance
ob_clean();
header('Content-Type: text/plain');
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Load database config
require_once __DIR__ . '/includes/config.php';

// ========== CONFIG & VALIDATION ==========
$bot_token = getenv('TELEGRAM_BOT_TOKEN');
if (empty($bot_token)) {
    error_log('[BOT] TELEGRAM_BOT_TOKEN not set');
    http_response_code(500);
    exit('Config error');
}
if (!preg_match('/^\d+:[A-Za-z0-9_-]{35,}$/', $bot_token)) {
    error_log('[BOT] Invalid token format');
    http_response_code(500);
    exit('Config error');
}
if (!isset($pdo) || !($pdo instanceof PDO)) {
    error_log('[BOT] PDO connection missing');
    http_response_code(500);
    exit('DB error');
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ========== INPUT HANDLING ==========
$input = file_get_contents('php://input');
$update = json_decode($input, true);
if (!$update) { http_response_code(200); exit; }

$is_callback = isset($update['callback_query']);
$src = $is_callback ? $update['callback_query'] : ($update['message'] ?? null);
if (!$src) { http_response_code(200); exit; }

$chat_id = (int)$src['chat']['id'];
$msg_id = (int)($src['message_id'] ?? 0);
$uid = (int)$src['from']['id'];
$text = trim($src['text'] ?? $src['data'] ?? '');

// ========== FIX #1: cURL for answerCallbackQuery ==========
if ($is_callback) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "https://api.telegram.org/bot{$bot_token}/answerCallbackQuery",
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'callback_query_id' => $update['callback_query']['id'],
            'show_alert' => false
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// ========== DATABASE LOOKUPS ==========
$patient = null;
$session = null;
try {
    $pat_stmt = $pdo->prepare("SELECT patient_id, first_name, last_name, email FROM patients WHERE telegram_user_id = ? LIMIT 1");
    $pat_stmt->execute([$uid]);
    $patient = $pat_stmt->fetch();

    $ses_stmt = $pdo->prepare("SELECT step, data_json FROM telegram_sessions WHERE telegram_user_id = ? LIMIT 1");
    $ses_stmt->execute([$uid]);
    $session = $ses_stmt->fetch();
} catch (PDOException $e) {
    error_log('[BOT] DB lookup error: ' . $e->getMessage());
}

// ========== HELPER FUNCTIONS ==========
function createKb(array $rows): string {
    $kb = [];
    foreach ($rows as $row) {
        $kb[] = is_array($row[0] ?? null) ? $row : [$row];
    }
    return json_encode(['inline_keyboard' => $kb], JSON_UNESCAPED_UNICODE);
}

// ========== FIX #4: SSL verification in tgReq ==========
function tgReq(string $url, array $data, bool $raw = false) {
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($data),
            'timeout' => 10
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'cafile' => '/etc/ssl/certs/ca-certificates.crt'
        ]
    ]);
    $res = @file_get_contents($url, false, $ctx);
    return $raw ? $res : (json_decode($res, true)['ok'] ?? false);
}

function tgSend(int $cid, string $txt, string $tok, ?string $kb = null): bool {
    $d = ['chat_id' => $cid, 'text' => $txt, 'parse_mode' => 'Markdown', 'disable_web_page_preview' => true];
    if ($kb) { $d['reply_markup'] = $kb; }
    return tgReq("https://api.telegram.org/bot{$tok}/sendMessage", $d);
}

function tgEdit(int $cid, int $mid, string $txt, string $tok, ?string $kb = null): bool {
    $d = ['chat_id' => $cid, 'message_id' => $mid, 'text' => $txt, 'parse_mode' => 'Markdown', 'disable_web_page_preview' => true];
    if ($kb) { $d['reply_markup'] = $kb; }
    $r = tgReq("https://api.telegram.org/bot{$tok}/editMessageText", $d, true);
    return $r !== false && strpos($r, 'message is not modified') === false;
}

function tgMsg(int $cid, ?int $mid, string $txt, string $tok, ?string $kb): bool {
    return $mid !== null ? tgEdit($cid, $mid, $txt, $tok, $kb) : tgSend($cid, $txt, $tok, $kb);
}

function getSlots(PDO $pdo, string $date, int $doc_id): array {
    try {
        $s = $pdo->prepare("SELECT appointment_time FROM appointments WHERE appointment_date = ? AND doctor_id = ? AND status NOT IN ('cancelled', 'no-show')");
        $s->execute([$date, $doc_id]);
        $booked = [];
        foreach ($s->fetchAll() as $t) {
            $booked[] = date('H:i:s', strtotime($t['appointment_time']));
        }
    } catch (Exception $e) { return []; }
    
    $all = [
        '08:30:00'=>'8:30 AM','09:00:00'=>'9:00 AM','09:30:00'=>'9:30 AM','10:00:00'=>'10:00 AM',
        '10:30:00'=>'10:30 AM','11:00:00'=>'11:00 AM','11:30:00'=>'11:30 AM','12:00:00'=>'12:00 PM',
        '12:30:00'=>'12:30 PM','13:00:00'=>'1:00 PM','13:30:00'=>'1:30 PM','14:00:00'=>'2:00 PM',
        '14:30:00'=>'2:30 PM','15:00:00'=>'3:00 PM','15:30:00'=>'3:30 PM','16:00:00'=>'4:00 PM','16:30:00'=>'4:30 PM'
    ];
    $out = [];
    foreach ($all as $t => $d) {
        if (!in_array($t, $booked, true)) { $out[] = ['time' => $t, 'display' => $d]; }
    }
    return $out;
}

function getDocs(PDO $pdo): array {
    try {
        return $pdo->query("SELECT doctor_id, first_name, last_name, specialization FROM doctors ORDER BY specialization, last_name")->fetchAll();
    } catch (Exception $e) { return []; }
}

function getNextDays(int $n = 7): array {
    $days = [];
    for ($i = 1; $i <= $n; $i++) {
        $dt = new DateTime("+$i days");
        $days[] = ['value' => $dt->format('Y-m-d'), 'label' => $dt->format('D, M j')];
    }
    return $days;
}

// ========== DISPLAY FUNCTIONS ==========
function showMenu(int $cid, ?int $mid, string $tok, array $p, ?string $cust = null): bool {
    $txt = $cust ?? "🏥 *Shifa Medical Center*\n\nHello, " . htmlspecialchars($p['first_name']) . "! 👋\n\nHow can we help you today?";
    $kb = createKb([
        [['text'=>'🏥 Book Appointment','callback_data'=>'menu:book'], ['text'=>'📋 My Appointments','callback_data'=>'menu:appt']],
        [['text'=>'📅 Next Visit','callback_data'=>'menu:next'], ['text'=>'🎫 Queue','callback_data'=>'menu:queue']],
        [['text'=>'👤 Profile','callback_data'=>'menu:prof'], ['text'=>'❓ Help','callback_data'=>'menu:help']]
    ]);
    return tgMsg($cid, $mid, $txt, $tok, $kb);
}

function showAppts(int $cid, ?int $mid, string $tok, PDO $pdo, array $p): bool {
    try {
        $s = $pdo->prepare("SELECT a.*, CONCAT(d.first_name,' ',d.last_name) as doc_name FROM appointments a JOIN doctors d ON a.doctor_id=d.doctor_id WHERE a.patient_id=? ORDER BY a.appointment_date DESC, a.appointment_time DESC LIMIT 15");
        $s->execute([$p['patient_id']]);
        $a = $s->fetchAll();
    } catch (Exception $e) {
        return tgMsg($cid, $mid, "❌ Error loading appointments.", $tok, createKb([[['text'=>'🏠 Menu','callback_data'=>'menu:home']]]));
    }
    $txt = $a ? "📋 *Your Appointments*\n\n" : "📭 *No appointments yet*\n\nBook your first appointment below:";
    foreach ($a as $x) {
        $icon = in_array($x['status'], ['confirmed','completed']) ? '✅' : '⏳';
        $txt .= "{$icon} ".date('M j',strtotime($x['appointment_date']))." • ".date('g:i A',strtotime($x['appointment_time']))."\n";
        $txt .= "   Dr. {$x['doc_name']} | #{$x['queue_number']}\n   Status: {$x['status']}\n\n";
    }
    return tgMsg($cid, $mid, $txt, $tok, createKb([[['text'=>'🏥 Book','callback_data'=>'menu:book'], ['text'=>'🏠 Menu','callback_data'=>'menu:home']]]));
}

function showNext(int $cid, ?int $mid, string $tok, PDO $pdo, array $p): bool {
    try {
        $s = $pdo->prepare("SELECT a.*, CONCAT(d.first_name,' ',d.last_name) as doc_name FROM appointments a JOIN doctors d ON a.doctor_id=d.doctor_id WHERE a.patient_id=? AND a.appointment_date>=CURDATE() AND a.status='confirmed' ORDER BY a.appointment_date ASC, a.appointment_time ASC LIMIT 1");
        $s->execute([$p['patient_id']]);
        $x = $s->fetch();
    } catch (Exception $e) {
        return tgMsg($cid, $mid, "❌ Error.", $tok, createKb([[['text'=>'🏠 Menu','callback_data'=>'menu:home']]]));
    }
    $txt = $x ? "📅 *Next Appointment*\n\n📆 ".date('l, M j',strtotime($x['appointment_date']))."\n⏰ ".date('g:i A',strtotime($x['appointment_time']))."\n👨‍⚕️ Dr. {$x['doc_name']}\n🎫 Queue #{$x['queue_number']}" : "❌ *No upcoming confirmed appointments*";
    return tgMsg($cid, $mid, $txt, $tok, createKb([[['text'=>'🏥 Book','callback_data'=>'menu:book'], ['text'=>'📋 All','callback_data'=>'menu:appt']]]));
}

function showQueue(int $cid, ?int $mid, string $tok, PDO $pdo, array $p): bool {
    try {
        $s = $pdo->prepare("SELECT a.*, CONCAT(d.first_name,' ',d.last_name) as doc_name, (SELECT COUNT(*) FROM appointments WHERE appointment_date=a.appointment_date AND appointment_time<a.appointment_time AND status IN ('scheduled','confirmed') AND doctor_id=a.doctor_id) as ahead FROM appointments a JOIN doctors d ON a.doctor_id=d.doctor_id WHERE a.patient_id=? AND a.appointment_date=CURDATE() AND a.status IN ('scheduled','confirmed') ORDER BY a.appointment_time ASC LIMIT 1");
        $s->execute([$p['patient_id']]);
        $x = $s->fetch();
    } catch (Exception $e) {
        return tgMsg($cid, $mid, "❌ Error.", $tok, createKb([[['text'=>'🏠 Menu','callback_data'=>'menu:home']]]));
    }
    $txt = $x ? "🎫 *Your Queue*\n\n👨‍⚕️ Dr. {$x['doc_name']}\n🎟️ #{$x['queue_number']}\n" . ((int)$x['ahead']===0 ? "🔔 *You're NEXT!*" : "⏱️ ~".((int)$x['ahead']*15)." min wait") : "🎫 *No active queue today*";
    return tgMsg($cid, $mid, $txt, $tok, createKb([[['text'=>'📋 View Appointments','callback_data'=>'menu:appt']]]));
}

function showProf(int $cid, ?int $mid, string $tok, array $p): bool {
    $txt = "👤 *Profile*\n\nName: {$p['first_name']} {$p['last_name']}\nEmail: {$p['email']}";
    return tgMsg($cid, $mid, $txt, $tok, createKb([[['text'=>'🏠 Back','callback_data'=>'menu:home']]]));
}

function showHelp(int $cid, ?int $mid, string $tok): bool {
    $txt = "❓ *Commands*\n\n🏥 `/book` — Book new\n📋 `/appt` — View all\n📅 `/next` — Next\n🎫 `/queue` — Position\n👤 `/profile` — Details\n💡 *Tip:* Use buttons below!";
    return tgMsg($cid, $mid, $txt, $tok, createKb([[['text'=>'🏥 Book','callback_data'=>'menu:book'], ['text'=>'📋 Appointments','callback_data'=>'menu:appt'], ['text'=>'🎫 Queue','callback_data'=>'menu:queue']]]));
}

// ========== BOOKING FLOW ==========
function startBook(int $cid, ?int $mid, string $tok, PDO $pdo, int $uid): bool {
    $docs = getDocs($pdo);
    if (empty($docs)) {
        return tgMsg($cid, $mid, "❌ No doctors available.", $tok, createKb([[['text'=>'🏠 Menu','callback_data'=>'menu:home']]]));
    }
    $txt = "🏥 *Book Appointment*\n\n*Step 1: Choose a doctor*:";
    $b = []; $r = [];
    foreach ($docs as $d) {
        $r[] = ['text'=>"👨‍⚕️ {$d['first_name']}", 'callback_data'=>"doc:{$d['doctor_id']}"];
        if (count($r) == 2) { $b[] = $r; $r = []; }
    }
    if (!empty($r)) { $b[] = $r; }
    $b[] = [['text'=>'🏠 Cancel','callback_data'=>'menu:home']];
    try {
        // ========== FIX #3: Consistent JSON encoding ==========
        $pdo->prepare("REPLACE INTO telegram_sessions (telegram_user_id, step, data_json) VALUES (?, 'booking_doc', ?)")->execute([$uid, json_encode([])]);
    } catch (Exception $e) {}
    return tgMsg($cid, $mid, $txt, $tok, createKb($b));
}

function selDoc(int $did, int $cid, ?int $mid, string $tok, PDO $pdo, array $p, int $uid): bool {
    $docs = getDocs($pdo); $sel = null;
    foreach ($docs as $d) { if ($d['doctor_id'] == $did) { $sel = $d; break; } }
    if (!$sel) {
        return tgMsg($cid, $mid, "❌ Not found.", $tok, createKb([[['text'=>'🔙 Back','callback_data'=>'menu:book']]]));
    }
    try {
        // ========== FIX #3: Consistent JSON encoding ==========
        $pdo->prepare("REPLACE INTO telegram_sessions (telegram_user_id, step, data_json) VALUES (?, 'booking_date', ?)")->execute([
            $uid, 
            json_encode(['doc_id'=>$sel['doctor_id'], 'doc_name'=>"{$sel['first_name']} {$sel['last_name']}"])
        ]);
    } catch (Exception $e) {}
    $txt = "✅ *Doctor:* Dr. {$sel['first_name']} {$sel['last_name']}\n\n*Step 2: Select date*\n📅 Tap below:";
    $b = []; $r = [];
    for ($i = 1; $i <= 7; $i++) {
        $dt = new DateTime("+$i days");
        $v = $dt->format('Y-m-d'); $l = $dt->format('D M j');
        $r[] = ['text' => $l, 'callback_data' => "date:{$v}"];
        if (count($r) == 2) { $b[] = $r; $r = []; }
    }
    if (!empty($r)) { $b[] = $r; }
    $b[] = [['text'=>'📅 More','callback_data'=>'date:more'], ['text'=>'🔄 Cancel','callback_data'=>'menu:home']];
    return tgMsg($cid, $mid, $txt, $tok, createKb($b));
}

function selDate(string $val, int $cid, ?int $mid, string $tok, PDO $pdo, array $p, int $uid, ?array $sess): bool {
    if (!$sess) { return tgMsg($cid, $mid, "⚠️ Session expired.", $tok, createKb([[['text'=>'🏥 Book','callback_data'=>'menu:book']]])); }
    $d = json_decode($sess['data_json'], true);
    if ($val === 'more') {
        $b = []; $r = [];
        for ($i = 8; $i <= 14; $i++) {
            $dt = new DateTime("+$i days");
            $r[] = ['text'=>$dt->format('D M j'), 'callback_data'=>"date:{$dt->format('Y-m-d')}"];
            if (count($r) == 2) { $b[] = $r; $r = []; }
        }
        if (!empty($r)) { $b[] = $r; }
        $b[] = [['text'=>'🔙 Back','callback_data'=>'menu:book']];
        return tgEdit($cid, $mid, "📅 *Select a date*", $tok, createKb($b));
    }
    $obj = DateTime::createFromFormat('Y-m-d', $val);
    $tom = new DateTime('tomorrow');
    if (!$obj || $obj < $tom) {
        return tgMsg($cid, $mid, "❌ Future dates only.", $tok, createKb([[['text'=>'📅 Retry','callback_data'=>'date:pick']]]));
    }
    $d['date'] = $obj->format('Y-m-d'); $d['disp_date'] = $obj->format('l, M j');
    $slots = getSlots($pdo, $d['date'], $d['doc_id']);
    if (empty($slots)) {
        return tgMsg($cid, $mid, "❌ No slots on {$d['disp_date']}", $tok, createKb([[['text'=>'📅 Pick Another','callback_data'=>'date:more']], [['text'=>'🔄 Cancel','callback_data'=>'menu:home']]]));
    }
    $d['slots'] = $slots;
    try {
        // ========== FIX #3: Consistent JSON encoding ==========
        $pdo->prepare("REPLACE INTO telegram_sessions (telegram_user_id, step, data_json) VALUES (?, 'booking_time', ?)")->execute([$uid, json_encode($d)]);
    } catch (Exception $e) {}
    $txt = "📅 *{$d['disp_date']}*\n\n⏰ *Select time*:";
    $b = []; $r = [];
    foreach ($slots as $s) {
        $r[] = ['text'=>$s['display'], 'callback_data'=>"time:{$s['time']}"];
        if (count($r) == 3) { $b[] = $r; $r = []; }
    }
    if (!empty($r)) { $b[] = $r; }
    $b[] = [['text'=>'🔙 Change Date','callback_data'=>'date:more'], ['text'=>'🔄 Cancel','callback_data'=>'menu:home']];
    return tgMsg($cid, $mid, $txt, $tok, createKb($b));
}

function selTime(string $val, int $cid, ?int $mid, string $tok, PDO $pdo, array $p, int $uid, ?array $sess): bool {
    if (!$sess) { return tgMsg($cid, $mid, "⚠️ Expired.", $tok, createKb([[['text'=>'🏥 Book','callback_data'=>'menu:book']]])); }
    $d = json_decode($sess['data_json'], true); $slots = $d['slots'] ?? [];
    $sel = null; foreach ($slots as $s) { if ($s['time'] === $val) { $sel = $s; break; } }
    if (!$sel) { return tgMsg($cid, $mid, "❌ Invalid.", $tok, createKb([[['text'=>'⏰ Retry','callback_data'=>'time:retry']]])); }
    
    try {
        $chk = $pdo->prepare("SELECT appointment_id FROM appointments WHERE appointment_date=? AND appointment_time=? AND doctor_id=? AND status NOT IN ('cancelled','no-show') LIMIT 1");
        $chk->execute([$d['date'], $sel['time'], $d['doc_id']]);
        
        // ========== FIX #5: Refresh slots if taken (race condition handling) ==========
        if ($chk->rowCount() > 0) {
            $new_slots = getSlots($pdo, $d['date'], $d['doc_id']);
            if (!empty($new_slots)) {
                // Update session with fresh slots and show updated list
                $d['slots'] = $new_slots;
                $pdo->prepare("REPLACE INTO telegram_sessions (telegram_user_id, step, data_json) VALUES (?, 'booking_time', ?)")->execute([$uid, json_encode($d)]);
                $txt = "⚠️ That slot was just taken. Please choose another:\n\n📅 *{$d['disp_date']}*\n⏰ *Select time*:";
                $b = []; $r = [];
                foreach ($new_slots as $s) {
                    $r[] = ['text'=>$s['display'], 'callback_data'=>"time:{$s['time']}"];
                    if (count($r) == 3) { $b[] = $r; $r = []; }
                }
                if (!empty($r)) { $b[] = $r; }
                $b[] = [['text'=>'🔙 Change Date','callback_data'=>'date:more'], ['text'=>'🔄 Cancel','callback_data'=>'menu:home']];
                return tgMsg($cid, $mid, $txt, $tok, createKb($b));
            } else {
                // No slots left at all
                return tgMsg($cid, $mid, "❌ No slots available on {$d['disp_date']}. Please pick another date.", $tok, createKb([[['text'=>'📅 Pick Another','callback_data'=>'date:more']], [['text'=>'🔄 Cancel','callback_data'=>'menu:home']]]));
            }
        }
    } catch (Exception $e) { return tgMsg($cid, $mid, "❌ Error checking slot.", $tok, createKb([[['text'=>'🏠 Menu','callback_data'=>'menu:home']]])); }
    
    $d['time'] = $sel['time']; $d['disp_time'] = $sel['display']; unset($d['slots']);
    try {
        $pdo->prepare("REPLACE INTO telegram_sessions (telegram_user_id, step, data_json) VALUES (?, 'booking_conf', ?)")->execute([$uid, json_encode($d)]);
    } catch (Exception $e) {}
    $txt = "⏰ *{$sel['display']}*\n\n✅ *Confirm*\n👨‍⚕️ Dr. {$d['doc_name']}\n📆 {$d['disp_date']}\n⏰ {$sel['display']}\n\nTap to confirm:";
    return tgMsg($cid, $mid, $txt, $tok, createKb([
        [['text'=>'✅ Confirm','callback_data'=>'conf:book'], ['text'=>'❌ Cancel','callback_data'=>'menu:home']],
        [['text'=>'🔄 Change Time','callback_data'=>'time:retry'], ['text'=>'📅 Change Date','callback_data'=>'date:more']]
    ]));
}

function confBook(int $cid, ?int $mid, string $tok, PDO $pdo, array $p, int $uid, ?array $sess): bool {
    if (!$sess) { return tgMsg($cid, $mid, "⚠️ Expired.", $tok, createKb([[['text'=>'🏥 Book','callback_data'=>'menu:book']]])); }
    $d = json_decode($sess['data_json'], true);
    try {
        $chk = $pdo->prepare("SELECT appointment_id FROM appointments WHERE appointment_date=? AND appointment_time=? AND doctor_id=? AND status NOT IN ('cancelled','no-show') LIMIT 1 FOR UPDATE");
        $chk->execute([$d['date'], $d['time'], $d['doc_id']]);
        if ($chk->rowCount() > 0) {
            $pdo->prepare("DELETE FROM telegram_sessions WHERE telegram_user_id=?")->execute([$uid]);
            return tgMsg($cid, $mid, "❌ Slot gone.", $tok, createKb([[['text'=>'🔄 Retry','callback_data'=>'menu:book']]]));
        }
        $pdo->beginTransaction();
        $q = $pdo->prepare("SELECT COUNT(*)+1 FROM appointments WHERE appointment_date=? AND appointment_time<? AND doctor_id=? AND status IN ('scheduled','confirmed')");
        $q->execute([$d['date'], $d['time'], $d['doc_id']]);
        $qnum = (int)$q->fetchColumn();
        $pdo->prepare("INSERT INTO appointments (patient_id, doctor_id, appointment_date, appointment_time, queue_number, status, send_sms) VALUES (?, ?, ?, ?, ?, 'scheduled', 1)")->execute([$p['patient_id'], $d['doc_id'], $d['date'], $d['time'], $qnum]);
        $pdo->prepare("DELETE FROM telegram_sessions WHERE telegram_user_id=?")->execute([$uid]);
        $pdo->commit();
        $txt = "✅ *Booked!*\n👨‍⚕️ Dr. {$d['doc_name']}\n📆 {$d['disp_date']}\n⏰ {$d['disp_time']}\n🎫 Queue #{$qnum}\n📌 Arrive 10 min early.";
        return tgMsg($cid, $mid, $txt, $tok, createKb([[['text'=>'📋 My Appts','callback_data'=>'menu:appt'], ['text'=>'🏠 Menu','callback_data'=>'menu:home']]]));
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('[BOT] Booking failed: '.$e->getMessage());
        return tgMsg($cid, $mid, "❌ Failed. Retry.", $tok, createKb([[['text'=>'🔄 Retry','callback_data'=>'menu:book'], ['text'=>'🏠 Menu','callback_data'=>'menu:home']]]));
    }
}

// ========== MAIN ROUTER ==========

// UNLINKED USER - EMAIL ONLY LOOKUP
if (!$patient) {
    if ($text === '/start') {
        try { $pdo->prepare("REPLACE INTO telegram_sessions (telegram_user_id, step) VALUES (?, 'wait_email')")->execute([$uid]); } catch (Exception $e) {}
        return tgSend($chat_id, "🏥 *Welcome*\n\nLink your account by entering your **registered email**:", $bot_token);
    } elseif ($session && $session['step'] === 'wait_email') {
        $email = trim($text);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return tgSend($chat_id, "❌ Invalid email format.", $bot_token);
        }
        try {
            // Lookup by EMAIL only (using your UNIQUE email constraint)
            $f = $pdo->prepare("SELECT patient_id, first_name, last_name FROM patients WHERE email = ? LIMIT 1");
            $f->execute([$email]);
            $found = $f->fetch();
            if ($found) {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE patients SET telegram_user_id = ?, telegram_linked_at = NOW() WHERE patient_id = ?")->execute([$uid, $found['patient_id']]);
                $pdo->prepare("DELETE FROM telegram_sessions WHERE telegram_user_id = ?")->execute([$uid]);
                $pdo->commit();
                return showMenu($chat_id, null, $bot_token, $found, "✅ *Linked!*\nWelcome, {$found['first_name']}! 👋");
            }
        } catch (PDOException $e) { $pdo->rollBack(); }
        return tgSend($chat_id, "❌ *Not found*\nNo patient with email: `{$email}`", $bot_token);
    }
    http_response_code(200); exit;
}

// CALLBACK ROUTER
if ($is_callback) {
    $cb = $update['callback_query']['data'] ?? '';
    
    if (strpos($cb, 'menu:') === 0) {
        $cmd = substr($cb, 5);
        if ($cmd === 'home') { return showMenu($chat_id, $msg_id, $bot_token, $patient); }
        if ($cmd === 'appt') { return showAppts($chat_id, $msg_id, $bot_token, $pdo, $patient); }
        if ($cmd === 'next') { return showNext($chat_id, $msg_id, $bot_token, $pdo, $patient); }
        if ($cmd === 'queue') { return showQueue($chat_id, $msg_id, $bot_token, $pdo, $patient); }
        if ($cmd === 'prof') { return showProf($chat_id, $msg_id, $bot_token, $patient); }
        if ($cmd === 'book') { return startBook($chat_id, $msg_id, $bot_token, $pdo, $uid); }
        if ($cmd === 'help') { return showHelp($chat_id, $msg_id, $bot_token); }
    }
    if (strpos($cb, 'doc:') === 0) { return selDoc((int)substr($cb, 4), $chat_id, $msg_id, $bot_token, $pdo, $patient, $uid); }
    if (strpos($cb, 'date:') === 0) { return selDate(substr($cb, 5), $chat_id, $msg_id, $bot_token, $pdo, $patient, $uid, $session); }
    if (strpos($cb, 'time:') === 0) { return selTime(substr($cb, 5), $chat_id, $msg_id, $bot_token, $pdo, $patient, $uid, $session); }
    if ($cb === 'conf:book') { return confBook($chat_id, $msg_id, $bot_token, $pdo, $patient, $uid, $session); }
    if ($cb === 'menu:home') { return showMenu($chat_id, $msg_id, $bot_token, $patient); }
    
    http_response_code(200); exit;
}

// TEXT COMMAND ROUTER
$cmds = ['/start'=>'home', '/book'=>'book', '/appt'=>'appt', '/next'=>'next', '/queue'=>'queue', '/profile'=>'prof', '/help'=>'help'];
if (isset($cmds[$text])) {
    $cmd = $cmds[$text];
    if ($cmd === 'home') { return showMenu($chat_id, null, $bot_token, $patient); }
    if ($cmd === 'appt') { return showAppts($chat_id, null, $bot_token, $pdo, $patient); }
    if ($cmd === 'next') { return showNext($chat_id, null, $bot_token, $pdo, $patient); }
    if ($cmd === 'queue') { return showQueue($chat_id, null, $bot_token, $pdo, $patient); }
    if ($cmd === 'prof') { return showProf($chat_id, null, $bot_token, $patient); }
    if ($cmd === 'book') { return startBook($chat_id, null, $bot_token, $pdo, $uid); }
    if ($cmd === 'help') { return showHelp($chat_id, null, $bot_token); }
}

// ========== FIX #2: Feedback for unknown commands ==========
if (str_starts_with($text, '/')) {
    return tgSend($chat_id, "🤖 Unknown command. Type /help for available commands.", $bot_token);
}

// BOOKING TEXT FALLBACKS
if ($session) {
    $sd = json_decode($session['data_json'] ?? '{}', true);
    if ($session['step'] === 'booking_doc' && is_numeric($text) && $text > 0) {
        $docs = getDocs($pdo); $c = (int)$text;
        if ($c <= count($docs)) { return selDoc($docs[$c-1]['doctor_id'], $chat_id, $msg_id, $bot_token, $pdo, $patient, $uid); }
    } elseif ($session['step'] === 'booking_date' && strtolower($text) !== 'cancel') {
        $obj = DateTime::createFromFormat('Y-m-d', $text);
        $tom = new DateTime('tomorrow');
        if ($obj && $obj >= $tom) { return selDate($obj->format('Y-m-d'), $chat_id, $msg_id, $bot_token, $pdo, $patient, $uid, $session); }
    } elseif ($session['step'] === 'booking_time' && is_numeric($text)) {
        $slots = $sd['slots'] ?? []; $c = (int)$text;
        if ($c > 0 && $c <= count($slots)) { return selTime($slots[$c-1]['time'], $chat_id, $msg_id, $bot_token, $pdo, $patient, $uid, $session); }
    } elseif ($session['step'] === 'booking_conf') {
        if (strtolower($text) === 'confirm') { return confBook($chat_id, $msg_id, $bot_token, $pdo, $patient, $uid, $session); }
    }
}

http_response_code(200);
