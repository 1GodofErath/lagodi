<?php
session_start();
require __DIR__ . '/../db.php';

// Перевірка авторизації та ролі
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'junior_admin'])) {
    header("Location: /../login.php");
    exit();
}

// Перевірка CSRF токену
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    die("Помилка безпеки: Недійсний CSRF токен");
}

// Перевірка параметрів запиту
if (!isset($_POST['order_id']) || !isset($_POST['status'])) {
    header("Location: admin_dashboard.php?section=orders&error=missing_parameters");
    exit();
}

$orderId = intval($_POST['order_id']);
$newStatus = trim($_POST['status']);
$userId = $_SESSION['user_id'];

try {
    // Перевіряємо, чи замовлення існує і отримуємо його поточний статус
    $checkQuery = "SELECT id, status FROM orders WHERE id = ?";
    $checkStmt = $conn->prepare($checkQuery);
    $checkStmt->bind_param("i", $orderId);
    $checkStmt->execute();
    $result = $checkStmt->get_result();

    if ($result->num_rows === 0) {
        throw new Exception("Замовлення не знайдено");
    }

    $orderData = $result->fetch_assoc();
    $currentStatus = $orderData['status'];

    // Перевіряємо права доступу в залежності від ролі
    if ($_SESSION['role'] === 'junior_admin') {
        // Молодший адмін не може змінювати статус завершених замовлень
        $blockedStatuses = ['Завершено', 'Виконано', 'Не можливо виконати'];
        if (in_array($currentStatus, $blockedStatuses)) {
            header("Location: admin_dashboard.php?section=orders&error=status_blocked");
            exit();
        }
    }

    // Якщо статус не змінився, просто повертаємось назад
    if ($currentStatus === $newStatus) {
        header("Location: admin_dashboard.php?section=orders&id=$orderId");
        exit();
    }

    // Оновлюємо статус замовлення
    $updateQuery = "UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ?";
    $updateStmt = $conn->prepare($updateQuery);
    $updateStmt->bind_param("si", $newStatus, $orderId);

    if (!$updateStmt->execute()) {
        throw new Exception("Помилка при оновленні статусу: " . $updateStmt->error);
    }

    // Додаємо запис в історію статусів
    $historyQuery = "INSERT INTO status_history (order_id, user_id, previous_status, new_status, created_at) 
                    VALUES (?, ?, ?, ?, NOW())";
    $historyStmt = $conn->prepare($historyQuery);
    $historyStmt->bind_param("iiss", $orderId, $userId, $currentStatus, $newStatus);
    $historyStmt->execute();

    // Додаємо запис в лог системи
    $logQuery = "INSERT INTO logs (user_id, action, created_at) VALUES (?, ?, NOW())";
    $logStmt = $conn->prepare($logQuery);
    $action = "Змінив статус замовлення #$orderId з \"$currentStatus\" на \"$newStatus\"";
    $logStmt->bind_param("is", $userId, $action);
    $logStmt->execute();

    // Перенаправляємо назад з повідомленням про успіх
    header("Location: admin_dashboard.php?section=orders&id=$orderId&success=status_updated");
    exit();

} catch (Exception $e) {
    header("Location: admin_dashboard.php?section=orders&error=" . urlencode($e->getMessage()));
    exit();
}