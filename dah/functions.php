<?php
/**
 * Файл з функціями для роботи з даними
 */

/**
 * Перевірка статусу блокування користувача
 * @param mysqli $conn З'єднання з базою даних
 * @param int $user_id ID користувача
 * @return array|false Дані про блокування або false, якщо користувач не заблокований
 */
function checkUserBlock($conn, $user_id) {
    // Оскільки інформація про блокування знаходиться в таблиці users, змінюємо запит
    $stmt = $conn->prepare("SELECT blocked_until, block_reason FROM users WHERE id = ? AND (blocked = 1 OR is_blocked = 1) AND blocked_until > NOW()");
    if (!$stmt) {
        error_log("Помилка SQL при підготовці запиту для перевірки блокування: " . $conn->error);
        return false;
    }

    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc();
    }

    return false;
}

/**
 * Отримання даних користувача
 * @param mysqli $conn З'єднання з базою даних
 * @param int $user_id ID користувача
 * @return array|false Дані користувача або false у випадку помилки
 */
function getUserData($conn, $user_id) {
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    if (!$stmt) {
        error_log("Помилка SQL при підготовці запиту для отримання даних користувача: " . $conn->error);
        return false;
    }

    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc();
    }

    return false;
}

/**
 * Отримання замовлень користувача з можливістю фільтрації та пагінацією
 * @param mysqli $conn З'єднання з базою даних
 * @param int $user_id ID користувача
 * @param string $filter_status Фільтр за статусом (опціонально)
 * @param string $filter_service Фільтр за послугою (опціонально)
 * @param string $search_query Пошуковий запит (опціонально)
 * @param int $page Номер сторінки
 * @param int $per_page Кількість записів на сторінку
 * @return array Масив з замовленнями та інформацією про пагінацію
 */
function getOrders($conn, $user_id, $filter_status = '', $filter_service = '', $search_query = '', $page = 1, $per_page = 10) {
    // Перевіряємо та конвертуємо параметри
    $page = (int)$page;
    $per_page = (int)$per_page;

    if ($page < 1) $page = 1;
    if ($per_page < 1) $per_page = 10;

    // Визначаємо початковий запис для вибірки
    $offset = ($page - 1) * $per_page;

    // Спочатку отримаємо загальну кількість замовлень для пагінації
    $count_query = "SELECT COUNT(*) as total FROM orders WHERE user_id = ?";
    $count_params = array($user_id);
    $count_types = "i";

    // Додаємо умови фільтрації до запиту підрахунку
    if ($filter_status) {
        $count_query .= " AND status = ?";
        $count_params[] = $filter_status;
        $count_types .= "s";
    }

    if ($filter_service) {
        $count_query .= " AND service = ?";
        $count_params[] = $filter_service;
        $count_types .= "s";
    }

    if ($search_query) {
        $count_query .= " AND (id LIKE ? OR details LIKE ? OR device_type LIKE ? OR service LIKE ?)";
        $search_param = "%" . $search_query . "%";
        $count_params[] = $search_param;
        $count_params[] = $search_param;
        $count_params[] = $search_param;
        $count_params[] = $search_param;
        $count_types .= "ssss";
    }

    $count_stmt = $conn->prepare($count_query);
    if (!$count_stmt) {
        error_log("Помилка SQL при підготовці запиту для підрахунку: " . $conn->error);
        return array('orders' => array(), 'pagination' => array('total' => 0, 'pages' => 0, 'current' => $page));
    }

    $count_stmt->bind_param($count_types, ...$count_params);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result()->fetch_assoc();
    $total = (int)$count_result['total'];

    // Тепер отримуємо дані замовлень з пагінацією
    $query = "SELECT * FROM orders WHERE user_id = ?";
    $params = array($user_id);
    $types = "i";

    // Додаємо умови фільтрації
    if ($filter_status) {
        $query .= " AND status = ?";
        $params[] = $filter_status;
        $types .= "s";
    }

    if ($filter_service) {
        $query .= " AND service = ?";
        $params[] = $filter_service;
        $types .= "s";
    }

    if ($search_query) {
        $query .= " AND (id LIKE ? OR details LIKE ? OR device_type LIKE ? OR service LIKE ?)";
        $search_param = "%" . $search_query . "%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= "ssss";
    }

    // Додаємо сортування по ID у спадному порядку для гарантування унікального порядку
    $query .= " ORDER BY id DESC";

    // Додаємо обмеження для пагінації
    $query .= " LIMIT ?, ?";
    $params[] = $offset;
    $params[] = $per_page;
    $types .= "ii";

    // Виконуємо запит для отримання даних
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log("Помилка SQL при підготовці запиту: " . $conn->error);
        return array('orders' => array(), 'pagination' => array('total' => 0, 'pages' => 0, 'current' => $page));
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $orders = array();

    // Використовуємо масив для відстеження ID замовлень і уникнення дублікатів
    $orderIds = array();

    while ($row = $result->fetch_assoc()) {
        // Перевіряємо, чи не було вже додано це замовлення
        if (in_array($row['id'], $orderIds)) {
            continue;
        }

        $orderIds[] = $row['id'];

        // Додаємо файли для кожного замовлення
        $row['files'] = getOrderFiles($conn, $row['id']);

        // Додаємо коментарі для кожного замовлення
        $row['comments'] = getOrderComments($conn, $row['id']);

        // Додаємо інформацію про непрочитані сповіщення для замовлення
        $row['unread_count'] = countUnreadNotificationsForOrder($conn, $user_id, $row['id']);

        // Додаємо останні оновлення статусу
        $row['status_updates'] = getStatusUpdates($conn, $row['id'], 1);

        $orders[] = $row;
    }

    // Розраховуємо кількість сторінок
    $pages = ceil($total / $per_page);

    // Повертаємо замовлення і дані для пагінації
    return array(
        'orders' => $orders,
        'pagination' => array(
            'total' => $total,
            'pages' => $pages,
            'current' => $page,
            'per_page' => $per_page
        )
    );
}

/**
 * Отримання файлів для замовлення
 * @param mysqli $conn З'єднання з базою даних
 * @param int $order_id ID замовлення
 * @return array Масив з файлами
 */
function getOrderFiles($conn, $order_id) {
    // Перевіримо, яка таблиця існує для файлів - order_files чи order_media
    $tableExists = false;
    $tableName = '';

    // Перевірка order_files
    $checkOrderFilesQuery = "SHOW TABLES LIKE 'order_files'";
    $orderFilesResult = $conn->query($checkOrderFilesQuery);
    if ($orderFilesResult && $orderFilesResult->num_rows > 0) {
        $tableExists = true;
        $tableName = 'order_files';
    } else {
        // Перевірка order_media
        $checkOrderMediaQuery = "SHOW TABLES LIKE 'order_media'";
        $orderMediaResult = $conn->query($checkOrderMediaQuery);
        if ($orderMediaResult && $orderMediaResult->num_rows > 0) {
            $tableExists = true;
            $tableName = 'order_media';
        } else {
            // Перевірка orders_files
            $checkOrdersFilesQuery = "SHOW TABLES LIKE 'orders_files'";
            $ordersFilesResult = $conn->query($checkOrdersFilesQuery);
            if ($ordersFilesResult && $ordersFilesResult->num_rows > 0) {
                $tableExists = true;
                $tableName = 'orders_files';
            }
        }
    }

    if (!$tableExists) {
        return array();
    }

    // Визначаємо поля відповідно до таблиці
    if ($tableName == 'order_files') {
        // Перевіримо структуру таблиці order_files
        $checkColumnsQuery = "SHOW COLUMNS FROM order_files LIKE 'original_name'";
        $columnsResult = $conn->query($checkColumnsQuery);

        if ($columnsResult && $columnsResult->num_rows > 0) {
            $query = "SELECT id, order_id, original_name as name, file_path as path, mime_type as type, file_size as size, uploaded_at FROM {$tableName} WHERE order_id = ?";
        } else {
            $query = "SELECT id, order_id, file_name as name, file_path as path, COALESCE(mime_type, '') as type, file_size as size, uploaded_at FROM {$tableName} WHERE order_id = ?";
        }
    } elseif ($tableName == 'order_media') {
        $query = "SELECT id, order_id, original_name as name, file_path as path, file_type as type, file_size as size, uploaded_at FROM {$tableName} WHERE order_id = ?";
    } else { // orders_files
        $query = "SELECT id, order_id, file_name as name, file_path as path, file_type as type, file_size as size, uploaded_at FROM {$tableName} WHERE order_id = ?";
    }

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log("Помилка SQL при підготовці запиту для отримання файлів: " . $conn->error);
        return array();
    }

    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $files = array();

    while ($row = $result->fetch_assoc()) {
        // Додаємо розширення файлу
        $row['ext'] = pathinfo($row['name'], PATHINFO_EXTENSION);
        $files[] = $row;
    }

    return $files;
}

/**
 * Отримання коментарів для замовлення
 * @param mysqli $conn З'єднання з базою даних
 * @param int $order_id ID замовлення
 * @return array Масив з коментарями
 */
function getOrderComments($conn, $order_id) {
    // Перевіримо, яка таблиця існує для коментарів - order_comments чи comments
    $tableExists = false;
    $tableName = '';

    // Перевірка order_comments
    $checkOrderCommentsQuery = "SHOW TABLES LIKE 'order_comments'";
    $orderCommentsResult = $conn->query($checkOrderCommentsQuery);
    if ($orderCommentsResult && $orderCommentsResult->num_rows > 0) {
        $tableExists = true;
        $tableName = 'order_comments';
    } else {
        // Перевірка comments
        $checkCommentsQuery = "SHOW TABLES LIKE 'comments'";
        $commentsResult = $conn->query($checkCommentsQuery);
        if ($commentsResult && $commentsResult->num_rows > 0) {
            $tableExists = true;
            $tableName = 'comments';
        }
    }

    if (!$tableExists) {
        return array();
    }

    if ($tableName == 'order_comments') {
        // Запит для таблиці order_comments
        $query = "SELECT c.*, u.username as author 
                FROM {$tableName} c 
                LEFT JOIN users u ON c.user_id = u.id 
                WHERE c.order_id = ? 
                ORDER BY c.created_at DESC";
    } else {
        // Запит для таблиці comments
        $query = "SELECT c.*, u.username as author, COALESCE(c.admin_name, '') as admin_name 
                FROM {$tableName} c 
                LEFT JOIN users u ON c.user_id = u.id 
                WHERE c.order_id = ? 
                ORDER BY c.created_at DESC";
    }

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log("Помилка SQL при підготовці запиту для отримання коментарів: " . $conn->error);
        return array();
    }

    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $comments = array();

    while ($row = $result->fetch_assoc()) {
        // Перевіряємо, яке поле містить текст коментаря (content або comment)
        if (isset($row['content']) && !isset($row['comment'])) {
            $row['comment'] = $row['content'];
        }

        $comments[] = $row;
    }

    return $comments;
}

/**
 * Отримання унікальних статусів замовлень користувача
 * @param mysqli $conn З'єднання з базою даних
 * @param int $user_id ID користувача
 * @return array Масив унікальних статусів
 */
function getUniqueOrderStatuses($conn, $user_id) {
    $stmt = $conn->prepare("SELECT DISTINCT status FROM orders WHERE user_id = ?");
    if (!$stmt) {
        error_log("Помилка SQL при підготовці запиту на отримання унікальних статусів: " . $conn->error);
        return array();
    }

    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $statuses = array();

    while ($row = $result->fetch_assoc()) {
        $statuses[] = $row['status'];
    }

    return $statuses;
}

/**
 * Отримання унікальних послуг замовлень користувача
 * @param mysqli $conn З'єднання з базою даних
 * @param int $user_id ID користувача
 * @return array Масив унікальних послуг
 */
function getUniqueOrderServices($conn, $user_id) {
    $stmt = $conn->prepare("SELECT DISTINCT service FROM orders WHERE user_id = ?");
    if (!$stmt) {
        error_log("Помилка SQL при підготовці запиту на отримання унікальних послуг: " . $conn->error);
        return array();
    }

    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $services = array();

    while ($row = $result->fetch_assoc()) {
        $services[] = $row['service'];
    }

    return $services;
}

/**
 * Підрахунок активних замовлень користувача
 * @param mysqli $conn З'єднання з базою даних
 * @param int $user_id ID користувача
 * @return int Кількість активних замовлень
 */
function countActiveOrders($conn, $user_id) {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM orders WHERE user_id = ? AND status NOT IN ('Завершено', 'Скасовано')");
    if (!$stmt) {
        error_log("Помилка SQL при підготовці запиту для підрахунку активних замовлень: " . $conn->error);
        return 0;
    }

    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $row = $result->fetch_assoc()) {
        return (int)$row['count'];
    }

    return 0;
}

/**
 * Підрахунок завершених замовлень користувача
 * @param mysqli $conn З'єднання з базою даних
 * @param int $user_id ID користувача
 * @return int Кількість завершених замовлень
 */
function countCompletedOrders($conn, $user_id) {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM orders WHERE user_id = ? AND status = 'Завершено'");
    if (!$stmt) {
        error_log("Помилка SQL при підготовці запиту для підрахунку завершених замовлень: " . $conn->error);
        return 0;
    }

    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $row = $result->fetch_assoc()) {
        return (int)$row['count'];
    }

    return 0;
}

/**
 * Підрахунок замовлень користувача в очікуванні
 * @param mysqli $conn З'єднання з базою даних
 * @param int $user_id ID користувача
 * @return int Кількість замовлень в очікуванні
 */
function countPendingOrders($conn, $user_id) {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM orders WHERE user_id = ? AND status = 'Очікується поставки товару'");
    if (!$stmt) {
        error_log("Помилка SQL при підготовці запиту для підрахунку очікуючих замовлень: " . $conn->error);
        return 0;
    }

    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $row = $result->fetch_assoc()) {
        return (int)$row['count'];
    }

    return 0;
}

/**
 * Отримання непрочитаних сповіщень користувача
 * @param mysqli $conn З'єднання з базою даних
 * @param int $user_id ID користувача
 * @param int $limit Максимальна кількість сповіщень (опціонально)
 * @return array Масив з непрочитаними сповіщеннями
 */
function getUnreadNotifications($conn, $user_id, $limit = 20) {
    $query = "SELECT * FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC";
    if ($limit > 0) {
        $query .= " LIMIT ?";
    }

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log("Помилка SQL при підготовці запиту для отримання сповіщень: " . $conn->error);
        return array();
    }

    if ($limit > 0) {
        $stmt->bind_param("ii", $user_id, $limit);
    } else {
        $stmt->bind_param("i", $user_id);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $notifications = array();

    while ($row = $result->fetch_assoc()) {
        // Адаптуємо поля відповідно до вашої структури таблиці
        if (isset($row['content']) && !isset($row['description'])) {
            $row['description'] = $row['content'];
        }

        if (!isset($row['title']) && isset($row['type'])) {
            // Генеруємо заголовок на основі типу
            switch ($row['type']) {
                case 'status_update':
                    $row['title'] = 'Зміна статусу замовлення';
                    break;
                case 'comment':
                    $row['title'] = 'Новий коментар';
                    break;
                case 'admin_message':
                    $row['title'] = 'Повідомлення від адміністратора';
                    break;
                default:
                    $row['title'] = 'Сповіщення';
            }
        }

        $notifications[] = $row;
    }

    return $notifications;
}

/**
 * Підрахунок загальної кількості непрочитаних сповіщень
 * @param mysqli $conn З'єднання з базою даних
 * @param int $user_id ID користувача
 * @return int Кількість непрочитаних сповіщень
 */
function countTotalUnreadNotifications($conn, $user_id) {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
    if (!$stmt) {
        error_log("Помилка SQL при підготовці запиту для підрахунку сповіщень: " . $conn->error);
        return 0;
    }

    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $row = $result->fetch_assoc()) {
        return (int)$row['count'];
    }

    return 0;
}

/**
 * Підрахунок непрочитаних сповіщень для конкретного замовлення
 * @param mysqli $conn З'єднання з базою даних
 * @param int $user_id ID користувача
 * @param int $order_id ID замовлення
 * @return int Кількість непрочитаних сповіщень
 */
function countUnreadNotificationsForOrder($conn, $user_id, $order_id) {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND order_id = ? AND is_read = 0");
    if (!$stmt) {
        error_log("Помилка SQL при підготовці запиту для підрахунку сповіщень замовлення: " . $conn->error);
        return 0;
    }

    $stmt->bind_param("ii", $user_id, $order_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $row = $result->fetch_assoc()) {
        return (int)$row['count'];
    }

    return 0;
}

/**
 * Отримання оновлень статусу для замовлення
 * @param mysqli $conn З'єднання з базою даних
 * @param int $order_id ID замовлення
 * @param int $limit Максимальна кількість оновлень (опціонально)
 * @return array Масив з оновленнями статусу
 */
function getStatusUpdates($conn, $order_id, $limit = 5) {
    // Перевіримо, яка таблиця існує для історії - order_history чи order_status_updates
    $tableExists = false;
    $tableName = '';

    // Перевірка order_history
    $checkOrderHistoryQuery = "SHOW TABLES LIKE 'order_history'";
    $orderHistoryResult = $conn->query($checkOrderHistoryQuery);
    if ($orderHistoryResult && $orderHistoryResult->num_rows > 0) {
        $tableExists = true;
        $tableName = 'order_history';
    }

    if (!$tableExists) {
        return array();
    }

    if ($tableName == 'order_history') {
        $query = "SELECT *, previous_status as old_status, changed_at as created_at, new_status, 'Зміна статусу' as message 
                FROM {$tableName} 
                WHERE order_id = ? 
                ORDER BY changed_at DESC";
    }

    if ($limit > 0) {
        $query .= " LIMIT ?";
    }

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log("Помилка SQL при підготовці запиту для отримання оновлень статусу: " . $conn->error);
        return array();
    }

    if ($limit > 0) {
        $stmt->bind_param("ii", $order_id, $limit);
    } else {
        $stmt->bind_param("i", $order_id);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $updates = array();

    while ($row = $result->fetch_assoc()) {
        $updates[] = $row;
    }

    return $updates;
}

/**
 * Перевірка чи можна редагувати замовлення залежно від статусу
 * @param string $status Статус замовлення
 * @return bool true, якщо замовлення можна редагувати, інакше false
 */
function isOrderEditable($status) {
    // Перетворюємо статус до нижнього регістру для порівняння
    $status = mb_strtolower($status, 'UTF-8');

    $editableStatuses = array('новий', 'очікується поставки товару', 'в роботі');

    foreach ($editableStatuses as $editableStatus) {
        if (mb_strpos($status, $editableStatus) !== false) {
            return true;
        }
    }

    return false;
}

/**
 * Отримання CSS класу для статусу замовлення
 * @param string $status Статус замовлення
 * @return string CSS клас
 */
function getStatusClass($status) {
    // Перетворюємо статус до нижнього регістру для порівняння
    $status = mb_strtolower($status, 'UTF-8');

    if (mb_strpos($status, 'нов') !== false) {
        return 'new';
    } else if (mb_strpos($status, 'робот') !== false) {
        return 'in-progress';
    } else if (mb_strpos($status, 'готов') !== false || mb_strpos($status, 'заверш') !== false) {
        return 'completed';
    } else if (mb_strpos($status, 'скасова') !== false) {
        return 'cancelled';
    } else if (mb_strpos($status, 'очіку') !== false) {
        return 'pending';
    }

    return '';
}

/**
 * Отримання іконки для сповіщення залежно від типу
 * @param string $type Тип сповіщення
 * @return string CSS клас для іконки
 */
function getNotificationIcon($type) {
    switch ($type) {
        case 'status_update':
            return 'fas fa-sync';
        case 'comment':
            return 'fas fa-comment-alt';
        case 'admin_message':
            return 'fas fa-envelope';
        case 'system':
            return 'fas fa-cog';
        default:
            return 'fas fa-bell';
    }
}

/**
 * Отримання іконки для файлу залежно від розширення
 * @param string $extension Розширення файлу
 * @return string CSS клас для іконки
 */
function getFileIcon($extension) {
    $extension = strtolower($extension);

    if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'])) {
        return 'fas fa-file-image';
    } else if (in_array($extension, ['mp4', 'avi', 'mov', 'wmv', 'mkv', 'flv'])) {
        return 'fas fa-file-video';
    } else if (in_array($extension, ['mp3', 'wav', 'ogg', 'flac', 'aac'])) {
        return 'fas fa-file-audio';
    } else if ($extension == 'pdf') {
        return 'fas fa-file-pdf';
    } else if (in_array($extension, ['doc', 'docx'])) {
        return 'fas fa-file-word';
    } else if (in_array($extension, ['xls', 'xlsx'])) {
        return 'fas fa-file-excel';
    } else if (in_array($extension, ['ppt', 'pptx'])) {
        return 'fas fa-file-powerpoint';
    } else if (in_array($extension, ['zip', 'rar', '7z', 'tar', 'gz'])) {
        return 'fas fa-file-archive';
    } else if (in_array($extension, ['txt', 'log', 'md'])) {
        return 'fas fa-file-alt';
    } else if (in_array($extension, ['html', 'css', 'js', 'php', 'xml', 'json'])) {
        return 'fas fa-file-code';
    }

    return 'fas fa-file';
}

/**
 * Отримання розширення файлу з імені
 * @param string $filename Ім'я файлу
 * @return string Розширення файлу
 */
function getFileExtension($filename) {
    if (!$filename) return '';
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
}

/**
 * Форматування дати
 * @param string $dateStr Дата у форматі строки
 * @return string Відформатована дата
 */
function formatDate($dateStr) {
    $date = new DateTime($dateStr);
    return $date->format('d.m.Y');
}

/**
 * Форматування дати та часу
 * @param string $dateStr Дата у форматі строки
 * @return string Відформатована дата і час
 */
function formatDateTime($dateStr) {
    $date = new DateTime($dateStr);
    return $date->format('d.m.Y H:i');
}

/**
 * Форматування часу у вигляді "n часів тому"
 * @param string $dateStr Дата у форматі строки
 * @return string Відформатований час
 */
function formatTimeAgo($dateStr) {
    $date = new DateTime($dateStr);
    $now = new DateTime();
    $interval = $now->diff($date);

    if ($interval->y > 0) {
        return $interval->y . ' ' . pluralize($interval->y, 'рік', 'роки', 'років') . ' тому';
    } else if ($interval->m > 0) {
        return $interval->m . ' ' . pluralize($interval->m, 'місяць', 'місяці', 'місяців') . ' тому';
    } else if ($interval->d > 0) {
        return $interval->d . ' ' . pluralize($interval->d, 'день', 'дні', 'днів') . ' тому';
    } else if ($interval->h > 0) {
        return $interval->h . ' ' . pluralize($interval->h, 'годину', 'години', 'годин') . ' тому';
    } else if ($interval->i > 0) {
        return $interval->i . ' ' . pluralize($interval->i, 'хвилину', 'хвилини', 'хвилин') . ' тому';
    } else {
        return 'щойно';
    }
}

/**
 * Функція для вибору правильної форми слова залежно від числа (1, 2, 5)
 * @param int $n Кількість
 * @param string $form1 Форма для 1 (рік)
 * @param string $form2 Форма для 2-4 (роки)
 * @param string $form5 Форма для 5+ (років)
 * @return string Правильна форма слова
 */
function pluralize($n, $form1, $form2, $form5) {
    $n = abs($n) % 100;
    $n1 = $n % 10;

    if ($n > 10 && $n < 20) return $form5;
    if ($n1 > 1 && $n1 < 5) return $form2;
    if ($n1 == 1) return $form1;

    return $form5;
}

/**
 * Обробка POST запитів
 * @param mysqli $conn З'єднання з базою даних
 * @param int $user_id ID користувача
 * @param string $username Ім'я користувача
 * @param array $user_data Дані користувача
 */
function handlePostRequests($conn, $user_id, $username, $user_data) {
    // Код обробки POST запитів залишається таким же
    // ...
}

/**
 * Обробка AJAX запитів
 * @param mysqli $conn З'єднання з базою даних
 * @param int $user_id ID користувача
 */
function handleAjaxRequests($conn, $user_id) {
    // Код обробки AJAX запитів залишається таким же
    // ...
}