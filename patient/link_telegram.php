<?php
session_start();
require_once '../includes/config.php';

// This file handles Telegram linking from the bot
// URL format: link_telegram.php?telegram_user_id=123456&patient_id=1

if (!isset($_GET['telegram_user_id']) || !isset($_GET['patient_id'])) {
    die("Invalid link parameters.");
}

$telegram_user_id = filter_var($_GET['telegram_user_id'], FILTER_VALIDATE_INT);
$patient_id = filter_var($_GET['patient_id'], FILTER_VALIDATE_INT);

if (!$telegram_user_id || !$patient_id) {
    die("Invalid parameters.");
}

// Optional: Check if patient is logged in or add security token
// For now, updates the patient record

$stmt = $pdo->prepare("UPDATE patients SET telegram_user_id = ? WHERE patient_id = ?");
$stmt->execute([$telegram_user_id, $patient_id]);

if ($stmt->rowCount() > 0) {
    echo "✅ Telegram account linked successfully! You can now close this window.";
} else {
    echo "❌ Failed to link account. Patient not found.";
}
?>
