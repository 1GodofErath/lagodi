<?php
// Підключення файлу з базою даних

// Функція для логування дій користувача
function logUserAction($user_id, $action, $description = null) {
    $database = new Database();
    $db = $database->getConnection();

    $query = "INSERT INTO user_logs (user_id, action, description) VALUES (:user_id, :action, :description)";
    $stmt = $db->prepare($query);

    $stmt->bindParam(':user_id', $user_id);
    $stmt->bindParam(':action', $action);
    $stmt->bindParam(':description', $description);

    return $stmt->execute();
}

// Функція для перевірки чи заблокований користувач
function isUserBlocked($user_id) {
    $database = new Database();
    $db = $database->getConnection();

    $query = "SELECT is_blocked, blocked_until FROM users WHERE id = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();

    $user = $stmt->fetch();

    if ($user && $user['is_blocked'] == 1) {
        return true;
    }

    if ($user && $user['blocked_until'] !== null) {
        $now = new DateTime();
        $blockedUntil = new DateTime($user['blocked_until']);
        if ($now < $blockedUntil) {
            return true;
        }
    }

    return false;
}

// Функція для отримання замовлень користувача
function getUserOrders($user_id, $limit = 10, $offset = 0, $filters = []) {
    $database = new Database();
    $db = $database->getConnection();

    $whereClause = "WHERE user_id = :user_id";
    $params = [':user_id' => $user_id];

    // Додавання фільтрів
    if (!empty($filters)) {
        if (isset($filters['status']) && $filters['status'] != '') {
            $whereClause .= " AND status = :status";
            $params[':status'] = $filters['status'];
        }

        if (isset($filters['service']) && $filters['service'] != '') {
            $whereClause .= " AND service = :service";
            $params[':service'] = $filters['service'];
        }

        if (isset($filters['date_from']) && $filters['date_from'] != '') {
            $whereClause .= " AND created_at >= :date_from";
            $params[':date_from'] = $filters['date_from'] . " 00:00:00";
        }

        if (isset($filters['date_to']) && $filters['date_to'] != '') {
            $whereClause .= " AND created_at <= :date_to";
            $params[':date_to'] = $filters['date_to'] . " 23:59:59";
        }

        if (isset($filters['search']) && $filters['search'] != '') {
            $whereClause .= " AND (id LIKE :search OR service LIKE :search_like OR details LIKE :search_like)";
            $params[':search'] = $filters['search'];
            $params[':search_like'] = '%' . $filters['search'] . '%';
        }
    }

    $query = "SELECT * FROM orders $whereClause ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
    $stmt = $db->prepare($query);

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);

    $stmt->execute();

    return $stmt->fetchAll();
}

// Функція для отримання деталей замовлення
function getOrderDetails($order_id, $user_id = null) {
    $database = new Database();
    $db = $database->getConnection();

    $whereClause = "WHERE o.id = :order_id";
    $params = [':order_id' => $order_id];

    // Якщо вказано user_id, додаємо перевірку доступу
    if ($user_id !== null) {
        $whereClause .= " AND o.user_id = :user_id";
        $params[':user_id'] = $user_id;
    }

    $query = "SELECT o.*, 
              u.username, u.email, u.first_name, u.last_name, u.phone,
              (SELECT COUNT(*) FROM comments WHERE order_id = o.id) as comments_count
              FROM orders o 
              LEFT JOIN users u ON o.user_id = u.id 
              $whereClause";

    $stmt = $db->prepare($query);

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    $stmt->execute();

    return $stmt->fetch();
}

// Функція для отримання коментарів до замовлення
function getOrderComments($order_id) {
    $database = new Database();
    $db = $database->getConnection();

    $query = "SELECT c.*, u.username, u.first_name, u.last_name, u.profile_pic, u.role
              FROM comments c
              LEFT JOIN users u ON c.user_id = u.id
              WHERE c.order_id = :order_id
              ORDER BY c.created_at ASC";

    $stmt = $db->prepare($query);
    $stmt->bindParam(':order_id', $order_id);
    $stmt->execute();

    return $stmt->fetchAll();
}

// Функція для додавання коментаря
function addComment($order_id, $user_id, $content, $file_attachment = null) {
    $database = new Database();
    $db = $database->getConnection();

    $query = "INSERT INTO comments (order_id, user_id, content, file_attachment, created_at) 
              VALUES (:order_id, :user_id, :content, :file_attachment, NOW())";

    $stmt = $db->prepare($query);

    $stmt->bindParam(':order_id', $order_id);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->bindParam(':content', $content);
    $stmt->bindParam(':file_attachment', $file_attachment);

    if ($stmt->execute()) {
        $comment_id = $db->lastInsertId();

        // Створюємо повідомлення для адміністратора
        $query = "INSERT INTO admin_notifications (order_id, type, content, created_at) 
                  VALUES (:order_id, 'new_comment', :content, NOW())";

        $stmt = $db->prepare($query);
        $stmt->bindParam(':order_id', $order_id);
        $stmt->bindParam(':content', $content);
        $stmt->execute();

        // Оновлюємо кількість непрочитаних повідомлень
        $query = "UPDATE orders SET unread_count = unread_count + 1, has_notifications = 1 WHERE id = :order_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':order_id', $order_id);
        $stmt->execute();

        return $comment_id;
    }

    return false;
}

// Функція для збереження завантаженого файлу
function saveUploadedFile($file, $order_id, $comment_id = null, $user_id = null) {
    $target_dir = $_SERVER['DOCUMENT_ROOT'] . "/uploads/";

    // Створюємо директорії, якщо вони не існують
    if (!file_exists($target_dir . "images/")) {
        mkdir($target_dir . "images/", 0755, true);
    }
    if (!file_exists($target_dir . "videos/")) {
        mkdir($target_dir . "videos/", 0755, true);
    }
    if (!file_exists($target_dir . "documents/")) {
        mkdir($target_dir . "documents/", 0755, true);
    }

    $file_name = time() . '_' . basename($file["name"]);
    $file_type = $file["type"];
    $file_size = $file["size"];
    $original_name = basename($file["name"]);

    // Визначаємо тип файлу і відповідну директорію
    if (strpos($file_type, 'image') !== false) {
        $target_folder = "images/";
    } elseif (strpos($file_type, 'video') !== false) {
        $target_folder = "videos/";
    } else {
        $target_folder = "documents/";
    }

    $target_file = $target_dir . $target_folder . $file_name;
    $relative_path = "/uploads/" . $target_folder . $file_name;

    // Переміщаємо файл
    if (move_uploaded_file($file["tmp_name"], $target_file)) {
        $database = new Database();
        $db = $database->getConnection();

        // Зберігаємо інформацію про файл до бази даних
        if ($comment_id) {
            // Якщо файл додано до коментаря
            $query = "INSERT INTO order_files (order_id, file_name, file_path, original_name, mime_type, file_size, uploaded_at, comment_id, user_id) 
                      VALUES (:order_id, :file_name, :file_path, :original_name, :mime_type, :file_size, NOW(), :comment_id, :user_id)";

            $stmt = $db->prepare($query);
            $stmt->bindParam(':comment_id', $comment_id);
        } else {
            // Якщо файл додано до замовлення
            $query = "INSERT INTO order_media (order_id, file_name, file_path, file_type, file_size, original_name, uploaded_by, uploaded_at) 
                      VALUES (:order_id, :file_name, :file_path, :mime_type, :file_size, :original_name, :user_id, NOW())";

            $stmt = $db->prepare($query);
        }

        $stmt->bindParam(':order_id', $order_id);
        $stmt->bindParam(':file_name', $file_name);
        $stmt->bindParam(':file_path', $relative_path);
        $stmt->bindParam(':original_name', $original_name);
        $stmt->bindParam(':mime_type', $file_type);
        $stmt->bindParam(':file_size', $file_size);
        $stmt->bindParam(':user_id', $user_id);

        if ($stmt->execute()) {
            return [
                'success' => true,
                'file_path' => $relative_path,
                'file_name' => $original_name,
                'file_id' => $db->lastInsertId()
            ];
        }
    }

    return ['success' => false, 'message' => 'Помилка при завантаженні файлу'];
}

// Функція для отримання сервісів
function getServices() {
    $database = new Database();
    $db = $database->getConnection();

    $query = "SELECT * FROM services ORDER BY category, name";
    $stmt = $db->prepare($query);
    $stmt->execute();

    return $stmt->fetchAll();
}

// Функція для отримання категорій сервісів
function getServiceCategories() {
    $database = new Database();
    $db = $database->getConnection();

    $query = "SELECT * FROM service_categories WHERE is_active = 1 ORDER BY name";
    $stmt = $db->prepare($query);
    $stmt->execute();

    return $stmt->fetchAll();
}

// Функція для отримання кількості непрочитаних коментарів (замість сповіщень)
function getUnreadNotificationsCount($user_id) {
    $database = new Database();
    $db = $database->getConnection();

    // Записуємо в лог для відлагодження
    $log_dir = $_SERVER['DOCUMENT_ROOT'] . '/dah/logs';
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    $log_file = $_SERVER['DOCUMENT_ROOT'] . '/dah/logs/functions.log';

    try {
        // Тепер перевіряємо коментарі замість сповіщень
        $query = "SELECT COUNT(*) as count 
                  FROM comments c
                  JOIN orders o ON c.order_id = o.id
                  WHERE o.user_id = :user_id AND c.is_read = 0";
        $stmt = $db->prepare($query);

        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();

        $result = $stmt->fetch();
        $count = $result['count'] ?? 0;

        // Логуємо для відлагодження
        file_put_contents($log_file, date('Y-m-d H:i:s') . " - getUnreadNotificationsCount called for user $user_id, count: $count\n", FILE_APPEND);

        return $count;
    } catch (Exception $e) {
        // Логуємо помилку
        file_put_contents($log_file, date('Y-m-d H:i:s') . " - ERROR in getUnreadNotificationsCount: " . $e->getMessage() . "\n", FILE_APPEND);
        return 0;
    }
}

// Функція для отримання повідомлень користувача (тепер коментарів)
function getUserNotifications($user_id, $limit = 10, $offset = 0, $only_unread = false) {
    $database = new Database();
    $db = $database->getConnection();

    $whereClause = "JOIN orders o ON c.order_id = o.id WHERE o.user_id = :user_id";

    if ($only_unread) {
        $whereClause .= " AND c.is_read = 0";
    }

    $query = "SELECT c.*, o.service FROM comments c $whereClause ORDER BY c.created_at DESC LIMIT :limit OFFSET :offset";
    $stmt = $db->prepare($query);

    $stmt->bindParam(':user_id', $user_id);
    $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);

    $stmt->execute();

    return $stmt->fetchAll();
}

// Функція для позначення повідомлення як прочитаного (тепер коментаря)
function markNotificationAsRead($notification_id, $user_id) {
    $database = new Database();
    $db = $database->getConnection();

    try {
        // Перевіряємо, чи коментар належить до замовлення поточного користувача
        $check_query = "SELECT c.id FROM comments c
                        JOIN orders o ON c.order_id = o.id
                        WHERE c.id = :comment_id AND o.user_id = :user_id";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(':comment_id', $notification_id);
        $check_stmt->bindParam(':user_id', $user_id);
        $check_stmt->execute();

        if ($check_stmt->rowCount() === 0) {
            return false;
        }

        // Оновлюємо статус прочитання коментаря
        $query = "UPDATE comments SET is_read = 1 WHERE id = :comment_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':comment_id', $notification_id);

        return $stmt->execute();
    } catch (Exception $e) {
        // Можливо тут варто додати лог помилки
        return false;
    }
}

// Функція для позначення всіх повідомлень як прочитаних (тепер коментарів)
function markAllNotificationsAsRead($user_id) {
    $database = new Database();
    $db = $database->getConnection();

    try {
        $query = "UPDATE comments c
                 JOIN orders o ON c.order_id = o.id
                 SET c.is_read = 1
                 WHERE o.user_id = :user_id AND c.is_read = 0";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':user_id', $user_id);

        return $stmt->execute();
    } catch (Exception $e) {
        // Можливо тут варто додати лог помилки
        return false;
    }
}

// Функція для оновлення налаштувань профілю користувача
function updateUserProfile($user_id, $data) {
    $database = new Database();
    $db = $database->getConnection();

    $allowed_fields = [
        'first_name', 'last_name', 'middle_name', 'phone', 'address',
        'delivery_method', 'theme_preference', 'timezone', 'notification_preferences',
        'bio', 'website', 'position', 'company'
    ];

    $fields = [];
    $params = [':user_id' => $user_id];

    foreach ($data as $key => $value) {
        if (in_array($key, $allowed_fields)) {
            $fields[] = "$key = :$key";
            $params[":$key"] = $value;
        }
    }

    if (empty($fields)) {
        return false;
    }

    $query = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = :user_id";
    $stmt = $db->prepare($query);

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    if ($stmt->execute()) {
        // Логуємо дію
        logUserAction($user_id, 'update_profile', 'Оновлено профіль користувача');
        return true;
    }

    return false;
}

// Функція для зміни пароля
function changeUserPassword($user_id, $current_password, $new_password) {
    $database = new Database();
    $db = $database->getConnection();

    // Перевіряємо поточний пароль
    $query = "SELECT password FROM users WHERE id = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();

    $user = $stmt->fetch();

    if (!$user || !password_verify($current_password, $user['password'])) {
        return ['success' => false, 'message' => 'Неправильний поточний пароль'];
    }

    // Зберігаємо новий пароль
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

    $query = "UPDATE users SET password = :password, last_password_change = NOW() WHERE id = :user_id";
    $stmt = $db->prepare($query);

    $stmt->bindParam(':password', $hashed_password);
    $stmt->bindParam(':user_id', $user_id);

    if ($stmt->execute()) {
        // Логуємо дію
        logUserAction($user_id, 'change_password', 'Змінено пароль');
        return ['success' => true, 'message' => 'Пароль успішно змінено'];
    }

    return ['success' => false, 'message' => 'Помилка при зміні пароля'];
}

// Функція для зміни електронної пошти
function changeUserEmail($user_id, $new_email, $password) {
    $database = new Database();
    $db = $database->getConnection();

    // Перевіряємо, чи існує користувач з такою поштою
    $query = "SELECT COUNT(*) as count FROM users WHERE email = :email AND id != :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':email', $new_email);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();

    $result = $stmt->fetch();

    if ($result['count'] > 0) {
        return ['success' => false, 'message' => 'Ця електронна пошта вже використовується'];
    }

    // Перевіряємо пароль
    $query = "SELECT password FROM users WHERE id = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();

    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        return ['success' => false, 'message' => 'Неправильний пароль'];
    }

    // Генеруємо токен верифікації
    $verification_token = bin2hex(random_bytes(32));

    // Оновлюємо електронну пошту та встановлюємо email_verified = 0
    $query = "UPDATE users SET email = :email, email_verified = 0, verification_token = :token WHERE id = :user_id";
    $stmt = $db->prepare($query);

    $stmt->bindParam(':email', $new_email);
    $stmt->bindParam(':token', $verification_token);
    $stmt->bindParam(':user_id', $user_id);

    if ($stmt->execute()) {
        // Логуємо дію
        logUserAction($user_id, 'change_email', 'Змінено електронну пошту');

        // Тут можна додати відправку листа з підтвердженням

        return [
            'success' => true,
            'message' => 'Електронну пошту змінено. Перевірте свою пошту для підтвердження.',
            'token' => $verification_token
        ];
    }

    return ['success' => false, 'message' => 'Помилка при зміні електронної пошти'];
}

// Функція для зміни теми сайту
function changeUserTheme($user_id, $theme) {
    $database = new Database();
    $db = $database->getConnection();

    $allowed_themes = ['light', 'dark'];

    if (!in_array($theme, $allowed_themes)) {
        return ['success' => false, 'message' => 'Невірна тема'];
    }

    $query = "UPDATE users SET theme = :theme WHERE id = :user_id";
    $stmt = $db->prepare($query);

    $stmt->bindParam(':theme', $theme);
    $stmt->bindParam(':user_id', $user_id);

    if ($stmt->execute()) {
        // Логуємо дію
        logUserAction($user_id, 'change_theme', 'Змінено тему сайту на ' . $theme);
        return ['success' => true, 'message' => 'Тему успішно змінено'];
    }

    return ['success' => false, 'message' => 'Помилка при зміні теми'];
}

// Функція для отримання додаткових полів користувача
function getUserAdditionalFields($user_id) {
    $database = new Database();
    $db = $database->getConnection();

    $query = "SELECT * FROM user_additional_fields WHERE user_id = :user_id";
    $stmt = $db->prepare($query);

    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();

    $result = [];
    while ($row = $stmt->fetch()) {
        $result[$row['field_name']] = $row['field_value'];
    }

    return $result;
}

// Функція для оновлення додаткових полів користувача
function updateUserAdditionalFields($user_id, $fields) {
    $database = new Database();
    $db = $database->getConnection();

    // Починаємо транзакцію
    $db->beginTransaction();

    try {
        foreach ($fields as $field_name => $field_value) {
            // Перевірка, чи існує вже таке поле
            $query = "SELECT id FROM user_additional_fields WHERE user_id = :user_id AND field_name = :field_name";
            $stmt = $db->prepare($query);

            $stmt->bindParam(':user_id', $user_id);
            $stmt->bindParam(':field_name', $field_name);
            $stmt->execute();

            $existing = $stmt->fetch();

            if ($existing) {
                // Оновлюємо існуюче поле
                $query = "UPDATE user_additional_fields SET field_value = :field_value 
                          WHERE user_id = :user_id AND field_name = :field_name";
            } else {
                // Додаємо нове поле
                $query = "INSERT INTO user_additional_fields (user_id, field_name, field_value) 
                          VALUES (:user_id, :field_name, :field_value)";
            }

            $stmt = $db->prepare($query);

            $stmt->bindParam(':user_id', $user_id);
            $stmt->bindParam(':field_name', $field_name);
            $stmt->bindParam(':field_value', $field_value);
            $stmt->execute();
        }

        // Завершуємо транзакцію
        $db->commit();

        // Логуємо дію
        logUserAction($user_id, 'update_additional_fields', 'Оновлено додаткові поля користувача');

        return ['success' => true, 'message' => 'Додаткові поля успішно оновлено'];
    } catch (Exception $e) {
        // Відкочуємо транзакцію у випадку помилки
        $db->rollBack();

        return ['success' => false, 'message' => 'Помилка при оновленні додаткових полів: ' . $e->getMessage()];
    }
}

// Функція для створення нового замовлення
function createOrder($user_id, $data) {
    $database = new Database();
    $db = $database->getConnection();

    $required_fields = ['service', 'details', 'phone', 'delivery_method'];

    foreach ($required_fields as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            return ['success' => false, 'message' => 'Заповніть всі обов\'язкові поля'];
        }
    }

    // Поля, які можна додати до замовлення
    $allowed_fields = [
        'service', 'details', 'phone', 'address', 'delivery_method',
        'device_type', 'user_comment', 'first_name', 'last_name', 'middle_name'
    ];

    $fields = ['user_id'];
    $values = [':user_id'];
    $params = [':user_id' => $user_id];

    foreach ($data as $key => $value) {
        if (in_array($key, $allowed_fields)) {
            $fields[] = $key;
            $values[] = ":$key";
            $params[":$key"] = $value;
        }
    }

    $query = "INSERT INTO orders (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $values) . ")";
    $stmt = $db->prepare($query);

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    if ($stmt->execute()) {
        $order_id = $db->lastInsertId();

        // Логуємо дію
        logUserAction($user_id, 'create_order', 'Створено нове замовлення #' . $order_id);

        // Створюємо запис в історії замовлення
        $query = "INSERT INTO order_history (order_id, user_id, new_status, changed_at) 
                  VALUES (:order_id, :user_id, 'Новий', NOW())";

        $stmt = $db->prepare($query);
        $stmt->bindParam(':order_id', $order_id);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();

        // Додаємо повідомлення для адміністратора
        $query = "INSERT INTO admin_notifications (order_id, type, content, created_at) 
                  VALUES (:order_id, 'new_order', 'Нове замовлення створено', NOW())";

        $stmt = $db->prepare($query);
        $stmt->bindParam(':order_id', $order_id);
        $stmt->execute();

        // Оновлюємо лічильник активних замовлень користувача
        $query = "UPDATE users SET active_orders_count = active_orders_count + 1, last_order_date = NOW() 
                  WHERE id = :user_id";

        $stmt = $db->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();

        return ['success' => true, 'message' => 'Замовлення успішно створено', 'order_id' => $order_id];
    }

    return ['success' => false, 'message' => 'Помилка при створенні замовлення'];
}

// Функція для оновлення замовлення
function updateOrder($order_id, $user_id, $data) {
    $database = new Database();
    $db = $database->getConnection();

    // Перевіряємо, чи належить замовлення користувачу
    $query = "SELECT * FROM orders WHERE id = :order_id AND user_id = :user_id";
    $stmt = $db->prepare($query);

    $stmt->bindParam(':order_id', $order_id);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();

    $order = $stmt->fetch();

    if (!$order) {
        return ['success' => false, 'message' => 'Замовлення не знайдено або у вас немає до нього доступу'];
    }

    // Перевіряємо, чи можна редагувати замовлення
    if ($order['status'] != 'Новий' && $order['status'] != 'В роботі') {
        return ['success' => false, 'message' => 'Неможливо редагувати замовлення в поточному статусі'];
    }

    // Поля, які можна оновити
    $allowed_fields = [
        'details', 'phone', 'address', 'delivery_method',
        'device_type', 'user_comment', 'first_name', 'last_name', 'middle_name'
    ];

    $fields = [];
    $params = [':order_id' => $order_id, ':user_id' => $user_id];

    foreach ($data as $key => $value) {
        if (in_array($key, $allowed_fields)) {
            $fields[] = "$key = :$key";
            $params[":$key"] = $value;
        }
    }

    if (empty($fields)) {
        return ['success' => false, 'message' => 'Немає даних для оновлення'];
    }

    $query = "UPDATE orders SET " . implode(', ', $fields) . " WHERE id = :order_id AND user_id = :user_id";
    $stmt = $db->prepare($query);

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    if ($stmt->execute()) {
        // Логуємо дію
        logUserAction($user_id, 'update_order', 'Оновлено замовлення #' . $order_id);

        // Додаємо повідомлення для адміністратора
        $query = "INSERT INTO admin_notifications (order_id, type, content, created_at) 
                  VALUES (:order_id, 'order_update', 'Замовлення оновлено користувачем', NOW())";

        $stmt = $db->prepare($query);
        $stmt->bindParam(':order_id', $order_id);
        $stmt->execute();

        return ['success' => true, 'message' => 'Замовлення успішно оновлено'];
    }

    return ['success' => false, 'message' => 'Помилка при оновленні замовлення'];
}