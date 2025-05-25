<?php
require_once 'db.php';

// Налаштування сесії
session_set_cookie_params([
    'lifetime' => 1, // 30 хвилин (1800 секунд)
    'path' => '/',
    'domain' => $_SERVER['HTTP_HOST'],
    'secure' => isset($_SERVER['HTTPS']), // Автовизначення HTTPS
    'httponly' => true,
    'samesite' => 'Strict'
]);

session_start();

// Перевірка активності (30 хв)
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > 1) {
    session_unset();
    session_destroy();
    header("Location: login.php?error=session_expired");
    exit();
}

// Оновлення часу активності при кожному запиті
$_SESSION['LAST_ACTIVITY'] = time();

// Перевірка авторизації
function require_auth() {
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php?error=auth_required");
        exit();
    }
}
?>