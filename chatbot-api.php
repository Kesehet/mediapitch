<?php

require_once __DIR__ . '/chatbot-lib.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed.']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new InvalidArgumentException('Invalid request body.');
    }

    $action = isset($input['action']) ? $input['action'] : 'message';
    $sessionId = chatbot_session_id(isset($input['session_id']) ? $input['session_id'] : '');
    $session = chatbot_read_session($sessionId);
    if (!$session) {
        $session = chatbot_new_session($sessionId);
    }

    if (!empty($input['page'])) {
        $session['page'] = substr((string) $input['page'], 0, 500);
    }

    if ($action === 'finalize') {
        $sent = chatbot_email_session($session);
        if ($sent) {
            $session['email_sent'] = true;
            $session['email_sent_at'] = date('c');
            chatbot_write_session($session);
        }

        echo json_encode(['ok' => true, 'session_id' => $sessionId, 'email_sent' => $sent]);
        exit;
    }

    $message = trim(isset($input['message']) ? (string) $input['message'] : '');
    if ($message === '') {
        throw new InvalidArgumentException('Message is required.');
    }

    $messageLength = function_exists('mb_strlen') ? mb_strlen($message) : strlen($message);
    if ($messageLength > 1500) {
        throw new InvalidArgumentException('Message is too long.');
    }

    $session['messages'][] = [
        'role' => 'user',
        'content' => $message,
        'time' => date('c'),
    ];

    $recentMessages = array_slice($session['messages'], -12);
    $reply = chatbot_gemini_generate(chatbot_contents_from_messages($recentMessages));

    $session['messages'][] = [
        'role' => 'assistant',
        'content' => $reply,
        'time' => date('c'),
    ];

    chatbot_write_session($session);

    echo json_encode([
        'ok' => true,
        'session_id' => $sessionId,
        'reply' => $reply,
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ]);
}
