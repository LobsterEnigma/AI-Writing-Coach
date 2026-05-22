<?php

declare(strict_types=1);

require __DIR__ . '/src/autoload.php';

$config = require __DIR__ . '/config/app.php';
if (! defined('APP_DEBUG')) {
    define('APP_DEBUG', ! empty($config['debug']));
}

if (! empty($config['security']['session_cookie'])) {
    session_name($config['security']['session_cookie']);
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

