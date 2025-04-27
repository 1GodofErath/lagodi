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

// Перевірка методу запиту
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Невірний метод запиту']);
    exit;
}

// Отримання даних запиту
$json_data = file_get_contents('php://input');
$data = json_decode($json_data, true);

if (!isset($data['theme']) || !in_array($data['theme'], ['light', 'dark'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Невірні параметри']);
    exit;
}

// Отримання ID поточного користувача
$user_id = getCurrentUserId();

// Зміна теми
$result = changeUserTheme($user_id, $data['theme']);

// Оновлюємо тему в сесії
if ($result['success']) {
    $_SESSION['theme'] = $data['theme'];
}

// Повертаємо результат
header('Content-Type: application/json');
echo json_encode($result);
?>