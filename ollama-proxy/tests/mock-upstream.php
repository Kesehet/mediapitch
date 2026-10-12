<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
if ($path === '/api/tags') {
    echo '{"models":[{"name":"test:cloud","model":"test:cloud"}]}';
} elseif ($path === '/api/show') {
    echo '{"capabilities":["tools"],"model_info":{"general.architecture":"llama","llama.context_length":32768,"general.basename":"test"}}';
} elseif ($path === '/v1/models') {
    echo '{"object":"list","data":[{"id":"test:cloud","object":"model"}]}';
} elseif ($path === '/v1/chat/completions' || $path === '/api/chat') {
    $request = json_decode(file_get_contents('php://input'), true);
    if (($request['stream'] ?? false) === true) {
        header('Content-Type: text/event-stream');
        echo "data: {\"choices\":[{\"delta\":{\"content\":\"Hi\"}}]}\n\n";
        echo "data: [DONE]\n\n";
    } else {
        echo '{"choices":[{"message":{"content":"Hi"}}]}';
    }
} else {
    http_response_code(404);
    echo '{"error":"Unknown upstream route"}';
}
