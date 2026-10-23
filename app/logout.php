<?php

require_once __DIR__ . '/config.php';
Logger::security('user_logout', [
    'user_id' => $_SESSION['uid'] ?? null,
    'session_duration' => isset($_SESSION['login_time']) ? (time() - $_SESSION['login_time']) : null
]);

destroy_session();
redirect('/login.php');
