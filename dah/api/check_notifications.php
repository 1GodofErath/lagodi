<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// Подключение необходимых файлов
require_once $_SERVER['DOCUMENT_ROOT'] . '/dah/confi/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dah/include/auth.php';

// Проверка авторизации
if (!isLoggedIn()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Получение текущего пользователя
$user = getCurrentUser();
$userId = $user['id'];

// Проверка на наличие новых уведомлений
$database = new Database();
$db = $database->getConnection();

// Проверка количества непрочитанных уведомлений
$query = "SELECT COUNT(*) as unread_count FROM notifications WHERE user_id = :user_id AND read_at IS NULL";
$stmt = $db->prepare($query);
$stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
$stmt->execute();

$result = $stmt->fetch(PDO::FETCH_ASSOC);
$unreadCount = intval($result['unread_count']);

// Проверка на наличие уведомлений, появившихся после последней проверки
$lastCheckTime = isset($_SESSION['last_notification_check']) ? $_SESSION['last_notification_check'] : null;
$hasNewNotifications = false;
$playSound = false;

if ($lastCheckTime) {
    $query = "SELECT COUNT(*) as new_count FROM notifications 
              WHERE user_id = :user_id AND created_at > :last_check_time";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
    $stmt->bindParam(':last_check_time', $lastCheckTime);
    $stmt->execute();
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $hasNewNotifications = intval($result['new_count']) > 0;
    $playSound = $hasNewNotifications; // Воспроизводить звук только если есть новые уведомления
}

// Обновляем время последней проверки
$_SESSION['last_notification_check'] = date('Y-m-d H:i:s');

// Отправляем результат
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'unread_count' => $unreadCount,
    'has_new' => $unreadCount > 0,
    'has_new_since_last_check' => $hasNewNotifications,
    'play_sound' => $playSound
]);
?>