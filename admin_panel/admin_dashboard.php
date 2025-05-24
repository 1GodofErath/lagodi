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

// Встановлення теми
$currentTheme = isset($_COOKIE['theme']) ? $_COOKIE['theme'] : 'dark';

// Функція для перевірки можливості коментування
function canAddComments($status) {
    $status = trim($status); // Прибираємо зайві пробіли
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

// Ініціалізація змінних для повідомлень
$successMessage = '';
$errorMessage = '';

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
                c.name as category_name 
            FROM orders o
            JOIN users u ON o.user_id = u.id
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
        // Перевіряємо, чи пошуковий термін - число (для пошуку за ID)
        if (is_numeric($searchTerm)) {
            $query .= " AND (o.id = $searchTerm OR o.service LIKE '%$searchTerm%')";
        } else {
            $query .= " AND (o.service LIKE '%$searchTerm%' OR u.username LIKE '%$searchTerm%')";
        }
    }

    // Додаємо сортування
    $query .= ($sortOrder === 'oldest') ? " ORDER BY o.created_at ASC" : " ORDER BY o.created_at DESC";

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
} catch (Exception $e) {
    $errorMessage = "Помилка при отриманні списку замовлень: " . $e->getMessage();
    $orders = [];
    $stats = [
        'new' => 0,
        'in_progress' => 0,
        'waiting' => 0,
        'waiting_delivery' => 0,
        'completed' => 0,
        'canceled' => 0
    ];
}

// Retrieve comments for each order
$comments = [];
foreach ($orders as $order) {
    try {
        $orderId = $order['id'];
        $stmt = $conn->prepare("
            SELECT c.*, u.username, u.role 
            FROM comments c
            JOIN users u ON c.user_id = u.id 
            WHERE c.order_id = ?
            ORDER BY c.created_at DESC
        ");
        $stmt->bind_param("i", $orderId);
        $stmt->execute();
        $result = $stmt->get_result();
        $comments[$orderId] = $result->fetch_all(MYSQLI_ASSOC);
    } catch (Exception $e) {
        $errorMessage = "Помилка при отриманні коментарів: " . $e->getMessage();
        $comments[$order['id']] = [];
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
    $comment = htmlspecialchars($_POST['comment']);

    if ($orderIdForComment && !empty($comment)) {
        try {
            // Спочатку перевіряємо статус замовлення
            $statusCheckQuery = "SELECT status FROM orders WHERE id = ?";
            $statusCheckStmt = $conn->prepare($statusCheckQuery);
            $statusCheckStmt->bind_param("i", $orderIdForComment);
            $statusCheckStmt->execute();
            $statusResult = $statusCheckStmt->get_result();
            $orderStatus = $statusResult->fetch_assoc()['status'];

            // Точна перевірка статусу з обрізкою пробілів
            $blockedStatuses = ['Завершено', 'Виконано', 'Не можливо виконати'];
            if (in_array(trim($orderStatus), $blockedStatuses)) {
                $errorMessage = "Неможливо додати коментар до замовлення зі статусом '$orderStatus'";
                header("Location: admin_dashboard.php?section=orders&error=comment_blocked");
                exit;
            }

            $commentQuery = "INSERT INTO comments (order_id, user_id, content, is_read, created_at) 
                           VALUES (?, ?, ?, 0, NOW())";

            $commentStmt = $conn->prepare($commentQuery);
            $userId = $_SESSION['user_id'];
            $commentStmt->bind_param("iis", $orderIdForComment, $userId, $comment);
            $commentStmt->execute();

            // Перенаправляємо на ту ж сторінку, щоб уникнути повторної відправки форми
            header("Location: admin_dashboard.php?section=orders&comment_added=true");
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

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Змінна для зберігання даних про замовлення при перегляді окремого замовлення
$orderDetails = null;
if (isset($_GET['id'])) {
    $orderId = filter_var($_GET['id'], FILTER_VALIDATE_INT);

    if ($orderId) {
        try {
            $query = "SELECT o.*, u.username, c.name as category_name 
                     FROM orders o
                     LEFT JOIN users u ON o.user_id = u.id
                     LEFT JOIN service_categories c ON o.category_id = c.id 
                     WHERE o.id = ?";

            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $orderId);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $orderDetails = $result->fetch_assoc();

                // Отримуємо коментарі до замовлення
                $commentsQuery = "SELECT c.*, u.username, u.role 
                                 FROM comments c
                                 LEFT JOIN users u ON c.user_id = u.id 
                                 WHERE c.order_id = ? 
                                 ORDER BY c.created_at DESC";

                $commentsStmt = $conn->prepare($commentsQuery);
                $commentsStmt->bind_param("i", $orderId);
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

                // Визначаємо кількість коментарів і скільки показувати спочатку
                $totalComments = count($orderComments);
                $showInitialComments = 3;
                $showLoadMore = $totalComments > $showInitialComments;
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

    <style>
        /* Загальні стилі */
        :root[data-theme="light"] {
            --primary-color: #3498db;
            --primary-color-dark: #2980b9;
            --text-primary: #333333;
            --text-secondary: #888888;
            --card-bg: #ffffff;
            --bg-primary: #f8f9fa;
            --border-color: #dee2e6;
            --success-color: #2ecc71;
            --danger-color: #e74c3c;
            --warning-color: #f39c12;
            --info-color: #3498db;
        }

        :root[data-theme="dark"] {
            --primary-color: #3498db;
            --primary-color-dark: #2980b9;
            --text-primary: #e2e8f0;
            --text-secondary: #a0aec0;
            --card-bg: #2d3748;
            --bg-primary: #1a202c;
            --border-color: #4a5568;
            --success-color: #2ecc71;
            --danger-color: #e74c3c;
            --warning-color: #f39c12;
            --info-color: #3498db;
        }

        body {
            background-color: var(--bg-primary);
            color: var(--text-primary);
            transition: background-color 0.3s, color 0.3s;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        /* Стилі для фільтрів */
        .filters-form {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: center;
            margin-bottom: 20px;
            padding: 15px;
            background-color: var(--card-bg);
            border-radius: 8px;
            border: 1px solid var(--border-color);
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }

        /* Стили для елементів фільтрації */
        .filter-group {
            display: flex;
            flex-direction: column;
            min-width: 180px;
            margin-bottom: 0;
            position: relative;
        }

        .filter-group label {
            font-size: 0.85rem;
            margin-bottom: 5px;
            color: var(--text-secondary);
            font-weight: 500;
        }

        .filter-group select,
        .search-input {
            padding: 10px 12px;
            border-radius: 6px;
            border: 1px solid var(--border-color);
            background-color: var(--card-bg);
            color: var(--text-primary);
            min-width: 150px;
            appearance: none;
            background-position: right 12px center;
            background-repeat: no-repeat;
            background-size: 12px;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 0.95rem;
        }

        .filter-group select:hover,
        .search-input:hover {
            border-color: var(--primary-color);
        }

        .filter-group select:focus,
        .search-input:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.1);
            outline: none;
        }

        /* Стрелка вниз для селектів (адаптивна для обох тем) */
        .filter-group select {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%236b7280' viewBox='0 0 16 16'%3E%3Cpath fill-rule='evenodd' d='M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z'/%3E%3C/svg%3E");
            padding-right: 30px; /* Місце для стрілки */
        }

        /* Пошук */
        .search-group {
            display: flex;
            flex-grow: 1;
            min-width: 200px;
            position: relative;
        }

        .search-input {
            flex-grow: 1;
            padding: 10px 12px 10px 35px;
        }

        .search-icon {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
            pointer-events: none;
        }

        .search-btn {
            border: none;
            border-radius: 6px;
            padding: 10px 15px;
            background-color: var(--primary-color);
            color: white;
            cursor: pointer;
            margin-left: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background-color 0.2s;
            font-weight: 600;
        }

        .search-btn:hover {
            background-color: var(--primary-color-dark);
            transform: translateY(-2px);
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        /* Стилі для статистики */
        .orders-stats {
            margin-bottom: 25px;
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            justify-content: space-between;
        }

        .stat-item {
            flex: 1;
            min-width: 150px;
            background-color: var(--card-bg);
            border-radius: 8px;
            padding: 15px;
            display: flex;
            align-items: center;
            gap: 15px;
            border: 1px solid var(--border-color);
            transition: transform 0.2s, box-shadow 0.2s;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }

        .stat-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .stat-icon {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
        }

        .stat-icon-new {
            background-color: rgba(243, 156, 18, 0.2);
            color: #f39c12;
        }

        .stat-icon-processing,
        .stat-icon-in-progress {
            background-color: rgba(52, 152, 219, 0.2);
            color: #3498db;
        }

        .stat-icon-waiting {
            background-color: rgba(142, 68, 173, 0.2);
            color: #8e44ad;
        }

        .stat-icon-waiting-delivery {
            background-color: rgba(255, 152, 0, 0.2);
            color: #ff9800;
        }

        .stat-icon-completed {
            background-color: rgba(46, 204, 113, 0.2);
            color: #2ecc71;
        }

        .stat-icon-canceled {
            background-color: rgba(231, 76, 60, 0.2);
            color: #e74c3c;
        }

        .stat-content {
            flex: 1;
        }

        .stat-number {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .stat-label {
            font-size: 0.9rem;
            color: var(--text-secondary);
        }

        /* Стилі для списку замовлень */
        .orders-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .order-card {
            background-color: var(--card-bg);
            border-radius: 10px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            transition: transform 0.2s, box-shadow 0.3s;
            border: 1px solid var(--border-color);
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .order-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .order-card-header {
            background-color: rgba(0, 0, 0, 0.05);
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .order-service {
            font-weight: 600;
            font-size: 1.1rem;
            color: var(--text-primary);
        }

        .status-badge {
            display: inline-block;
            padding: 6px 15px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
            text-align: center;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .status-new {
            background-color: #f39c12;
            color: white;
        }

        .status-in-progress {
            background-color: #3498db;
            color: white;
        }

        .status-waiting {
            background-color: #8e44ad;
            color: white;
        }

        .status-waiting-delivery {
            background-color: #ff9800;
            color: white;
        }

        .status-completed {
            background-color: #2ecc71;
            color: white;
        }

        .status-canceled {
            background-color: #e74c3c;
            color: white;
        }

        .order-card-content {
            padding: 15px;
            flex-grow: 1;
        }

        .order-number {
            font-weight: 600;
            margin-bottom: 15px;
            color: var(--text-primary);
            font-size: 1.1rem;
        }

        .order-info-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .order-info-item {
            display: flex;
            margin-bottom: 12px;
        }

        .order-info-label {
            flex: 0 0 90px;
            font-weight: 500;
            color: var(--text-secondary);
        }

        .order-info-value {
            flex: 1;
            color: var(--text-primary);
        }

        .order-card-footer {
            background-color: rgba(0, 0, 0, 0.02);
            padding: 12px 15px;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .order-date {
            font-size: 0.85rem;
            color: var(--text-secondary);
        }

        .order-details-link {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
            padding: 5px 10px;
            border-radius: 5px;
            transition: background-color 0.2s;
        }

        .order-details-link:hover {
            background-color: rgba(52, 152, 219, 0.1);
        }

        /* Стилі для форми зміни статусу */
        .status-form {
            margin: 15px 0;
            padding: 15px;
            background-color: rgba(0, 0, 0, 0.03);
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }

        .status-form select {
            padding: 8px 12px;
            border-radius: 6px;
            border: 1px solid var(--border-color);
            background-color: var(--card-bg);
            color: var(--text-primary);
            width: 100%;
            margin-bottom: 10px;
            height: 40px;
        }

        .btn-primary {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 10px 15px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
            height: 40px;
        }

        .btn-primary:hover {
            background-color: var(--primary-color-dark);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }

        /* Стилі для деталей замовлення */
        .order-details-container {
            background-color: var(--card-bg);
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            margin-bottom: 20px;
            border: 1px solid var(--border-color);
        }

        .order-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px;
            border-bottom: 1px solid var(--border-color);
            background-color: rgba(0, 0, 0, 0.03);
        }

        .order-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--primary-color);
        }

        .detail-row {
            display: flex;
            border-bottom: 1px solid var(--border-color);
            background-color: transparent !important;
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-col {
            padding: 15px 20px;
            flex: 1;
            background-color: transparent !important;
        }

        .detail-col:first-child {
            border-right: 1px solid var(--border-color);
        }

        .detail-label {
            font-weight: 500;
            color: var(--text-secondary);
            margin-bottom: 8px;
            font-size: 0.9rem;
        }

        .detail-value {
            color: var(--text-primary);
            font-weight: 500;
            font-size: 1rem;
        }

        .detail-value.empty {
            color: var(--text-secondary);
            font-style: italic;
        }

        /* Стиль для опису */
        .description-section {
            padding: 15px 20px;
            border-top: 1px solid var(--border-color);
        }

        .description-label {
            font-weight: 500;
            color: var(--text-secondary);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 1.1rem;
        }

        .description-label i {
            color: var(--primary-color);
        }

        .description-text {
            padding: 15px;
            line-height: 1.5;
            background-color: transparent !important;
            border-radius: 6px;
            border: 1px solid var(--border-color);
            color: var(--text-primary);
        }

        .description-text.empty {
            color: var(--text-secondary);
            font-style: italic;
        }

        /* Файли */
        .files-section {
            padding: 15px 20px;
            border-top: 1px solid var(--border-color);
            background-color: transparent;
        }

        .files-label, .comments-label {
            display: flex;
            align-items: center;
            font-weight: 500;
            color: var(--text-secondary);
            margin-bottom: 15px;
            font-size: 1.1rem;
        }

        .files-label i, .comments-label i {
            margin-right: 8px;
            color: var(--primary-color);
        }

        .files-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 10px;
        }

        .file-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 12px;
            background-color: transparent;
            border-radius: 6px;
            border: 1px solid var(--border-color);
            transition: all 0.2s ease;
        }

        .file-item:hover {
            background-color: rgba(255, 255, 255, 0.05);
            transform: translateY(-2px);
            box-shadow: 0 3px 6px rgba(0, 0, 0, 0.1);
        }

        .file-item i {
            color: var(--primary-color);
        }

        .file-name {
            color: var(--text-primary);
        }

        .file-download-btn {
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.2s ease;
            background: none;
            border: none;
            cursor: pointer;
            color: var(--primary-color);
        }

        .file-download-btn:hover {
            background-color: var(--primary-color);
            color: white !important;
        }

        .file-download-btn:hover i {
            color: white !important;
        }

        /* Стилі для коментарів у детальному перегляді */
        .comments-section {
            padding: 15px 20px;
            border-top: 1px solid var(--border-color);
        }

        .comments-list {
            margin: 15px 0 5px 0;
        }

        .comment-item {
            margin-bottom: 15px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            overflow: hidden;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .comment-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        /* Власні коментарі */
        .comment-item.own-comment {
            border-left: 3px solid var(--primary-color);
        }

        /* Коментарі адміна */
        .comment-item.admin-comment {
            border-left: 3px solid #e74c3c;
        }

        .comment-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 15px;
            background-color: rgba(0, 0, 0, 0.05);
            border-bottom: 1px solid var(--border-color);
            color: var(--text-primary);
        }

        .comment-user {
            display: flex;
            align-items: center;
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
            margin-right: 10px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .admin-comment .comment-avatar {
            background-color: #e74c3c;
        }

        .comment-user-info {
            display: flex;
            flex-direction: column;
        }

        .comment-username {
            font-weight: 600;
            display: flex;
            align-items: center;
            color: var(--text-primary);
        }

        .admin-badge {
            background-color: #e74c3c;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-left: 8px;
        }

        .comment-time {
            font-size: 0.8rem;
            color: var(--text-secondary);
        }

        .comment-content {
            padding: 15px;
            line-height: 1.5;
            color: var(--text-primary);
        }

        .no-comments {
            text-align: center;
            padding: 30px;
            color: var(--text-secondary);
            background-color: rgba(0, 0, 0, 0.03);
            border-radius: 8px;
            border: 1px dashed var(--border-color);
        }

        .no-comments i {
            font-size: 2rem;
            margin-bottom: 10px;
            display: block;
            opacity: 0.6;
        }

        .comment-form {
            margin-top: 20px;
            padding: 15px;
            background-color: rgba(0, 0, 0, 0.03);
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }

        .comment-form-header {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            font-size: 1.1rem;
        }

        .comment-form-header i {
            color: var(--primary-color);
            margin-right: 8px;
        }

        .comment-form textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background-color: var(--card-bg);
            color: var(--text-primary);
            min-height: 100px;
            margin-bottom: 15px;
            resize: vertical;
            transition: border-color 0.3s;
            font-size: 0.95rem;
            font-family: inherit;
        }

        .comment-form textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.1);
        }

        /* Стилі для повідомлень про неможливість коментування */
        .comments-disabled-notice {
            background-color: rgba(221, 44, 0, 0.1);
            color: #cc3c2e;
            padding: 15px;
            margin-top: 15px;
            border-radius: 6px;
            border: 1px solid rgba(221, 44, 0, 0.2);
            display: flex;
            align-items: center;
            font-weight: 500;
        }

        .comments-disabled-notice i {
            margin-right: 10px;
            font-size: 1.2rem;
        }

        /* Кнопка "Показати більше коментарів" */
        .show-more-btn {
            background-color: transparent;
            border: 1px solid var(--border-color);
            padding: 8px 15px;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: all 0.2s ease;
            color: var(--text-primary);
        }

        .show-more-btn i {
            color: var(--primary-color);
        }

        .show-more-btn:hover {
            background-color: rgba(52, 152, 219, 0.05);
            border-color: var(--primary-color);
        }

        .show-more-comments {
            display: flex;
            justify-content: center;
            margin: 5px 0 20px 0;
        }

        .comment-item.hidden-comment {
            display: none;
        }

        /* Навігація назад */
        .back-link {
            display: inline-flex;
            align-items: center;
            margin-right: 10px;
            color: var(--primary-color);
            text-decoration: none;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        /* Адаптивність */
        @media (max-width: 768px) {
            .filters-form {
                flex-direction: column;
                align-items: stretch;
            }

            .filter-group,
            .search-group {
                min-width: 100%;
            }

            .orders-stats {
                flex-direction: column;
            }

            .stat-item {
                min-width: 100%;
            }

            .orders-grid {
                grid-template-columns: 1fr;
            }

            .detail-row {
                flex-direction: column;
            }

            .detail-col:first-child {
                border-right: none;
                border-bottom: 1px solid var(--border-color);
            }

            .files-list {
                grid-template-columns: 1fr;
            }
        }

        /* Модифікації для стилів адмін-панелі */
        .navbar {
            background-color: var(--card-bg);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            border: 1px solid var(--border-color);
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .welcome-group {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .welcome-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background-color: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 1.2rem;
        }

        .welcome-heading {
            color: var(--text-primary);
            font-size: 1.5rem;
            margin: 0 0 5px 0;
        }

        .welcome-heading span {
            color: var(--primary-color);
        }

        .role-indicator {
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.9rem;
        }

        .system-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .time-display {
            text-align: right;
        }

        .time-label {
            font-size: 0.8rem;
            color: var(--text-secondary);
        }

        .time-value {
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--text-primary);
        }

        .logout-btn {
            display: flex;
            align-items: center;
            gap: 5px;
            background-color: var(--danger-color);
            color: white;
            padding: 8px 15px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.2s;
        }

        .logout-btn:hover {
            background-color: #c0392b;
            transform: translateY(-2px);
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .admin-sections {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            background-color: var(--card-bg);
            border-radius: 10px;
            padding: 10px;
            border: 1px solid var(--border-color);
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }

        .admin-sections a {
            padding: 10px 15px;
            border-radius: 6px;
            text-decoration: none;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 8px;
            transition: background-color 0.2s;
            font-weight: 500;
        }

        .admin-sections a:hover,
        .admin-sections a.active {
            background-color: var(--primary-color);
            color: white;
        }

        .admin-sections a svg {
            fill: currentColor;
        }

        .section-title {
            color: var(--text-primary);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.5rem;
        }

        .section-title i {
            color: var(--primary-color);
        }

        /* Кнопка теми */
        .theme-switch-btn {
            background: none;
            border: none;
            cursor: pointer;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            transition: background-color 0.2s;
            font-size: 1.1rem;
        }

        .theme-switch-btn:hover {
            background-color: rgba(0, 0, 0, 0.05);
        }

        /* Модифікації для секцій користувачів та логів */
        .users-grid, .logs-table {
            background-color: var(--card-bg);
            border-radius: 10px;
            padding: 20px;
            border: 1px solid var(--border-color);
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }

        table {
            color: var(--text-primary);
            border-collapse: collapse;
            width: 100%;
        }

        table th, table td {
            border: 1px solid var(--border-color);
            padding: 10px 12px;
        }

        table th {
            background-color: rgba(0, 0, 0, 0.05);
            font-weight: 600;
        }

        .user-card {
            background-color: var(--card-bg);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            border: 1px solid var(--border-color);
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }

        .pagination {
            display: flex;
            gap: 5px;
            margin-top: 15px;
            justify-content: center;
        }

        .pagination a {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 5px;
            text-decoration: none;
            background-color: var(--card-bg);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
        }

        .pagination a.active {
            background-color: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        /* Додаткові стилі для покращення вигляду */
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-danger {
            background-color: rgba(231, 76, 60, 0.1);
            border: 1px solid rgba(231, 76, 60, 0.3);
            color: #e74c3c;
        }

        .alert-success {
            background-color: rgba(46, 204, 113, 0.1);
            border: 1px solid rgba(46, 204, 113, 0.3);
            color: #2ecc71;
        }

        .no-orders {
            text-align: center;
            padding: 40px 20px;
            background-color: var(--card-bg);
            border-radius: 10px;
            border: 1px dashed var(--border-color);
            margin-top: 30px;
            color: var(--text-secondary);
        }

        .no-orders i {
            font-size: 3rem;
            color: var(--text-secondary);
            margin-bottom: 15px;
            opacity: 0.6;
        }

        .clear-filters-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #e74c3c;
            text-decoration: none;
            padding: 6px 12px;
            border-radius: 6px;
            font-weight: 500;
            transition: all 0.2s;
        }

        .clear-filters-btn:hover {
            background-color: rgba(231, 76, 60, 0.1);
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: var(--text-secondary);
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background-color: var(--card-bg);
            color: var(--text-primary);
        }

        .btn {
            padding: 8px 15px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
            border: none;
            transition: all 0.2s;
        }

        .btn-danger {
            background-color: #e74c3c;
            color: white;
        }

        .btn-danger:hover {
            background-color: #c0392b;
        }

        .btn-warning {
            background-color: #f39c12;
            color: white;
        }

        .btn-warning:hover {
            background-color: #d68910;
        }

        .btn-success {
            background-color: #2ecc71;
            color: white;
        }

        .btn-success:hover {
            background-color: #27ae60;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="navbar">
        <div class="welcome-group">
            <div class="welcome-avatar"><?= substr(htmlspecialchars($_SESSION['username']), 0, 2) ?></div>
            <div class="welcome-text">
                <h1 class="welcome-heading">
                    Вітаємо, <span><?= htmlspecialchars($_SESSION['username']) ?></span>
                </h1>
                <div class="role-indicator">
                    <?php if ($_SESSION['role'] === 'admin'): ?>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M20.995 4.824A1 1 0 0020 4h-16a1 1 0 00-.995.824L2 8v1a3 3 0 003 3c1.306 0 2.418-.835 2.83-2h8.34c.412 1.165 1.524 2 2.83 2a3 3 0 003-3V8l-1.005-3.176zM5 10a1 1 0 110-2 1 1 0 010 2zm14 0a1 1 0 110-2 1 1 0 010 2z"/>
                        </svg>
                        Адміністратор
                    <?php elseif ($_SESSION['role'] === 'junior_admin'): ?>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 2C6.486 2 2 6.486 2 12s4.486 10 10 10 10-4.486 10-10S17.514 2 12 2zm0 18c-4.411 0-8-3.589-8-8s3.589-8 8-8 8 3.589 8 8-3.589 8-8 8z"/>
                            <path d="M13 7h-2v5.414l3.293 3.293 1.414-1.414L13 11.586z"/>
                        </svg>
                        Молодший Адміністратор
                    <?php endif; ?>
                    <div class="mobile-nav" style="display: none;">
                        <a href="?section=orders">Замовлення</a>
                        <?php if ($_SESSION['role'] === 'admin'): ?>
                            <a href="?section=users">Користувачі</a>
                            <a href="?section=logs">Логи</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="system-info">
            <div class="time-display">
                <div class="time-label">Поточний час (UTC)</div>
                <div class="time-value">2025-05-23 18:40:53</div>
            </div>

            <button id="themeSwitchBtn" class="theme-switch-btn">
                <i class="bi <?php echo $currentTheme === 'dark' ? 'bi-sun' : 'bi-moon'; ?>"></i>
            </button>

            <a href="/../logout.php" class="logout-btn">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M16 13v-2H7V8l-5 4 5 4v-3z"/>
                    <path d="M20 3h-9c-1.103 0-2 .897-2 2v4h2V5h9v14h-9v-4H9v4c0 1.103.897 2 2 2h9c1.103 0 2-.897 2-2V5c0-1.103-.897-2-2-2z"/>
                </svg>
                Вийти
            </a>
        </div>
    </div>

    <!-- Admin Sections -->
    <div class="admin-sections">
        <a href="?section=orders" data-section="orders" class="<?= $activeSection === 'orders' ? 'active' : '' ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V5h14v14z"/>
                <path d="M7 7h10v2H7zm0 4h10v2H7zm0 4h7v2H7z"/>
            </svg>
            Замовлення
        </a>
        <?php if ($_SESSION['role'] === 'admin'): ?>
        <a href="?section=users" data-section="users" class="<?= $activeSection === 'users' ? 'active' : '' ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
            </svg>
            Користувачі
        </a>
            <a href="?section=logs" data-section="logs" class="<?= $activeSection === 'logs' ? 'active' : '' ?>">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 0c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1z"/>
                </svg>
                Логи
            </a>
        <?php endif; ?>
    </div>

    <!-- Сповіщення про помилки -->
    <?php if ($errorMessage): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle"></i> <?php echo $errorMessage; ?>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['comment_added']) && $_GET['comment_added'] === 'true'): ?>
        <div class="alert alert-success">
            <i class="bi bi-check-circle"></i> Коментар успішно додано
        </div>
    <?php endif; ?>

    <!-- Orders Section -->
    <section id="orders" style="display: <?= $activeSection === 'orders' ? 'block' : 'none' ?>;">
        <?php if (isset($orderDetails)): ?>
            <!-- Детальний перегляд замовлення -->
            <h2 class="section-title">
                <a href="?section=orders" class="back-link"><i class="bi bi-arrow-left"></i></a>
                <i class="bi bi-clipboard-check"></i> Замовлення #<?php echo $orderDetails['id']; ?>
            </h2>

            <div class="order-details-container">
                <div class="order-header">
                    <div class="order-title"><?php echo safeEcho($orderDetails['service']); ?></div>
                    <div class="status-badge <?php echo getStatusClass($orderDetails['status']); ?>">
                        <?php echo safeEcho($orderDetails['status']); ?>
                    </div>
                </div>

                <div class="detail-row">
                    <div class="detail-col">
                        <div class="detail-label">Номер замовлення:</div>
                        <div class="detail-value">#<?php echo $orderDetails['id']; ?></div>
                    </div>
                    <div class="detail-col">
                        <div class="detail-label">Клієнт:</div>
                        <div class="detail-value"><?php echo safeEcho($orderDetails['username']); ?></div>
                    </div>
                </div>

                <div class="detail-row">
                    <div class="detail-col">
                        <div class="detail-label">Послуга:</div>
                        <div class="detail-value"><?php echo safeEcho($orderDetails['service']); ?></div>
                    </div>
                    <div class="detail-col">
                        <div class="detail-label">Категорія:</div>
                        <div class="detail-value <?php echo empty(trim($orderDetails['category_name'] ?? '')) ? 'empty' : ''; ?>">
                            <?php echo !empty(trim($orderDetails['category_name'] ?? '')) ? safeEcho($orderDetails['category_name']) : 'Не вказано'; ?>
                        </div>
                    </div>
                </div>

                <div class="detail-row">
                    <div class="detail-col">
                        <div class="detail-label">Тип пристрою:</div>
                        <div class="detail-value <?php echo empty(trim($orderDetails['device_type'] ?? '')) ? 'empty' : ''; ?>">
                            <?php echo !empty(trim($orderDetails['device_type'] ?? '')) ? safeEcho($orderDetails['device_type']) : 'Не вказано'; ?>
                        </div>
                    </div>
                    <div class="detail-col">
                        <div class="detail-label">Модель пристрою:</div>
                        <div class="detail-value <?php echo empty(trim($orderDetails['device_model'] ?? '')) ? 'empty' : ''; ?>">
                            <?php echo !empty(trim($orderDetails['device_model'] ?? '')) ? safeEcho($orderDetails['device_model']) : 'Не вказано'; ?>
                        </div>
                    </div>
                </div>

                <div class="detail-row">
                    <div class="detail-col">
                        <div class="detail-label">Дата створення:</div>
                        <div class="detail-value"><?php echo formatDate($orderDetails['created_at']); ?></div>
                    </div>
                    <div class="detail-col">
                        <div class="detail-label">Статус:</div>
                        <div class="detail-value">
                            <span class="status-badge <?php echo getStatusClass($orderDetails['status']); ?>">
                                <?php echo safeEcho($orderDetails['status']); ?>
                            </span>
                        </div>
                    </div>
                </div>

                <?php if ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'junior_admin'): ?>
                    <div class="status-form">
                        <form method="POST" action="update_status.php">
                            <input type="hidden" name="order_id" value="<?= $orderDetails['id'] ?>">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <div class="detail-label">Зміна статусу замовлення:</div>
                            <select name="status" class="status-select">
                                <option value="Новий" <?= $orderDetails['status'] === 'Новий' ? 'selected' : '' ?>>Новий</option>
                                <option value="В роботі" <?= $orderDetails['status'] === 'В роботі' ? 'selected' : '' ?>>В роботі</option>
                                <option value="Очікується" <?= $orderDetails['status'] === 'Очікується' ? 'selected' : '' ?>>Очікується</option>
                                <option value="Очікується поставки товару" <?= $orderDetails['status'] === 'Очікується поставки товару' ? 'selected' : '' ?>>Очікується поставки товару</option>
                                <option value="Завершено" <?= $orderDetails['status'] === 'Завершено' ? 'selected' : '' ?>>Завершено</option>
                                <option value="Не можливо виконати" <?= $orderDetails['status'] === 'Не можливо виконати' ? 'selected' : '' ?>>Не можливо виконати</option>
                            </select>
                            <button type="submit" class="btn-primary">
                                <i class="bi bi-check-circle"></i> Змінити статус
                            </button>
                        </form>
                    </div>
                <?php endif; ?>

                <?php if (!empty($orderAttachedFiles)): ?>
                    <div class="files-section">
                        <div class="files-label">
                            <i class="bi bi-paperclip"></i> Прикріплені файли
                        </div>
                        <div class="files-list">
                            <?php foreach ($orderAttachedFiles as $file): ?>
                                <div class="file-item">
                                    <?php
                                    $ext = pathinfo($file['original_name'], PATHINFO_EXTENSION);
                                    $icon = 'bi-file-earmark';

                                    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) {
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
                                    <div>
                                        <i class="bi <?php echo $icon; ?>"></i>
                                        <span class="file-name"><?php echo safeEcho($file['original_name']); ?></span>
                                    </div>
                                    <a href="<?php echo isset($file['file_path']) ? str_replace('../../', '/', $file['file_path']) : '#'; ?>" download class="file-download-btn" title="Завантажити файл">
                                        <i class="bi bi-download"></i>
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="comments-section">
                    <div class="comments-label">
                        <i class="bi bi-chat-left-text"></i> Коментарі
                    </div>

                    <?php if (!empty($orderComments)): ?>
                        <div class="comments-list">
                            <?php foreach ($orderComments as $index => $comment):
                                $isHidden = $index >= $showInitialComments;
                                $isAdminComment = in_array($comment['role'], ['admin', 'junior_admin']);
                                $isOwnComment = isset($_SESSION['user_id']) && $comment['user_id'] == $_SESSION['user_id'];
                                ?>
                                <div class="comment-item <?php echo $isOwnComment ? 'own-comment' : ''; ?>
                                     <?php echo $isAdminComment ? 'admin-comment' : ''; ?>
                                     <?php echo $isHidden ? 'hidden-comment' : ''; ?>"
                                     id="comment-<?php echo $comment['id']; ?>">
                                    <div class="comment-header">
                                        <div class="comment-user">
                                            <div class="comment-avatar">
                                                <?php echo strtoupper(substr($comment['username'] ?? 'U', 0, 1)); ?>
                                            </div>
                                            <div class="comment-user-info">
                                                <div class="comment-username" data-original-name="<?php echo safeEcho($comment['username']); ?>">
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

                        <?php if (isset($showLoadMore) && $showLoadMore): ?>
                            <div class="show-more-comments">
                                <button type="button" id="showMoreCommentsBtn" class="show-more-btn" aria-label="Показати більше коментарів">
                                    <i class="bi bi-chat-square-text"></i> Показати більше коментарів
                                </button>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="no-comments">
                            <i class="bi bi-chat-left"></i>
                            <p>Поки що немає коментарів</p>
                        </div>
                    <?php endif; ?>

                    <?php if (canAddComments($orderDetails['status'])): ?>
                        <div class="comment-form">
                            <div class="comment-form-header">
                                <i class="bi bi-plus-circle"></i> Додати новий коментар
                            </div>
                            <form method="post" action="admin_dashboard.php">
                                <input type="hidden" name="order_id" value="<?php echo $orderDetails['id']; ?>">
                                <textarea name="comment" placeholder="Напишіть ваш коментар тут..." required></textarea>
                                <button type="submit" name="add_comment" class="btn-primary" aria-label="Відправити">
                                    <i class="bi bi-send"></i> Відправити
                                </button>
                            </form>
                        </div>
                    <?php else: ?>
                        <div class="comments-disabled-notice">
                            <i class="bi bi-exclamation-triangle"></i>
                            <span>Додавання коментарів недоступне для замовлень зі статусом "<?php echo $orderDetails['status']; ?>"</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <!-- Список замовлень -->
            <h2 class="section-title">
                <i class="bi bi-list-check"></i> Список замовлень
            </h2>

            <!-- Оновлені фільтри для списку замовлень -->
            <form method="get" action="admin_dashboard.php" class="filters-form">
                <input type="hidden" name="section" value="orders">
                <div class="filter-group filter-status">
                    <label for="status-filter">Статус:</label>
                    <select id="status-filter" name="status" onchange="this.form.submit()">
                        <option value="all" <?= !isset($statusFilter) || $statusFilter === 'all' ? 'selected' : '' ?>>Всі статуси</option>
                        <option value="new" <?= isset($statusFilter) && $statusFilter === 'new' ? 'selected' : '' ?>>Новий</option>
                        <option value="in_progress" <?= isset($statusFilter) && $statusFilter === 'in_progress' ? 'selected' : '' ?>>В роботі</option>
                        <option value="waiting" <?= isset($statusFilter) && $statusFilter === 'waiting' ? 'selected' : '' ?>>Очікується</option>
                        <option value="waiting_delivery" <?= isset($statusFilter) && $statusFilter === 'waiting_delivery' ? 'selected' : '' ?>>Очікується поставки товару</option>
                        <option value="completed" <?= isset($statusFilter) && $statusFilter === 'completed' ? 'selected' : '' ?>>Завершено</option>
                        <option value="canceled" <?= isset($statusFilter) && $statusFilter === 'canceled' ? 'selected' : '' ?>>Не можливо виконати</option>
                    </select>
                </div>

                <div class="filter-group filter-sort">
                    <label for="sort-by">Сортувати за:</label>
                    <select id="sort-by" name="sort" onchange="this.form.submit()">
                        <option value="newest" <?= !isset($sortOrder) || $sortOrder === 'newest' ? 'selected' : '' ?>>Спочатку нові</option>
                        <option value="oldest" <?= isset($sortOrder) && $sortOrder === 'oldest' ? 'selected' : '' ?>>Спочатку старі</option>
                    </select>
                </div>

                <div class="search-group">
                    <i class="bi bi-search search-icon"></i>
                    <input type="text" class="search-input" name="search" placeholder="Пошук за номером або послугою..." value="<?= isset($searchTerm) ? htmlspecialchars($searchTerm) : '' ?>">
                    <button type="submit" class="search-btn"><i class="bi bi-search"></i></button>
                </div>

                <?php if ((isset($statusFilter) && $statusFilter !== 'all') || (isset($searchTerm) && !empty($searchTerm))): ?>
                    <div class="filter-actions">
                        <a href="?section=orders" class="clear-filters-btn"><i class="bi bi-x-circle"></i> Очистити фільтри</a>
                    </div>
                <?php endif; ?>
            </form>

            <div class="orders-stats">
                <div class="stat-item">
                    <div class="stat-icon stat-icon-new">
                        <i class="bi bi-hourglass"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?= $stats['new'] ?></div>
                        <div class="stat-label">Новий</div>
                    </div>
                </div>

                <div class="stat-item">
                    <div class="stat-icon stat-icon-in-progress">
                        <i class="bi bi-gear"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?= $stats['in_progress'] ?></div>
                        <div class="stat-label">В роботі</div>
                    </div>
                </div>

                <div class="stat-item">
                    <div class="stat-icon stat-icon-waiting">
                        <i class="bi bi-clock"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?= $stats['waiting'] ?></div>
                        <div class="stat-label">Очікується</div>
                    </div>
                </div>

                <div class="stat-item">
                    <div class="stat-icon stat-icon-waiting-delivery">
                        <i class="bi bi-truck"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?= $stats['waiting_delivery'] ?? 0 ?></div>
                        <div class="stat-label">Очікується поставки</div>
                    </div>
                </div>

                <div class="stat-item">
                    <div class="stat-icon stat-icon-completed">
                        <i class="bi bi-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?= $stats['completed'] ?></div>
                        <div class="stat-label">Завершено</div>
                    </div>
                </div>

                <div class="stat-item">
                    <div class="stat-icon stat-icon-canceled">
                        <i class="bi bi-x-circle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?= $stats['canceled'] ?></div>
                        <div class="stat-label">Не можливо виконати</div>
                    </div>
                </div>
            </div>

            <?php if (!empty($orders)): ?>
                <div class="orders-grid">
                    <?php foreach ($orders as $order):
                        $orderIsDisabled = ($order['status'] === 'Завершено' || $order['status'] === 'Виконано' || $order['status'] === 'Не можливо виконати');
                        ?>
                        <div class="order-card">
                            <div class="order-card-header">
                                <div class="order-service"><?= safeEcho($order['service']) ?></div>
                                <div class="status-badge <?= getStatusClass($order['status']) ?>">
                                    <?= safeEcho($order['status']) ?>
                                </div>
                            </div>

                            <div class="order-card-content">
                                <div class="order-number">Замовлення #<?= $order['id'] ?></div>

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
                                </ul>

                                <?php if ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'junior_admin'): ?>
                                    <form method="POST" action="update_status.php" class="status-form">
                                        <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <select name="status" class="status-select">
                                            <option value="Новий" <?= $order['status'] === 'Новий' ? 'selected' : '' ?>>Новий</option>
                                            <option value="В роботі" <?= $order['status'] === 'В роботі' ? 'selected' : '' ?>>В роботі</option>
                                            <option value="Очікується" <?= $order['status'] === 'Очікується' ? 'selected' : '' ?>>Очікується</option>
                                            <option value="Очікується поставки товару" <?= $order['status'] === 'Очікується поставки товару' ? 'selected' : '' ?>>Очікується поставки товару</option>
                                            <option value="Завершено" <?= $order['status'] === 'Завершено' ? 'selected' : '' ?>>Завершено</option>
                                            <option value="Не можливо виконати" <?= $order['status'] === 'Не можливо виконати' ? 'selected' : '' ?>>Не можливо виконати</option>
                                        </select>
                                        <button type="submit" class="btn-primary">Змінити статус</button>
                                    </form>
                                <?php endif; ?>
                            </div>

                            <div class="order-card-footer">
                                <div class="order-date"><?= formatDate($order['created_at']) ?></div>
                                <a href="?section=orders&id=<?= $order['id'] ?>" class="order-details-link">
                                    <i class="bi bi-eye"></i> Деталі
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="no-orders">
                    <i class="bi bi-clipboard-x"></i>
                    <p>Замовлень немає або вони не відповідають вибраним фільтрам.</p>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <!-- Users Section (admin only) -->
    <?php if ($_SESSION['role'] === 'admin'): ?>
        <section id="users" style="display: <?= $activeSection === 'users' ? 'block' : 'none' ?>;">
            <h2 class="section-title">
                <i class="bi bi-people"></i> Керування користувачами
            </h2>
            <form method="GET" class="filters-form">
                <input type="hidden" name="section" value="users">
                <div class="search-group">
                    <i class="bi bi-search search-icon"></i>
                    <input type="text" class="search-input" name="user_search" placeholder="Пошук за логіном" value="<?= htmlspecialchars($userSearch) ?>">
                    <button type="submit" class="search-btn"><i class="bi bi-search"></i></button>
                </div>
            </form>
            <?php if (!empty($users)): ?>
                <div class="users-grid">
                    <?php foreach ($users as $user): ?>
                        <div class="user-card role-<?= str_replace('_', '-', $user['role']) ?>">
                            <form method="POST" action="update_user.php">
                                <input type="hidden" name="section" value="<?= $activeSection ?>">
                                <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <div class="form-group">
                                    <label>Логін:</label>
                                    <input type="text" name="username" value="<?= htmlspecialchars($user['username']) ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>Роль:</label>
                                    <select name="role" class="role-select">
                                        <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Адміністратор</option>
                                        <option value="junior_admin" <?= $user['role'] === 'junior_admin' ? 'selected' : '' ?>>Молодший адмін</option>
                                        <option value="user" <?= $user['role'] === 'user' ? 'selected' : '' ?>>Користувач</option>
                                    </select>
                                </div>
                                <button type="submit" class="btn-primary">Оновити</button>
                                <button type="button" class="btn btn-danger" onclick="confirmDelete(<?= $user['id'] ?>)">Видалити</button>
                            </form>
                            <?php if (isset($user['blocked_until']) && strtotime($user['blocked_until']) > time()): ?>
                                <p class="blocked-info">
                                    Заблоковано до: <?= htmlspecialchars($user['blocked_until']) ?><br>
                                    Причина: <?= htmlspecialchars($user['block_reason']) ?>
                                </p>
                                <button type="button" class="btn btn-success" onclick="unblockUser(<?= $user['id'] ?>)">Розблокувати</button>
                            <?php else: ?>
                                <button type="button" class="btn btn-warning" onclick="toggleBlockForm(<?= $user['id'] ?>)">Заблокувати</button>
                                <div id="block-form-<?= $user['id'] ?>" style="display:none; margin-top: 10px;">
                                    <form method="POST" action="block_user.php">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                        <div class="form-group">
                                            <label>Причина блокування:</label>
                                            <input type="text" name="block_reason" required>
                                        </div>
                                        <div class="form-group">
                                            <label>Час блокування (до):</label>
                                            <input type="datetime-local" name="blocked_until" required>
                                        </div>
                                        <button type="submit" class="btn-primary">Заблокувати</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p>Користувачів не знайдено.</p>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <!-- Logs Section (admin only) -->
    <?php if ($_SESSION['role'] === 'admin'): ?>
        <section id="logs" style="display: <?= $activeSection === 'logs' ? 'block' : 'none' ?>;">
            <h2 class="section-title">
                <i class="bi bi-journal-text"></i> Системні логи
            </h2>

            <form method="GET" class="filters-form">
                <input type="hidden" name="section" value="logs">
                <div class="filter-group">
                    <label for="log_user">Користувач:</label>
                    <input type="text" id="log_user" name="log_user" placeholder="Фільтр за користувачем" value="<?= htmlspecialchars($logUser) ?>" class="search-input">
                </div>
                <div class="filter-group">
                    <label for="log_date">Дата:</label>
                    <input type="date" id="log_date" name="log_date" value="<?= htmlspecialchars($logDate) ?>" title="Оберіть дату" class="search-input">
                </div>
                <div class="filter-group">
                    <label for="log_action">Дія:</label>
                    <input type="text" id="log_action" name="log_action" placeholder="Фільтр за діями" value="<?= htmlspecialchars($logAction) ?>" class="search-input">
                </div>
                <button type="submit" class="search-btn">
                    <i class="bi bi-funnel"></i> Фільтрувати
                </button>

                <?php if (!empty($logUser) || !empty($logDate) || !empty($logAction)): ?>
                    <a href="?section=logs" class="clear-filters-btn"><i class="bi bi-x-circle"></i> Очистити фільтри</a>
                <?php endif; ?>
            </form>

            <?php if (!empty($logs)): ?>
                <div class="logs-table">
                    <table>
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
                                <td class="log-date"><?= htmlspecialchars($log['created_at']) ?></td>
                                <td class="log-user">
                                    <span class="log-username <?= $log['role'] === 'admin' ? 'admin-username' : ($log['role'] === 'junior_admin' ? 'junior-admin-username' : '') ?>">
                                        <?php if ($log['role'] === 'admin'): ?>
                                            <i class="bi bi-stars"></i>
                                        <?php elseif ($log['role'] === 'junior_admin'): ?>
                                            <i class="bi bi-patch-check"></i>
                                        <?php endif; ?>
                                        <?= htmlspecialchars($log['username']) ?>
                                    </span>
                                    <?php if (isset($log['role'])): ?>
                                        <?php if ($log['role'] === 'admin'): ?>
                                            <span class="admin-badge">Адміністратор</span>
                                        <?php elseif ($log['role'] === 'junior_admin'): ?>
                                            <span class="admin-badge">Мол. Адміністратор</span>
                                        <?php else: ?>
                                            <span class="user-badge">Користувач</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td class="log-action" data-type="<?= strtolower(explode(' ', $log['action'])[0]) ?>">
                                    <?= htmlspecialchars($log['action']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php if ($totalPages > 1): ?>
                        <div class="pagination">
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <?php
                                $queryParams = $_GET;
                                $queryParams['log_page'] = $i;
                                $queryString = http_build_query($queryParams);
                                ?>
                                <a href="?<?= $queryString ?>"
                                   class="<?= $i == $logPage ? 'active' : '' ?>">
                                    <?= $i ?>
                                </a>
                            <?php endfor; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <p class="no-logs">Логи не знайдено</p>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const activeSection = new URLSearchParams(window.location.search).get('section') || 'orders';
        document.querySelectorAll('section').forEach(s => s.style.display = 'none');
        document.querySelector('#' + activeSection).style.display = 'block';

        document.querySelectorAll('.admin-sections a').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const section = this.getAttribute('data-section');
                window.history.pushState({}, '', '?section=' + section);
                document.querySelectorAll('section').forEach(s => s.style.display = 'none');
                document.querySelector('#' + section).style.display = 'block';
            });
        });

        // Показати більше коментарів
        const showMoreCommentsBtn = document.getElementById('showMoreCommentsBtn');
        if (showMoreCommentsBtn) {
            showMoreCommentsBtn.addEventListener('click', function() {
                document.querySelectorAll('.hidden-comment').forEach(comment => {
                    comment.classList.remove('hidden-comment');
                });
                this.parentNode.style.display = 'none';
            });
        }

        // Переключення теми
        const themeSwitchBtn = document.getElementById('themeSwitchBtn');
        if (themeSwitchBtn) {
            themeSwitchBtn.addEventListener('click', function() {
                const htmlElement = document.documentElement;
                const currentTheme = htmlElement.getAttribute('data-theme') || 'dark';
                const newTheme = currentTheme === 'dark' ? 'light' : 'dark';

                htmlElement.setAttribute('data-theme', newTheme);
                localStorage.setItem('theme', newTheme);
                document.cookie = `theme=${newTheme}; path=/; max-age=2592000`; // 30 днів

                const icon = this.querySelector('i');
                if (icon) {
                    icon.className = newTheme === 'dark' ? 'bi bi-sun' : 'bi bi-moon';
                }
            });
        }
    });

    function toggleBlockForm(userId) {
        const form = document.getElementById('block-form-' + userId);
        form.style.display = (form.style.display === 'none' || form.style.display === '') ? 'block' : 'none';
    }

    function unblockUser(userId) {
        if (confirm('Розблокувати користувача?')) {
            window.location.href = '/admin_panel/unblock_user.php?id=' + userId + '&csrf_token=' + encodeURIComponent("<?= $_SESSION['csrf_token'] ?>");
        }
    }

    function confirmDelete(userId) {
        if (confirm('Видалити користувача?')) {
            window.location.href = '/admin_panel/delete_user.php?id=' + userId + '&csrf_token=' + encodeURIComponent("<?= $_SESSION['csrf_token'] ?>");
        }
    }

    // Функція для отримання поточного локального часу
    function getCurrentLocalTime() {
        const now = new Date();
        const year = now.getFullYear();
        const month = String(now.getMonth() + 1).padStart(2, '0');
        const day = String(now.getDate()).padStart(2, '0');
        const hours = String(now.getHours()).padStart(2, '0');
        const minutes = String(now.getMinutes()).padStart(2, '0');
        const seconds = String(now.getSeconds()).padStart(2, '0');

        return `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;
    }

    // Функція оновлення часу
    function updateTime() {
        const timeString = getCurrentLocalTime();
        document.querySelectorAll('.time-value').forEach(el => {
            el.textContent = timeString;
        });
    }

    // Запускаємо оновлення часу кожну секунду
    setInterval(updateTime, 1000);

    // Початкове оновлення часу
    updateTime();
</script>
</body>
</html>