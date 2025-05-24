<?php
session_start();
require __DIR__ . '/../db.php';

// Перевірка авторизації та ролі
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'junior_admin'])) {
    header("Location: /../login.php");
    exit();
}

// Перевірка CSRF токену
if (!isset($_GET['csrf_token']) || $_GET['csrf_token'] !== $_SESSION['csrf_token']) {
    die("Помилка безпеки: Недійсний CSRF токен");
}

// Перевірка ID замовлення
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: admin_dashboard.php?section=orders&error=invalid_order_id");
    exit();
}

$orderId = intval($_GET['id']);
$userId = $_SESSION['user_id'];

try {
    // Перевіряємо, чи замовлення існує і чи не заблоковане для редагування
    $checkQuery = "SELECT id, handler_id, status FROM orders WHERE id = ?";
    $checkStmt = $conn->prepare($checkQuery);
    $checkStmt->bind_param("i", $orderId);
    $checkStmt->execute();
    $result = $checkStmt->get_result();

    if ($result->num_rows === 0) {
        throw new Exception("Замовлення не знайдено");
    }

    $orderData = $result->fetch_assoc();

    // Перевіряємо, чи замовлення не має заблокованого статусу
    $blockedStatuses = ['Завершено', 'Виконано', 'Не можливо виконати'];
    if (in_array($orderData['status'], $blockedStatuses)) {
        header("Location: admin_dashboard.php?section=orders&error=status_blocked");
        exit();
    }

    // Перевіряємо, чи замовлення вже не призначено іншому адміністратору
    if (!empty($orderData['handler_id']) && $orderData['handler_id'] != $userId) {
        header("Location: admin_dashboard.php?section=orders&error=already_assigned");
        exit();
    }

    // Призначаємо замовлення поточному адміністратору
    $updateQuery = "UPDATE orders SET handler_id = ? WHERE id = ?";
    $updateStmt = $conn->prepare($updateQuery);
    $updateStmt->bind_param("ii", $userId, $orderId);

    if (!$updateStmt->execute()) {
        throw new Exception("Помилка при оновленні замовлення: " . $updateStmt->error);
    }

    // Додаємо запис в лог системи
    $logQuery = "INSERT INTO logs (user_id, action, created_at) VALUES (?, ?, NOW())";
    $logStmt = $conn->prepare($logQuery);
    $action = "Призначив собі замовлення #$orderId";
    $logStmt->bind_param("is", $userId, $action);
    $logStmt->execute();

    // Якщо замовлення в статусі "Новий", то змінюємо його на "В роботі"
    if ($orderData['status'] === 'Новий') {
        $statusUpdateQuery = "UPDATE orders SET status = 'В роботі', updated_at = NOW() WHERE id = ?";
        $statusUpdateStmt = $conn->prepare($statusUpdateQuery);
        $statusUpdateStmt->bind_param("i", $orderId);
        $statusUpdateStmt->execute();

        // Додаємо запис в історію статусів
        $historyQuery = "INSERT INTO status_history (order_id, user_id, previous_status, new_status, created_at) 
                        VALUES (?, ?, ?, 'В роботі', NOW())";
        $historyStmt = $conn->prepare($historyQuery);
        $prevStatus = $orderData['status'];
        $historyStmt->bind_param("iis", $orderId, $userId, $prevStatus);
        $historyStmt->execute();

        // Додаємо ще один запис в лог
        $logQuery = "INSERT INTO logs (user_id, action, created_at) VALUES (?, ?, NOW())";
        $logStmt = $conn->prepare($logQuery);
        $action = "Змінив статус замовлення #$orderId з \"$prevStatus\" на \"В роботі\"";
        $logStmt->bind_param("is", $userId, $action);
        $logStmt->execute();
    }

    // Перенаправляємо назад з повідомленням про успіх
    header("Location: admin_dashboard.php?section=orders&success=order_assigned");
    exit();

} catch (Exception $e) {
    header("Location: admin_dashboard.php?section=orders&error=" . urlencode($e->getMessage()));
    exit();
}