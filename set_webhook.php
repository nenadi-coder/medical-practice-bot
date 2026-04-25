<?php


define('BOT_TOKEN', '8330456846:AAHSmyKZrvCL5yLqpHjynBMqC6tM2u9k6N8');
define('SECRET_TOKEN', 'nadia');
define('WEBHOOK_URL', 'https://shifacenter.me/telegram_bot.php');

$url = "https://api.telegram.org/bot" . BOT_TOKEN . "/setWebhook";

$data = [
    'url' => WEBHOOK_URL,
    'secret_token' => SECRET_TOKEN,
    'drop_pending_updates' => true
];

$options = [
    'http' => [
        'header' => "Content-Type: application/json\r\n",
        'method' => 'POST',
        'content' => json_encode($data)
    ]
];

$context = stream_context_create($options);
$result = file_get_contents($url, false, $context);
$response = json_decode($result, true);

echo "<pre>";
if ($response['ok']) {
    echo "✅ Webhook set successfully!\n";
    echo "URL: " . WEBHOOK_URL . "\n";
} else {
    echo "❌ Failed: " . $response['description'] . "\n";
}
echo "</pre>";
?>
