<?php
// Розміщуйте цей файл у директорії /dah/api/
header('Content-Type: application/json');
session_start();

// Підключення необхідних файлів
require_once $_SERVER['DOCUMENT_ROOT'] . '/dah/confi/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dah/include/auth.php';

// Перевірка авторизації
if (!isLoggedIn()) {
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized'
    ]);
    exit;
}

// Отримання поточного користувача
$user = getCurrentUser();

// Функція для отримання сповіщень
function getUserNotificationsApi($userId, $limit = 5) {
    $database = new Database();
    $db = $database->getConnection();

    $query = "SELECT * FROM notifications 
              WHERE user_id = :user_id 
              ORDER BY is_read ASC, created_at DESC 
              LIMIT :limit";

    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Функція для підрахунку непрочитаних сповіщень
function getUnreadNotificationsCountApi($userId) {
    $database = new Database();
    $db = $database->getConnection();

    $query = "SELECT COUNT(*) as unread_count 
              FROM notifications 
              WHERE user_id = :user_id AND is_read = 0";

    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
    $stmt->execute();

    $result = $stmt->fetch();
    return isset($result['unread_count']) ? intval($result['unread_count']) : 0;
}

// Отримання сповіщень
$notifications = getUserNotificationsApi($user['id'], 5);
$unread_count = getUnreadNotificationsCountApi($user['id']);

// Повернення результату
echo json_encode([
    'success' => true,
    'notifications' => $notifications,
    'unread_count' => $unread_count
]);