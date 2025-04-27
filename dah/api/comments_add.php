<?php
// Підключення необхідних файлів
require_once $_SERVER['DOCUMENT_ROOT'] . '/confi/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/include/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/include/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/include/session.php';

// Перевірка авторизації
if (!isLoggedIn()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Необхідно авторизуватися']);
    exit;
}

// Перевірка методу запиту
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Невірний метод запиту']);
    exit;
}

// Отримання даних запиту
$order_id = intval($_POST['order_id'] ?? 0);
$content = trim($_POST['content'] ?? '');
$user_id = getCurrentUserId();

// Перевірка наявності необхідних даних
if ($order_id <= 0 || empty($content)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Невірні параметри']);
    exit;
}

// Перевірка доступу до замовлення
$database = new Database();
$db = $database->getConnection();

$query = "SELECT id FROM orders WHERE id = :order_id AND user_id = :user_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':order_id', $order_id);
$stmt->bindParam(':user_id', $user_id);
$stmt->execute();

if (!$stmt->fetch()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'У вас немає доступу до цього замовлення']);
    exit;
}

// Перевірка наявності файлу
$file_attachment = null;

if (isset($_FILES['file_attachment']) && $_FILES['file_attachment']['error'] === UPLOAD_ERR_OK) {
    // Перевірка розміру файлу (максимум 5 МБ)
    if ($_FILES['file_attachment']['size'] > 5 * 1024 * 1024) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Розмір файлу перевищує допустимий ліміт (5 МБ)']);
        exit;
    }
    
    // Завантажуємо файл
    $upload_result = saveUploadedFile($_FILES['file_attachment'], $order_id, null, $user_id);
    
    if ($upload_result['success']) {
        $file_attachment = $upload_result['file_path'];
    }
}

// Додаємо коментар
$comment_id = addComment($order_id, $user_id, $content, $file_attachment);

if ($comment_id) {
    // Якщо є файл, прив'язуємо його до коментаря
    if (isset($upload_result) && $upload_result['success']) {
        $query = "UPDATE order_files SET comment_id = :comment_id WHERE id = :file_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':comment_id', $comment_id);
        $stmt->bindParam(':file_id', $upload_result['file_id']);
        $stmt->execute();
    }
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true, 
        'message' => 'Коментар успішно додано',
        'comment_id' => $comment_id,
        'file_attachment' => $file_attachment
    ]);
} else {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Помилка при додаванні коментаря']);
}
?>