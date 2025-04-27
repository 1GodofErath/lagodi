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

// Отримання поточного користувача
$user_id = getCurrentUserId();

// Перевірка, чи заблокований користувач
if (isUserBlocked($user_id)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Ваш обліковий запис заблоковано']);
    exit;
}

// Збираємо дані замовлення
$order_data = [
    'service' => $_POST['service'] ?? '',
    'details' => $_POST['details'] ?? '',
    'phone' => $_POST['phone'] ?? '',
    'delivery_method' => $_POST['delivery_method'] ?? '',
    'address' => $_POST['address'] ?? null,
    'device_type' => $_POST['device_type'] ?? null,
    'user_comment' => $_POST['user_comment'] ?? null,
    'first_name' => $_POST['first_name'] ?? null,
    'last_name' => $_POST['last_name'] ?? null,
    'middle_name' => $_POST['middle_name'] ?? null
];

// Створюємо замовлення
$result = createOrder($user_id, $order_data);

if ($result['success']) {
    $order_id = $result['order_id'];
    
    // Завантаження медіа-файлів, якщо вони є
    if (isset($_FILES['media_files']) && !empty($_FILES['media_files']['name'][0])) {
        $uploaded_files = [];
        $file_count = count($_FILES['media_files']['name']);
        
        for ($i = 0; $i < $file_count; $i++) {
            // Перевірка розміру файлу (максимум 5 МБ)
            if ($_FILES['media_files']['size'][$i] > 5 * 1024 * 1024) {
                continue;
            }
            
            $file = [
                'name' => $_FILES['media_files']['name'][$i],
                'type' => $_FILES['media_files']['type'][$i],
                'tmp_name' => $_FILES['media_files']['tmp_name'][$i],
                'error' => $_FILES['media_files']['error'][$i],
                'size' => $_FILES['media_files']['size'][$i]
            ];
            
            if ($file['error'] === UPLOAD_ERR_OK) {
                $upload_result = saveUploadedFile($file, $order_id, null, $user_id);
                
                if ($upload_result['success']) {
                    $uploaded_files[] = $upload_result;
                }
            }
        }
        
        // Додаємо інформацію про завантажені файли до відповіді
        $result['uploaded_files'] = $uploaded_files;
    }
    
    header('Content-Type: application/json');
    echo json_encode($result);
} else {
    header('Content-Type: application/json');
    echo json_encode($result);
}
?>