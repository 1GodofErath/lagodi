<?php
session_start();
require __DIR__ . '/../db.php';

if (!isset($_SESSION['user_id'])) {
    die("Доступ заборонено");
}

if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    die("CSRF помилка");
}

$order_id = $_POST['order_id'];
$new_status = $_POST['status'];

// Додано перевірку поточного статусу
try {
    $stmt = $conn->prepare("SELECT status FROM orders WHERE id = ?");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $order = $result->fetch_assoc();

    if ($order['status'] === 'Завершено') {
        die("Неможливо змінити статус завершеного замовлення");
    }
} catch (Exception $e) {
    die("Помилка перевірки статусу: " . $e->getMessage());
}

if ($_SESSION['role'] === 'junior_admin' && $new_status === 'Завершено') {
    die("Junior admin не може встановлювати статус 'Завершено'");
}

try {
    $stmt = $conn->prepare("UPDATE orders SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $new_status, $order_id);
    $stmt->execute();

    if ($_SESSION['role'] === 'admin') {
        $action = "Оновлено статус замовлення #$order_id на '$new_status'";
        $log_stmt = $conn->prepare("INSERT INTO logs (user_id, action) VALUES (?, ?)");
        $log_stmt->bind_param("is", $_SESSION['user_id'], $action);
        $log_stmt->execute();
    }

    // Оновлено перенаправлення з параметром section
    $section = $_POST['section'] ?? 'orders';
    header("Location: /admin_panel/admin_dashboard.php?section=$section");
    exit();

} catch (Exception $e) {
    die("Помилка оновлення статусу: " . $e->getMessage());
}
?>