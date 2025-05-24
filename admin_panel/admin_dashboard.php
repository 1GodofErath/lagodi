<?php
session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Include database connection
require __DIR__ . '/../db.php';

if ($conn->connect_error) {
    die("Помилка підключення до БД: " . $conn->connect_error);
}

if (!isset($_SESSION['role'])) {
    header("Location: /../login.php");
    exit();
}

if (!in_array($_SESSION['role'], ['admin', 'junior_admin'])) {
    die("Доступ заборонено");
}

// Get parameters
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : 'all';
$activeSection = $_GET['section'] ?? 'orders';
$userSearch = $_GET['user_search'] ?? '';
$logPage = isset($_GET['log_page']) ? (int)$_GET['log_page'] : 1;
$logUser = $_GET['log_user'] ?? '';
$logDate = $_GET['log_date'] ?? '';
$logAction = $_GET['log_action'] ?? '';
$searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';
$sortOrder = isset($_GET['sort']) ? $_GET['sort'] : 'newest';
$statsUserFilter = $_GET['stats_user'] ?? 'all';
$statsDateRange = $_GET['stats_date_range'] ?? '7days';

// Обробка повідомлень про успіх/помилку
$successMessage = '';
$errorMessage = '';

// Обробка повідомлень про успіх/помилку
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'order_assigned':
            $successMessage = "Замовлення успішно призначено вам";
            break;
        case 'status_updated':
            $successMessage = "Статус замовлення успішно оновлено";
            break;
        case 'comment_added':
            $successMessage = "Коментар успішно додано";
            break;
        default:
            $successMessage = "Операція виконана успішно";
    }
}

if (isset($_GET['error'])) {
    switch ($_GET['error']) {
        case 'already_assigned':
            $errorMessage = "Замовлення вже призначено іншому адміністратору";
            break;
        case 'comment_blocked':
            $errorMessage = "Неможливо додати коментар до замовлення з цим статусом";
            break;
        case 'status_blocked':
            $errorMessage = "Неможливо змінити статус цього замовлення";
            break;
        default:
            $errorMessage = $_GET['error'];
    }
}

// Встановлення теми
$currentTheme = isset($_COOKIE['theme']) ? $_COOKIE['theme'] : 'dark';

// Функція для перевірки можливості коментування
function canAddComments($status) {
    $status = trim($status); // Прибираємо зайві пробіли
    $blockedStatuses = ['Завершено', 'Виконано', 'Не можливо виконати'];
    return !in_array($status, $blockedStatuses);
}

// Функція для перевірки можливості зміни статусу
function canChangeStatus($status) {
    $status = trim($status);
    $blockedStatuses = ['Завершено', 'Виконано', 'Не можливо виконати'];
    return !in_array($status, $blockedStatuses);
}

// Функція для безпечного виведення тексту
function safeEcho($text, $default = '') {
    return htmlspecialchars($text ?? $default, ENT_QUOTES, 'UTF-8');
}

// Функція для форматування дати
function formatDate($dateString) {
    $date = new DateTime($dateString);
    return $date->format('d.m.Y H:i');
}

// Функція для отримання класу статусу замовлення
function getStatusClass($status) {
    switch($status) {
        case 'Новий':
            return 'status-new';
        case 'В обробці':
            return 'status-processing';
        case 'В роботі':
            return 'status-in-progress';
        case 'Виконано':
            return 'status-completed';
        case 'Завершено':
            return 'status-completed';
        case 'Очікується поставки товару':
            return 'status-waiting-delivery';
        case 'Очікується':
            return 'status-waiting';
        case 'Скасовано':
        case 'Не можливо виконати':
            return 'status-canceled';
        default:
            return 'status-default';
    }
}

// Функція для отримання іконки статусу
function getStatusIcon($status) {
    switch($status) {
        case 'new':
            return 'bi-hourglass';
        case 'in_progress':
            return 'bi-gear';
        case 'waiting':
            return 'bi-clock';
        case 'waiting_delivery':
            return 'bi-truck';
        case 'completed':
            return 'bi-check-circle';
        case 'canceled':
            return 'bi-x-circle';
        default:
            return 'bi-circle';
    }
}

// Функція для отримання назви статусу
function getStatusName($statusCode) {
    switch($statusCode) {
        case 'new':
            return 'Новий';
        case 'in_progress':
            return 'В роботі';
        case 'waiting':
            return 'Очікується';
        case 'waiting_delivery':
            return 'Очікується поставки товару';
        case 'completed':
            return 'Завершено';
        case 'canceled':
            return 'Не можливо виконати';
        default:
            return 'Всі статуси';
    }
}

// Функція для перевірки наявності прав адміністратора
function hasAdminAccess() {
    return isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'junior_admin']);
}

// Отримуємо дату для фільтрації статистики
switch($statsDateRange) {
    case 'today':
        $statsFromDate = date('Y-m-d');
        break;
    case '7days':
        $statsFromDate = date('Y-m-d', strtotime('-7 days'));
        break;
    case '30days':
        $statsFromDate = date('Y-m-d', strtotime('-30 days'));
        break;
    case '90days':
        $statsFromDate = date('Y-m-d', strtotime('-90 days'));
        break;
    case 'year':
        $statsFromDate = date('Y-m-d', strtotime('-1 year'));
        break;
    default:
        $statsFromDate = date('Y-m-d', strtotime('-7 days'));
}

// Retrieve orders with an optional status filter
try {
    // Базовий запит
    $query = "SELECT 
                o.id,
                u.username,
                o.service,
                o.status,
                o.user_id,
                o.created_at,
                o.device_type,
                o.device_model,
                o.handler_id,
                h.username as handler_name,
                c.name as category_name 
            FROM orders o
            JOIN users u ON o.user_id = u.id
            LEFT JOIN users h ON o.handler_id = h.id
            LEFT JOIN service_categories c ON o.category_id = c.id
            WHERE 1=1";

    // Додаємо фільтр за статусом
    if ($statusFilter !== 'all') {
        $statusName = '';
        switch ($statusFilter) {
            case 'new':
                $statusName = 'Новий';
                break;
            case 'in_progress':
                $statusName = 'В роботі';
                break;
            case 'waiting':
                $statusName = 'Очікується';
                break;
            case 'waiting_delivery':
                $statusName = 'Очікується поставки товару';
                break;
            case 'completed':
                $statusName = 'Завершено';
                break;
            case 'canceled':
                $statusName = 'Не можливо виконати';
                break;
        }

        if (!empty($statusName)) {
            $query .= " AND o.status = '$statusName'";
        }
    }

    // Додаємо пошук
    if (!empty($searchTerm)) {
        // Використовуємо prepared statement для запобігання SQL-ін'єкціям
        if (is_numeric($searchTerm)) {
            $stmt = $conn->prepare("SELECT id, service FROM orders WHERE id = ? OR service LIKE ?");
            $searchTermWithWildcards = "%$searchTerm%";
            $stmt->bind_param("is", $searchTerm, $searchTermWithWildcards);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $matchingIds = [];
                while ($row = $result->fetch_assoc()) {
                    $matchingIds[] = $row['id'];
                }
                $query .= " AND o.id IN (" . implode(",", $matchingIds) . ")";
            } else {
                $query .= " AND 0"; // Нічого не знайдено
            }
        } else {
            $stmt = $conn->prepare("SELECT o.id FROM orders o JOIN users u ON o.user_id = u.id WHERE o.service LIKE ? OR u.username LIKE ?");
            $searchTermWithWildcards = "%$searchTerm%";
            $stmt->bind_param("ss", $searchTermWithWildcards, $searchTermWithWildcards);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $matchingIds = [];
                while ($row = $result->fetch_assoc()) {
                    $matchingIds[] = $row['id'];
                }
                $query .= " AND o.id IN (" . implode(",", $matchingIds) . ")";
            } else {
                $query .= " AND 0"; // Нічого не знайдено
            }
        }
    }

    // Додаємо сортування з пріоритетом для нових замовлень
    // Спочатку сортуємо за пріоритетом статусу, потім за часом
    $query .= " ORDER BY 
                CASE 
                    WHEN o.status = 'Новий' THEN 1
                    WHEN o.status = 'В роботі' THEN 2
                    WHEN o.status = 'Очікується' THEN 3
                    WHEN o.status = 'Очікується поставки товару' THEN 4
                    WHEN o.status = 'Завершено' THEN 5
                    WHEN o.status = 'Не можливо виконати' THEN 6
                    ELSE 7
                END ASC";

    // Додаємо сортування за датою
    $query .= ($sortOrder === 'oldest') ? ", o.created_at ASC" : ", o.created_at DESC";

    $result = $conn->query($query);

    if ($result === false) {
        throw new Exception("Помилка виконання запиту: " . $conn->error);
    }

    $orders = $result->fetch_all(MYSQLI_ASSOC);

    // Отримуємо статистику за кожним статусом
    $statsQuery = "SELECT status, COUNT(*) as count FROM orders GROUP BY status";
    $statsResult = $conn->query($statsQuery);

    if ($statsResult === false) {
        throw new Exception("Помилка отримання статистики: " . $conn->error);
    }

    $statsResults = $statsResult->fetch_all(MYSQLI_ASSOC);

    // Створюємо масив статистики
    $stats = [
        'new' => 0,
        'in_progress' => 0,
        'waiting' => 0,
        'waiting_delivery' => 0,
        'completed' => 0,
        'canceled' => 0
    ];

    foreach ($statsResults as $stat) {
        switch ($stat['status']) {
            case 'Новий':
                $stats['new'] = $stat['count'];
                break;
            case 'В роботі':
                $stats['in_progress'] = $stat['count'];
                break;
            case 'Очікується':
                $stats['waiting'] = $stat['count'];
                break;
            case 'Очікується поставки товару':
                $stats['waiting_delivery'] = $stat['count'];
                break;
            case 'Завершено':
            case 'Виконано':
                $stats['completed'] = (isset($stats['completed']) ? $stats['completed'] : 0) + $stat['count'];
                break;
            case 'Не можливо виконати':
            case 'Скасовано':
                $stats['canceled'] = (isset($stats['canceled']) ? $stats['canceled'] : 0) + $stat['count'];
                break;
        }
    }

    // Статистика по адміністраторам
    $adminStatsQuery = "
        SELECT 
            u.id, 
            u.username, 
            u.role,
            COUNT(DISTINCT o.id) as total_orders,
            SUM(CASE WHEN o.status IN ('Завершено', 'Виконано') THEN 1 ELSE 0 END) as completed_orders,
            SUM(CASE WHEN o.status IN ('Не можливо виконати', 'Скасовано') THEN 1 ELSE 0 END) as canceled_orders,
            SUM(CASE WHEN o.status = 'В роботі' THEN 1 ELSE 0 END) as in_progress_orders,
            AVG(TIMESTAMPDIFF(HOUR, o.created_at, CASE 
                WHEN o.status IN ('Завершено', 'Виконано') THEN o.updated_at 
                ELSE NOW() 
            END)) as avg_completion_time
        FROM users u
        LEFT JOIN orders o ON u.id = o.handler_id
        WHERE u.role IN ('admin', 'junior_admin')
        GROUP BY u.id
        ORDER BY total_orders DESC
    ";

    $adminStatsResult = $conn->query($adminStatsQuery);
    if ($adminStatsResult === false) {
        throw new Exception("Помилка отримання статистики адміністраторів: " . $conn->error);
    }

    $adminStats = $adminStatsResult->fetch_all(MYSQLI_ASSOC);

    // Статистика за часовим періодом для графіків
    $timeStatsQuery = "
        SELECT 
            DATE(created_at) as date,
            COUNT(*) as count,
            SUM(CASE WHEN status IN ('Завершено', 'Виконано') THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status = 'Новий' THEN 1 ELSE 0 END) as new_orders
        FROM orders
        WHERE created_at >= ?
        GROUP BY DATE(created_at)
        ORDER BY date
    ";

    $timeStatsStmt = $conn->prepare($timeStatsQuery);
    $timeStatsStmt->bind_param("s", $statsFromDate);
    $timeStatsStmt->execute();
    $timeStatsResult = $timeStatsStmt->get_result();

    $timeStats = [];
    $timeStatsLabels = [];
    $timeStatsNewOrders = [];
    $timeStatsCompletedOrders = [];

    while ($row = $timeStatsResult->fetch_assoc()) {
        $timeStats[] = $row;
        $timeStatsLabels[] = date('d.m', strtotime($row['date']));
        $timeStatsNewOrders[] = (int)$row['new_orders'];
        $timeStatsCompletedOrders[] = (int)$row['completed'];
    }

    // Статистика за продуктивністю по днях тижня
    $weekdayStatsQuery = "
        SELECT 
            DAYOFWEEK(created_at) as weekday,
            COUNT(*) as count,
            SUM(CASE WHEN status IN ('Завершено', 'Виконано') THEN 1 ELSE 0 END) as completed
        FROM orders
        WHERE created_at >= ?
        GROUP BY weekday
        ORDER BY weekday
    ";

    $weekdayStatsStmt = $conn->prepare($weekdayStatsQuery);
    $weekdayStatsStmt->bind_param("s", $statsFromDate);
    $weekdayStatsStmt->execute();
    $weekdayStatsResult = $weekdayStatsStmt->get_result();

    $weekdays = ['Неділя', 'Понеділок', 'Вівторок', 'Середа', 'Четвер', 'П\'ятниця', 'Субота'];
    $weekdayStats = array_fill(0, 7, 0);
    $weekdayCompletedStats = array_fill(0, 7, 0);

    while ($row = $weekdayStatsResult->fetch_assoc()) {
        $index = $row['weekday'] - 1;
        $weekdayStats[$index] = (int)$row['count'];
        $weekdayCompletedStats[$index] = (int)$row['completed'];
    }

    // Статистика за клієнтами (топ-10 активних)
    $clientStatsQuery = "
        SELECT 
            u.id,
            u.username,
            COUNT(o.id) as order_count,
            SUM(CASE WHEN o.status IN ('Завершено', 'Виконано') THEN 1 ELSE 0 END) as completed_orders
        FROM users u
        JOIN orders o ON u.id = o.user_id
        WHERE u.role = 'user' AND o.created_at >= ?
        GROUP BY u.id
        ORDER BY order_count DESC
        LIMIT 10
    ";

    $clientStatsStmt = $conn->prepare($clientStatsQuery);
    $clientStatsStmt->bind_param("s", $statsFromDate);
    $clientStatsStmt->execute();
    $clientStatsResult = $clientStatsStmt->get_result();

    $clientStats = $clientStatsResult->fetch_all(MYSQLI_ASSOC);

    // Статистика за категоріями замовлень
    $categoryStatsQuery = "
        SELECT 
            COALESCE(c.name, 'Без категорії') as category,
            COUNT(o.id) as count
        FROM orders o
        LEFT JOIN service_categories c ON o.category_id = c.id
        WHERE o.created_at >= ?
        GROUP BY category
        ORDER BY count DESC
    ";

    $categoryStatsStmt = $conn->prepare($categoryStatsQuery);
    $categoryStatsStmt->bind_param("s", $statsFromDate);
    $categoryStatsStmt->execute();
    $categoryStatsResult = $categoryStatsStmt->get_result();

    $categoryStats = $categoryStatsResult->fetch_all(MYSQLI_ASSOC);

    // Статистика для вибраного адміністратора
    $selectedAdminStats = null;
    if ($statsUserFilter !== 'all') {
        $adminDetailsQuery = "
            SELECT 
                u.id, 
                u.username, 
                u.role,
                COUNT(DISTINCT o.id) as total_orders,
                SUM(CASE WHEN o.status IN ('Завершено', 'Виконано') THEN 1 ELSE 0 END) as completed_orders,
                SUM(CASE WHEN o.status IN ('Не можливо виконати', 'Скасовано') THEN 1 ELSE 0 END) as canceled_orders,
                SUM(CASE WHEN o.status = 'В роботі' THEN 1 ELSE 0 END) as in_progress_orders,
                AVG(TIMESTAMPDIFF(HOUR, o.created_at, CASE 
                    WHEN o.status IN ('Завершено', 'Виконано') THEN o.updated_at 
                    ELSE NOW() 
                END)) as avg_completion_time
            FROM users u
            LEFT JOIN orders o ON u.id = o.handler_id
            WHERE u.id = ? AND o.created_at >= ?
            GROUP BY u.id
        ";

        $adminDetailsStmt = $conn->prepare($adminDetailsQuery);
        $adminDetailsStmt->bind_param("is", $statsUserFilter, $statsFromDate);
        $adminDetailsStmt->execute();
        $adminDetailsResult = $adminDetailsStmt->get_result();

        if ($adminDetailsResult->num_rows > 0) {
            $selectedAdminStats = $adminDetailsResult->fetch_assoc();

            // Отримуємо статистику за днями для вибраного адміна
            $adminDailyStatsQuery = "
                SELECT 
                    DATE(o.created_at) as date,
                    COUNT(o.id) as count,
                    SUM(CASE WHEN o.status IN ('Завершено', 'Виконано') THEN 1 ELSE 0 END) as completed
                FROM orders o
                WHERE o.handler_id = ? AND o.created_at >= ?
                GROUP BY DATE(o.created_at)
                ORDER BY date
            ";

            $adminDailyStatsStmt = $conn->prepare($adminDailyStatsQuery);
            $adminDailyStatsStmt->bind_param("is", $statsUserFilter, $statsFromDate);
            $adminDailyStatsStmt->execute();
            $adminDailyStatsResult = $adminDailyStatsStmt->get_result();

            $adminDailyStats = [];
            $adminDailyLabels = [];
            $adminDailyValues = [];

            while ($row = $adminDailyStatsResult->fetch_assoc()) {
                $adminDailyStats[] = $row;
                $adminDailyLabels[] = date('d.m', strtotime($row['date']));
                $adminDailyValues[] = (int)$row['count'];
            }

            // Отримуємо статистику за категоріями для вибраного адміна
            $adminCategoryStatsQuery = "
                SELECT 
                    COALESCE(c.name, 'Без категорії') as category,
                    COUNT(o.id) as count
                FROM orders o
                LEFT JOIN service_categories c ON o.category_id = c.id
                WHERE o.handler_id = ? AND o.created_at >= ?
                GROUP BY category
                ORDER BY count DESC
            ";

            $adminCategoryStatsStmt = $conn->prepare($adminCategoryStatsQuery);
            $adminCategoryStatsStmt->bind_param("is", $statsUserFilter, $statsFromDate);
            $adminCategoryStatsStmt->execute();
            $adminCategoryStatsResult = $adminCategoryStatsStmt->get_result();

            $adminCategoryStats = $adminCategoryStatsResult->fetch_all(MYSQLI_ASSOC);
        }
    }

} catch (Exception $e) {
    $errorMessage = "Помилка при отриманні даних: " . $e->getMessage();
    $orders = [];
    $stats = [
        'new' => 0,
        'in_progress' => 0,
        'waiting' => 0,
        'waiting_delivery' => 0,
        'completed' => 0,
        'canceled' => 0
    ];
    $adminStats = [];
    $timeStats = [];
    $clientStats = [];
    $categoryStats = [];
}

// Retrieve comments for each order with pagination
$comments = [];
$commentsPerPage = 5;
foreach ($orders as $order) {
    try {
        $orderId = $order['id'];
        $commentPage = isset($_GET['comment_page_'.$orderId]) ? (int)$_GET['comment_page_'.$orderId] : 1;
        $offset = ($commentPage - 1) * $commentsPerPage;

        // Отримуємо загальну кількість коментарів
        $countStmt = $conn->prepare("SELECT COUNT(*) FROM comments WHERE order_id = ?");
        $countStmt->bind_param("i", $orderId);
        $countStmt->execute();
        $totalComments = $countStmt->get_result()->fetch_row()[0];
        $totalPages = ceil($totalComments / $commentsPerPage);

        // Отримуємо коментарі з пагінацією
        $stmt = $conn->prepare("
            SELECT c.*, u.username, u.role 
            FROM comments c
            JOIN users u ON c.user_id = u.id 
            WHERE c.order_id = ?
            ORDER BY c.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->bind_param("iii", $orderId, $commentsPerPage, $offset);
        $stmt->execute();
        $result = $stmt->get_result();
        $comments[$orderId] = [
            'data' => $result->fetch_all(MYSQLI_ASSOC),
            'current_page' => $commentPage,
            'total_pages' => $totalPages,
            'total_comments' => $totalComments
        ];
    } catch (Exception $e) {
        $errorMessage = "Помилка при отриманні коментарів: " . $e->getMessage();
        $comments[$order['id']] = [
            'data' => [],
            'current_page' => 1,
            'total_pages' => 0,
            'total_comments' => 0
        ];
    }
}

// Отримуємо файли, прикріплені до замовлень
$orderFiles = [];
foreach ($orders as $order) {
    try {
        $orderId = $order['id'];
        $filesQuery = "SELECT * FROM order_files WHERE order_id = ?";
        $stmt = $conn->prepare($filesQuery);
        $stmt->bind_param("i", $orderId);
        $stmt->execute();
        $result = $stmt->get_result();
        $orderFiles[$orderId] = $result->fetch_all(MYSQLI_ASSOC);
    } catch (Exception $e) {
        $errorMessage = "Помилка при отриманні файлів замовлення: " . $e->getMessage();
        $orderFiles[$order['id']] = [];
    }
}

// Обробка додавання коментаря
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_comment']) && isset($_POST['order_id']) && isset($_POST['comment'])) {
    $orderIdForComment = filter_var($_POST['order_id'], FILTER_VALIDATE_INT);
    $comment = trim(htmlspecialchars($_POST['comment']));

    if ($orderIdForComment && !empty($comment)) {
        try {
            // Спочатку перевіряємо статус замовлення
            $statusCheckQuery = "SELECT status FROM orders WHERE id = ?";
            $statusCheckStmt = $conn->prepare($statusCheckQuery);
            $statusCheckStmt->bind_param("i", $orderIdForComment);
            $statusCheckStmt->execute();
            $statusResult = $statusCheckStmt->get_result();

            if ($statusResult->num_rows === 0) {
                throw new Exception("Замовлення не знайдено");
            }

            $orderStatus = $statusResult->fetch_assoc()['status'];

            // Точна перевірка статусу з обрізкою пробілів
            if (!canAddComments($orderStatus)) {
                $errorMessage = "Неможливо додати коментар до замовлення зі статусом '$orderStatus'";
                header("Location: admin_dashboard.php?section=orders&error=comment_blocked");
                exit;
            }

            // Додаємо коментар
            $commentQuery = "INSERT INTO comments (order_id, user_id, content, is_read, created_at) 
                           VALUES (?, ?, ?, 0, NOW())";

            $commentStmt = $conn->prepare($commentQuery);
            $userId = $_SESSION['user_id'];
            $commentStmt->bind_param("iis", $orderIdForComment, $userId, $comment);
            $commentStmt->execute();

            // Логуємо дію
            if (isset($_SESSION['user_id'])) {
                $logQuery = "INSERT INTO logs (user_id, action, created_at) VALUES (?, ?, NOW())";
                $logStmt = $conn->prepare($logQuery);
                $action = "Додав коментар до замовлення #$orderIdForComment";
                $logStmt->bind_param("is", $_SESSION['user_id'], $action);
                $logStmt->execute();
            }

            // Перенаправляємо на ту ж сторінку, щоб уникнути повторної відправки форми
            header("Location: admin_dashboard.php?section=orders&id=$orderIdForComment&success=comment_added");
            exit;
        } catch (Exception $e) {
            $errorMessage = "Помилка при додаванні коментаря: " . $e->getMessage();
        }
    }
}

// Retrieve users (only for admin)
$users = [];
if ($_SESSION['role'] === 'admin') {
    try {
        $search = "%{$userSearch}%";
        $sql = "SELECT id, username, role, blocked_until, block_reason
                FROM users 
                WHERE username LIKE ?
                ORDER BY FIELD(role, 'admin', 'junior_admin') DESC, username ASC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $search);
        $stmt->execute();
        $result = $stmt->get_result();
        $users = $result->fetch_all(MYSQLI_ASSOC);
    } catch (Exception $e) {
        $errorMessage = "Помилка отримання користувачів: " . $e->getMessage();
    }
}

// Retrieve logs (only for admin)
$logs = [];
$totalPages = 1;
if ($_SESSION['role'] === 'admin') {
    try {
        $limit = 10;
        $offset = ($logPage - 1) * $limit;
        $userFilter = "%{$logUser}%";
        $actionFilter = "%{$logAction}%";
        $sql = "SELECT SQL_CALC_FOUND_ROWS l.*, u.username, u.role 
                FROM logs l
                JOIN users u ON l.user_id = u.id
                WHERE u.username LIKE ?
                AND (DATE(l.created_at) = ? OR ? = '')
                AND l.action LIKE ?
                ORDER BY l.created_at DESC
                LIMIT ? OFFSET ?";
        $stmt = $conn->prepare($sql);
        $dateParam = $logDate ?: '';
        $stmt->bind_param("ssssii",
            $userFilter,
            $dateParam,
            $dateParam,
            $actionFilter,
            $limit,
            $offset
        );
        $stmt->execute();
        $result = $stmt->get_result();
        $logs = $result->fetch_all(MYSQLI_ASSOC);
        $totalResult = $conn->query("SELECT FOUND_ROWS() AS total");
        $totalLogs = $totalResult->fetch_assoc()['total'];
        $totalPages = ceil($totalLogs / $limit);
    } catch (Exception $e) {
        $errorMessage = "Помилка отримання логів: " . $e->getMessage();
    }
}

// Отримуємо список адміністраторів для фільтра статистики
$adminListQuery = "SELECT id, username, role FROM users WHERE role IN ('admin', 'junior_admin') ORDER BY role ASC, username ASC";
$adminListResult = $conn->query($adminListQuery);
$adminList = $adminListResult->fetch_all(MYSQLI_ASSOC);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Змінна для зберігання даних про замовлення при перегляді окремого замовлення
$orderDetails = null;
if (isset($_GET['id'])) {
    $orderId = filter_var($_GET['id'], FILTER_VALIDATE_INT);

    if ($orderId) {
        try {
            $query = "SELECT o.*, u.username, c.name as category_name, h.username as handler_name 
                     FROM orders o
                     LEFT JOIN users u ON o.user_id = u.id
                     LEFT JOIN users h ON o.handler_id = h.id
                     LEFT JOIN service_categories c ON o.category_id = c.id 
                     WHERE o.id = ?";

            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $orderId);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $orderDetails = $result->fetch_assoc();

                // Отримуємо коментарі до замовлення з пагінацією
                $commentPage = isset($_GET['comment_page']) ? (int)$_GET['comment_page'] : 1;
                $commentsPerPage = 5;
                $offset = ($commentPage - 1) * $commentsPerPage;

                // Рахуємо загальну кількість коментарів
                $countStmt = $conn->prepare("SELECT COUNT(*) FROM comments WHERE order_id = ?");
                $countStmt->bind_param("i", $orderId);
                $countStmt->execute();
                $totalComments = $countStmt->get_result()->fetch_row()[0];
                $totalCommentPages = ceil($totalComments / $commentsPerPage);

                // Отримуємо коментарі з пагінацією
                $commentsQuery = "SELECT c.*, u.username, u.role 
                                 FROM comments c
                                 LEFT JOIN users u ON c.user_id = u.id 
                                 WHERE c.order_id = ? 
                                 ORDER BY c.created_at DESC
                                 LIMIT ? OFFSET ?";

                $commentsStmt = $conn->prepare($commentsQuery);
                $commentsStmt->bind_param("iii", $orderId, $commentsPerPage, $offset);
                $commentsStmt->execute();
                $commentsResult = $commentsStmt->get_result();
                $orderComments = $commentsResult->fetch_all(MYSQLI_ASSOC);

                // Отримуємо файли, прикріплені до замовлення
                $filesQuery = "SELECT * FROM order_files WHERE order_id = ?";
                $filesStmt = $conn->prepare($filesQuery);
                $filesStmt->bind_param("i", $orderId);
                $filesStmt->execute();
                $filesResult = $filesStmt->get_result();
                $orderAttachedFiles = $filesResult->fetch_all(MYSQLI_ASSOC);

                // Отримуємо історію змін статусу
                $statusHistoryQuery = "
                    SELECT 
                        sh.*,
                        u.username,
                        u.role
                    FROM status_history sh
                    JOIN users u ON sh.user_id = u.id
                    WHERE sh.order_id = ?
                    ORDER BY sh.created_at DESC
                ";

                $statusHistoryStmt = $conn->prepare($statusHistoryQuery);
                $statusHistoryStmt->bind_param("i", $orderId);
                $statusHistoryStmt->execute();
                $statusHistoryResult = $statusHistoryStmt->get_result();
                $statusHistory = $statusHistoryResult->fetch_all(MYSQLI_ASSOC);

            } else {
                header("Location: admin_dashboard.php?section=orders");
                exit;
            }
        } catch (Exception $e) {
            $errorMessage = "Помилка при отриманні деталей замовлення: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="uk" data-theme="<?php echo $currentTheme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title><?php echo isset($orderDetails) ? "Замовлення #" . $orderDetails['id'] : "Адмін-панель замовлень"; ?> - Lagodi Service</title>

    <!-- Блокуємо рендеринг сторінки до встановлення теми -->
    <script>
        (function() {
            const storedTheme = localStorage.getItem('theme') || '<?php echo $currentTheme; ?>';
            document.documentElement.setAttribute('data-theme', storedTheme);
        })();
    </script>

    <!-- CSS файли -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/../style/dash.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/apexcharts@3.40.0/dist/apexcharts.min.css">

    <style>
        /* Змінні кольорів */
        :root[data-theme="light"] {
            --primary-color: #3498db;
            --primary-color-dark: #2980b9;
            --primary-color-light: #e1f0fa;
            --primary-hover: #2980b9;
            --secondary-color: #6c757d;
            --text-primary: #333333;
            --text-secondary: #6c757d;
            --card-bg: #ffffff;
            --bg-primary: #f8f9fa;
            --bg-secondary: #e9ecef;
            --border-color: #dee2e6;
            --success-color: #2ecc71;
            --success-bg: #e8f8f2;
            --danger-color: #e74c3c;
            --danger-bg: #fdedeb;
            --warning-color: #f39c12;
            --warning-bg: #fef6e8;
            --info-color: #3498db;
            --info-bg: #e7f3fb;
            --shadow-sm: 0 .125rem .25rem rgba(0,0,0,.075);
            --shadow: 0 .5rem 1rem rgba(0,0,0,.15);
            --shadow-lg: 0 1rem 3rem rgba(0,0,0,.175);
            --accent-color-1: #8e44ad;
            --accent-color-2: #16a085;
            --accent-color-3: #f1c40f;
            --accent-color-4: #e67e22;
            --accent-color-5: #1abc9c;
        }

        :root[data-theme="dark"] {
            --primary-color: #3498db;
            --primary-color-dark: #2980b9;
            --primary-color-light: #0d2536;
            --primary-hover: #4aa3df;
            --secondary-color: #6c757d;
            --text-primary: #e2e8f0;
            --text-secondary: #a0aec0;
            --card-bg: #2d3748;
            --bg-primary: #1a202c;
            --bg-secondary: #2d3748;
            --border-color: #4a5568;
            --success-color: #2ecc71;
            --success-bg: #112c1f;
            --danger-color: #e74c3c;
            --danger-bg: #2d1b19;
            --warning-color: #f39c12;
            --warning-bg: #302411;
            --info-color: #3498db;
            --info-bg: #13283b;
            --shadow-sm: 0 .125rem .25rem rgba(0,0,0,.15);
            --shadow: 0 .5rem 1rem rgba(0,0,0,.3);
            --shadow-lg: 0 1rem 3rem rgba(0,0,0,.4);
            --accent-color-1: #9b59b6;
            --accent-color-2: #1abc9c;
            --accent-color-3: #f1c40f;
            --accent-color-4: #e67e22;
            --accent-color-5: #16a085;
        }

        /* Загальні стилі */
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            background-color: var(--bg-primary);
            color: var(--text-primary);
            transition: background-color 0.3s, color 0.3s;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            font-size: 16px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        /* Сітка на основі CSS Grid */
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }

        .grid-col-2 {
            grid-column: span 2;
        }

        .grid-col-3 {
            grid-column: span 3;
        }

        /* Основні компоненти */
        .card {
            background-color: var(--card-bg);
            border-radius: 10px;
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            transition: transform 0.2s, box-shadow 0.3s;
            border: 1px solid var(--border-color);
        }

        .card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow);
        }

        .card-header {
            padding: 15px 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background-color: rgba(0, 0, 0, 0.03);
        }

        .card-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--text-primary);
            margin: 0;
        }

        .card-body {
            padding: 20px;
        }

        .card-footer {
            padding: 15px 20px;
            border-top: 1px solid var(--border-color);
            background-color: rgba(0, 0, 0, 0.02);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        /* Статус-бейджі з модернізованим дизайном */
        .badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 600;
            text-align: center;
            box-shadow: var(--shadow-sm);
            position: relative;
            overflow: hidden;
        }

        .badge.status-new {
            background-color: #f39c12;
            color: white;
        }

        .badge.status-in-progress {
            background-color: #3498db;
            color: white;
        }

        .badge.status-waiting {
            background-color: #8e44ad;
            color: white;
        }

        .badge.status-waiting-delivery {
            background-color: #ff9800;
            color: white;
        }

        .badge.status-completed {
            background-color: #2ecc71;
            color: white;
        }

        .badge.status-canceled {
            background-color: #e74c3c;
            color: white;
        }

        /* Форми та інпути */
        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        .form-control {
            display: block;
            width: 100%;
            padding: 0.75rem 1rem;
            font-size: 1rem;
            line-height: 1.5;
            color: var(--text-primary);
            background-color: var(--card-bg);
            background-clip: padding-box;
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
        }

        .form-control:focus {
            border-color: var(--primary-color);
            outline: 0;
            box-shadow: 0 0 0 0.2rem rgba(52, 152, 219, 0.25);
        }

        /* Стилі для неактивних елементів форми */
        .form-control:disabled,
        .form-control[readonly],
        select:disabled {
            background-color: var(--bg-secondary);
            opacity: 0.65;
            cursor: not-allowed;
        }

        /* Повідомлення про неможливість зміни статусу */
        .status-locked-message {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 15px;
            background-color: var(--warning-bg);
            color: var(--warning-color);
            border-radius: 8px;
            border: 1px solid var(--warning-color);
            margin-top: 10px;
            font-weight: 500;
        }

        /* Анімація для заблокованого статусу */
        @keyframes lock-shake {
            0% { transform: rotate(0); }
            25% { transform: rotate(-5deg); }
            50% { transform: rotate(0); }
            75% { transform: rotate(5deg); }
            100% { transform: rotate(0); }
        }

        .lock-icon-animated {
            animation: lock-shake 0.5s ease;
        }

        /* Стилі для кнопки "Взяти" з ефектом наведення */
        .btn-assign {
            background-color: var(--warning-color);
            color: white;
            transition: all 0.3s ease;
        }

        .btn-assign:hover {
            background-color: var(--warning-color);
            transform: translateY(-3px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }

        .btn-assign:active {
            transform: translateY(0);
        }

        /* Ефект пульсації для кнопки "Взяти" */
        @keyframes pulse-orange {
            0% { box-shadow: 0 0 0 0 rgba(243, 156, 18, 0.7); }
            70% { box-shadow: 0 0 0 10px rgba(243, 156, 18, 0); }
            100% { box-shadow: 0 0 0 0 rgba(243, 156, 18, 0); }
        }

        .btn-assign-pulse {
            animation: pulse-orange 2s infinite;
        }

        /* Кнопки */
        .btn {
            display: inline-block;
            font-weight: 500;
            text-align: center;
            vertical-align: middle;
            user-select: none;
            border: 1px solid transparent;
            padding: 0.75rem 1.5rem;
            font-size: 1rem;
            line-height: 1.5;
            border-radius: 0.5rem;
            transition: all 0.2s ease-in-out;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .btn:active {
            transform: translateY(0);
        }

        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background-color: var(--primary-hover);
        }

        .btn-success {
            background-color: var(--success-color);
            color: white;
        }

        .btn-success:hover {
            background-color: #27ae60;
        }

        .btn-danger {
            background-color: var(--danger-color);
            color: white;
        }

        .btn-danger:hover {
            background-color: #c0392b;
        }

        .btn-warning {
            background-color: var(--warning-color);
            color: white;
        }

        .btn-warning:hover {
            background-color: #d68910;
        }

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.875rem;
            border-radius: 0.375rem;
        }

        .btn-icon {
            padding: 0.75rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .btn-icon i {
            margin-right: 0;
        }

        .btn-with-icon {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Навігація та меню */
        .navbar {
            background-color: var(--card-bg);
            border-radius: 10px;
            padding: 15px 20px;
            margin-bottom: 20px;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-sm);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .navbar-brand {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .navbar-brand-text {
            margin: 0;
        }

        .nav-tabs {
            display: flex;
            flex-wrap: wrap;
            padding-left: 0;
            margin-bottom: 20px;
            list-style: none;
            border-bottom: 1px solid var(--border-color);
        }

        .nav-item {
            margin-bottom: -1px;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 1rem 1.5rem;
            border: 1px solid transparent;
            border-top-left-radius: 0.5rem;
            border-top-right-radius: 0.5rem;
            color: var(--text-primary);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.2s;
        }

        .nav-link:hover {
            color: var(--primary-color);
            border-color: transparent transparent var(--primary-color);
        }

        .nav-link.active {
            color: var(--primary-color);
            background-color: var(--card-bg);
            border-color: var(--border-color) var(--border-color) transparent;
        }

        .admin-sections {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            background-color: var(--card-bg);
            border-radius: 10px;
            padding: 10px;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-sm);
            overflow-x: auto;
        }

        .admin-sections a {
            padding: 10px 15px;
            border-radius: 8px;
            text-decoration: none;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
            font-weight: 500;
            white-space: nowrap;
        }

        .admin-sections a:hover,
        .admin-sections a.active {
            background-color: var(--primary-color);
            color: white;
        }

        /* Фільтри та пошук */
        .filters {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: center;
            margin-bottom: 20px;
            padding: 20px;
            background-color: var(--card-bg);
            border-radius: 10px;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-sm);
        }

        .filter-group {
            min-width: 200px;
            flex-grow: 1;
        }

        .search-group {
            position: relative;
            flex-grow: 2;
            min-width: 250px;
        }

        .search-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
        }

        .search-input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 2.5rem;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background-color: var(--card-bg);
            color: var(--text-primary);
            font-size: 1rem;
            transition: all 0.2s;
        }

        .search-input:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(52, 152, 219, 0.25);
            outline: none;
        }

        .filter-actions {
            display: flex;
            align-items: center;
        }

        /* Статистика та дашборд */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background-color: var(--card-bg);
            border-radius: 10px;
            overflow: hidden;
            padding: 20px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-color);
            transition: transform 0.3s, box-shadow 0.3s;
            display: flex;
            align-items: center;
            gap: 15px;
            cursor: pointer;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            background-color: var(--primary-color-light);
            color: var(--primary-color);
        }

        .stat-content {
            flex: 1;
        }

        .stat-number {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--text-primary);
            line-height: 1.2;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 0.875rem;
            color: var(--text-secondary);
            font-weight: 500;
        }

        /* Кольори для різних статистичних карток */
        .stat-icon-1 {
            background-color: rgba(142, 68, 173, 0.1);
            color: var(--accent-color-1);
        }

        .stat-icon-2 {
            background-color: rgba(22, 160, 133, 0.1);
            color: var(--accent-color-2);
        }

        .stat-icon-3 {
            background-color: rgba(241, 196, 15, 0.1);
            color: var(--accent-color-3);
        }

        .stat-icon-4 {
            background-color: rgba(230, 126, 34, 0.1);
            color: var(--accent-color-4);
        }

        .stat-icon-5 {
            background-color: rgba(26, 188, 156, 0.1);
            color: var(--accent-color-5);
        }

        /* Списки замовлень з однаковою висотою */
        .orders-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
        }

        .order-card {
            background-color: var(--card-bg);
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-sm);
            transition: transform 0.2s, box-shadow 0.3s;
            height: 100%;
            display: flex;
            flex-direction: column;
            min-height: 400px; /* Встановлюємо мінімальну висоту */
        }

        .order-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow);
        }

        /* Карточки завершених замовлень */
        .order-completed {
            opacity: 0.8;
            background-color: var(--bg-secondary);
        }

        /* Карточки завершених замовлень при наведенні */
        .order-completed:hover {
            opacity: 1;
        }

        .order-card-header {
            padding: 15px 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background-color: rgba(0, 0, 0, 0.03);
        }

        .order-service {
            font-weight: 600;
            font-size: 1.1rem;
            color: var(--text-primary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 70%;
        }

        .order-card-content {
            padding: 20px;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .order-number {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .order-info-list {
            list-style: none;
            padding: 0;
            margin: 0;
            display: flex;
            flex-direction: column;
            gap: 10px;
            flex-grow: 0;
        }

        .order-info-item {
            display: flex;
            align-items: flex-start;
        }

        .order-info-label {
            flex: 0 0 100px;
            font-weight: 500;
            color: var(--text-secondary);
        }

        .order-info-value {
            flex: 1;
            color: var(--text-primary);
        }

        .order-card-footer {
            padding: 15px 20px;
            border-top: 1px solid var(--border-color);
            background-color: rgba(0, 0, 0, 0.02);
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: auto; /* Прикріплюємо футер до низу карточки */
        }

        .order-date {
            font-size: 0.85rem;
            color: var(--text-secondary);
        }

        /* Заповнювач для замовлень без коментарів */
        .empty-comments {
            background-color: var(--bg-secondary);
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 5px;
            min-height: 80px;
            justify-content: center;
        }

        .empty-comments i {
            font-size: 1.5rem;
            opacity: 0.5;
        }

        /* Деталі замовлення */
        .order-detail-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .order-detail-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            color: var(--primary-color);
            text-decoration: none;
            padding: 8px 12px;
            border-radius: 8px;
            transition: background-color 0.2s;
        }

        .back-link:hover {
            background-color: var(--primary-color-light);
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .detail-item {
            background-color: var(--card-bg);
            padding: 15px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
        }

        .detail-label {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-bottom: 5px;
        }

        .detail-value {
            font-size: 1rem;
            font-weight: 500;
            color: var(--text-primary);
        }

        /* Коментарі */
        .comments-section {
            margin-top: 30px;
        }

        .comments-header {
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .comments-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .comments-count {
            background-color: var(--primary-color);
            color: white;
            font-size: 0.75rem;
            font-weight: 600;
            padding: 3px 8px;
            border-radius: 10px;
            margin-left: 5px;
        }

        .comments-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .comment-item {
            background-color: var(--card-bg);
            border-radius: 10px;
            overflow: hidden;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-sm);
            transition: transform 0.2s, box-shadow 0.3s;
        }

        .comment-item:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow);
        }

        .comment-item.admin-comment {
            border-left: 4px solid var(--danger-color);
        }

        .comment-item.own-comment {
            border-left: 4px solid var(--primary-color);
        }

        .comment-header {
            padding: 12px 15px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background-color: rgba(0, 0, 0, 0.03);
        }

        .comment-user {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .comment-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background-color: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 1rem;
        }

        .admin-comment .comment-avatar {
            background-color: var(--danger-color);
        }

        .comment-user-info {
            display: flex;
            flex-direction: column;
        }

        .comment-username {
            display: flex;
            align-items: center;
            gap: 5px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .admin-badge {
            display: inline-block;
            padding: 2px 8px;
            font-size: 0.7rem;
            font-weight: 600;
            color: white;
            background-color: var(--danger-color);
            border-radius: 10px;
        }

        .comment-time {
            font-size: 0.8rem;
            color: var(--text-secondary);
        }

        .comment-content {
            padding: 15px;
            color: var(--text-primary);
            line-height: 1.6;
        }

        .comment-form {
            background-color: var(--card-bg);
            border-radius: 10px;
            padding: 20px;
            margin-top: 20px;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-sm);
        }

        .comment-form-header {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .comment-form textarea {
            width: 100%;
            min-height: 120px;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background-color: var(--card-bg);
            color: var(--text-primary);
            font-size: 1rem;
            font-family: inherit;
            resize: vertical;
            margin-bottom: 15px;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .comment-form textarea:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(52, 152, 219, 0.25);
            outline: none;
        }

        /* Файли */
        .files-section {
            margin-top: 30px;
        }

        .files-header {
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .files-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .files-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 15px;
        }

        .file-item {
            background-color: var(--card-bg);
            border-radius: 10px;
            padding: 15px;
            border: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: transform 0.2s, box-shadow 0.3s;
            box-shadow: var(--shadow-sm);
        }

        .file-item:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow);
        }

        .file-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .file-icon {
            color: var(--primary-color);
            font-size: 1.5rem;
        }

        .file-name {
            font-size: 0.9rem;
            color: var(--text-primary);
            font-weight: 500;
            word-break: break-word;
        }

        .file-actions {
            display: flex;
            gap: 5px;
        }

        .file-download-btn {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background-color: var(--primary-color-light);
            color: var(--primary-color);
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
        }

        .file-download-btn:hover {
            background-color: var(--primary-color);
            color: white;
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        /* Пагінація */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 5px;
            margin-top: 20px;
        }

        .pagination-link {
            min-width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.2s;
        }

        .pagination-link:hover {
            background-color: var(--primary-color-light);
            color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .pagination-link.active {
            background-color: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        /* Форма зміни статусу */
        .status-form {
            margin-top: 20px;
            padding: 20px;
            background-color: var(--card-bg);
            border-radius: 10px;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-sm);
        }

        .status-form-header {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .status-select {
            width: 100%;
            padding: 12px 15px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background-color: var(--card-bg);
            color: var(--text-primary);
            font-size: 1rem;
            margin-bottom: 15px;
            transition: border-color 0.2s, box-shadow 0.2s;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%236b7280' viewBox='0 0 16 16'%3E%3Cpath fill-rule='evenodd' d='M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 15px center;
            background-size: 12px;
        }

        .status-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(52, 152, 219, 0.25);
            outline: none;
        }

        /* Сповіщення */
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            box-shadow: var(--shadow-sm);
        }

        .alert-success {
            background-color: var(--success-bg);
            border: 1px solid var(--success-color);
            color: var(--success-color);
        }

        .alert-danger {
            background-color: var(--danger-bg);
            border: 1px solid var(--danger-color);
            color: var(--danger-color);
        }

        .alert-warning {
            background-color: var(--warning-bg);
            border: 1px solid var(--warning-color);
            color: var(--warning-color);
        }

        .alert-info {
            background-color: var(--info-bg);
            border: 1px solid var(--info-color);
            color: var(--info-color);
        }

        /* Порожні стани */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            background-color: var(--card-bg);
            border-radius: 10px;
            border: 1px dashed var(--border-color);
            margin: 20px 0;
        }

        .empty-state-icon {
            font-size: 3rem;
            color: var(--text-secondary);
            margin-bottom: 15px;
            opacity: 0.6;
        }

        .empty-state-text {
            font-size: 1.1rem;
            color: var(--text-secondary);
            margin-bottom: 20px;
        }

        /* Статистика та метрики */
        .admin-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .admin-stat-card {
            background-color: var(--card-bg);
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-sm);
            transition: transform 0.2s, box-shadow 0.3s;
        }

        .admin-stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow);
        }

        .admin-stat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px 20px;
            border-bottom: 1px solid var(--border-color);
            background-color: rgba(0, 0, 0, 0.03);
        }

        .admin-stat-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .admin-stat-body {
            padding: 20px;
        }

        .admin-stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 10px;
            text-align: center;
        }

        .admin-stat-label {
            font-size: 0.9rem;
            color: var(--text-secondary);
            text-align: center;
        }

        .admin-stat-footer {
            padding: 15px 20px;
            border-top: 1px solid var(--border-color);
            background-color: rgba(0, 0, 0, 0.02);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .admin-stat-progress {
            height: 10px;
            background-color: var(--border-color);
            border-radius: 5px;
            overflow: hidden;
            margin-top: 15px;
        }

        .admin-stat-progress-bar {
            height: 100%;
            border-radius: 5px;
            background-color: var(--primary-color);
        }

        .admin-stat-indicator {
            font-size: 0.9rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .admin-stat-indicator.positive {
            color: var(--success-color);
        }

        .admin-stat-indicator.negative {
            color: var(--danger-color);
        }

        /* Профіль адміністратора */
        .admin-profile {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .admin-profile-header {
            display: flex;
            align-items: center;
            gap: 20px;
            padding: 20px;
            background-color: var(--card-bg);
            border-radius: 12px;
            border: 1px solid var(--border-color);
        }

        .admin-profile-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background-color: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            font-weight: 600;
        }

        .admin-profile-info {
            flex: 1;
        }

        .admin-profile-name {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 5px;
        }

        .admin-profile-role {
            color: var(--text-secondary);
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .admin-profile-stats {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 15px;
        }

        .admin-profile-stat {
            display: flex;
            flex-direction: column;
            align-items: center;
            min-width: 100px;
            padding: 10px;
            background-color: var(--bg-secondary);
            border-radius: 8px;
        }

        .admin-profile-stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .admin-profile-stat-label {
            font-size: 0.8rem;
            color: var(--text-secondary);
        }

        /* Клієнтські метрики */
        .client-stats-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            overflow: hidden;
            border-radius: 10px;
            margin-top: 20px;
        }

        .client-stats-table th,
        .client-stats-table td {
            padding: 12px 15px;
            text-align: left;
        }

        .client-stats-table th {
            background-color: rgba(0, 0, 0, 0.05);
            font-weight: 600;
            border-bottom: 1px solid var(--border-color);
        }

        .client-stats-table tr {
            background-color: var(--card-bg);
        }

        .client-stats-table tr:nth-child(even) {
            background-color: var(--bg-secondary);
        }

        .client-stats-table tr:hover {
            background-color: rgba(52, 152, 219, 0.05);
        }

        /* Графіки та діаграми */
        .chart-container {
            width: 100%;
            height: 350px;
            margin-bottom: 20px;
            background-color: var(--card-bg);
            border-radius: 10px;
            padding: 20px;
            border: 1px solid var(--border-color);
        }

        .chart-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            overflow-x: auto;
            padding-bottom: 5px;
        }

        .chart-tab {
            padding: 8px 15px;
            background-color: var(--bg-secondary);
            border-radius: 20px;
            color: var(--text-secondary);
            font-weight: 500;
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.2s;
        }

        .chart-tab.active {
            background-color: var(--primary-color);
            color: white;
        }

        .chart-tab:hover {
            background-color: var(--primary-color-light);
            color: var(--primary-color);
        }

        /* Timeline компонент */
        .timeline {
            position: relative;
            padding-left: 30px;
        }

        .timeline::before {
            content: '';
            position: absolute;
            top: 0;
            bottom: 0;
            left: 14px;
            width: 2px;
            background-color: var(--border-color);
        }

        .timeline-item {
            position: relative;
            margin-bottom: 20px;
        }

        .timeline-item:last-child {
            margin-bottom: 0;
        }

        .timeline-dot {
            position: absolute;
            left: -30px;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background-color: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 0.8rem;
            z-index: 1;
        }

        .timeline-content {
            background-color: var(--card-bg);
            border-radius: 8px;
            padding: 15px;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-sm);
        }

        .timeline-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }

        .timeline-title {
            font-weight: 600;
            color: var(--text-primary);
        }

        .timeline-date {
            font-size: 0.85rem;
            color: var(--text-secondary);
        }

        .timeline-body {
            color: var(--text-primary);
        }

        /* Додаткові утиліти */
        .text-primary { color: var(--primary-color) !important; }
        .text-success { color: var(--success-color) !important; }
        .text-danger { color: var(--danger-color) !important; }
        .text-warning { color: var(--warning-color) !important; }
        .text-secondary { color: var(--text-secondary) !important; }
        .text-accent-1 { color: var(--accent-color-1) !important; }
        .text-accent-2 { color: var(--accent-color-2) !important; }
        .text-accent-3 { color: var(--accent-color-3) !important; }
        .text-accent-4 { color: var(--accent-color-4) !important; }
        .text-accent-5 { color: var(--accent-color-5) !important; }

        .bg-primary { background-color: var(--primary-color) !important; }
        .bg-success { background-color: var(--success-color) !important; }
        .bg-danger { background-color: var(--danger-color) !important; }
        .bg-warning { background-color: var(--warning-color) !important; }
        .bg-secondary { background-color: var(--secondary-color) !important; }
        .bg-accent-1 { background-color: var(--accent-color-1) !important; }
        .bg-accent-2 { background-color: var(--accent-color-2) !important; }
        .bg-accent-3 { background-color: var(--accent-color-3) !important; }
        .bg-accent-4 { background-color: var(--accent-color-4) !important; }
        .bg-accent-5 { background-color: var(--accent-color-5) !important; }

        .mt-1 { margin-top: 0.25rem !important; }
        .mt-2 { margin-top: 0.5rem !important; }
        .mt-3 { margin-top: 1rem !important; }
        .mt-4 { margin-top: 1.5rem !important; }
        .mt-5 { margin-top: 3rem !important; }

        .mb-1 { margin-bottom: 0.25rem !important; }
        .mb-2 { margin-bottom: 0.5rem !important; }
        .mb-3 { margin-bottom: 1rem !important; }
        .mb-4 { margin-bottom: 1.5rem !important; }
        .mb-5 { margin-bottom: 3rem !important; }

        .ml-1 { margin-left: 0.25rem !important; }
        .ml-2 { margin-left: 0.5rem !important; }
        .ml-3 { margin-left: 1rem !important; }

        .mr-1 { margin-right: 0.25rem !important; }
        .mr-2 { margin-right: 0.5rem !important; }
        .mr-3 { margin-right: 1rem !important; }

        .p-1 { padding: 0.25rem !important; }
        .p-2 { padding: 0.5rem !important; }
        .p-3 { padding: 1rem !important; }
        .p-4 { padding: 1.5rem !important; }
        .p-5 { padding: 3rem !important; }

        .d-flex { display: flex !important; }
        .d-block { display: block !important; }
        .d-inline-block { display: inline-block !important; }
        .d-none { display: none !important; }

        .flex-column { flex-direction: column !important; }
        .justify-content-between { justify-content: space-between !important; }
        .justify-content-center { justify-content: center !important; }
        .justify-content-start { justify-content: flex-start !important; }
        .justify-content-end { justify-content: flex-end !important; }

        .align-items-center { align-items: center !important; }
        .align-items-start { align-items: flex-start !important; }
        .align-items-end { align-items: flex-end !important; }

        .gap-1 { gap: 0.25rem !important; }
        .gap-2 { gap: 0.5rem !important; }
        .gap-3 { gap: 1rem !important; }
        .gap-4 { gap: 1.5rem !important; }
        .gap-5 { gap: 3rem !important; }

        .w-100 { width: 100% !important; }
        .h-100 { height: 100% !important; }

        .rounded { border-radius: 0.25rem !important; }
        .rounded-circle { border-radius: 50% !important; }
        .border { border: 1px solid var(--border-color) !important; }

        .position-relative { position: relative !important; }
        .position-absolute { position: absolute !important; }

        .overflow-hidden { overflow: hidden !important; }
        .overflow-auto { overflow: auto !important; }

        .shadow-sm { box-shadow: var(--shadow-sm) !important; }
        .shadow { box-shadow: var(--shadow) !important; }
        .shadow-lg { box-shadow: var(--shadow-lg) !important; }

        .text-center { text-align: center !important; }
        .text-left { text-align: left !important; }
        .text-right { text-align: right !important; }

        .font-weight-bold { font-weight: 700 !important; }
        .font-weight-normal { font-weight: 400 !important; }
        .font-weight-light { font-weight: 300 !important; }

        /* Адаптивність */
        @media (max-width: 992px) {
            .stats-grid {
                grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            }

            .orders-grid {
                grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            }

            .detail-grid {
                grid-template-columns: 1fr;
            }

            .admin-stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .navbar {
                flex-direction: column;
                gap: 15px;
            }

            .navbar-brand {
                width: 100%;
                justify-content: space-between;
            }

            .system-info {
                width: 100%;
                justify-content: space-between;
            }

            .admin-sections {
                overflow-x: auto;
                padding: 10px 5px;
            }

            .filters {
                flex-direction: column;
                align-items: stretch;
            }

            .filter-group,
            .search-group {
                width: 100%;
            }

            .stats-grid {
                grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            }

            .orders-grid {
                grid-template-columns: 1fr;
            }

            .files-grid {
                grid-template-columns: 1fr;
            }

            .admin-profile-header {
                flex-direction: column;
                text-align: center;
            }

            .admin-profile-stats {
                justify-content: center;
            }
        }

        @media (max-width: 576px) {
            .container {
                padding: 10px;
            }

            .card-header,
            .card-body,
            .card-footer {
                padding: 15px;
            }

            .stat-card {
                padding: 15px;
            }

            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }

            .nav-link {
                padding: 0.75rem 1rem;
            }

            .btn {
                padding: 0.6rem 1.2rem;
            }

            .timeline {
                padding-left: 25px;
            }

            .timeline::before {
                left: 12px;
            }

            .timeline-dot {
                left: -25px;
                width: 16px;
                height: 16px;
                font-size: 0.7rem;
            }
        }

        /* Анімації */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        .fade-in {
            animation: fadeIn 0.3s ease-out forwards;
        }

        .pulse {
            animation: pulse 2s infinite;
        }

        /* Модальні вікна */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            overflow: auto;
            padding: 50px 0;
        }

        .modal.show {
            display: block;
        }

        .modal-dialog {
            max-width: 500px;
            margin: 1.75rem auto;
            background-color: var(--card-bg);
            border-radius: 10px;
            box-shadow: var(--shadow-lg);
            transform: translateY(0);
            transition: transform 0.3s ease-out;
        }

        .modal-content {
            position: relative;
            background-color: var(--card-bg);
            border-radius: 10px;
            overflow: hidden;
            border: 1px solid var(--border-color);
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px 20px;
            border-bottom: 1px solid var(--border-color);
        }

        .modal-title {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-secondary);
            cursor: pointer;
            padding: 0;
            margin: 0;
        }

        .modal-body {
            padding: 20px;
        }

        .modal-footer {
            padding: 15px 20px;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        /* Стилі для welcome-avatar */
        .welcome-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background-color: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 1.25rem;
        }

        /* Стилі для часу */
        .time-display {
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .time-label {
            font-size: 0.75rem;
            color: var(--text-secondary);
        }

        .time-value {
            font-weight: 600;
            color: var(--text-primary);
        }

        /* Стилі для системної інформації */
        .system-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        /* Стилі для індикатора ролі */
        .role-indicator {
            display: flex;
            align-items: center;
            gap: 5px;
            color: var(--text-secondary);
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
<div class="container">
    <!-- Навігаційна панель -->
    <div class="navbar">
        <div class="navbar-brand">
            <div class="welcome-avatar"><?= substr(htmlspecialchars($_SESSION['username']), 0, 2) ?></div>
            <div>
                <h1 class="navbar-brand-text">
                    Вітаємо, <span class="text-primary"><?= htmlspecialchars($_SESSION['username']) ?></span>
                </h1>
                <div class="role-indicator">
                    <?php if ($_SESSION['role'] === 'admin'): ?>
                        <i class="bi bi-shield-lock-fill text-primary"></i>
                        Адміністратор
                    <?php elseif ($_SESSION['role'] === 'junior_admin'): ?>
                        <i class="bi bi-shield text-primary"></i>
                        Молодший Адміністратор
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="system-info">
            <div class="time-display">
                <div class="time-label">Поточний час</div>
                <div class="time-value" id="currentTime">-</div>
            </div>

            <button id="themeSwitchBtn" class="btn btn-icon" aria-label="Змінити тему">
                <i class="bi <?php echo $currentTheme === 'dark' ? 'bi-sun' : 'bi-moon'; ?>"></i>
            </button>

            <a href="/../logout.php" class="btn btn-danger btn-with-icon">
                <i class="bi bi-box-arrow-right"></i>
                Вийти
            </a>
        </div>
    </div>

    <!-- Вкладки -->
    <div class="admin-sections">
        <a href="?section=orders" data-section="orders" class="<?= $activeSection === 'orders' ? 'active' : '' ?>">
            <i class="bi bi-list-check"></i>
            Замовлення
        </a>
        <a href="?section=stats" data-section="stats" class="<?= $activeSection === 'stats' ? 'active' : '' ?>">
            <i class="bi bi-bar-chart-fill"></i>
            Статистика
        </a>
        <?php if ($_SESSION['role'] === 'admin'): ?>
            <a href="?section=users" data-section="users" class="<?= $activeSection === 'users' ? 'active' : '' ?>">
                <i class="bi bi-people-fill"></i>
                Користувачі
            </a>
            <a href="?section=admin_stats" data-section="admin_stats" class="<?= $activeSection === 'admin_stats' ? 'active' : '' ?>">
                <i class="bi bi-person-badge"></i>
                Метрики адмінів
            </a>
            <a href="?section=logs" data-section="logs" class="<?= $activeSection === 'logs' ? 'active' : '' ?>">
                <i class="bi bi-journal-text"></i>
                Логи
            </a>
        <?php endif; ?>
    </div>

    <!-- Сповіщення -->
    <?php if ($errorMessage): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <div><?php echo $errorMessage; ?></div>
        </div>
    <?php endif; ?>

    <?php if ($successMessage): ?>
        <div class="alert alert-success">
            <i class="bi bi-check-circle-fill"></i>
            <div><?php echo $successMessage; ?></div>
        </div>
    <?php endif; ?>

    <!-- Секція замовлень -->
    <section id="orders" class="fade-in" style="display: <?= $activeSection === 'orders' ? 'block' : 'none' ?>;">
        <?php if (isset($orderDetails)): ?>
            <!-- Детальний перегляд замовлення -->
            <div class="order-detail-header">
                <div class="order-detail-title">
                    <a href="?section=orders" class="back-link">
                        <i class="bi bi-arrow-left"></i>
                    </a>
                    <i class="bi bi-clipboard-check text-primary"></i>
                    Замовлення #<?php echo $orderDetails['id']; ?>
                </div>
                <div class="badge <?php echo getStatusClass($orderDetails['status']); ?>">
                    <?php echo safeEcho($orderDetails['status']); ?>
                </div>
            </div>

            <!-- Картка з деталями замовлення -->
            <div class="card mb-4">
                <div class="card-header">
                    <h2 class="card-title"><?php echo safeEcho($orderDetails['service']); ?></h2>

                    <?php if (!empty($orderDetails['handler_name'])): ?>
                        <div class="d-flex align-items-center gap-2">
                            <span class="text-secondary">Обробляє:</span>
                            <span class="badge bg-primary"><?php echo safeEcho($orderDetails['handler_name']); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <div class="detail-grid">
                        <div class="detail-item">
                            <div class="detail-label">Номер замовлення</div>
                            <div class="detail-value">#<?php echo $orderDetails['id']; ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Клієнт</div>
                            <div class="detail-value"><?php echo safeEcho($orderDetails['username']); ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Категорія</div>
                            <div class="detail-value">
                                <?php echo !empty(trim($orderDetails['category_name'] ?? '')) ? safeEcho($orderDetails['category_name']) : 'Не вказано'; ?>
                            </div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Тип пристрою</div>
                            <div class="detail-value">
                                <?php echo !empty(trim($orderDetails['device_type'] ?? '')) ? safeEcho($orderDetails['device_type']) : 'Не вказано'; ?>
                            </div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Модель пристрою</div>
                            <div class="detail-value">
                                <?php echo !empty(trim($orderDetails['device_model'] ?? '')) ? safeEcho($orderDetails['device_model']) : 'Не вказано'; ?>
                            </div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Дата створення</div>
                            <div class="detail-value"><?php echo formatDate($orderDetails['created_at']); ?></div>
                        </div>
                    </div>

                    <?php if (!empty($orderDetails['description'])): ?>
                        <div class="mt-4">
                            <h3 class="mb-3"><i class="bi bi-card-text text-primary mr-2"></i> Опис</h3>
                            <div class="card">
                                <div class="card-body">
                                    <?php echo nl2br(safeEcho($orderDetails['description'])); ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Історія зміни статусу -->
                    <?php if (!empty($statusHistory)): ?>
                        <div class="mt-4">
                            <h3 class="mb-3"><i class="bi bi-clock-history text-primary mr-2"></i> Історія змін статусу</h3>
                            <div class="timeline">
                                <?php foreach ($statusHistory as $historyItem): ?>
                                    <div class="timeline-item">
                                        <div class="timeline-dot">
                                            <i class="bi bi-clock"></i>
                                        </div>
                                        <div class="timeline-content">
                                            <div class="timeline-header">
                                                <div class="timeline-title">
                                                    Статус змінено на <span class="badge <?= getStatusClass($historyItem['new_status']) ?>"><?= safeEcho($historyItem['new_status']) ?></span>
                                                </div>
                                                <div class="timeline-date"><?= formatDate($historyItem['created_at']) ?></div>
                                            </div>
                                            <div class="timeline-body">
                                                <div class="d-flex align-items-center gap-2 mb-2">
                                                    <span>Попередній статус:</span>
                                                    <span class="badge <?= getStatusClass($historyItem['previous_status']) ?>"><?= safeEcho($historyItem['previous_status']) ?></span>
                                                </div>
                                                <div class="d-flex align-items-center gap-2">
                                                    <span>Змінив:</span>
                                                    <strong><?= safeEcho($historyItem['username']) ?></strong>
                                                    <?php if (in_array($historyItem['role'], ['admin', 'junior_admin'])): ?>
                                                        <span class="badge <?= $historyItem['role'] === 'admin' ? 'status-canceled' : 'status-in-progress' ?>">
                                                        <?= $historyItem['role'] === 'admin' ? 'Адмін' : 'Мол. Адмін' ?>
                                                    </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="card-footer">
                    <div>Створено: <?php echo formatDate($orderDetails['created_at']); ?></div>
                </div>
            </div>

            <!-- Форма зміни статусу -->
            <?php if ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'junior_admin'): ?>
                <div class="status-form">
                    <div class="status-form-header">
                        <i class="bi bi-toggles text-primary"></i>
                        Зміна статусу замовлення
                    </div>

                    <?php
                    $isStatusLocked = in_array($orderDetails['status'], ['Завершено', 'Виконано', 'Не можливо виконати']);
                    $canChangeStatus = $_SESSION['role'] === 'admin' || !$isStatusLocked;
                    ?>

                    <?php if ($isStatusLocked && $_SESSION['role'] !== 'admin'): ?>
                        <div class="status-locked-message mt-2 mb-3">
                            <i class="bi bi-lock-fill lock-icon-animated"></i>
                            <span>Ви не можете змінювати статус замовлення "<?php echo $orderDetails['status']; ?>"</span>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="update_status.php" class="d-flex flex-column gap-3">
                        <input type="hidden" name="order_id" value="<?= $orderDetails['id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <select name="status" class="status-select" <?= $canChangeStatus ? '' : 'disabled' ?>>
                            <option value="Новий" <?= $orderDetails['status'] === 'Новий' ? 'selected' : '' ?>>Новий</option>
                            <option value="В роботі" <?= $orderDetails['status'] === 'В роботі' ? 'selected' : '' ?>>В роботі</option>
                            <option value="Очікується" <?= $orderDetails['status'] === 'Очікується' ? 'selected' : '' ?>>Очікується</option>
                            <option value="Очікується поставки товару" <?= $orderDetails['status'] === 'Очікується поставки товару' ? 'selected' : '' ?>>Очікується поставки товару</option>
                            <option value="Завершено" <?= $orderDetails['status'] === 'Завершено' ? 'selected' : '' ?>>Завершено</option>
                            <option value="Не можливо виконати" <?= $orderDetails['status'] === 'Не можливо виконати' ? 'selected' : '' ?>>Не можливо виконати</option>
                        </select>
                        <div class="d-flex gap-3">
                            <button type="submit" class="btn btn-primary btn-with-icon" <?= $canChangeStatus ? '' : 'disabled' ?>>
                                <i class="bi bi-check-circle"></i> Змінити статус
                            </button>

                            <!-- Кнопка для призначення собі замовлення -->
                            <?php if (empty($orderDetails['handler_id']) && !$isStatusLocked): ?>
                                <button type="button" class="btn btn-assign btn-with-icon btn-assign-pulse" onclick="assignToMe(<?= $orderDetails['id'] ?>)">
                                    <i class="bi bi-person-fill-check"></i> Призначити собі
                                </button>
                            <?php elseif ($orderDetails['handler_id'] == $_SESSION['user_id']): ?>
                                <button type="button" class="btn btn-success btn-with-icon" disabled>
                                    <i class="bi bi-person-check-fill"></i> Призначено вам
                                </button>
                            <?php else: ?>
                                <button type="button" class="btn btn-secondary btn-with-icon" disabled>
                                    <i class="bi bi-person-fill"></i> Призначено іншому адміну
                                </button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

            <!-- Файли -->
            <?php if (!empty($orderAttachedFiles)): ?>
                <div class="files-section">
                    <div class="files-header">
                        <div class="files-title">
                            <i class="bi bi-paperclip text-primary"></i>
                            Прикріплені файли
                            <span class="badge bg-primary"><?= count($orderAttachedFiles) ?></span>
                        </div>
                    </div>
                    <div class="files-grid">
                        <?php foreach ($orderAttachedFiles as $file): ?>
                            <?php
                            $ext = pathinfo($file['original_name'], PATHINFO_EXTENSION);
                            $icon = 'bi-file-earmark';

                            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                                $icon = 'bi-file-earmark-image';
                            } elseif (in_array($ext, ['pdf'])) {
                                $icon = 'bi-file-earmark-pdf';
                            } elseif (in_array($ext, ['doc', 'docx'])) {
                                $icon = 'bi-file-earmark-word';
                            } elseif (in_array($ext, ['xls', 'xlsx'])) {
                                $icon = 'bi-file-earmark-excel';
                            } elseif (in_array($ext, ['zip', 'rar', '7z'])) {
                                $icon = 'bi-file-earmark-zip';
                            } elseif (in_array($ext, ['txt'])) {
                                $icon = 'bi-file-earmark-text';
                            }
                            ?>
                            <div class="file-item">
                                <div class="file-info">
                                    <i class="bi <?php echo $icon; ?> file-icon"></i>
                                    <span class="file-name"><?php echo safeEcho($file['original_name']); ?></span>
                                </div>
                                <div class="file-actions">
                                    <a href="<?php echo isset($file['file_path']) ? str_replace('../../', '/', $file['file_path']) : '#'; ?>"
                                       download class="file-download-btn"
                                       title="Завантажити файл">
                                        <i class="bi bi-download"></i>
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Коментарі -->
            <div class="comments-section">
                <div class="comments-header">
                    <div class="comments-title">
                        <i class="bi bi-chat-left-text text-primary"></i>
                        Коментарі
                        <?php if (!empty($orderComments)): ?>
                            <span class="comments-count"><?= $totalComments ?? count($orderComments) ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($orderComments)): ?>
                    <div class="comments-list">
                        <?php foreach ($orderComments as $comment):
                            $isAdminComment = in_array($comment['role'], ['admin', 'junior_admin']);
                            $isOwnComment = isset($_SESSION['user_id']) && $comment['user_id'] == $_SESSION['user_id'];
                            ?>
                            <div class="comment-item <?= $isOwnComment ? 'own-comment' : '' ?> <?= $isAdminComment ? 'admin-comment' : '' ?>">
                                <div class="comment-header">
                                    <div class="comment-user">
                                        <div class="comment-avatar">
                                            <?php echo strtoupper(substr($comment['username'] ?? 'U', 0, 1)); ?>
                                        </div>
                                        <div class="comment-user-info">
                                            <div class="comment-username">
                                                <?php echo safeEcho($comment['username']); ?>
                                                <?php if ($isAdminComment): ?>
                                                    <span class="admin-badge"><?= $comment['role'] === 'admin' ? 'Адмін' : 'Мол. Адмін' ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="comment-time"><?php echo formatDate($comment['created_at']); ?></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="comment-content">
                                    <?php echo nl2br(safeEcho($comment['content'])); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if (isset($totalCommentPages) && $totalCommentPages > 1): ?>
                        <div class="pagination mt-4">
                            <?php for ($i = 1; $i <= $totalCommentPages; $i++): ?>
                                <a href="?section=orders&id=<?= $orderDetails['id'] ?>&comment_page=<?= $i ?>"
                                   class="pagination-link <?= $i == ($commentPage ?? 1) ? 'active' : '' ?>">
                                    <?= $i ?>
                                </a>
                            <?php endfor; ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="bi bi-chat-left"></i>
                        </div>
                        <div class="empty-state-text">Поки що немає коментарів</div>
                    </div>
                <?php endif; ?>

                <?php if (canAddComments($orderDetails['status'])): ?>
                    <div class="comment-form">
                        <div class="comment-form-header">
                            <i class="bi bi-plus-circle text-primary"></i> Додати новий коментар
                        </div>
                        <form method="post" action="admin_dashboard.php">
                            <input type="hidden" name="order_id" value="<?php echo $orderDetails['id']; ?>">
                            <textarea name="comment" placeholder="Напишіть ваш коментар тут..." required></textarea>
                            <button type="submit" name="add_comment" class="btn btn-primary btn-with-icon">
                                <i class="bi bi-send"></i> Відправити коментар
                            </button>
                        </form>
                    </div>
                <?php else: ?>
                    <div class="status-locked-message mt-4">
                        <i class="bi bi-lock-fill lock-icon-animated"></i>
                        <span>Додавання коментарів недоступне для замовлень зі статусом "<?php echo $orderDetails['status']; ?>"</span>
                    </div>
                <?php endif; ?>
            </div>

        <?php else: ?>
        <!-- Список замовлень -->
        <h2 class="section-title mb-4">
            <i class="bi bi-list-check text-primary"></i> Список замовлень
        </h2>

        <!-- Статистика замовлень -->
        <div class="stats-grid">
            <div class="stat-card" onclick="window.location.href='?section=orders&status=new'">
                <div class="stat-icon stat-icon-3">
                    <i class="bi bi-hourglass"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-number"><?= $stats['new'] ?></div>
                    <div class="stat-label">Нові</div>
                </div>
            </div>

            <div class="stat-card" onclick="window.location.href='?section=orders&status=in_progress'">
                <div class="stat-icon">
                    <i class="bi bi-gear"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-number"><?= $stats['in_progress'] ?></div>
                    <div class="stat-label">В роботі</div>
                </div>
            </div>

            <div class="stat-card" onclick="window.location.href='?section=orders&status=waiting'">
                <div class="stat-icon stat-icon-1">
                    <i class="bi bi-clock"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-number"><?= $stats['waiting'] ?></div>
                    <div class="stat-label">Очікується</div>
                </div>
            </div>

            <div class="stat-card" onclick="window.location.href='?section=orders&status=waiting_delivery'">
                <div class="stat-icon stat-icon-4">
                    <i class="bi bi-truck"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-number"><?= $stats['waiting_delivery'] ?? 0 ?></div>
                    <div class="stat-label">Очікується поставки</div>
                </div>
            </div>

            <div class="stat-card" onclick="window.location.href='?section=orders&status=completed'">
                <div class="stat-icon stat-icon-2">
                    <i class="bi bi-check-circle"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-number"><?= $stats['completed'] ?></div>
                    <div class="stat-label">Завершено</div>
                </div>
            </div>

            <div class="stat-card" onclick="window.location.href='?section=orders&status=canceled'">
                <div class="stat-icon stat-icon-5">
                    <i class="bi bi-x-circle"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-number"><?= $stats['canceled'] ?></div>
                    <div class="stat-label">Не можливо виконати</div>
                </div>
            </div>
        </div>

        <!-- Фільтри замовлень -->
        <form method="get" action="admin_dashboard.php" class="filters">
            <input type="hidden" name="section" value="orders">

            <div class="filter-group">
                <label for="status-filter">Статус замовлення</label>
                <select id="status-filter" name="status" class="form-control" onchange="this.form.submit()">
                    <option value="all" <?= !isset($statusFilter) || $statusFilter === 'all' ? 'selected' : '' ?>>Всі статуси</option>
                    <option value="new" <?= isset($statusFilter) && $statusFilter === 'new' ? 'selected' : '' ?>>Нові</option>
                    <option value="in_progress" <?= isset($statusFilter) && $statusFilter === 'in_progress' ? 'selected' : '' ?>>В роботі</option>
                    <option value="waiting" <?= isset($statusFilter) && $statusFilter === 'waiting' ? 'selected' : '' ?>>Очікується</option>
                    <option value="waiting_delivery" <?= isset($statusFilter) && $statusFilter === 'waiting_delivery' ? 'selected' : '' ?>>Очікується поставки товару</option>
                    <option value="completed" <?= isset($statusFilter) && $statusFilter === 'completed' ? 'selected' : '' ?>>Завершено</option>
                    <option value="canceled" <?= isset($statusFilter) && $statusFilter === 'canceled' ? 'selected' : '' ?>>Не можливо виконати</option>
                </select>
            </div>

            <div class="filter-group">
                <label for="sort-by">Сортування</label>
                <select id="sort-by" name="sort" class="form-control" onchange="this.form.submit()">
                    <option value="newest" <?= !isset($sortOrder) || $sortOrder === 'newest' ? 'selected' : '' ?>>Спочатку нові</option>
                    <option value="oldest" <?= isset($sortOrder) && $sortOrder === 'oldest' ? 'selected' : '' ?>>Спочатку старі</option>
                </select>
            </div>

            <div class="search-group">
                <label for="search-input">Пошук</label>
                <div class="d-flex w-100">
                    <div class="position-relative w-100">
                        <i class="bi bi-search search-icon"></i>
                        <input type="text" id="search-input" class="search-input" name="search"
                               placeholder="Пошук за номером, послугою або клієнтом..."
                               value="<?= isset($searchTerm) ? htmlspecialchars($searchTerm) : '' ?>">
                    </div>
                    <button type="submit" class="btn btn-primary ml-2">
                        <i class="bi bi-search"></i>
                    </button>
                </div>
            </div>

                <?php if ((isset($statusFilter) && $statusFilter !== 'all') || (isset($searchTerm) && !empty($searchTerm))): ?>
                    <div class="filter-actions">
                        <a href="?section=orders" class="btn btn-sm btn-with-icon">
                            <i class="bi bi-x-circle"></i> Очистити фільтри
                        </a>
                    </div>
                <?php endif; ?>
            </form>

            <!-- Список замовлень -->
            <?php if (!empty($orders)): ?>
                <div class="orders-grid mt-4">
                    <?php foreach ($orders as $order):
                        $orderIsDisabled = ($order['status'] === 'Завершено' || $order['status'] === 'Виконано' || $order['status'] === 'Не можливо виконати');
                        $isAssignedToMe = isset($order['handler_id']) && $order['handler_id'] == $_SESSION['user_id'];
                        $isAssignedToOther = isset($order['handler_id']) && !empty($order['handler_id']) && $order['handler_id'] != $_SESSION['user_id'];
                        $canChangeStatus = $_SESSION['role'] === 'admin' || !$orderIsDisabled;
                        ?>
                        <div class="order-card fade-in <?= $isAssignedToMe ? 'border-primary' : '' ?> <?= $orderIsDisabled ? 'order-completed' : '' ?>">
                            <div class="order-card-header">
                                <div class="order-service"><?= safeEcho($order['service']) ?></div>
                                <div class="badge <?= getStatusClass($order['status']) ?>">
                                    <?= safeEcho($order['status']) ?>
                                </div>
                            </div>

                            <div class="order-card-content">
                                <div class="order-number">
                                    Замовлення #<?= $order['id'] ?>
                                    <?php if ($isAssignedToMe): ?>
                                        <span class="badge bg-primary ml-2" title="Призначено вам">
                                            <i class="bi bi-person-check-fill"></i>
                                        </span>
                                    <?php elseif ($isAssignedToOther): ?>
                                        <span class="badge bg-secondary ml-2" title="Призначено: <?= safeEcho($order['handler_name']) ?>">
                                            <i class="bi bi-person-fill"></i>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <ul class="order-info-list">
                                    <li class="order-info-item">
                                        <div class="order-info-label">Клієнт:</div>
                                        <div class="order-info-value"><?= safeEcho($order['username']) ?></div>
                                    </li>
                                    <li class="order-info-item">
                                        <div class="order-info-label">Створено:</div>
                                        <div class="order-info-value"><?= formatDate($order['created_at']) ?></div>
                                    </li>
                                    <li class="order-info-item">
                                        <div class="order-info-label">Категорія:</div>
                                        <div class="order-info-value"><?= safeEcho($order['category_name'] ?? 'Не вказано') ?></div>
                                    </li>
                                    <?php if ($isAssignedToOther): ?>
                                        <li class="order-info-item">
                                            <div class="order-info-label">Відповідальний:</div>
                                            <div class="order-info-value text-primary"><?= safeEcho($order['handler_name']) ?></div>
                                        </li>
                                    <?php endif; ?>
                                </ul>

                                <!-- Коментарі до замовлення -->
                                <?php if (!empty($comments[$order['id']]['data'])): ?>
                                    <div class="mt-3">
                                        <div class="d-flex align-items-center mb-2">
                                            <i class="bi bi-chat-left-text text-primary mr-2"></i>
                                            <strong>Останні коментарі</strong>
                                            <span class="comments-count ml-2"><?= $comments[$order['id']]['total_comments'] ?></span>
                                        </div>
                                        <div class="card">
                                            <div class="card-body p-2">
                                                <?php foreach (array_slice($comments[$order['id']]['data'], 0, 2) as $comment): ?>
                                                    <div class="d-flex mb-2">
                                                        <div class="mr-2 text-primary"><?= safeEcho($comment['username']) ?>:</div>
                                                        <div class="text-truncate"><?= safeEcho(substr($comment['content'], 0, 70)) ?><?= strlen($comment['content']) > 70 ? '...' : '' ?></div>
                                                    </div>
                                                <?php endforeach; ?>
                                                <?php if ($comments[$order['id']]['total_comments'] > 2): ?>
                                                    <div class="text-primary text-center">+ ще <?= $comments[$order['id']]['total_comments'] - 2 ?> коментарів</div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="empty-comments">
                                        <i class="bi bi-chat-left text-secondary"></i>
                                        <span class="text-secondary">Немає коментарів</span>
                                    </div>
                                <?php endif; ?>

                                <!-- Файли замовлення (показуємо кількість) -->
                                <?php if (!empty($orderFiles[$order['id']])): ?>
                                    <div class="d-flex align-items-center mt-3">
                                        <i class="bi bi-paperclip text-primary mr-2"></i>
                                        <span>Прикріплених файлів: <strong><?= count($orderFiles[$order['id']]) ?></strong></span>
                                    </div>
                                <?php endif; ?>

                                <?php if (!$orderIsDisabled || $_SESSION['role'] === 'admin'): ?>
                                    <form method="POST" action="update_status.php" class="mt-3">
                                        <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                                        <div class="d-flex gap-2">
                                            <select name="status" class="form-control" <?= $canChangeStatus ? '' : 'disabled' ?>>
                                                <option value="Новий" <?= $order['status'] === 'Новий' ? 'selected' : '' ?>>Новий</option>
                                                <option value="В роботі" <?= $order['status'] === 'В роботі' ? 'selected' : '' ?>>В роботі</option>
                                                <option value="Очікується" <?= $order['status'] === 'Очікується' ? 'selected' : '' ?>>Очікується</option>
                                                <option value="Очікується поставки товару" <?= $order['status'] === 'Очікується поставки товару' ? 'selected' : '' ?>>Очікується поставки</option>
                                                <option value="Завершено" <?= $order['status'] === 'Завершено' ? 'selected' : '' ?>>Завершено</option>
                                                <option value="Не можливо виконати" <?= $order['status'] === 'Не можливо виконати' ? 'selected' : '' ?>>Не можливо виконати</option>
                                            </select>
                                            <button type="submit" class="btn btn-primary" <?= $canChangeStatus ? '' : 'disabled' ?>>
                                                <i class="bi bi-check-lg"></i>
                                            </button>
                                        </div>
                                    </form>
                                <?php elseif (!$canChangeStatus): ?>
                                    <div class="status-locked-message mt-3">
                                        <i class="bi bi-lock-fill"></i>
                                        <span>Ви не можете змінювати статус цього замовлення</span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="order-card-footer">
                                <div class="order-date">
                                    <i class="bi bi-calendar3 mr-1"></i>
                                    <?= formatDate($order['created_at']) ?>
                                </div>
                                <div class="d-flex gap-2">
                                    <?php if (empty($order['handler_id']) && !$orderIsDisabled): ?>
                                        <button type="button" class="btn btn-warning btn-sm btn-with-icon btn-assign" onclick="assignToMe(<?= $order['id'] ?>)">
                                            <i class="bi bi-person-fill-add"></i> Взяти
                                        </button>
                                    <?php elseif ($isAssignedToMe): ?>
                                        <button type="button" class="btn btn-success btn-sm btn-with-icon" disabled>
                                            <i class="bi bi-person-check"></i> Ваше
                                        </button>
                                    <?php endif; ?>
                                    <a href="?section=orders&id=<?= $order['id'] ?>" class="btn btn-primary btn-sm btn-with-icon">
                                        <i class="bi bi-eye"></i> Деталі
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state mt-4">
                    <div class="empty-state-icon">
                        <i class="bi bi-clipboard-x"></i>
                    </div>
                    <div class="empty-state-text">Замовлень немає або вони не відповідають вибраним фільтрам</div>
                    <?php if ((isset($statusFilter) && $statusFilter !== 'all') || (isset($searchTerm) && !empty($searchTerm))): ?>
                        <a href="?section=orders" class="btn btn-primary btn-with-icon">
                            <i class="bi bi-arrow-repeat"></i> Показати всі замовлення
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <!-- Секція статистики -->
    <section id="stats" class="fade-in" style="display: <?= $activeSection === 'stats' ? 'block' : 'none' ?>;">
        <h2 class="section-title mb-4">
            <i class="bi bi-bar-chart-fill text-primary"></i> Статистика замовлень
        </h2>

        <!-- Фільтри статистики -->
        <form method="get" action="admin_dashboard.php" class="filters">
            <input type="hidden" name="section" value="stats">

            <div class="filter-group">
                <label for="stats-date-range">Період</label>
                <select id="stats-date-range" name="stats_date_range" class="form-control" onchange="this.form.submit()">
                    <option value="today" <?= $statsDateRange === 'today' ? 'selected' : '' ?>>Сьогодні</option>
                    <option value="7days" <?= $statsDateRange === '7days' ? 'selected' : '' ?>>Останні 7 днів</option>
                    <option value="30days" <?= $statsDateRange === '30days' ? 'selected' : '' ?>>Останні 30 днів</option>
                    <option value="90days" <?= $statsDateRange === '90days' ? 'selected' : '' ?>>Останні 90 днів</option>
                    <option value="year" <?= $statsDateRange === 'year' ? 'selected' : '' ?>>Останній рік</option>
                </select>
            </div>
        </form>

        <!-- Основні метрики -->
        <div class="card mb-4">
            <div class="card-header">
                <h3 class="card-title">Ключові метрики за період</h3>
            </div>
            <div class="card-body">
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon stat-icon-3">
                            <i class="bi bi-diagram-3"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number">
                                <?php
                                $totalOrdersInPeriod = 0;
                                foreach ($timeStats as $stat) {
                                    $totalOrdersInPeriod += $stat['count'];
                                }
                                echo $totalOrdersInPeriod;
                                ?>
                            </div>
                            <div class="stat-label">Всього замовлень</div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon stat-icon-2">
                            <i class="bi bi-check-circle"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number">
                                <?php
                                $totalCompletedInPeriod = 0;
                                foreach ($timeStats as $stat) {
                                    $totalCompletedInPeriod += $stat['completed'];
                                }
                                echo $totalCompletedInPeriod;
                                ?>
                            </div>
                            <div class="stat-label">Завершено</div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="bi bi-percent"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number">
                                <?= $totalOrdersInPeriod > 0 ? round(($totalCompletedInPeriod / $totalOrdersInPeriod) * 100) : 0 ?>%
                            </div>
                            <div class="stat-label">Ефективність</div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon stat-icon-1">
                            <i class="bi bi-speedometer2"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number">
                                <?php
                                $avgPerDay = count($timeStats) > 0 ? round($totalOrdersInPeriod / count($timeStats), 1) : 0;
                                echo $avgPerDay;
                                ?>
                            </div>
                            <div class="stat-label">Замовлень на день</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Графік динаміки замовлень -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title">Динаміка замовлень</h3>
                <div class="chart-tabs">
                    <div class="chart-tab active" data-chart="ordersChart">Загальна</div>
                    <div class="chart-tab" data-chart="ordersTypesChart">За статусами</div>
                </div>
            </div>
            <div class="card-body">
                <div class="chart-container" id="ordersChartContainer">
                    <div id="ordersChart"></div>
                </div>
                <div class="chart-container" id="ordersTypesChartContainer" style="display: none;">
                    <div id="ordersTypesChart"></div>
                </div>
            </div>
        </div>

        <!-- Графік продуктивності по днях тижня -->
        <div class="card mb-4">
            <div class="card-header">
                <h3 class="card-title">Продуктивність по днях тижня</h3>
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <div id="weekdayChart"></div>
                </div>
            </div>
        </div>

        <!-- Розподіл замовлень за категоріями -->
        <div class="card mb-4">
            <div class="card-header">
                <h3 class="card-title">Розподіл замовлень за категоріями</h3>
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <div id="categoriesChart"></div>
                </div>
            </div>
        </div>

        <!-- Топ клієнтів -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Топ-10 активних клієнтів</h3>
            </div>
            <div class="card-body">
                <?php if (!empty($clientStats)): ?>
                    <div class="table-responsive">
                        <table class="client-stats-table">
                            <thead>
                            <tr>
                                <th>#</th>
                                <th>Клієнт</th>
                                <th>Кількість замовлень</th>
                                <th>Завершено</th>
                                <th>% Завершених</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($clientStats as $index => $client): ?>
                                <tr>
                                    <td><?= $index + 1 ?></td>
                                    <td><?= safeEcho($client['username']) ?></td>
                                    <td><?= $client['order_count'] ?></td>
                                    <td><?= $client['completed_orders'] ?></td>
                                    <td>
                                        <?= $client['order_count'] > 0
                                            ? round(($client['completed_orders'] / $client['order_count']) * 100) . '%'
                                            : '0%'
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="bi bi-people"></i>
                        </div>
                        <div class="empty-state-text">Недостатньо даних для відображення статистики клієнтів</div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Секція статистики адмінів (доступна лише для адміністраторів) -->
    <?php if ($_SESSION['role'] === 'admin'): ?>
        <section id="admin_stats" class="fade-in" style="display: <?= $activeSection === 'admin_stats' ? 'block' : 'none' ?>;">
            <h2 class="section-title mb-4">
                <i class="bi bi-person-badge text-primary"></i> Метрики адміністраторів
            </h2>

            <!-- Фільтри для статистики адмінів -->
            <form method="get" action="admin_dashboard.php" class="filters">
                <input type="hidden" name="section" value="admin_stats">

                <div class="filter-group">
                    <label for="stats-user">Адміністратор</label>
                    <select id="stats-user" name="stats_user" class="form-control" onchange="this.form.submit()">
                        <option value="all" <?= $statsUserFilter === 'all' ? 'selected' : '' ?>>Всі адміністратори</option>
                        <?php foreach ($adminList as $admin): ?>
                            <option value="<?= $admin['id'] ?>" <?= $statsUserFilter == $admin['id'] ? 'selected' : '' ?>>
                                <?= safeEcho($admin['username']) ?> (<?= $admin['role'] === 'admin' ? 'Адмін' : 'Мол. Адмін' ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="admin-stats-date-range">Період</label>
                    <select id="admin-stats-date-range" name="stats_date_range" class="form-control" onchange="this.form.submit()">
                        <option value="today" <?= $statsDateRange === 'today' ? 'selected' : '' ?>>Сьогодні</option>
                        <option value="7days" <?= $statsDateRange === '7days' ? 'selected' : '' ?>>Останні 7 днів</option>
                        <option value="30days" <?= $statsDateRange === '30days' ? 'selected' : '' ?>>Останні 30 днів</option>
                        <option value="90days" <?= $statsDateRange === '90days' ? 'selected' : '' ?>>Останні 90 днів</option>
                        <option value="year" <?= $statsDateRange === 'year' ? 'selected' : '' ?>>Останній рік</option>
                    </select>
                </div>
            </form>

            <?php if ($statsUserFilter === 'all'): ?>
                <!-- Загальна статистика всіх адміністраторів -->
                <div class="admin-stats-grid">
                    <?php foreach ($adminStats as $admin): ?>
                        <div class="admin-stat-card">
                            <div class="admin-stat-header">
                                <div class="admin-stat-title">
                                    <i class="bi bi-person-circle"></i>
                                    <?= safeEcho($admin['username']) ?>
                                </div>
                                <span class="badge <?= $admin['role'] === 'admin' ? 'status-canceled' : 'status-in-progress' ?>">
                                    <?= $admin['role'] === 'admin' ? 'Адмін' : 'Мол. Адмін' ?>
                                </span>
                            </div>
                            <div class="admin-stat-body">
                                <div class="admin-stat-value"><?= $admin['total_orders'] ?? 0 ?></div>
                                <div class="admin-stat-label">Всього замовлень</div>

                                <div class="admin-stat-progress mt-3">
                                    <?php
                                    $completionRate = $admin['total_orders'] > 0
                                        ? ($admin['completed_orders'] / $admin['total_orders']) * 100
                                        : 0;
                                    ?>
                                    <div class="admin-stat-progress-bar" style="width: <?= $completionRate ?>%;"></div>
                                </div>

                                <div class="d-flex justify-content-between mt-2">
                                    <div>Завершено: <strong><?= $admin['completed_orders'] ?? 0 ?></strong></div>
                                    <div><?= round($completionRate) ?>%</div>
                                </div>
                            </div>
                            <div class="admin-stat-footer">
                                <div class="admin-stat-indicator <?= $admin['avg_completion_time'] < 48 ? 'positive' : 'negative' ?>">
                                    <i class="bi <?= $admin['avg_completion_time'] < 48 ? 'bi-clock' : 'bi-clock-history' ?>"></i>
                                    Середній час виконання: <?= round($admin['avg_completion_time'] ?? 0) ?> год.
                                </div>
                                <a href="?section=admin_stats&stats_user=<?= $admin['id'] ?>" class="btn btn-sm btn-primary">
                                    Деталі
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Графік продуктивності адміністраторів -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h3 class="card-title">Порівняння продуктивності адміністраторів</h3>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <div id="adminComparisonChart"></div>
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <!-- Детальна статистика вибраного адміністратора -->
                <?php if ($selectedAdminStats): ?>
                    <div class="admin-profile mb-4">
                        <div class="admin-profile-header">
                            <div class="admin-profile-avatar">
                                <?= strtoupper(substr($selectedAdminStats['username'], 0, 1)) ?>
                            </div>
                            <div class="admin-profile-info">
                                <div class="admin-profile-name"><?= safeEcho($selectedAdminStats['username']) ?></div>
                                <div class="admin-profile-role">
                                    <i class="bi <?= $selectedAdminStats['role'] === 'admin' ? 'bi-shield-lock-fill' : 'bi-shield' ?> text-primary"></i>
                                    <?= $selectedAdminStats['role'] === 'admin' ? 'Адміністратор' : 'Молодший адміністратор' ?>
                                </div>

                                <div class="admin-profile-stats">
                                    <div class="admin-profile-stat">
                                        <div class="admin-profile-stat-value"><?= $selectedAdminStats['total_orders'] ?? 0 ?></div>
                                        <div class="admin-profile-stat-label">Замовлень</div>
                                    </div>
                                    <div class="admin-profile-stat">
                                        <div class="admin-profile-stat-value"><?= $selectedAdminStats['completed_orders'] ?? 0 ?></div>
                                        <div class="admin-profile-stat-label">Завершено</div>
                                    </div>
                                    <div class="admin-profile-stat">
                                        <div class="admin-profile-stat-value"><?= $selectedAdminStats['in_progress_orders'] ?? 0 ?></div>
                                        <div class="admin-profile-stat-label">В роботі</div>
                                    </div>
                                    <div class="admin-profile-stat">
                                        <div class="admin-profile-stat-value"><?= round($selectedAdminStats['avg_completion_time'] ?? 0) ?></div>
                                        <div class="admin-profile-stat-label">Год. на замовл.</div>
                                    </div>
                                    <div class="admin-profile-stat">
                                        <div class="admin-profile-stat-value">
                                            <?= $selectedAdminStats['total_orders'] > 0
                                                ? round(($selectedAdminStats['completed_orders'] / $selectedAdminStats['total_orders']) * 100)
                                                : 0 ?>%
                                        </div>
                                        <div class="admin-profile-stat-label">Ефективність</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Графік активності за період -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h3 class="card-title">Активність за період</h3>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <div id="adminActivityChart"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Розподіл за категоріями -->
                    <?php if (!empty($adminCategoryStats)): ?>
                        <div class="card mb-4">
                            <div class="card-header">
                                <h3 class="card-title">Розподіл за категоріями</h3>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <div id="adminCategoriesChart"></div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="bi bi-person-x"></i>
                        </div>
                        <div class="empty-state-text">Інформація про адміністратора не знайдена або відсутня статистика за вибраний період</div>
                        <a href="?section=admin_stats" class="btn btn-primary mt-3">
                            Повернутися до загальної статистики
                        </a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <!-- Секція користувачів (тільки для адмінів) -->
    <?php if ($_SESSION['role'] === 'admin'): ?>
        <section id="users" class="fade-in" style="display: <?= $activeSection === 'users' ? 'block' : 'none' ?>;">
            <h2 class="section-title mb-4">
                <i class="bi bi-people-fill text-primary"></i> Керування користувачами
            </h2>

            <!-- Форма пошуку користувачів -->
            <form method="GET" class="filters">
                <input type="hidden" name="section" value="users">
                <div class="search-group">
                    <label for="user-search">Пошук користувачів</label>
                    <div class="d-flex w-100">
                        <div class="position-relative w-100">
                            <i class="bi bi-search search-icon"></i>
                            <input type="text" id="user-search" class="search-input" name="user_search"
                                   placeholder="Введіть логін користувача..."
                                   value="<?= htmlspecialchars($userSearch) ?>">
                        </div>
                        <button type="submit" class="btn btn-primary ml-2">
                            <i class="bi bi-search"></i>
                        </button>
                    </div>
                </div>
                <?php if (!empty($userSearch)): ?>
                    <div class="filter-actions">
                        <a href="?section=users" class="btn btn-sm btn-with-icon">
                            <i class="bi bi-x-circle"></i> Очистити пошук
                        </a>
                    </div>
                <?php endif; ?>
            </form>

            <!-- Кнопка додавання нового користувача -->
            <div class="d-flex justify-content-end mb-4">
                <button type="button" id="addUserBtn" class="btn btn-success btn-with-icon">
                    <i class="bi bi-person-plus"></i> Додати користувача
                </button>
            </div>

            <!-- Список користувачів -->
            <?php if (!empty($users)): ?>
                <div class="grid">
                    <?php foreach ($users as $user): ?>
                        <div class="card fade-in">
                            <div class="card-header">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="comment-avatar">
                                        <?php echo strtoupper(substr($user['username'] ?? 'U', 0, 1)); ?>
                                    </div>
                                    <h3 class="card-title"><?= htmlspecialchars($user['username']) ?></h3>
                                </div>
                                <div>
                                    <?php if ($user['role'] === 'admin'): ?>
                                        <span class="badge status-completed">Адміністратор</span>
                                    <?php elseif ($user['role'] === 'junior_admin'): ?>
                                        <span class="badge status-in-progress">Молодший адмін</span>
                                    <?php else: ?>
                                        <span class="badge status-new">Користувач</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="update_user.php" class="d-flex flex-column gap-3">
                                    <input type="hidden" name="section" value="<?= $activeSection ?>">
                                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                                    <div class="form-group">
                                        <label for="username-<?= $user['id'] ?>">Логін</label>
                                        <input type="text" id="username-<?= $user['id'] ?>" name="username" value="<?= htmlspecialchars($user['username']) ?>" class="form-control" required>
                                    </div>

                                    <div class="form-group">
                                        <label for="role-<?= $user['id'] ?>">Роль</label>
                                        <select id="role-<?= $user['id'] ?>" name="role" class="form-control">
                                            <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Адміністратор</option>
                                            <option value="junior_admin" <?= $user['role'] === 'junior_admin' ? 'selected' : '' ?>>Молодший адмін</option>
                                            <option value="user" <?= $user['role'] === 'user' ? 'selected' : '' ?>>Користувач</option>
                                        </select>
                                    </div>

                                    <div>
                                        <button type="submit" class="btn btn-primary btn-with-icon">
                                            <i class="bi bi-check-circle"></i> Оновити
                                        </button>
                                    </div>
                                </form>

                                <?php if (isset($user['blocked_until']) && strtotime($user['blocked_until']) > time()): ?>
                                    <div class="alert alert-danger mt-3">
                                        <div class="d-flex flex-column">
                                            <div><strong>Заблоковано до:</strong> <?= htmlspecialchars($user['blocked_until']) ?></div>
                                            <div><strong>Причина:</strong> <?= htmlspecialchars($user['block_reason']) ?></div>
                                        </div>
                                    </div>
                                    <button type="button" class="btn btn-success btn-with-icon mt-2" onclick="unblockUser(<?= $user['id'] ?>)">
                                        <i class="bi bi-unlock"></i> Розблокувати
                                    </button>
                                <?php else: ?>
                                    <div class="mt-3">
                                        <button type="button" class="btn btn-warning btn-with-icon" onclick="toggleBlockForm(<?= $user['id'] ?>)">
                                            <i class="bi bi-lock"></i> Заблокувати
                                        </button>
                                    </div>
                                    <div id="block-form-<?= $user['id'] ?>" style="display:none; margin-top: 15px;">
                                        <form method="POST" action="block_user.php" class="d-flex flex-column gap-3">
                                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">

                                            <div class="form-group">
                                                <label for="block-reason-<?= $user['id'] ?>">Причина блокування</label>
                                                <input type="text" id="block-reason-<?= $user['id'] ?>" name="block_reason" class="form-control" required>
                                            </div>

                                            <div class="form-group">
                                                <label for="blocked-until-<?= $user['id'] ?>">Час блокування (до)</label>
                                                <input type="datetime-local" id="blocked-until-<?= $user['id'] ?>" name="blocked_until" class="form-control" required>
                                            </div>

                                            <div>
                                                <button type="submit" class="btn btn-danger btn-with-icon">
                                                    <i class="bi bi-lock-fill"></i> Заблокувати
                                                </button>
                                                <button type="button" class="btn btn-secondary btn-with-icon ml-2" onclick="toggleBlockForm(<?= $user['id'] ?>)">
                                                    <i class="bi bi-x"></i> Скасувати
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="card-footer">
                                <button type="button" class="btn btn-danger btn-with-icon" onclick="confirmDelete(<?= $user['id'] ?>)">
                                    <i class="bi bi-trash"></i> Видалити
                                </button>

                                <button type="button" class="btn btn-secondary btn-with-icon" onclick="resetPassword(<?= $user['id'] ?>)">
                                    <i class="bi bi-key"></i> Скинути пароль
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <i class="bi bi-people"></i>
                    </div>
                    <div class="empty-state-text">Користувачів не знайдено</div>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <!-- Секція логів (тільки для адмінів) -->
    <?php if ($_SESSION['role'] === 'admin'): ?>
        <section id="logs" class="fade-in" style="display: <?= $activeSection === 'logs' ? 'block' : 'none' ?>;">
            <h2 class="section-title mb-4">
                <i class="bi bi-journal-text text-primary"></i> Системні логи
            </h2>

            <!-- Фільтри логів -->
            <form method="GET" class="filters">
                <input type="hidden" name="section" value="logs">

                <div class="filter-group">
                    <label for="log-user">Користувач</label>
                    <input type="text" id="log-user" name="log_user" class="form-control"
                           placeholder="Фільтр за користувачем"
                           value="<?= htmlspecialchars($logUser) ?>">
                </div>

                <div class="filter-group">
                    <label for="log-date">Дата</label>
                    <input type="date" id="log-date" name="log_date" class="form-control"
                           value="<?= htmlspecialchars($logDate) ?>">
                </div>

                <div class="filter-group">
                    <label for="log-action">Дія</label>
                    <input type="text" id="log-action" name="log_action" class="form-control"
                           placeholder="Фільтр за діями"
                           value="<?= htmlspecialchars($logAction) ?>">
                </div>

                <div class="filter-group d-flex align-items-end">
                    <button type="submit" class="btn btn-primary btn-with-icon">
                        <i class="bi bi-funnel"></i> Фільтрувати
                    </button>
                </div>

                <?php if (!empty($logUser) || !empty($logDate) || !empty($logAction)): ?>
                    <div class="filter-actions">
                        <a href="?section=logs" class="btn btn-sm btn-with-icon">
                            <i class="bi bi-x-circle"></i> Очистити фільтри
                        </a>
                    </div>
                <?php endif; ?>
            </form>

            <!-- Таблиця логів -->
            <?php if (!empty($logs)): ?>
                <div class="card mt-4">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                <tr>
                                    <th>Дата та час</th>
                                    <th>Користувач</th>
                                    <th>Дія</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($logs as $log): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($log['created_at']) ?></td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <?php if ($log['role'] === 'admin'): ?>
                                                    <i class="bi bi-shield-lock-fill text-danger"></i>
                                                <?php elseif ($log['role'] === 'junior_admin'): ?>
                                                    <i class="bi bi-shield text-primary"></i>
                                                <?php else: ?>
                                                    <i class="bi bi-person text-secondary"></i>
                                                <?php endif; ?>

                                                <span><?= htmlspecialchars($log['username']) ?></span>

                                                <?php if (isset($log['role'])): ?>
                                                    <?php if ($log['role'] === 'admin'): ?>
                                                        <span class="badge status-canceled">Адміністратор</span>
                                                    <?php elseif ($log['role'] === 'junior_admin'): ?>
                                                        <span class="badge status-in-progress">Мол. Адміністратор</span>
                                                    <?php else: ?>
                                                        <span class="badge status-new">Користувач</span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td><?= htmlspecialchars($log['action']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Пагінація логів -->
                <?php if ($totalPages > 1): ?>
                    <div class="pagination mt-4">
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <?php
                            $queryParams = $_GET;
                            $queryParams['log_page'] = $i;
                            $queryString = http_build_query($queryParams);
                            ?>
                            <a href="?<?= $queryString ?>" class="pagination-link <?= $i == $logPage ? 'active' : '' ?>">
                                <?= $i ?>
                            </a>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="empty-state mt-4">
                    <div class="empty-state-icon">
                        <i class="bi bi-journal-x"></i>
                    </div>
                    <div class="empty-state-text">Логи не знайдено</div>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>

<!-- JavaScript -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/apexcharts@3.40.0/dist/apexcharts.min.js"></script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Функція для отримання URL параметра
        function getUrlParam(name) {
            const urlParams = new URLSearchParams(window.location.search);
            return urlParams.get(name);
        }

        // Визначаємо активну секцію
        const activeSection = getUrlParam('section') || 'orders';

        // Показуємо активну секцію
        document.querySelectorAll('section').forEach(s => s.style.display = 'none');
        const currentSection = document.getElementById(activeSection);
        if (currentSection) {
            currentSection.style.display = 'block';
        }

        // Обробники для навігації
        document.querySelectorAll('.admin-sections a').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const section = this.getAttribute('data-section');
                window.location.href = `?section=${section}`;
            });
        });

        // Переключення теми
        const themeSwitchBtn = document.getElementById('themeSwitchBtn');
        if (themeSwitchBtn) {
            themeSwitchBtn.addEventListener('click', function() {
                const htmlElement = document.documentElement;
                const currentTheme = htmlElement.getAttribute('data-theme') || 'dark';
                const newTheme = currentTheme === 'dark' ? 'light' : 'dark';

                htmlElement.setAttribute('data-theme', newTheme);
                localStorage.setItem('theme', newTheme);
                document.cookie = `theme=${newTheme}; path=/; max-age=2592000`;

                const icon = this.querySelector('i');
                if (icon) {
                    icon.className = newTheme === 'dark' ? 'bi bi-sun' : 'bi bi-moon';
                }

                // Оновлюємо графіки при зміні теми
                updateCharts(newTheme);
            });
        }

        // Оновлення часу
        function updateCurrentTime() {
            const now = new Date();
            const options = {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hour12: false
            };
            const formattedTime = now.toLocaleString('uk-UA', options);

            const timeElement = document.getElementById('currentTime');
            if (timeElement) {
                timeElement.textContent = formattedTime;
            }
        }

        // Запускаємо оновлення часу кожну секунду
        updateCurrentTime();
        setInterval(updateCurrentTime, 1000);

        // Модальне вікно для додавання користувача
        const addUserBtn = document.getElementById('addUserBtn');
        if (addUserBtn) {
            const modalHTML = `
                <div class="modal" id="addUserModal" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Додати нового користувача</h5>
                                <button type="button" class="modal-close" data-dismiss="modal" aria-label="Close">×</button>
                            </div>
                            <div class="modal-body">
                                <form id="addUserForm" method="POST" action="add_user.php">
                                    <input type="hidden" name="csrf_token" value="${document.querySelector('input[name="csrf_token"]').value}">

                                    <div class="form-group">
                                        <label for="new-username">Логін</label>
                                        <input type="text" id="new-username" name="username" class="form-control" required>
                                    </div>

                                    <div class="form-group">
                                        <label for="new-password">Пароль</label>
                                        <input type="password" id="new-password" name="password" class="form-control" required>
                                    </div>

                                    <div class="form-group">
                                        <label for="new-role">Роль</label>
                                        <select id="new-role" name="role" class="form-control">
                                            <option value="user">Користувач</option>
                                            <option value="junior_admin">Молодший адмін</option>
                                            <option value="admin">Адміністратор</option>
                                        </select>
                                    </div>
                                </form>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-dismiss="modal">Скасувати</button>
                                <button type="submit" form="addUserForm" class="btn btn-primary">Додати</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            // Додаємо модальне вікно в DOM
            if (!document.getElementById('addUserModal')) {
                document.body.insertAdjacentHTML('beforeend', modalHTML);

                const modal = document.getElementById('addUserModal');

                // Функція для відкриття модального вікна
                addUserBtn.addEventListener('click', function() {
                    modal.style.display = 'block';
                    modal.classList.add('show');
                });

                // Функція для закриття модального вікна
                document.querySelectorAll('[data-dismiss="modal"]').forEach(closeBtn => {
                    closeBtn.addEventListener('click', function() {
                        modal.style.display = 'none';
                        modal.classList.remove('show');
                    });
                });

                // Закриття при кліку поза модальним вікном
                window.addEventListener('click', function(event) {
                    if (event.target === modal) {
                        modal.style.display = 'none';
                        modal.classList.remove('show');
                    }
                });
            }
        }

        // Функції для керування користувачами
        window.toggleBlockForm = function(userId) {
            const form = document.getElementById('block-form-' + userId);
            form.style.display = form.style.display === 'none' ? 'block' : 'none';
        };

        window.unblockUser = function(userId) {
            if (confirm('Розблокувати користувача?')) {
                window.location.href = '/admin_panel/unblock_user.php?id=' + userId + '&csrf_token=' + encodeURIComponent(document.querySelector('input[name="csrf_token"]').value);
            }
        };

        window.confirmDelete = function(userId) {
            if (confirm('Видалити користувача? Ця дія безповоротна.')) {
                window.location.href = '/admin_panel/delete_user.php?id=' + userId + '&csrf_token=' + encodeURIComponent(document.querySelector('input[name="csrf_token"]').value);
            }
        };

        window.resetPassword = function(userId) {
            if (confirm('Скинути пароль для цього користувача?')) {
                window.location.href = '/admin_panel/reset_password.php?id=' + userId + '&csrf_token=' + encodeURIComponent(document.querySelector('input[name="csrf_token"]').value);
            }
        };

        window.assignToMe = function(orderId) {
            if (confirm('Призначити це замовлення собі?')) {
                window.location.href = '/admin_panel/assign_order.php?id=' + orderId + '&csrf_token=' + encodeURIComponent(document.querySelector('input[name="csrf_token"]').value);
            }
        };

        // Переключення між вкладками графіків
        document.querySelectorAll('.chart-tab').forEach(tab => {
            tab.addEventListener('click', function() {
                const chartId = this.getAttribute('data-chart');

                // Активуємо вкладку
                document.querySelectorAll('.chart-tab').forEach(t => t.classList.remove('active'));
                this.classList.add('active');

                // Показуємо відповідний графік
                document.querySelectorAll('[id$="ChartContainer"]').forEach(container => {
                    container.style.display = 'none';
                });
                document.getElementById(chartId + 'Container').style.display = 'block';
            });
        });

        // Ініціалізація графіків
        function initCharts() {
            if (typeof ApexCharts === 'undefined') return;

            const currentTheme = document.documentElement.getAttribute('data-theme') || 'dark';

            // Графік для динаміки замовлень
            const ordersChartElement = document.getElementById('ordersChart');
            if (ordersChartElement) {
                const ordersChart = new ApexCharts(ordersChartElement, {
                    chart: {
                        type: 'area',
                        height: 350,
                        toolbar: {
                            show: true
                        },
                        animations: {
                            enabled: true
                        }
                    },
                    theme: {
                        mode: currentTheme
                    },
                    series: [{
                        name: 'Нові замовлення',
                        data: <?= json_encode($timeStatsNewOrders) ?>
                    }, {
                        name: 'Завершені замовлення',
                        data: <?= json_encode($timeStatsCompletedOrders) ?>
                    }],
                    xaxis: {
                        categories: <?= json_encode($timeStatsLabels) ?>,
                        labels: {
                            style: {
                                colors: currentTheme === 'dark' ? '#a0aec0' : '#6c757d'
                            }
                        }
                    },
                    stroke: {
                        curve: 'smooth',
                        width: 3
                    },
                    fill: {
                        type: 'gradient',
                        gradient: {
                            shadeIntensity: 1,
                            opacityFrom: 0.7,
                            opacityTo: 0.3
                        }
                    },
                    markers: {
                        size: 4
                    },
                    colors: ['#3498db', '#2ecc71']
                });
                ordersChart.render();

                // Зберігаємо посилання на графік для оновлення теми
                window.ordersChart = ordersChart;
            }

            // Графік для типів замовлень
            const ordersTypesChartElement = document.getElementById('ordersTypesChart');
            if (ordersTypesChartElement) {
                const ordersTypesChart = new ApexCharts(ordersTypesChartElement, {
                    chart: {
                        type: 'bar',
                        height: 350,
                        stacked: true,
                        toolbar: {
                            show: true
                        }
                    },
                    theme: {
                        mode: currentTheme
                    },
                    series: [
                        {
                            name: 'Нові',
                            data: <?= json_encode($timeStatsNewOrders) ?>
                        },
                        {
                            name: 'Завершені',
                            data: <?= json_encode($timeStatsCompletedOrders) ?>
                        }
                    ],
                    xaxis: {
                        categories: <?= json_encode($timeStatsLabels) ?>,
                        labels: {
                            style: {
                                colors: currentTheme === 'dark' ? '#a0aec0' : '#6c757d'
                            }
                        }
                    },
                    colors: ['#f39c12', '#2ecc71'],
                    plotOptions: {
                        bar: {
                            borderRadius: 5,
                            columnWidth: '70%'
                        }
                    },
                    dataLabels: {
                        enabled: false
                    }
                });
                ordersTypesChart.render();

                // Зберігаємо посилання на графік для оновлення теми
                window.ordersTypesChart = ordersTypesChart;
            }

            // Графік по днях тижня
            const weekdayChartElement = document.getElementById('weekdayChart');
            if (weekdayChartElement) {
                const weekdayChart = new ApexCharts(weekdayChartElement, {
                    chart: {
                        type: 'bar',
                        height: 350,
                        toolbar: {
                            show: true
                        }
                    },
                    theme: {
                        mode: currentTheme
                    },
                    series: [{
                        name: 'Кількість замовлень',
                        data: <?= json_encode($weekdayStats) ?>
                    }, {
                        name: 'Завершені замовлення',
                        data: <?= json_encode($weekdayCompletedStats) ?>
                    }],
                    xaxis: {
                        categories: <?= json_encode($weekdays) ?>,
                        labels: {
                            style: {
                                colors: currentTheme === 'dark' ? '#a0aec0' : '#6c757d'
                            }
                        }
                    },
                    plotOptions: {
                        bar: {
                            borderRadius: 5,
                            columnWidth: '60%',
                            dataLabels: {
                                position: 'top'
                            }
                        }
                    },
                    dataLabels: {
                        enabled: true,
                        formatter: function(val) {
                            return val > 0 ? val : '';
                        },
                        offsetY: -20,
                        style: {
                            fontSize: '12px',
                            colors: [currentTheme === 'dark' ? '#e2e8f0' : '#333333']
                        }
                    },
                    colors: ['#3498db', '#2ecc71']
                });
                weekdayChart.render();

                // Зберігаємо посилання на графік для оновлення теми
                window.weekdayChart = weekdayChart;
            }

            // Графік категорій
            const categoriesChartElement = document.getElementById('categoriesChart');
            if (categoriesChartElement) {
                const categoryNames = [];
                const categoryCounts = [];

                <?php foreach ($categoryStats as $category): ?>
                categoryNames.push('<?= safeEcho($category['category']) ?>');
                categoryCounts.push(<?= $category['count'] ?>);
                <?php endforeach; ?>

                const categoriesChart = new ApexCharts(categoriesChartElement, {
                    chart: {
                        type: 'pie',
                        height: 350
                    },
                    theme: {
                        mode: currentTheme
                    },
                    series: categoryCounts,
                    labels: categoryNames,
                    colors: ['#3498db', '#2ecc71', '#f39c12', '#9b59b6', '#1abc9c', '#e74c3c', '#34495e', '#16a085', '#27ae60', '#d35400'],
                    legend: {
                        position: 'bottom',
                        labels: {
                            colors: currentTheme === 'dark' ? '#e2e8f0' : '#333333'
                        }
                    },
                    responsive: [{
                        breakpoint: 480,
                        options: {
                            chart: {
                                width: 300
                            },
                            legend: {
                                position: 'bottom'
                            }
                        }
                    }]
                });
                categoriesChart.render();

                // Зберігаємо посилання на графік для оновлення теми
                window.categoriesChart = categoriesChart;
            }

            // Графік порівняння адміністраторів
            const adminComparisonChartElement = document.getElementById('adminComparisonChart');
            if (adminComparisonChartElement) {
                const adminNames = [];
                const adminCompletedOrders = [];
                const adminTotalOrders = [];

                <?php foreach ($adminStats as $admin): ?>
                adminNames.push('<?= safeEcho($admin['username']) ?>');
                adminCompletedOrders.push(<?= $admin['completed_orders'] ?? 0 ?>);
                adminTotalOrders.push(<?= $admin['total_orders'] ?? 0 ?>);
                <?php endforeach; ?>

                const adminComparisonChart = new ApexCharts(adminComparisonChartElement, {
                    chart: {
                        type: 'bar',
                        height: 350,
                        toolbar: {
                            show: true
                        }
                    },
                    theme: {
                        mode: currentTheme
                    },
                    series: [{
                        name: 'Всього замовлень',
                        data: adminTotalOrders
                    }, {
                        name: 'Завершено',
                        data: adminCompletedOrders
                    }],
                    xaxis: {
                        categories: adminNames,
                        labels: {
                            style: {
                                colors: currentTheme === 'dark' ? '#a0aec0' : '#6c757d'
                            }
                        }
                    },
                    plotOptions: {
                        bar: {
                            horizontal: false,
                            columnWidth: '55%',
                            endingShape: 'rounded'
                        }
                    },
                    dataLabels: {
                        enabled: false
                    },
                    stroke: {
                        show: true,
                        width: 2,
                        colors: ['transparent']
                    },
                    colors: ['#3498db', '#2ecc71'],
                    fill: {
                        opacity: 1
                    },
                    tooltip: {
                        y: {
                            formatter: function(val) {
                                return val + " замовлень";
                            }
                        }
                    }
                });
                adminComparisonChart.render();

                // Зберігаємо посилання на графік для оновлення теми
                window.adminComparisonChart = adminComparisonChart;
            }

            // Графік активності адміна
            const adminActivityChartElement = document.getElementById('adminActivityChart');
            if (adminActivityChartElement) {
                const adminDailyLabels = [];
                const adminDailyValues = [];

                <?php if (isset($adminDailyLabels) && isset($adminDailyValues)): ?>
                <?php foreach ($adminDailyLabels as $label): ?>
                adminDailyLabels.push('<?= $label ?>');
                <?php endforeach; ?>

                <?php foreach ($adminDailyValues as $value): ?>
                adminDailyValues.push(<?= $value ?>);
                <?php endforeach; ?>
                <?php endif; ?>

                const adminActivityChart = new ApexCharts(adminActivityChartElement, {
                    chart: {
                        type: 'area',
                        height: 350,
                        toolbar: {
                            show: true
                        }
                    },
                    theme: {
                        mode: currentTheme
                    },
                    series: [{
                        name: 'Кількість замовлень',
                        data: adminDailyValues
                    }],
                    xaxis: {
                        categories: adminDailyLabels,
                        labels: {
                            style: {
                                colors: currentTheme === 'dark' ? '#a0aec0' : '#6c757d'
                            }
                        }
                    },
                    stroke: {
                        curve: 'smooth',
                        width: 3
                    },
                    fill: {
                        type: 'gradient',
                        gradient: {
                            shadeIntensity: 1,
                            opacityFrom: 0.7,
                            opacityTo: 0.3
                        }
                    },
                    markers: {
                        size: 4
                    },
                    colors: ['#9b59b6']
                });
                adminActivityChart.render();

                // Зберігаємо посилання на графік для оновлення теми
                window.adminActivityChart = adminActivityChart;
            }

            // Графік категорій адміна
            const adminCategoriesChartElement = document.getElementById('adminCategoriesChart');
            if (adminCategoriesChartElement) {
                const adminCategoryNames = [];
                const adminCategoryCounts = [];

                <?php if (isset($adminCategoryStats)): ?>
                <?php foreach ($adminCategoryStats as $category): ?>
                adminCategoryNames.push('<?= safeEcho($category['category']) ?>');
                adminCategoryCounts.push(<?= $category['count'] ?>);
                <?php endforeach; ?>
                <?php endif; ?>

                const adminCategoriesChart = new ApexCharts(adminCategoriesChartElement, {
                    chart: {
                        type: 'donut',
                        height: 350
                    },
                    theme: {
                        mode: currentTheme
                    },
                    series: adminCategoryCounts,
                    labels: adminCategoryNames,
                    colors: ['#3498db', '#2ecc71', '#f39c12', '#9b59b6', '#1abc9c', '#e74c3c', '#34495e', '#16a085', '#27ae60', '#d35400'],
                    legend: {
                        position: 'bottom',
                        labels: {
                            colors: currentTheme === 'dark' ? '#e2e8f0' : '#333333'
                        }
                    },
                    responsive: [{
                        breakpoint: 480,
                        options: {
                            chart: {
                                width: 300
                            },
                            legend: {
                                position: 'bottom'
                            }
                        }
                    }]
                });
                adminCategoriesChart.render();

                // Зберігаємо посилання на графік для оновлення теми
                window.adminCategoriesChart = adminCategoriesChart;
            }
        }

        // Функція для оновлення теми графіків
        function updateCharts(theme) {
            const charts = [
                'ordersChart', 'ordersTypesChart', 'weekdayChart', 'categoriesChart',
                'adminComparisonChart', 'adminActivityChart', 'adminCategoriesChart'
            ];

            charts.forEach(chartName => {
                if (window[chartName]) {
                    window[chartName].updateOptions({
                        theme: {
                            mode: theme
                        },
                        xaxis: {
                            labels: {
                                style: {
                                    colors: theme === 'dark' ? '#a0aec0' : '#6c757d'
                                }
                            }
                        },
                        dataLabels: {
                            style: {
                                colors: [theme === 'dark' ? '#e2e8f0' : '#333333']
                            }
                        },
                        legend: {
                            labels: {
                                colors: theme === 'dark' ? '#e2e8f0' : '#333333'
                            }
                        }
                    });
                }
            });
        }

        // Ініціалізуємо графіки після завантаження сторінки
        initCharts();

        // Анімація при скролі
        const fadeElements = document.querySelectorAll('.fade-in');
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = 1;
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, { threshold: 0.1 });

        fadeElements.forEach(element => {
            element.style.opacity = 0;
            element.style.transform = 'translateY(20px)';
            element.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
            observer.observe(element);
        });

        // Додаємо анімацію для елементів з locked статусом
        const lockIcons = document.querySelectorAll('.lock-icon-animated');
        lockIcons.forEach(icon => {
            icon.addEventListener('click', function() {
                this.style.animation = 'none';
                void this.offsetWidth; // Перезапуск анімації
                this.style.animation = 'lock-shake 0.5s ease';
            });
        });
    });
</script>
</body>
</html>