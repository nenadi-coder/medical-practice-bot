<?php
// Minimal test bot
$bot_token = '8330456846:AAFJFM3cy7rbKr5diPbcYi8QaIDDIhktpVU';

// Log all requests
file_put_contents('/tmp/bot_test.log', date('Y-m-d H:i:s') . " - Request received\n", FILE_APPEND);

$content = file_get_contents('php://input');
file_put_contents('/tmp/bot_test.log', "Input: " . $content . "\n", FILE_APPEND);

$update = json_decode($content, true);

if (!$update || !isset($update['message'])) {
    file_put_contents('/tmp/bot_test.log', "No valid message\n", FILE_APPEND);
    http_response_code(200);
    exit();
}

$chat_id = $update['message']['chat']['id'];
$text = trim($update['message']['text'] ?? '');

file_put_contents('/tmp/bot_test.log', "Chat ID: $chat_id, Text: $text\n", FILE_APPEND);

// Send a simple response
$url = "https://api.telegram.org/bot{$bot_token}/sendMessage";
$data = [
    'chat_id' => $chat_id,
    'text' => "✅ Bot is working! You said: " . $text
];

$options = [
    'http' => [
        'header' => "Content-type: application/x-www-form-urlencoded\r\n",
        'method' => 'POST',
        'content' => http_build_query($data)
    ]
];

$context = stream_context_create($options);
$result = @file_get_contents($url, false, $context);

file_put_contents('/tmp/bot_test.log', "Response sent\n", FILE_APPEND);

http_response_code(200);
?>
