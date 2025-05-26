<?php
session_start();

// Перевірка прав доступу - тільки адміністратори можуть змінювати відповідального
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: admin_dashboard.php?section=orders&error=handler_blocked");
    exit();
}

// Перевірка CSRF токена
if (!isset($_GET['csrf_token']) || $_GET['csrf_token'] !== $_SESSION['csrf_token']) {
    header("Location: admin_dashboard.php?section=orders&error=invalid_token");
    exit();
}

// Перевірка наявності ідентифікатора замовлення та ідентифікатора нового обробника
if (!isset($_GET['order_id']) || !isset($_GET['handler_id'])) {
    header("Location: admin_dashboard.php?section=orders&error=missing_parameters");
    exit();
}

$orderId = (int)$_GET['order_id'];
$handlerId = (int)$_GET['handler_id']; // Якщо 0, то знімаємо відповідального

// Підключення до бази даних
require __DIR__ . '/../db.php';

try {
    // Отримуємо поточні дані замовлення для логування
    $getOrderStmt = $conn->prepare("SELECT handler_id, (SELECT username FROM users WHERE id = handler_id) as handler_name FROM orders WHERE id = ?");
    $getOrderStmt->bind_param("i", $orderId);
    $getOrderStmt->execute();
    $orderData = $getOrderStmt->get_result()->fetch_assoc();
    
    $oldHandlerId = $orderData['handler_id'];
    $oldHandlerName = $orderData['handler_name'] ?? 'Не призначено';
    
    // Отримуємо ім'я нового відповідального для логування
    $newHandlerName = 'Не призначено';
    if ($handlerId > 0) {
        $getNewHandlerStmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
        $getNewHandlerStmt->bind_param("i", $handlerId);
        $getNewHandlerStmt->execute();
        $newHandlerData = $getNewHandlerStmt->get_result()->fetch_assoc();
        $newHandlerName = $newHandlerData['username'] ?? 'Не призначено';
    }

    // Оновлюємо відповідального за замовлення
    if ($handlerId > 0) {
        $stmt = $conn->prepare("UPDATE orders SET handler_id = ? WHERE id = ?");
        $stmt->bind_param("ii", $handlerId, $orderId);
    } else {
        // Якщо 0, то знімаємо відповідального
        $stmt = $conn->prepare("UPDATE orders SET handler_id = NULL WHERE id = ?");
        $stmt->bind_param("i", $orderId);
    }
    
    $stmt->execute();

    // Логуємо дію
    $logQuery = "INSERT INTO logs (user_id, action, created_at) VALUES (?, ?, NOW())";
    $logStmt = $conn->prepare($logQuery);
    $action = "Змінив відповідального за замовлення #$orderId з '$oldHandlerName' на '$newHandlerName'";
    $logStmt->bind_param("is", $_SESSION['user_id'], $action);
    $logStmt->execute();

    // Перенаправляємо назад з повідомленням про успіх
    header("Location: admin_dashboard.php?section=orders&id=$orderId&success=handler_changed");
} catch (Exception $e) {
    header("Location: admin_dashboard.php?section=orders&error=" . urlencode($e->getMessage()));
}
?>