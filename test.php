<?php
file_put_contents('/tmp/test_log.txt', date('Y-m-d H:i:s') . " - Request received\n", FILE_APPEND);

$content = file_get_contents('php://input');
file_put_contents('/tmp/test_log.txt', "Input: " . $content . "\n", FILE_APPEND);

$update = json_decode($content, true);

if ($update && isset($update['message'])) {
    $chat_id = $update['message']['chat']['id'];
    $text = $update['message']['text'];
    
    $url = "https://api.telegram.org/bot8330456846:AAFJFM3cy7rbKr5diPbcYi8QaIDDIhktpVU/sendMessage";
    $data = ['chat_id' => $chat_id, 'text' => "✅ Bot received: " . $text];
    $options = ['http' => ['header' => "Content-type: application/x-www-form-urlencoded\r\n", 'method' => 'POST', 'content' => http_build_query($data)]];
    file_get_contents($url, false, stream_context_create($options));
}

http_response_code(200);
?>
