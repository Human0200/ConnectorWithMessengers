<?php
const SERVICE_TOKEN = 'ВАШ_BOT_TOKEN';

// Отправка сообщения в Сервис
function sendServiceMessage($chat_id, $text) {
    $url = 'https://api.telegram.org/bot' . SERVICE_TOKEN . '/sendMessage';
    $data = [
        'chat_id' => $chat_id,
        'text' => $text
    ];
    
    $options = [
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/json',
            'content' => json_encode($data)
        ]
    ];
    
    file_get_contents($url, false, stream_context_create($options));
}