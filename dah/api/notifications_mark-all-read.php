<?php
// Підключення необхідних файлів
require_once $_SERVER['DOCUMENT_ROOT'] . '/confi/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/include/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/include/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/include/session.php';

// Перевірка авторизації
if (!isLoggedIn()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Необхідно авторизуватися']);
    exit;
}

// Отримання ID поточного користувача
$user_id = getCurrentUserId();

// Позначаємо всі повідомлення як прочитані
$result = markAllNotificationsAsRead($user_id);

// Повертаємо результат
header('Content-Type: application/json');
echo json_encode(['success' => $result]);
?>