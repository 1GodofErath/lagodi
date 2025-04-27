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
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Невірний метод запиту']);
    exit;
}

// Отримання даних запиту
$order_id = intval($_GET['order_id'] ?? 0);
$user_id = getCurrentUserId();

// Перевірка наявності необхідних даних
if ($order_id <= 0) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Невірні параметри']);
    exit;
}

// Перевірка доступу до замовлення
$database = new Database();
$db = $database->getConnection();

$query = "SELECT id FROM orders WHERE id = :order_id AND (user_id = :user_id OR (SELECT role FROM users WHERE id = :user_id) IN ('admin', 'junior_admin'))";
$stmt = $db->prepare($query);
$stmt->bindParam(':order_id', $order_id);
$stmt->bindParam(':user_id', $user_id);
$stmt->execute();

if (!$stmt->fetch()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'У вас немає доступу до цього замовлення']);
    exit;
}

// Отримання коментарів
$comments = getOrderComments($order_id);

// Повертаємо результат
header('Content-Type: application/json');
echo json_encode(['success' => true, 'comments' => $comments]);
?>