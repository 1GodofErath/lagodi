<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// Подключение необходимых файлов
require_once '../../dah/confi/database.php';
require_once '../../dah/include/auth.php';

// Проверка авторизации
if (!isLoggedIn()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Логирование для отладки
function logDebug($message) {
    $logFile = __DIR__ . '/notification_debug.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "{$timestamp} - {$message}\n", FILE_APPEND);
}

logDebug("API called by user: " . $_SESSION['user_id']);

// Получение текущего пользователя
$user = getCurrentUser();
$userId = $user['id'];

// Сохраняем запрос для отладки
logDebug("User ID: {$userId}, Request: " . file_get_contents('php://input'));

// Проверка методов запроса и наличия необходимых данных
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Получение данных из POST-запроса
    $data = json_decode(file_get_contents('php://input'), true);

    // Если это запрос на отметку одного уведомления как прочитанного
    if (isset($data['notification_id'])) {
        $notificationId = intval($data['notification_id']);
        logDebug("Marking notification ID {$notificationId} as read");

        // Подключение к БД
        $database = new Database();
        $db = $database->getConnection();

        // Проверка существования уведомления
        $checkQuery = "SELECT * FROM notifications WHERE id = :notification_id AND user_id = :user_id";
        $checkStmt = $db->prepare($checkQuery);
        $checkStmt->bindParam(':notification_id', $notificationId, PDO::PARAM_INT);
        $checkStmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $checkStmt->execute();

        $notification = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$notification) {
            logDebug("Notification ID {$notificationId} not found for user {$userId}");
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Notification not found']);
            exit;
        }

        // Обновление статуса уведомления на "прочитано"
        $query = "UPDATE notifications SET is_read = 1, updated_at = NOW() WHERE id = :notification_id AND user_id = :user_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':notification_id', $notificationId, PDO::PARAM_INT);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);

        $success = $stmt->execute();
        $rowsAffected = $stmt->rowCount();

        logDebug("Notification update result: success={$success}, rows={$rowsAffected}");

        header('Content-Type: application/json');
        echo json_encode([
            'success' => $success,
            'message' => $success ? 'Notification marked as read' : 'Database error',
            'rows' => $rowsAffected
        ]);
    }
    // Если это запрос на отметку всех уведомлений как прочитанных
    else if (isset($data['mark_all']) && $data['mark_all'] === true) {
        logDebug("Marking all notifications as read for user {$userId}");
        // Подключение к БД
        $database = new Database();
        $db = $database->getConnection();

        // Обновление статуса всех уведомлений пользователя
        $query = "UPDATE notifications SET is_read = 1, updated_at = NOW() WHERE user_id = :user_id AND is_read = 0";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);

        $success = $stmt->execute();
        $rowsAffected = $stmt->rowCount();

        logDebug("Mark all result: success={$success}, rows={$rowsAffected}");

        header('Content-Type: application/json');
        echo json_encode([
            'success' => $success,
            'message' => $success ? 'All notifications marked as read' : 'Database error',
            'rows' => $rowsAffected
        ]);
    }
    else {
        logDebug("Invalid request format");
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid request']);
    }
} else {
    logDebug("Invalid request method: " . $_SERVER['REQUEST_METHOD']);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>