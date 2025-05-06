<?php
session_start();
require __DIR__ . '/../db.php';

if (!isset($_SESSION['user_id']) || !isset($_POST['csrf_token']) || 
    $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    header("HTTP/1.1 403 Forbidden");
    exit();
}

$order_id = $_POST['order_id'] ?? '';
$details = $_POST['details'] ?? '';
$user_id = $_SESSION['user_id'];

if (empty($order_id) || empty($details)) {
    $_SESSION['error'] = "Всі поля повинні бути заповнені";
    header("Location: dashboard.php");
    exit();
}

try {
    $conn->begin_transaction();

    // Перевірка чи замовлення належить користувачу і чи не закрите
    $stmt = $conn->prepare("
        SELECT id, is_closed 
        FROM orders 
        WHERE id = ? AND user_id = ?
    ");
    $stmt->bind_param("ii", $order_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $order = $result->fetch_assoc();

    if (!$order || $order['is_closed']) {
        throw new Exception("Замовлення недоступне для редагування");
    }

    // Оновлення деталей замовлення
    $stmt = $conn->prepare("
        UPDATE orders 
        SET details = ? 
        WHERE id = ? AND user_id = ?
    ");
    $stmt->bind_param("sii", $details, $order_id, $user_id);
    $stmt->execute();

    // Обробка нових файлів
    if (!empty($_FILES['files']['name'][0])) {
        $upload_dir = 'uploads/orders/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        foreach ($_FILES['files']['tmp_name'] as $key => $tmp_name) {
            $file_name = $_FILES['files']['name'][$key];
            $file_path = $upload_dir . time() . '_' . $file_name;

            if (move_uploaded_file($tmp_name, $file_path)) {
                $stmt = $conn->prepare("
                    INSERT INTO orders_files (order_id, file_name, file_path, uploaded_at) 
                    VALUES (?, ?, ?, NOW())
                ");
                $stmt->bind_param("iss", $order_id, $file_name, $file_path);
                $stmt->execute();
            }
        }
    }

    // Логування зміни
    $log_details = "Відредаговано замовлення #" . $order_id;
    $stmt = $conn->prepare("
        INSERT INTO users_logs (user_id, action, details, created_at) 
        VALUES (?, 'edit_order', ?, NOW())
    ");
    $stmt->bind_param("is", $user_id, $log_details);
    $stmt->execute();

    $conn->commit();
    $_SESSION['success'] = "Замовлення успішно відредаговано";
} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['error'] = "Помилка: " . $e->getMessage();
}

header("Location: dashboard.php");
exit();