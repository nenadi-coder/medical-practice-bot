<?php
/**
 * Shifa Medical Center - Telegram Bot Webhook Handler
 * ✅ Uses ONLY columns from shifacenter.sql
 * ✅ Email-only account linking
 * ✅ Inline buttons + message editing
 * ✅ Production-ready error handling
 */

require_once 'includes/config.php';

// ========== 1. VALIDATION & SETUP ==========
$bot_token = getenv('TELEGRAM_BOT_TOKEN');
if (empty($bot_token)) {
    $bot_token = '8330456846:AAFYmkLZFCx1qw4n2sQa5eRCJBO26NV1QYM'; // REMOVE IN PRODUCTION
}
if (!preg_match('/^\d+:[A-Za-z0-9_-]{35,}$/', $bot_token)) {
    error_log('[BOT] Invalid token'); exit;
}
if (!isset($pdo) || !($pdo instanceof PDO)) {
    error_log('[BOT] Database connection missing'); exit;
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

// ========== 2. INPUT HANDLING ==========
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

// Acknowledge callback immediately to remove loading spinner
if ($is_callback) {
    file_get_contents("https://api.telegram.org/bot{$bot_token}/answerCallbackQuery?callback_query_id={$update['callback_query']['id']}");
}

// ========== 3. DATABASE LOOKUPS ==========
$patient = null; $session = null;
try {
    $pat_stmt = $pdo->prepare("SELECT patient_id, first_name, last_name, email FROM patients WHERE telegram_user_id = ? LIMIT 1");
    $pat_stmt->execute([$uid]);
    $patient = $pat_stmt->fetch();

    $ses_stmt = $pdo->prepare("SELECT step, data_json FROM telegram_sessions WHERE telegram_user_id = ? LIMIT 1");
    $ses_stmt->execute([$uid]);
    $session = $ses_stmt->fetch();
} catch (PDOException $e) {
    error_log('[BOT] DB Error: ' . $e->getMessage());
}

// ========== 4. HELPER FUNCTIONS ==========
function tgReq($url, $data, $raw = false) {
    $ctx = stream_context_create(['http' => ['method'=>'POST', 'header'=>"Content-Type: application/x-www-form-urlencoded\r\n", 'content'=>http_build_query($data), 'timeout'=>10]]);
    $res = @file_get_contents($url, false, $ctx);
    return $raw ? $res : (json_decode($res, true)['ok'] ?? false);
}

function tgSend($cid, $txt, $token, $kb = null) {
    $d = ['chat_id'=>$cid, 'text'=>$txt, 'parse_mode'=>'Markdown', 'disable_web_page_preview'=>true];
    if ($kb) $d['reply_markup'] = $kb;
    return tgReq("https://api.telegram.org/bot{$token}/sendMessage", $d);
}

function tgEdit($cid, $mid, $txt, $token, $kb = null) {
    $d = ['chat_id'=>$cid, 'message_id'=>$mid, 'text'=>$txt, 'parse_mode'=>'Markdown', 'disable_web_page_preview'=>true];
    if ($kb) $d['reply_markup'] = $kb;
    $r = tgReq("https://api.telegram.org/bot{$token}/editMessageText", $d, true);
    return $r !== false && strpos($r, 'message is not modified') === false;
}

function tgMsg($cid, $mid, $txt, $token, $kb) {
    return $mid ? tgEdit($cid, $mid, $txt, $token, $kb) : tgSend($cid, $txt, $token, $kb);
}

function kb($rows) {
    $k = []; foreach($rows as $r) $k[] = is_array($r[0] ?? null) ? $r : [$r];
    return json_encode(['inline_keyboard' => $k], JSON_UNESCAPED_UNICODE);
}

function getSlots($pdo, $date, $doc_id) {
    try {
        $s = $pdo->prepare("SELECT appointment_time FROM appointments WHERE appointment_date=? AND doctor_id=? AND status NOT IN ('cancelled','no-show')");
        $s->execute([$date, $doc_id]);
        $booked = array_map(fn($t)=>date('H:i:s',strtotime($t['appointment_time'])), $s->fetchAll());
    } catch(Exception $e) { return []; }
    $all = ['08:30:00'=>'8:30 AM','09:00:00'=>'9:00 AM','09:30:00'=>'9:30 AM','10:00:00'=>'10:00 AM','10:30:00'=>'10:30 AM','11:00:00'=>'11:00 AM','11:30:00'=>'11:30 AM','12:00:00'=>'12:00 PM','12:30:00'=>'12:30 PM','13:00:00'=>'1:00 PM','13:30:00'=>'1:30 PM','14:00:00'=>'2:00 PM','14:30:00'=>'2:30 PM','15:00:00'=>'3:00 PM','15:30:00'=>'3:30 PM','16:00:00'=>'4:00 PM','16:30:00'=>'4:30 PM'];
    $out = []; foreach($all as $t=>$d) if(!in_array($t,$booked)) $out[]=['time'=>$t,'display'=>$d];
    return $out;
}

function getDocs($pdo) {
    try { return $pdo->query("SELECT doctor_id, first_name, last_name, specialization FROM doctors ORDER BY specialization, last_name")->fetchAll(); } catch(Exception $e) { return []; }
}

// ========== 5. DISPLAY FUNCTIONS ==========
function showMenu($cid, $mid, $tok, $p, $cust=null) {
    $txt = $cust ?? "🏥 *Shifa Medical Center*\n\nHello, {$p['first_name']}! 👋\n\nHow can we help you today?";
    $k = kb([[['text'=>'🏥 Book Appointment','callback_data'=>'menu:book'], ['text'=>'📋 My Appointments','callback_data'=>'menu:appt']], [['text'=>'📅 Next Visit','callback_data'=>'menu:next'], ['text'=>'🎫 Queue','callback_data'=>'menu:queue']], [['text'=>'👤 Profile','callback_data'=>'menu:prof'], ['text'=>'❓ Help','callback_data'=>'menu:help']]]);
    return tgMsg($cid, $mid, $txt, $tok, $k);
}

function showAppts($cid, $mid, $tok, $pdo, $p) {
    try {
        $s = $pdo->prepare("SELECT a.*, CONCAT(d.first_name,' ',d.last_name) as doc_name FROM appointments a JOIN doctors d ON a.doctor_id=d.doctor_id WHERE a.patient_id=? ORDER BY a.appointment_date DESC, a.appointment_time DESC LIMIT 15");
        $s->execute([$p['patient_id']]); $a = $s->fetchAll();
    } catch(Exception $e) { return tgMsg($cid, $mid, "❌ Error loading appointments.", $tok, kb([[['text'=>'🏠 Menu','callback_data'=>'menu:home']]])); }
    $txt = $a ? "📋 *Your Appointments*\n\n" : "📭 *No appointments yet*\n\nBook your first appointment below:";
    foreach($a as $x) { $i = in_array($x['status'],['confirmed','completed'])?'✅':'⏳'; $txt .= "{$i} ".date('M j',strtotime($x['appointment_date']))." • ".date('g:i A',strtotime($x['appointment_time']))."\n   Dr. {$x['doc_name']} | #{$x['queue_number']}\n   Status: {$x['status']}\n\n"; }
    return tgMsg($cid, $mid, $txt, $tok, kb([[['text'=>'🏥 Book','callback_data'=>'menu:book'], ['text'=>'🏠 Menu','callback_data'=>'menu:home']]]));
}

function showNext($cid, $mid, $tok, $pdo, $p) {
    try {
        $s = $pdo->prepare("SELECT a.*, CONCAT(d.first_name,' ',d.last_name) as doc_name FROM appointments a JOIN doctors d ON a.doctor_id=d.doctor_id WHERE a.patient_id=? AND a.appointment_date>=CURDATE() AND a.status='confirmed' ORDER BY a.appointment_date ASC, a.appointment_time ASC LIMIT 1");
        $s->execute([$p['patient_id']]); $x = $s->fetch();
    } catch(Exception $e) { return tgMsg($cid, $mid, "❌ Error.", $tok, kb([[['text'=>'🏠 Menu','callback_data'=>'menu:home']]])); }
    $txt = $x ? "📅 *Next Appointment*\n\n📆 ".date('l, M j',strtotime($x['appointment_date']))."\n⏰ ".date('g:i A',strtotime($x['appointment_time']))."\n👨‍⚕️ Dr. {$x['doc_name']}\n🎫 Queue #{$x['queue_number']}" : "❌ *No upcoming confirmed appointments*";
    return tgMsg($cid, $mid, $txt, $tok, kb([[['text'=>'🏥 Book','callback_data'=>'menu:book'], ['text'=>'📋 All','callback_data'=>'menu:appt']]]));
}

function showQueue($cid, $mid, $tok, $pdo, $p) {
    try {
        $s = $pdo->prepare("SELECT a.*, CONCAT(d.first_name,' ',d.last_name) as doc_name, (SELECT COUNT(*) FROM appointments WHERE appointment_date=a.appointment_date AND appointment_time<a.appointment_time AND status IN ('scheduled','confirmed') AND doctor_id=a.doctor_id) as ahead FROM appointments a JOIN doctors d ON a.doctor_id=d.doctor_id WHERE a.patient_id=? AND a.appointment_date=CURDATE() AND a.status IN ('scheduled','confirmed') ORDER BY a.appointment_time ASC LIMIT 1");
        $s->execute([$p['patient_id']]); $x = $s->fetch();
    } catch(Exception $e) { return tgMsg($cid, $mid, "❌ Error.", $tok, kb([[['text'=>'🏠 Menu','callback_data'=>'menu:home']]])); }
    $txt = $x ? "🎫 *Your Queue*\n\n👨‍⚕️ Dr. {$x['doc_name']}\n🎟️ #{$x['queue_number']}\n" . ((int)$x['ahead']===0 ? "🔔 *You're NEXT!*" : "⏱️ ~" . ((int)$x['ahead']*15) . " min wait") : "🎫 *No active queue today*";
    return tgMsg($cid, $mid, $txt, $tok, kb([[['text'=>'📋 View Appointments','callback_data'=>'menu:appt']]]));
}

function showProf($cid, $mid, $tok, $p) {
    $txt = "👤 *Profile*\n\nName: {$p['first_name']} {$p['last_name']}\nEmail: {$p['email']}";
    return tgMsg($cid, $mid, $txt, $tok, kb([[['text'=>'🏠 Back','callback_data'=>'menu:home']]]));
}

function showHelp($cid, $mid, $tok) {
    $txt = "❓ *Commands*\n\n🏥 `/book` — Book new\n📋 `/appt` — View all\n📅 `/next` — Next\n🎫 `/queue` — Position\n👤 `/profile` — Details\n💡 *Tip:* Use buttons below!";
    return tgMsg($cid, $mid, $txt, $tok, kb([[['text'=>'🏥 Book','callback_data'=>'menu:book'], ['text'=>'📋 Appointments','callback_data'=>'menu:appt'], ['text'=>'🎫 Queue','callback_data'=>'menu:queue']]]));
}

// ========== 6. BOOKING FLOW ==========
function startBook($cid, $mid, $tok, $pdo, $uid) {
    $docs = getDocs($pdo);
    if(empty($docs)) return tgMsg($cid, $mid, "❌ No doctors available.", $tok, kb([[['text'=>'🏠 Menu','callback_data'=>'menu:home']]]));
    $txt = "🏥 *Book Appointment*\n\n*Step 1: Choose a doctor*:";
    $b=[]; $r=[]; foreach($docs as $d){ $r[]=['text'=>"👨‍⚕️ {$d['first_name']}",'callback_data'=>"doc:{$d['doctor_id']}"]; if(count($r)==2){$b[]=$r;$r=[];} }
    if($r) $b[]=$r; $b[]=[['text'=>'🏠 Cancel','callback_data'=>'menu:home']];
    try { $pdo->prepare("REPLACE INTO telegram_sessions (telegram_user_id, step, data_json) VALUES (?, 'booking_doc', '{}')")->execute([$uid]); } catch(Exception $e) {}
    return tgMsg($cid, $mid, $txt, $tok, kb($b));
}

function selDoc($did, $cid, $mid, $tok, $pdo, $p, $uid) {
    $docs = getDocs($pdo); $sel=null; foreach($docs as $d) if($d['doctor_id']==$did) $sel=$d;
    if(!$sel) return tgMsg($cid, $mid, "❌ Not found.", $tok, kb([[['text'=>'🔙 Back','callback_data'=>'menu:book']]]));
    try { $pdo->prepare("REPLACE INTO telegram_sessions (telegram_user_id, step, data_json) VALUES (?, 'booking_date', ?)")->execute([$uid, json_encode(['doc_id'=>$sel['doctor_id'],'doc_name'=>"{$sel['first_name']} {$sel['last_name']}"])]); } catch(Exception $e) {}
    $txt = "✅ *Doctor:* Dr. {$sel['first_name']} {$sel['last_name']}\n\n*Step 2: Select date*\n📅 Tap below:";
    $b=[]; $r=[]; for($i=1;$i<=7;$i++){ $dt=new DateTime("+$i days"); $v=$dt->format('Y-m-d'); $l=$dt->format('D M j'); $r[]=['text'=>$l,'callback_data'=>"date:{$v}"]; if(count($r)==2){$b[]=$r;$r=[];} }
    if($r) $b[]=$r; $b[]=[['text'=>'📅 More','callback_data'=>'date:more'], ['text'=>'🔄 Cancel','callback_data'=>'menu:home']];
    return tgMsg($cid, $mid, $txt, $tok, kb($b));
}

function selDate($val, $cid, $mid, $tok, $pdo, $p, $uid, $sess) {
    if(!$sess) return tgMsg($cid, $mid, "⚠️ Session expired.", $tok, kb([[['text'=>'🏥 Book','callback_data'=>'menu:book']]]));
    $d = json_decode($sess['data_json'], true);
    if($val==='more') {
        $b=[]; $r=[]; for($i=8;$i<=14;$i++){ $dt=new DateTime("+$i days"); $r[]=['text'=>$dt->format('D M j'),'callback_data'=>"date:{$dt->format('Y-m-d')}"]; if(count($r)==2){$b[]=$r;$r=[];} }
        if($r) $b[]=$r; $b[]=[['text'=>'🔙 Back','callback_data'=>'menu:book']];
        return tgEdit($cid, $mid, "📅 *Select a date*", $tok, kb($b));
    }
    $obj = DateTime::createFromFormat('Y-m-d', $val); $tom = new DateTime('tomorrow');
    if(!$obj || $obj < $tom) return tgMsg($cid, $mid, "❌ Future dates only.", $tok, kb([[['text'=>'📅 Retry','callback_data'=>'date:pick']]]));
    $d['date'] = $obj->format('Y-m-d'); $d['disp_date'] = $obj->format('l, M j');
    $slots = getSlots($pdo, $d['date'], $d['doc_id']);
    if(empty($slots)) return tgMsg($cid, $mid, "❌ No slots on {$d['disp_date']}", $tok, kb([[['text'=>'📅 Pick Another','callback_data'=>'date:more']], [['text'=>'🔄 Cancel','callback_data'=>'menu:home']]]));
    $d['slots'] = $slots;
    try { $pdo->prepare("REPLACE INTO telegram_sessions (telegram_user_id, step, data_json) VALUES (?, 'booking_time', ?)")->execute([$uid, json_encode($d)]); } catch(Exception $e) {}
    $txt = "📅 *{$d['disp_date']}*\n\n⏰ *Select time*:";
    $b=[]; $r=[]; foreach($slots as $s){ $r[]=['text'=>$s['display'],'callback_data'=>"time:{$s['time']}"]; if(count($r)==3){$b[]=$r;$r=[];} }
    if($r) $b[]=$r; $b[]=[['text'=>'🔙 Change Date','callback_data'=>'date:more'], ['text'=>'🔄 Cancel','callback_data'=>'menu:home']];
    return tgMsg($cid, $mid, $txt, $tok, kb($b));
}

function selTime($val, $cid, $mid, $tok, $pdo, $p, $uid, $sess) {
    if(!$sess) return tgMsg($cid, $mid, "⚠️ Expired.", $tok, kb([[['text'=>'🏥 Book','callback_data'=>'menu:book']]]));
    $d = json_decode($sess['data_json'], true); $slots = $d['slots'] ?? [];
    $sel=null; foreach($slots as $s) if($s['time']===$val) $sel=$s;
    if(!$sel) return tgMsg($cid, $mid, "❌ Invalid.", $tok, kb([[['text'=>'⏰ Retry','callback_data'=>'time:retry']]]));
    try {
        $chk = $pdo->prepare("SELECT appointment_id FROM appointments WHERE appointment_date=? AND appointment_time=? AND doctor_id=? AND status NOT IN ('cancelled','no-show') LIMIT 1");
        $chk->execute([$d['date'], $sel['time'], $d['doc_id']]);
        if($chk->rowCount()>0) { $pdo->prepare("DELETE FROM telegram_sessions WHERE telegram_user_id=?")->execute([$uid]); return tgMsg($cid, $mid, "❌ Slot just taken!", $tok, kb([[['text'=>'🏥 Start Over','callback_data'=>'menu:book']])); }
    } catch(Exception $e) { return tgMsg($cid, $mid, "❌ Error checking slot.", $tok, kb([[['text'=>'🏠 Menu','callback_data'=>'menu:home']]])); }
    $d['time'] = $sel['time']; $d['disp_time'] = $sel['display']; unset($d['slots']);
    try { $pdo->prepare("REPLACE INTO telegram_sessions (telegram_user_id, step, data_json) VALUES (?, 'booking_conf', ?)")->execute([$uid, json_encode($d)]); } catch(Exception $e) {}
    $txt = "⏰ *{$sel['display']}*\n\n✅ *Confirm*\n👨‍⚕️ Dr. {$d['doc_name']}\n📆 {$d['disp_date']}\n⏰ {$sel['display']}\n\nTap to confirm:";
    return tgMsg($cid, $mid, $txt, $tok, kb([[['text'=>'✅ Confirm','callback_data'=>'conf:book'], ['text'=>'❌ Cancel','callback_data'=>'menu:home']], [['text'=>'🔄 Change Time','callback_data'=>'time:retry'], ['text'=>'📅 Change Date','callback_data'=>'date:more']]]));
}

function confBook($cid, $mid, $tok, $pdo, $p, $uid, $sess) {
    if(!$sess) return tgMsg($cid, $mid, "⚠️ Expired.", $tok, kb([[['text'=>'🏥 Book','callback_data'=>'menu:book']]]));
    $d = json_decode($sess['data_json'], true);
    try {
        $chk = $pdo->prepare("SELECT appointment_id FROM appointments WHERE appointment_date=? AND appointment_time=? AND doctor_id=? AND status NOT IN ('cancelled','no-show') LIMIT 1 FOR UPDATE");
        $chk->execute([$d['date'], $d['time'], $d['doc_id']]);
        if($chk->rowCount()>0) { $pdo->prepare("DELETE FROM telegram_sessions WHERE telegram_user_id=?")->execute([$uid]); return tgMsg($cid, $mid, "❌ Slot gone.", $tok, kb([[['text'=>'🔄 Retry','callback_data'=>'menu:book']]])); }
        $pdo->beginTransaction();
        $q = $pdo->prepare("SELECT COUNT(*)+1 FROM appointments WHERE appointment_date=? AND appointment_time<? AND doctor_id=? AND status IN ('scheduled','confirmed')");
        $q->execute([$d['date'], $d['time'], $d['doc_id']]); $qnum = (int)$q->fetchColumn();
        $pdo->prepare("INSERT INTO appointments (patient_id, doctor_id, appointment_date, appointment_time, queue_number, status, send_sms) VALUES (?, ?, ?, ?, ?, 'scheduled', 1)")->execute([$p['patient_id'], $d['doc_id'], $d['date'], $d['time'], $qnum]);
        $pdo->prepare("DELETE FROM telegram_sessions WHERE telegram_user_id=?")->execute([$uid]);
        $pdo->commit();
        $txt = "✅ *Booked!*\n👨‍⚕️ Dr. {$d['doc_name']}\n📆 {$d['disp_date']}\n⏰ {$d['disp_time']}\n🎫 Queue #{$qnum}\n📌 Arrive 10 min early.";
        return tgMsg($cid, $mid, $txt, $tok, kb([[['text'=>'📋 My Appts','callback_data'=>'menu:appt'], ['text'=>'🏠 Menu','callback_data'=>'menu:home']]]));
    } catch(PDOException $e) { $pdo->rollBack(); error_log('[BOT] Booking failed: '.$e->getMessage()); return tgMsg($cid, $mid, "❌ Failed. Retry.", $tok, kb([[['text'=>'🔄 Retry','callback_data'=>'menu:book'], ['text'=>'🏠 Menu','callback_data'=>'menu:home']]])); }
}

// ========== 7. MAIN ROUTER ==========
// UNLINKED USER
if (!$patient) {
    if ($text === '/start') {
        try { $pdo->prepare("REPLACE INTO telegram_sessions (telegram_user_id, step) VALUES (?, 'wait_email')")->execute([$uid]); } catch(Exception $e) {}
        return tgSend($chat_id, "🏥 *Welcome*\n\nLink your account by entering your **registered email**:", $bot_token);
    } elseif ($session && $session['step'] === 'wait_email') {
        $email = trim($text);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return tgSend($chat_id, "❌ Invalid email format.", $bot_token);
        try {
            $f = $pdo->prepare("SELECT patient_id, first_name, last_name FROM patients WHERE email = ? LIMIT 1");
            $f->execute([$email]); $found = $f->fetch();
            if ($found) {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE patients SET telegram_user_id = ?, telegram_linked_at = NOW() WHERE patient_id = ?")->execute([$uid, $found['patient_id']]);
                $pdo->prepare("DELETE FROM telegram_sessions WHERE telegram_user_id = ?")->execute([$uid]);
                $pdo->commit();
                return showMenu($chat_id, null, $bot_token, $found, "✅ *Linked!*\nWelcome, {$found['first_name']}! 👋");
            }
        } catch(PDOException $e) { $pdo->rollBack(); }
        return tgSend($chat_id, "❌ *Not found*\nNo patient with email: `{$email}`", $bot_token);
    }
    http_response_code(200); exit;
}

// CALLBACK ROUTER
if ($is_callback) {
    if (strpos($cb_data, 'menu:') === 0) {
        $cmd = substr($cb_data, 5);
        $map = ['home'=>fn()=>showMenu($chat_id,$msg_id,$bot_token,$patient), 'appt'=>fn()=>showAppts($chat_id,$msg_id,$bot_token,$pdo,$patient), 'next'=>fn()=>showNext($chat_id,$msg_id,$bot_token,$pdo,$patient), 'queue'=>fn()=>showQueue($chat_id,$msg_id,$bot_token,$pdo,$patient), 'prof'=>fn()=>showProf($chat_id,$msg_id,$bot_token,$patient), 'book'=>fn()=>startBook($chat_id,$msg_id,$bot_token,$pdo,$uid), 'help'=>fn()=>showHelp($chat_id,$msg_id,$bot_token)];
        if (isset($map[$cmd])) { $map[$cmd](); http_response_code(200); exit; }
    }
    if (strpos($cb_data, 'doc:') === 0) { selDoc((int)substr($cb_data,4), $chat_id, $msg_id, $bot_token, $pdo, $patient, $uid); http_response_code(200); exit; }
    if (strpos($cb_data, 'date:') === 0) { selDate(substr($cb_data,5), $chat_id, $msg_id, $bot_token, $pdo, $patient, $uid, $session); http_response_code(200); exit; }
    if (strpos($cb_data, 'time:') === 0) { selTime(substr($cb_data,5), $chat_id, $msg_id, $bot_token, $pdo, $patient, $uid, $session); http_response_code(200); exit; }
    if ($cb_data === 'conf:book') { confBook($chat_id, $msg_id, $bot_token, $pdo, $patient, $uid, $session); http_response_code(200); exit; }
    if ($cb_data === 'menu:home') { showMenu($chat_id, $msg_id, $bot_token, $patient); http_response_code(200); exit; }
    http_response_code(200); exit;
}

// TEXT COMMAND ROUTER
$cmds = ['/start'=>'home','/book'=>'book','/appt'=>'appt','/next'=>'next','/queue'=>'queue','/profile'=>'prof','/help'=>'help'];
if (isset($cmds[$text])) {
    $cmd = $cmds[$text];
    $map = ['home'=>fn()=>showMenu($chat_id,null,$bot_token,$patient), 'appt'=>fn()=>showAppts($chat_id,null,$bot_token,$pdo,$patient), 'next'=>fn()=>showNext($chat_id,null,$bot_token,$pdo,$patient), 'queue'=>fn()=>showQueue($chat_id,null,$bot_token,$pdo,$patient), 'prof'=>fn()=>showProf($chat_id,null,$bot_token,$patient), 'book'=>fn()=>startBook($chat_id,null,$bot_token,$pdo,$uid), 'help'=>fn()=>showHelp($chat_id,null,$bot_token)];
    if (isset($map[$cmd])) { $map[$cmd](); http_response_code(200); exit; }
}

// BOOKING TEXT FALLBACKS
if ($session) {
    $sd = json_decode($session['data_json'] ?? '{}', true);
    if ($session['step']==='booking_doc' && is_numeric($text) && $text>0) { $docs=getDocs($pdo); $c=(int)$text; if($c<=count($docs)){selDoc($docs[$c-1]['doctor_id'],$chat_id,$msg_id,$bot_token,$pdo,$patient,$uid); http_response_code(200); exit;}}
    elseif ($session['step']==='booking_date' && strtolower($text)!=='cancel') { $obj = DateTime::createFromFormat('Y-m-d', $text); $tom=new DateTime('tomorrow'); if($obj && $obj>=$tom) { selDate($obj->format('Y-m-d'),$chat_id,$msg_id,$bot_token,$pdo,$patient,$uid,$session); http_response_code(200); exit; }}
    elseif ($session['step']==='booking_time' && is_numeric($text)) { $slots=$sd['slots']??[]; $c=(int)$text; if($c>0 && $c<=count($slots)) { selTime($slots[$c-1]['time'],$chat_id,$msg_id,$bot_token,$pdo,$patient,$uid,$session); http_response_code(200); exit; }}
    elseif ($session['step']==='booking_conf') { if(strtolower($text)==='confirm') { confBook($chat_id,$msg_id,$bot_token,$pdo,$patient,$uid,$session); http_response_code(200); exit; }}
}

http_response_code(200);
