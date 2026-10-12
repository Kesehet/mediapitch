<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('~^/api/(chat|generate|embed|embeddings|tags|show|ps|version)/?$~', $path, $m)) {
    $_GET['endpoint'] = $m[1];
} elseif (preg_match('~^/v1/(models|chat/completions|completions|embeddings|responses)/?$~', $path, $m)) {
    $_GET['endpoint'] = 'v1/' . $m[1];
} else {
    http_response_code(404);
    echo json_encode(['error'=>'Not found']);
    return true;
}
require dirname(__DIR__) . '/api.php';
