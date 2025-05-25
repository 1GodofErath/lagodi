<?php
// mark_comment_read.php - API для позначення коментарів як прочитаних (працюючий метод із notifications.php)
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/dah/confi/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dah/include/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dah/include/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dah/include/session.php';

// Створюємо директорію для логів, якщо вона не існує
$log_dir = $_SERVER['DOCUMENT_ROOT'] . '/dah/logs';
if (!is_dir($log_dir)) {
    mkdir($log_dir, 0755, true);
}
$debug_log_file = $_SERVER['DOCUMENT_ROOT'] . '/dah/logs/debug.log';

// Запис початку запиту в лог
file_put_contents($debug_log_file, "2025-05-23 13:32:38 - SYNCHRONIZED API Request started\n", FILE_APPEND);

// Отримуємо поточного користувача
$user = getCurrentUser();
$user_id = $user['id'];

// Запис інформації про користувача
file_put_contents($debug_log_file, "2025-05-23 13:32:38 - Current user: 1GodofErath (ID: $user_id)\n", FILE_APPEND);

try {
    // Підключаємось до бази
    $database = new Database();
    $db = $database->getConnection();

    // Обробка POST запиту
    $data = json_decode(file_get_contents('php://input'), true);

    // Отримуємо параметри з POST або JSON даних
    $mark_all = isset($_POST['mark_all']) ? $_POST['mark_all'] : (isset($data['mark_all']) ? $data['mark_all'] : false);
    $comment_id = isset($_POST['comment_id']) ? $_POST['comment_id'] : (isset($data['comment_id']) ? $data['comment_id'] : null);
    $user_id = isset($_POST['user_id']) ? $_POST['user_id'] : (isset($data['user_id']) ? $data['user_id'] : $user_id);

    // Логуємо отримані параметри
    file_put_contents($debug_log_file, "2025-05-23 13:32:38 - Parameters: mark_all=" . ($mark_all ? 'true' : 'false') . ", comment_id=" . ($comment_id ? $comment_id : 'null') . ", user_id=$user_id\n", FILE_APPEND);

    if ($comment_id) {
        // Позначаємо один коментар як прочитаний
        $query = "UPDATE comments SET is_read = 1 WHERE id = :comment_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':comment_id', $comment_id, PDO::PARAM_INT);
        $result = $stmt->execute();
        $affected = $stmt->rowCount();

        file_put_contents($debug_log_file, "2025-05-23 13:32:38 - Mark single comment result: " . ($result ? 'success' : 'failed') . ", affected: $affected\n", FILE_APPEND);

        // Повертаємо результат
        header('Content-Type: application/json');
        echo json_encode([
            'success' => $result,
            'affected' => $affected,
            'message' => $result ? 'Коментар позначено як прочитаний' : 'Помилка при оновленні коментаря',
            'timestamp' => '2025-05-23 13:32:38'
        ]);
    }
    elseif ($mark_all) {
        // ВИКОРИСТОВУЄМО МЕТОД ЯКИЙ 100% ПРАЦЮЄ В notifications.php

        // Отримуємо всі замовлення користувача
        $query_orders = "SELECT id FROM orders WHERE user_id = :user_id";
        $stmt_orders = $db->prepare($query_orders);
        $stmt_orders->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt_orders->execute();
        $order_ids = $stmt_orders->fetchAll(PDO::FETCH_COLUMN);

        file_put_contents($debug_log_file, "2025-05-23 13:32:38 - Found orders: " . json_encode($order_ids) . "\n", FILE_APPEND);

        // Оновлюємо статус для всіх коментарів користувача
        $success = true;
        $affected_total = 0;

        if (!empty($order_ids)) {
            foreach ($order_ids as $order_id) {
                $query = "UPDATE comments SET is_read = 1 WHERE order_id = :order_id AND is_read = 0";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':order_id', $order_id, PDO::PARAM_INT);
                $result = $stmt->execute();

                if ($result) {
                    $affected_total += $stmt->rowCount();
                    file_put_contents($debug_log_file, "2025-05-23 13:32:38 - Updated comments for order $order_id: {$stmt->rowCount()}\n", FILE_APPEND);
                } else {
                    $success = false;
                    file_put_contents($debug_log_file, "2025-05-23 13:32:38 - Failed to update comments for order $order_id\n", FILE_APPEND);
                }
            }
        }

        file_put_contents($debug_log_file, "2025-05-23 13:32:38 - Mark all comments result: " . ($success ? 'success' : 'failed') . ", affected total: $affected_total\n", FILE_APPEND);

        // Повертаємо результат
        header('Content-Type: application/json');
        echo json_encode([
            'success' => $success,
            'affected' => $affected_total,
            'message' => $success ? "Всі коментарі позначені як прочитані ($affected_total)" : 'Помилка при оновленні коментарів',
            'timestamp' => '2025-05-23 13:32:38'
        ]);
    }
    else {
        // Не вказано необхідні параметри
        file_put_contents($debug_log_file, "2025-05-23 13:32:38 - Missing required parameters\n", FILE_APPEND);

        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Не вказано необхідні параметри (mark_all або comment_id)',
            'timestamp' => '2025-05-23 13:32:38'
        ]);
    }
} catch (Exception $e) {
    // Логуємо помилку
    file_put_contents($debug_log_file, "2025-05-23 13:32:38 - Error: " . $e->getMessage() . "\n", FILE_APPEND);

    // Повертаємо помилку
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => '2025-05-23 13:32:38'
    ]);
}

// Запис завершення запиту в лог
file_put_contents($debug_log_file, "2025-05-23 13:32:38 - API request completed\n\n", FILE_APPEND);
?>