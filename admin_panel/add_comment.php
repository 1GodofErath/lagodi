<?php
session_start();
require __DIR__ . '/../db.php';

if (!isset($_SESSION['user_id'])) {
    die("Не авторизовано");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("Некоректний метод");
}

if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    die("CSRF помилка");
}

$order_id = (int)$_POST['order_id'];
$content = trim($_POST['content']);


// Додано перевірку статусу замовлення
try {
    $stmt = $conn->prepare("SELECT status FROM orders WHERE id = ?");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $order = $result->fetch_assoc();

    if ($order['status'] === 'Завершено') {
        die("Неможливо додати коментар до завершеного замовлення");
    }
} catch (Exception $e) {
    die("Помилка перевірки статусу: " . $e->getMessage());
}

if (empty($content)) {
    die("Пустий коментар");
}

try {
    $stmt = $conn->prepare("INSERT INTO comments (order_id, user_id, content) VALUES (?, ?, ?)");
    $stmt->bind_param("iis", $order_id, $_SESSION['user_id'], $content);
    $stmt->execute();

    // Оновлено перенаправлення з параметром section
    $section = $_POST['section'] ?? 'orders';
    header("Location: /admin_panel/admin_dashboard.php?section=$section");
    exit();

} catch (Exception $e) {
    die("Помилка: " . $e->getMessage());
}
?>

