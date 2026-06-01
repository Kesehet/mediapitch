<?php

function chatbot_load_env($path)
{
    static $loaded = false;

    if ($loaded || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        if (
            strlen($value) >= 2 &&
            (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        $_ENV[$key] = $value;
        putenv($key . '=' . $value);
    }

    $loaded = true;
}

function chatbot_env($key, $default = '')
{
    $value = getenv($key);
    if ($value === false && isset($_ENV[$key])) {
        $value = $_ENV[$key];
    }

    return $value === false || $value === '' ? $default : $value;
}

chatbot_load_env(__DIR__ . DIRECTORY_SEPARATOR . '.env');

