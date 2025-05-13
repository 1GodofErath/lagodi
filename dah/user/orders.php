<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Підключення необхідних файлів
require_once '../../dah/confi/database.php';
require_once '../../dah/include/session.php';
require_once '../../dah/include/functions.php';
require_once '../../dah/include/auth.php';

// Перевірка авторизації
if (!isLoggedIn()) {
    header("Location: /login.php");
    exit;
}

// Отримання поточного користувача
$user = getCurrentUser();

// Перевірка, чи заблокований користувач
if (isUserBlocked($user['id'])) {
    header("Location: /logout.php?reason=blocked");
    exit;
}

// Встановлення теми
if (isset($_GET['theme'])) {
    $theme = $_GET['theme'] === 'dark' ? 'dark' : 'light';
    setcookie('theme', $theme, time() + (86400 * 30), "/"); // 30 днів
    $_COOKIE['theme'] = $theme; // Встановлюємо значення одразу для поточного запиту

    // Отримуємо поточний URL
    $currentUrl = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

    // Перенаправлення назад на поточну сторінку без параметра theme
    $queryParams = $_GET;
    unset($queryParams['theme']);

    $queryString = '';
    if (!empty($queryParams)) {
        $queryString = '?' . http_build_query($queryParams);
    }

    header("Location: $currentUrl$queryString");
    exit;
}

$currentTheme = isset($_COOKIE['theme']) ? $_COOKIE['theme'] : 'dark'; // Темна тема за замовчуванням

// База даних
$database = new Database();
$db = $database->getConnection();

// Ініціалізація змінних для повідомлень
$successMessage = '';
$errorMessage = '';

// Функція для перевірки можливості коментування
function canAddComments($status) {
    $status = trim($status); // Прибираємо зайві пробіли
    $blockedStatuses = ['Завершено', 'Виконано', 'Не можливо виконати'];
    return !in_array($status, $blockedStatuses);
}

// Перевіряємо наявність параметра id для відображення деталей замовлення
if (isset($_GET['id'])) {
    $orderId = filter_var($_GET['id'], FILTER_VALIDATE_INT);

    // Перевіряємо успішне створення замовлення
    if (isset($_GET['success']) && $_GET['success'] === 'created') {
        $successMessage = "Замовлення #$orderId успішно створено!";
    }

    // Перевіряємо помилки та повідомлення з URL
    if (isset($_GET['error']) && $_GET['error'] === 'comment_blocked') {
        $errorMessage = "Неможливо додати коментар до замовлення з поточним статусом";
    }

    // Показуємо повідомлення про успішне додавання коментаря
    if (isset($_GET['comment_added']) && $_GET['comment_added'] === 'true') {
        $successMessage = "Коментар успішно додано";
    }

    // Отримуємо деталі замовлення
    try {
        $query = "SELECT o.*, c.name as category_name 
                 FROM orders o
                 LEFT JOIN service_categories c ON o.category_id = c.id 
                 WHERE o.id = :id AND o.user_id = :user_id";

        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $orderId);
        $stmt->bindParam(':user_id', $user['id']);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            $orderDetails = $stmt->fetch(PDO::FETCH_ASSOC);

            // Отримуємо коментарі до замовлення
            $commentsQuery = "SELECT c.*, u.username, u.role 
                             FROM comments c
                             LEFT JOIN users u ON c.user_id = u.id 
                             WHERE c.order_id = :order_id 
                             ORDER BY c.created_at DESC";  // Змінено на DESC для найновіших першими

            $commentsStmt = $db->prepare($commentsQuery);
            $commentsStmt->bindParam(':order_id', $orderId);
            $commentsStmt->execute();
            $comments = $commentsStmt->fetchAll(PDO::FETCH_ASSOC);

            // Визначаємо кількість коментарів і скільки показувати спочатку
            $totalComments = count($comments);
            $showInitialComments = 3;
            $showLoadMore = $totalComments > $showInitialComments;

            // Отримуємо файли, прикріплені до замовлення
            $filesQuery = "SELECT * FROM order_files WHERE order_id = :order_id";
            $filesStmt = $db->prepare($filesQuery);
            $filesStmt->bindParam(':order_id', $orderId);
            $filesStmt->execute();
            $orderFiles = $filesStmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            header("Location: orders.php");
            exit;
        }
    } catch (PDOException $e) {
        $errorMessage = "Помилка при отриманні деталей замовлення: " . $e->getMessage();
    }
} else {
    // Параметри фільтрації та пошуку
    $statusFilter = isset($_GET['status']) ? $_GET['status'] : 'all';
    $searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';
    $sortOrder = isset($_GET['sort']) ? $_GET['sort'] : 'newest';

    // Відображаємо список всіх замовлень користувача з фільтрацією
    try {
        // Базовий запит
        $query = "SELECT o.*, c.name as category_name 
                 FROM orders o
                 LEFT JOIN service_categories c ON o.category_id = c.id 
                 WHERE o.user_id = :user_id";

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
                case 'completed':
                    $statusName = 'Завершено';
                    break;
                case 'canceled':
                    $statusName = 'Не можливо виконати';
                    break;
            }

            if (!empty($statusName)) {
                $query .= " AND o.status = :status";
            }
        }

        // Додаємо пошук
        if (!empty($searchTerm)) {
            // Перевіряємо, чи пошуковий термін - число (для пошуку за ID)
            if (is_numeric($searchTerm)) {
                $query .= " AND (o.id = :search_id OR o.service LIKE :search_term)";
            } else {
                $query .= " AND o.service LIKE :search_term";
            }
        }

        // Додаємо сортування
        $query .= ($sortOrder === 'oldest') ? " ORDER BY o.created_at ASC" : " ORDER BY o.created_at DESC";

        $stmt = $db->prepare($query);
        $stmt->bindParam(':user_id', $user['id']);

        // Додаємо параметри фільтрів
        if ($statusFilter !== 'all' && !empty($statusName)) {
            $stmt->bindParam(':status', $statusName);
        }

        if (!empty($searchTerm)) {
            if (is_numeric($searchTerm)) {
                $stmt->bindParam(':search_id', $searchTerm);
                $searchWildcard = "%{$searchTerm}%";
                $stmt->bindParam(':search_term', $searchWildcard);
            } else {
                $searchWildcard = "%{$searchTerm}%";
                $stmt->bindParam(':search_term', $searchWildcard);
            }
        }

        $stmt->execute();
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Отримуємо статистику за кожним статусом
        $statsQuery = "SELECT status, COUNT(*) as count FROM orders WHERE user_id = :user_id GROUP BY status";
        $statsStmt = $db->prepare($statsQuery);
        $statsStmt->bindParam(':user_id', $user['id']);
        $statsStmt->execute();
        $statsResults = $statsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Створюємо масив статистики
        $stats = [
            'new' => 0,
            'in_progress' => 0,
            'waiting' => 0,
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
                case 'Завершено':
                case 'Виконано':
                    $stats['completed'] = $stat['count'];
                    break;
                case 'Не можливо виконати':
                case 'Скасовано':
                    $stats['canceled'] = $stat['count'];
                    break;
            }
        }
    } catch (PDOException $e) {
        $errorMessage = "Помилка при отриманні списку замовлень: " . $e->getMessage();
        $orders = [];
    }
}

// Обробка додавання коментаря
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_comment']) && isset($_POST['order_id']) && isset($_POST['comment'])) {
    $orderIdForComment = filter_var($_POST['order_id'], FILTER_VALIDATE_INT);
    $comment = htmlspecialchars($_POST['comment']);

    if ($orderIdForComment && !empty($comment)) {
        try {
            // Спочатку перевіряємо статус замовлення
            $statusCheckQuery = "SELECT status FROM orders WHERE id = :order_id AND user_id = :user_id";
            $statusCheckStmt = $db->prepare($statusCheckQuery);
            $statusCheckStmt->bindParam(':order_id', $orderIdForComment);
            $statusCheckStmt->bindParam(':user_id', $user['id']);
            $statusCheckStmt->execute();
            $orderStatus = $statusCheckStmt->fetchColumn();

            // Точна перевірка статусу з обрізкою пробілів
            $blockedStatuses = ['Завершено', 'Виконано', 'Не можливо виконати'];
            if (in_array(trim($orderStatus), $blockedStatuses)) {
                $errorMessage = "Неможливо додати коментар до замовлення зі статусом '$orderStatus'";
                header("Location: orders.php?id=$orderIdForComment&error=comment_blocked");
                exit;
            }

            $commentQuery = "INSERT INTO comments (order_id, user_id, content, is_read, created_at) 
                            VALUES (:order_id, :user_id, :content, 0, NOW())";

            $commentStmt = $db->prepare($commentQuery);
            $commentStmt->bindParam(':order_id', $orderIdForComment);
            $commentStmt->bindParam(':user_id', $user['id']);
            $commentStmt->bindParam(':content', $comment);
            $commentStmt->execute();

            // Перенаправляємо на ту ж сторінку, щоб уникнути повторної відправки форми
            header("Location: orders.php?id=$orderIdForComment&comment_added=true");
            exit;
        } catch (PDOException $e) {
            $errorMessage = "Помилка при додаванні коментаря: " . $e->getMessage();
        }
    }
}

// Обробка видалення коментаря
if (isset($_GET['delete_comment']) && isset($_GET['id'])) {
    $commentId = filter_var($_GET['delete_comment'], FILTER_VALIDATE_INT);
    $orderIdForDelete = filter_var($_GET['id'], FILTER_VALIDATE_INT);

    if ($commentId && $orderIdForDelete) {
        try {
            // Перевіряємо, що коментар належить поточному користувачу або користувач має права адміністратора
            $checkQuery = "SELECT c.user_id FROM comments c WHERE c.id = :comment_id AND c.order_id = :order_id";
            $checkStmt = $db->prepare($checkQuery);
            $checkStmt->bindParam(':comment_id', $commentId);
            $checkStmt->bindParam(':order_id', $orderIdForDelete);
            $checkStmt->execute();
            $commentOwner = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($commentOwner && ($commentOwner['user_id'] == $user['id'] || hasAdminAccess())) {
                $deleteQuery = "DELETE FROM comments WHERE id = :comment_id";
                $deleteStmt = $db->prepare($deleteQuery);
                $deleteStmt->bindParam(':comment_id', $commentId);
                $deleteStmt->execute();

                // Перенаправляємо на ту ж сторінку
                header("Location: orders.php?id=$orderIdForDelete&comment_deleted=true");
                exit;
            } else {
                $errorMessage = "У вас немає прав для видалення цього коментаря.";
            }
        } catch (PDOException $e) {
            $errorMessage = "Помилка при видаленні коментаря: " . $e->getMessage();
        }
    }
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
        case 'completed':
            return 'Завершено';
        case 'canceled':
            return 'Не можливо виконати';
        default:
            return 'Всі статуси';
    }
}

// Функція для перевірки наявності прав адміністратора
if (!function_exists('hasAdminAccess')) {
    function hasAdminAccess() {
        global $user;
        return isset($user['role']) && ($user['role'] === 'admin' || $user['role'] === 'manager');
    }
}
?>

    <!DOCTYPE html>
<html lang="uk" data-theme="<?php echo $currentTheme; ?>">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
        <title><?php echo isset($orderDetails) ? "Замовлення #" . $orderDetails['id'] : "Мої замовлення"; ?> - Lagodi Service</title>

        <!-- Блокуємо рендеринг сторінки до встановлення теми -->
        <script>
            (function() {
                const storedTheme = localStorage.getItem('theme') || '<?php echo $currentTheme; ?>';
                document.documentElement.setAttribute('data-theme', storedTheme);
            })();
        </script>

        <!-- CSS файли -->
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
        <link rel="stylesheet" href="../../style/dahm/user_dashboard.css">
        <link rel="stylesheet" href="../../style/dahm/orders.css">

        <style>
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
            }

            /* Стилі для випадаючих списків - адаптивні під темну та світлу теми */
            .filter-group {
                display: flex;
                flex-direction: column;
                min-width: 180px;
                position: relative;
                margin-bottom: 0;
            }

            .filter-group label {
                font-size: 0.85rem;
                margin-bottom: 5px;
                color: var(--text-secondary, #888);
            }

            /* Основний стиль для селектів */
            .filter-group select {
                padding: 8px 12px;
                border-radius: 6px;
                border: 1px solid var(--border-color);
                background-color: transparent; /* Прозорий фон */
                color: var(--text-primary); /* Колір тексту від теми */
                min-width: 150px;
                appearance: none;
                padding-left: 35px; /* Відступ для іконки */
                background-position: right 12px center;
                background-repeat: no-repeat;
                background-size: 12px;
            }

            /* Стрілка вниз для селектів (адаптивна для обох тем) */
            .filter-group select {
                background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%236b7280' viewBox='0 0 16 16'%3E%3Cpath fill-rule='evenodd' d='M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z'/%3E%3C/svg%3E");
            }

            /* Світла тема - додаткові стилі */
            :root[data-theme="light"] .filter-group select {
                background-color: #ffffff;
                color: #333333;
                border-color: #d1d5db;
            }

            /* Темна тема - додаткові стилі */
            :root[data-theme="dark"] .filter-group select {
                background-color: #2d3748;
                color: #e2e8f0;
                border-color: #4a5568;
            }

            /* Стилі для іконок у фільтрах */
            .filter-group::before {
                content: "";
                position: absolute;
                left: 10px;
                bottom: 12px;
                width: 16px;
                height: 16px;
                background-repeat: no-repeat;
                background-position: center;
                z-index: 1;
            }

            /* Адаптивні іконки для обох тем */
            :root[data-theme="light"] .filter-group::before {
                filter: brightness(0) opacity(0.6);
            }

            :root[data-theme="dark"] .filter-group::before {
                filter: brightness(0) invert(1) opacity(0.6);
            }

            /* Іконки для фільтрів статусів */
            .filter-status::before {
                background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%236b7280' viewBox='0 0 16 16'%3E%3Cpath d='M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z'/%3E%3Cpath d='M10.97 4.97a.235.235 0 0 0-.02.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-1.071-1.05z'/%3E%3C/svg%3E");
            }

            .filter-sort::before {
                background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%236b7280' viewBox='0 0 16 16'%3E%3Cpath fill-rule='evenodd' d='M10.082 5.629 9.664 7H8.598l1.789-5.332h1.234L13.402 7h-1.12l-.419-1.371h-1.781zm1.57-.785L11 2.687h-.047l-.652 2.157h1.351z'/%3E%3Cpath d='M12.96 14H9.028v-.691l2.579-3.72v-.054H9.098v-.867h3.785v.691l-2.567 3.72v.054h2.645V14zM4.5 2.5a.5.5 0 0 0-1 0v9.793l-1.146-1.147a.5.5 0 0 0-.708.708l2 1.999.007.007a.497.497 0 0 0 .7-.006l2-2a.5.5 0 0 0-.707-.708L4.5 12.293V2.5z'/%3E%3C/svg%3E");
            }

            /* Стилі для пошуку */
            .search-group {
                display: flex;
                flex-grow: 1;
                min-width: 200px;
                position: relative;
            }

            .search-input {
                flex-grow: 1;
                padding: 8px 12px 8px 35px;
                border: 1px solid var(--border-color);
                border-radius: 6px;
                background-color: transparent;
                color: var(--text-primary);
            }

            /* Адаптивна іконка пошуку */
            .search-icon {
                position: absolute;
                left: 10px;
                top: 50%;
                transform: translateY(-50%);
                pointer-events: none;
            }

            /* Завжди видима лупа на світлій темі */
            :root[data-theme="light"] .search-icon {
                color: #333 !important; /* Темніший колір для світлої теми */
            }

            :root[data-theme="dark"] .search-icon {
                color: #fff !important; /* Світліший колір для темної теми */
            }

            /* Для кнопки пошуку */
            .search-btn i {
                color: #ffffff !important; /* Білий колір іконки в кнопці пошуку */
            }
            .search-btn[data-theme="dark"] .search-icon {
                color: #fff !important; /* Світліший колір для темної теми */
            }

            /* Додаткові стилі для світлої теми */
            :root[data-theme="light"] .search-input {
                background-color: #ffffff;
                color: #333333;
                border-color: #d1d5db;
            }

            /* Додаткові стилі для темної теми */
            :root[data-theme="dark"] .search-input {
                background-color: #2d3748;
                color: #e2e8f0;
                border-color: #4a5568;
            }

            .search-btn {
                background-color: var(--primary-color);
                color: white;
                border: none;
                border-radius: 6px;
                padding: 8px 15px;
                cursor: pointer;
                margin-left: 8px;
                display: flex;
                align-items: center;
                justify-content: center;
                transition: background-color 0.2s;
            }

            .search-btn:hover {
                background-color: var(--primary-color-dark, #2980b9);
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

            /* Повідомлення про неможливість коментування */
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

            /* Покращення дизайну карток замовлень */
            .order-card {
                transition: transform 0.2s, box-shadow 0.3s;
            }

            .order-card:hover {
                transform: translateY(-3px);
                box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            }

            .order-card-header {
                background-color: rgba(0, 0, 0, 0.05);
                border-radius: 8px 8px 0 0;
            }

            .order-card-footer {
                background-color: rgba(0, 0, 0, 0.02);
            }

            .order-details-link {
                transition: background-color 0.2s;
                padding: 6px 12px;
                border-radius: 6px;
            }

            .order-details-link:hover {
                background-color: rgba(52, 152, 219, 0.1);
            }

            /* Покращення для статистики замовлень */
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
            }

            .stat-item:hover {
                transform: translateY(-3px);
                box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            }

            .stat-icon {
                width: 40px;
                height: 40px;
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
                color: #888;
            }

            /* Стилі для сповіщень */
            .notification {
                position: fixed;
                top: 15px;
                left: 50%;
                transform: translateX(-50%);
                padding: 12px 20px;
                border-radius: 8px;
                color: white;
                font-weight: 500;
                box-shadow: 0 3px 10px rgba(0, 0, 0, 0.2);
                z-index: 1000;
                display: flex;
                align-items: center;
                gap: 10px;
                min-width: 300px;
                max-width: 90%;
                opacity: 0;
                visibility: hidden;
                transition: opacity 0.3s, visibility 0.3s;
            }

            .notification.show {
                opacity: 1;
                visibility: visible;
            }

            .notification-success {
                background-color: #2ecc71;
            }

            .notification-error {
                background-color: #e74c3c;
            }

            .notification-info {
                background-color: #3498db;
            }

            .notification i {
                font-size: 1.2rem;
            }

            .notification-content {
                flex: 1;
            }

            /* Стиль для сповіщення про створення замовлення */
            .order-created-banner {
                background-color: rgba(46, 204, 113, 0.15);
                border-left: 4px solid #2ecc71;
                padding: 16px;
                margin-bottom: 20px;
                border-radius: 6px;
                display: flex;
                align-items: center;
                color: var(--text-primary);
            }

            .order-created-banner i {
                color: #2ecc71;
                font-size: 1.5rem;
                margin-right: 12px;
            }

            .order-created-banner strong {
                font-weight: 600;
            }

            /* Кнопка "Не має замовлень" */
            .no-orders {
                text-align: center;
                padding: 40px 20px;
                background-color: var(--card-bg);
                border-radius: 10px;
                border: 1px dashed var(--border-color);
                margin-top: 30px;
            }

            .no-orders i {
                font-size: 3rem;
                color: #888;
                margin-bottom: 15px;
                opacity: 0.6;
            }

            .no-orders p {
                font-size: 1.2rem;
                color: #888;
                margin-bottom: 20px;
            }

            .no-orders .create-order-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                background-color: var(--primary-color);
                color: white;
                padding: 10px 20px;
                border-radius: 6px;
                text-decoration: none;
                font-weight: 500;
                transition: all 0.2s;
                font-size: 0.95rem; /* Зменшено розмір шрифту */
            }

            .no-orders .create-order-btn:hover {
                background-color: var(--primary-color-dark, #2980b9);
                transform: translateY(-2px);
            }

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
            }

            <?php if (isset($orderDetails)): ?>
            /* Загальний стиль для контейнера деталей замовлення */
            .order-details-container {
                background-color: var(--card-bg);
                border-radius: 10px;
                box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
                overflow: hidden;
                margin-bottom: 20px;
                border: 1px solid var(--border-color);
            }

            /* Заголовок з номером замовлення */
            .order-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 15px 20px;
                border-bottom: 1px solid var(--border-color);
            }

            .order-title {
                font-size: 1.3rem;
                font-weight: 600;
                color: var(--primary-color);
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

            .status-processing,
            .status-in-progress {
                background-color: #3498db;
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

            /* Рядки з деталями замовлення */
            .detail-row {
                display: flex;
                border-bottom: 1px solid var(--border-color);
                background-color: transparent !important;
            }

            .detail-row:last-child {
                border-bottom: none;
            }

            .detail-col {
                padding: 12px 20px;
                flex: 1;
                background-color: transparent !important;
            }

            .detail-col:first-child {
                border-right: 1px solid var(--border-color);
            }

            .detail-label {
                font-weight: 500;
                color: #888;
                margin-bottom: 5px;
                font-size: 0.9rem;
            }

            .detail-value {
                color: var(--text-primary);
                font-weight: 500;
            }

            .detail-value.empty {
                color: #999;
                font-style: italic;
            }

            /* Стиль для опису */
            .description-section {
                padding: 15px 20px;
                border-top: 1px solid var(--border-color);
            }

            .description-label {
                font-weight: 500;
                color: #888;
                margin-bottom: 10px;
                display: flex;
                align-items: center;
                gap: 8px;
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
            }

            .description-text.empty {
                color: #999;
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
                color: #888;
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
                margin-right: 10px;
                font-size: 1.2rem;
            }

            .file-name {
                flex-grow: 1;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .file-download-btn {
                color: var(--primary-color);
                background: none;
                border: none;
                cursor: pointer;
                width: 32px;
                height: 32px;
                display: flex;
                align-items: center;
                justify-content: center;
                border-radius: 50%;
                transition: all 0.2s;
            }

            .file-download-btn:hover {
                background-color: var(--primary-color);
                color: white;
            }

            /* Коментарі */
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
                color: #888;
            }

            .comment-actions a {
                color: #e74c3c;
                text-decoration: none;
                padding: 4px 8px;
                border-radius: 4px;
                display: inline-flex;
                align-items: center;
                gap: 4px;
                transition: background-color 0.2s;
            }

            .comment-actions a:hover {
                background-color: rgba(231, 76, 60, 0.1);
            }

            .comment-content {
                padding: 15px;
                line-height: 1.5;
            }

            .no-comments {
                text-align: center;
                padding: 30px;
                color: #888;
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
                background-color: rgba(0, 0, 0, 0.03);
                border-radius: 8px;
                padding: 15px;
                border: 1px solid var(--border-color);
            }

            .comment-form-header {
                font-weight: 600;
                color: var(--text-primary);
                margin-bottom: 12px;
                display: flex;
                align-items: center;
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
            }

            .comment-form textarea:focus {
                outline: none;
                border-color: var(--primary-color);
                box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.1);
            }

            .submit-btn {
                background-color: var(--primary-color);
                color: white;
                border: none;
                border-radius: 6px;
                padding: 10px 15px;
                font-weight: 600;
                cursor: pointer;
                display: inline-flex;
                align-items: center;
                gap: 8px;
                transition: all 0.2s;
            }

            .submit-btn:hover {
                background-color: var(--primary-color-dark, #2980b9);
                transform: translateY(-2px);
            }

            /* Кнопка "Показати більше коментарів" */
            .show-more-comments {
                display: flex;
                justify-content: center;
                margin: 5px 0 20px 0;
            }

            .show-more-btn {
                background-color: transparent;
                border: 1px solid var(--border-color);
                color: var(--primary-color);
                padding: 8px 15px;
                border-radius: 6px;
                font-weight: 500;
                cursor: pointer;
                display: flex;
                align-items: center;
                gap: 5px;
                transition: all 0.2s;
            }

            .show-more-btn:hover {
                background-color: rgba(52, 152, 219, 0.05);
                border-color: var(--primary-color);
            }

            .comment-item.hidden-comment {
                display: none;
            }

            @media (max-width: 768px) {
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
            <?php endif; ?>
        </style>
    </head>
<body>
<div class="wrapper" id="mainWrapper">
    <!-- Сайдбар (ліва панель) -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <a href="/dah/dashboard.php" class="logo">Lagodi Service</a>
            <button id="sidebarToggle" class="toggle-btn">
                <i class="bi bi-arrow-left"></i>
            </button>
        </div>
        <div class="user-info">
            <div class="user-avatar">
                <?php echo strtoupper(substr($user['username'] ?? '', 0, 1)); ?>
            </div>
            <div class="user-details">
                <h3><?php echo safeEcho($user['username']); ?></h3>
                <p><?php echo safeEcho($user['email']); ?></p>
            </div>
            <a href="../user/profile.php" class="edit-profile-btn">Редагувати профіль</a>
        </div>
        <nav class="sidebar-nav">
            <a href="/dah/dashboard.php" class="nav-link">
                <i class="bi bi-speedometer2"></i>
                <span>Дашборд</span>
            </a>
            <a href="/dah/user/create-order.php" class="nav-link">
                <i class="bi bi-plus-circle"></i>
                <span>Нове замовлення</span>
            </a>
            <a href="/dah/user/orders.php" class="nav-link active">
                <i class="bi bi-list-check"></i>
                <span>Мої замовлення</span>
            </a>
            <a href="/dah/user/notifications.php" class="nav-link">
                <i class="bi bi-bell"></i>
                <span>Коментарі</span>
            </a>
            <a href="/dah/user/profile.php" class="nav-link">
                <i class="bi bi-person"></i>
                <span>Профіль</span>
            </a>
            <a href="/user/settings.php" class="nav-link">
                <i class="bi bi-gear"></i>
                <span>Налаштування</span>
            </a>
            <a href="/logout.php" class="nav-link logout">
                <i class="bi bi-box-arrow-right"></i>
                <span>Вихід</span>
            </a>
            <div class="nav-divider"></div>
            <div class="theme-switcher">
                <span class="theme-label">Тема:</span>
                <div class="theme-options">
                    <a href="?theme=light<?php echo isset($orderId) ? '&id='.$orderId : ''; ?>" class="theme-option <?php echo $currentTheme === 'light' ? 'active' : ''; ?>">
                        <i class="bi bi-sun"></i> Світла
                    </a>
                    <a href="?theme=dark<?php echo isset($orderId) ? '&id='.$orderId : ''; ?>" class="theme-option <?php echo $currentTheme === 'dark' ? 'active' : ''; ?>">
                        <i class="bi bi-moon"></i> Темна
                    </a>
                </div>
            </div>
        </nav>
    </aside>

    <!-- Основний контент -->
    <main class="main-content" id="mainContent">
    <header class="main-header">
        <button id="menuToggle" class="menu-toggle">
            <i class="bi bi-list"></i>
        </button>
        <div class="header-title">
            <h1>
                <?php if (isset($orderDetails)): ?>
                    <a href="orders.php" class="back-link"><i class="bi bi-arrow-left"></i></a>
                    Замовлення #<?php echo $orderDetails['id']; ?>
                <?php else: ?>
                    Мої замовлення
                <?php endif; ?>
            </h1>
        </div>
        <div class="header-actions">
            <button id="themeSwitchBtn" class="theme-switch-btn">
                <i class="bi <?php echo $currentTheme === 'dark' ? 'bi-sun' : 'bi-moon'; ?>"></i>
            </button>

            <div class="user-dropdown">
                <button class="user-dropdown-btn">
                    <div class="user-avatar-small">
                        <?php echo strtoupper(substr($user['username'] ?? '', 0, 1)); ?>
                    </div>
                    <span class="user-name"><?php echo safeEcho($user['username']); ?></span>
                    <i class="bi bi-chevron-down"></i>
                </button>
                <div class="user-dropdown-menu">
                    <a href="/dah/user/profile.php"><i class="bi bi-person"></i> Профіль</a>
                    <a href="/user/settings.php"><i class="bi bi-gear"></i> Налаштування</a>
                    <div class="dropdown-divider"></div>
                    <a href="#" id="toggleThemeButton">
                        <i class="bi <?php echo $currentTheme === 'dark' ? 'bi-sun' : 'bi-moon'; ?>"></i>
                        <?php echo $currentTheme === 'dark' ? 'Світла тема' : 'Темна тема'; ?>
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="/logout.php"><i class="bi bi-box-arrow-right"></i> Вихід</a>
                </div>
            </div>
        </div>
    </header>

    <!-- Сповіщення про успіх додавання коментаря -->
    <div class="notification notification-success" id="successNotification">
        <i class="bi bi-check-circle"></i>
        <span id="successMessage">Коментар успішно додано</span>
    </div>

    <!-- Сповіщення про успіх створення замовлення -->
    <div class="notification notification-success" id="orderSuccessNotification">
        <i class="bi bi-check-circle"></i>
        <span id="orderSuccessMessage">Замовлення успішно створено!</span>
    </div>

    <div class="content-wrapper">
    <div class="section-container">
<?php if ($errorMessage): ?>
    <div class="alert alert-danger">
        <i class="bi bi-exclamation-triangle"></i> <?php echo $errorMessage; ?>
    </div>
<?php endif; ?>

<?php
// Перевіряємо успішне створення замовлення
if (isset($_GET['success']) && $_GET['success'] === 'created' && isset($_GET['id'])) {
    $orderId = filter_var($_GET['id'], FILTER_VALIDATE_INT);
    echo '<div class="order-created-banner">
                            <i class="bi bi-check-circle-fill"></i>
                            <div>
                                <strong>Замовлення #' . $orderId . ' успішно створено!</strong>
                                <p>Ваше замовлення прийнято в роботу. Ми зв\'яжемося з вами найближчим часом.</p>
                            </div>
                        </div>';
}
?>

<?php if (isset($orderDetails)): ?>
    <!-- Покращене відображення деталей замовлення -->
<div class="order-details-container <?php echo getStatusClass($orderDetails['status']); ?>">
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
            <div class="detail-label">Послуга:</div>
            <div class="detail-value"><?php echo safeEcho($orderDetails['service']); ?></div>
        </div>
    </div>

    <div class="detail-row">
        <div class="detail-col">
            <div class="detail-label">Категорія:</div>
            <div class="detail-value <?php echo empty(trim($orderDetails['category_name'] ?? '')) ? 'empty' : ''; ?>">
                <?php echo !empty(trim($orderDetails['category_name'] ?? '')) ? safeEcho($orderDetails['category_name']) : 'Не вказано'; ?>
            </div>
        </div>
        <div class="detail-col">
            <div class="detail-label">Тип пристрою:</div>
            <div class="detail-value <?php echo empty(trim($orderDetails['device_type'] ?? '')) ? 'empty' : ''; ?>">
                <?php echo !empty(trim($orderDetails['device_type'] ?? '')) ? safeEcho($orderDetails['device_type']) : 'Не вказано'; ?>
            </div>
        </div>
    </div>

    <div class="detail-row">
        <div class="detail-col">
            <div class="detail-label">Модель пристрою:</div>
            <div class="detail-value <?php echo empty(trim($orderDetails['device_model'] ?? '')) ? 'empty' : ''; ?>">
                <?php echo !empty(trim($orderDetails['device_model'] ?? '')) ? safeEcho($orderDetails['device_model']) : 'Не вказано'; ?>
            </div>
        </div>
        <div class="detail-col">
            <div class="detail-label">Серійний номер:</div>
            <div class="detail-value <?php echo empty(trim($orderDetails['device_serial'] ?? '')) ? 'empty' : ''; ?>">
                <?php echo !empty(trim($orderDetails['device_serial'] ?? '')) ? safeEcho($orderDetails['device_serial']) : 'Не вказано'; ?>
            </div>
        </div>
    </div>

    <div class="detail-row">
        <div class="detail-col">
            <div class="detail-label">Контактний телефон:</div>
            <div class="detail-value <?php echo empty(trim($orderDetails['phone'] ?? '')) ? 'empty' : ''; ?>">
                <?php echo !empty(trim($orderDetails['phone'] ?? '')) ? safeEcho($orderDetails['phone']) : 'Не вказано'; ?>
            </div>
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

    <div class="detail-row">
        <div class="detail-col">
            <div class="detail-label">Дата створення:</div>
            <div class="detail-value"><?php echo formatDate($orderDetails['created_at']); ?></div>
        </div>
        <div class="detail-col"></div>
    </div>

    <div class="description-section">
        <div class="description-label">
            <i class="bi bi-file-text"></i> Опис:
        </div>
        <div class="description-text <?php echo empty(trim($orderDetails['description'] ?? '')) ? 'empty' : ''; ?>">
            <?php echo !empty(trim($orderDetails['description'] ?? '')) ? nl2br(safeEcho($orderDetails['description'])) : 'Не вказано'; ?>
        </div>
    </div>

    <?php if (!empty($orderFiles)): ?>
        <div class="files-section">
        <div class="files-label">
            <i class="bi bi-paperclip"></i> Прикріплені файли
        </div>
        <div class="files-list">
        <?php foreach ($orderFiles as $file): ?>
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
                <a href="<?php echo str_replace('../../', '/', $file['file_path']); ?>" download class="file-download-btn" title="Завантажити файл">
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

        <?php if (!empty($comments)): ?>
            <div class="comments-list">
                <?php foreach ($comments as $index => $comment):
                    $isHidden = $index >= $showInitialComments;
                    ?>
                    <div class="comment-item <?php echo $comment['user_id'] == $user['id'] ? 'own-comment' : ''; ?>
                                             <?php echo $comment['role'] === 'admin' ? 'admin-comment' : ''; ?>
                                             <?php echo $isHidden ? 'hidden-comment' : ''; ?>"
                         id="comment-<?php echo $comment['id']; ?>">
                        <div class="comment-header">
                            <div class="comment-user">
                                <div class="comment-avatar">
                                    <?php echo strtoupper(substr($comment['username'] ?? 'U', 0, 1)); ?>
                                </div>
                                <div class="comment-user-info">
                                    <div class="comment-username">
                                        <?php echo safeEcho($comment['username']); ?>
                                        <?php if ($comment['role'] === 'admin'): ?>
                                            <span class="admin-badge">Адмін</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="comment-time"><?php echo formatDate($comment['created_at']); ?></div>
                                </div>
                            </div>

                            <?php if ($comment['user_id'] == $user['id'] || hasAdminAccess()): ?>
                                <div class="comment-actions">
                                    <a href="?id=<?php echo $orderDetails['id']; ?>&delete_comment=<?php echo $comment['id']; ?>"
                                       title="Видалити коментар"
                                       onclick="return confirm('Ви впевнені, що хочете видалити цей коментар?');">
                                        <i class="bi bi-trash"></i> Видалити
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="comment-content">
                            <?php echo nl2br(safeEcho($comment['content'])); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($showLoadMore): ?>
                <div class="show-more-comments">
                    <button type="button" id="showMoreCommentsBtn" class="show-more-btn">
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
                <form method="post" action="orders.php">
                    <input type="hidden" name="order_id" value="<?php echo $orderDetails['id']; ?>">
                    <textarea name="comment" placeholder="Напишіть ваш коментар тут..." required></textarea>
                    <button type="submit" name="add_comment" class="submit-btn">
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
    <!-- Відображення списку замовлень -->
    <div class="orders-list-container">
        <div class="orders-header">
            <h2>Список замовлень</h2>
            <a href="/dah/user/create-order.php" class="btn-primary">
                <i class="bi bi-plus-circle"></i> Створити нове замовлення
            </a>
        </div>

        <!-- Оновлені фільтри для списку замовлень -->
        <form method="get" action="orders.php" class="filters-form">
            <div class="filter-group filter-status">
                <label for="status-filter">Статус:</label>
                <select id="status-filter" name="status" onchange="this.form.submit()">
                    <option value="all" <?php echo !isset($statusFilter) || $statusFilter === 'all' ? 'selected' : ''; ?>>Всі статуси</option>
                    <option value="new" <?php echo isset($statusFilter) && $statusFilter === 'new' ? 'selected' : ''; ?>>Новий</option>
                    <option value="in_progress" <?php echo isset($statusFilter) && $statusFilter === 'in_progress' ? 'selected' : ''; ?>>В роботі</option>
                    <option value="completed" <?php echo isset($statusFilter) && $statusFilter === 'completed' ? 'selected' : ''; ?>>Завершено</option>
                    <option value="canceled" <?php echo isset($statusFilter) && $statusFilter === 'canceled' ? 'selected' : ''; ?>>Не можливо виконати</option>
                </select>
            </div>

            <div class="filter-group filter-sort">
                <label for="sort-by">Сортувати за:</label>
                <select id="sort-by" name="sort" onchange="this.form.submit()">
                    <option value="newest" <?php echo !isset($sortOrder) || $sortOrder === 'newest' ? 'selected' : ''; ?>>Спочатку нові</option>
                    <option value="oldest" <?php echo isset($sortOrder) && $sortOrder === 'oldest' ? 'selected' : ''; ?>>Спочатку старі</option>
                </select>
            </div>

            <div class="search-group">
                <i class="bi bi-search search-icon"></i>
                <input type="text" class="search-input" name="search" placeholder="Пошук за номером або описом..."
                       value="<?php echo isset($searchTerm) ? htmlspecialchars($searchTerm) : ''; ?>">
                <button type="submit" class="search-btn"><i class="bi bi-search"></i></button>
            </div>

            <?php if ((isset($statusFilter) && $statusFilter !== 'all') || (isset($searchTerm) && !empty($searchTerm))): ?>
                <div class="filter-actions">
                    <a href="orders.php" class="clear-filters-btn"><i class="bi bi-x-circle"></i> Очистити фільтри</a>
                </div>
            <?php endif; ?>
        </form>

        <div class="orders-stats">
            <div class="stat-item">
                <div class="stat-icon stat-icon-new">
                    <i class="bi bi-hourglass"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-number"><?php echo $stats['new']; ?></div>
                    <div class="stat-label">Новий</div>
                </div>
            </div>

            <div class="stat-item">
                <div class="stat-icon stat-icon-in-progress">
                    <i class="bi bi-gear"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-number"><?php echo $stats['in_progress']; ?></div>
                    <div class="stat-label">В роботі</div>
                </div>
            </div>

            <div class="stat-item">
                <div class="stat-icon stat-icon-waiting">
                    <i class="bi bi-clock"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-number"><?php echo $stats['waiting']; ?></div>
                    <div class="stat-label">Очікується</div>
                </div>
            </div>

            <div class="stat-item">
                <div class="stat-icon stat-icon-completed">
                    <i class="bi bi-check-circle"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-number"><?php echo $stats['completed']; ?></div>
                    <div class="stat-label">Завершено</div>
                </div>
            </div>

            <div class="stat-item">
                <div class="stat-icon stat-icon-canceled">
                    <i class="bi bi-x-circle"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-number"><?php echo $stats['canceled']; ?></div>
                    <div class="stat-label">Не можливо виконати</div>
                </div>
            </div>
        </div>

        <?php if (!empty($orders)): ?>
            <div class="orders-grid">
                <?php foreach ($orders as $order): ?>
                    <div class="order-card">
                        <div class="order-card-header">
                            <div class="order-service"><?php echo safeEcho($order['service']); ?></div>
                            <div class="status-badge <?php echo getStatusClass($order['status']); ?>">
                                <?php echo safeEcho($order['status']); ?>
                            </div>
                        </div>

                        <div class="order-card-content">
                            <div class="order-number">Замовлення #<?php echo $order['id']; ?></div>

                            <ul class="order-info-list">
                                <li class="order-info-item">
                                    <div class="order-info-label">Створено:</div>
                                    <div class="order-info-value"><?php echo formatDate($order['created_at']); ?></div>
                                </li>

                                <li class="order-info-item">
                                    <div class="order-info-label">Опис:</div>
                                    <div class="order-info-value">
                                        <?php echo !empty($order['description']) ? (strlen($order['description']) > 50 ? substr(safeEcho($order['description']), 0, 50) . '...' : safeEcho($order['description'])) : 'Не вказано'; ?>
                                    </div>
                                </li>

                                <li class="order-info-item">
                                    <div class="order-info-label">Категорія:</div>
                                    <div class="order-info-value"><?php echo safeEcho($order['category_name'] ?? 'Не вказано'); ?></div>
                                </li>
                            </ul>
                        </div>

                        <div class="order-card-footer">
                            <div class="order-date"><?php echo formatDate($order['created_at']); ?></div>
                            <a href="orders.php?id=<?php echo $order['id']; ?>" class="order-details-link">
                                <i class="bi bi-eye"></i> Деталі
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="no-orders">
                <i class="bi bi-clipboard-x"></i>
                <p>У вас ще немає жодних замовлень</p>
                <a href="/dah/user/create-order.php" class="create-order-btn">
                    <i class="bi bi-plus-circle"></i> Створити замовлення
                </a>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
    </div>
    </div>
    </main>

    <!-- Затемнення фону при відкритті мобільного меню -->
    <div class="overlay" id="overlay"></div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const mainWrapper = document.getElementById('mainWrapper');
        const menuToggle = document.getElementById('menuToggle');
        const sidebarToggle = document.getElementById('sidebarToggle');
        const overlay = document.getElementById('overlay');
        const themeSwitchBtn = document.getElementById('themeSwitchBtn');
        const toggleThemeButton = document.getElementById('toggleThemeButton');
        const successNotification = document.getElementById('successNotification');
        const orderSuccessNotification = document.getElementById('orderSuccessNotification');

        // Перевіряємо, чи є збережений стан сайдбара
        const sidebarCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';

        // Функція для скривання/відображення сайдбару
        function toggleSidebar(collapse) {
            if (mainWrapper) {
                if (collapse) {
                    mainWrapper.classList.add('sidebar-collapsed');
                    localStorage.setItem('sidebarCollapsed', 'true');
                    if (sidebarToggle) sidebarToggle.innerHTML = '<i class="bi bi-arrow-right"></i>';
                } else {
                    mainWrapper.classList.remove('sidebar-collapsed');
                    localStorage.setItem('sidebarCollapsed', 'false');
                    if (sidebarToggle) sidebarToggle.innerHTML = '<i class="bi bi-arrow-left"></i>';
                }
            }
        }

        // Встановлюємо початковий стан сайдбару
        toggleSidebar(sidebarCollapsed);

        // Обробник для кнопки згортання сайдбару
        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', function() {
                const isCollapsed = mainWrapper && mainWrapper.classList.contains('sidebar-collapsed');
                toggleSidebar(!isCollapsed);
            });
        }

        // На мобільних пристроях сайдбар виїжджає
        if (menuToggle && sidebar && overlay) {
            menuToggle.addEventListener('click', function() {
                sidebar.classList.toggle('mobile-active');
                overlay.classList.toggle('active');
                document.body.classList.toggle('no-scroll');
            });

            // Клік по затемненню закриває меню
            overlay.addEventListener('click', function() {
                sidebar.classList.remove('mobile-active');
                overlay.classList.remove('active');
                document.body.classList.remove('no-scroll');
            });
        }

        // Випадаюче меню користувача
        const userDropdownBtn = document.querySelector('.user-dropdown-btn');
        const userDropdownMenu = document.querySelector('.user-dropdown-menu');

        if (userDropdownBtn && userDropdownMenu) {
            userDropdownBtn.addEventListener('click', function(event) {
                event.stopPropagation();
                userDropdownMenu.classList.toggle('show');
            });

            // Клік поза меню закриває їх
            document.addEventListener('click', function(event) {
                if (userDropdownMenu.classList.contains('show') &&
                    !userDropdownBtn.contains(event.target) &&
                    !userDropdownMenu.contains(event.target)) {
                    userDropdownMenu.classList.remove('show');
                }
            });
        }

        // Функція для перемикання теми з плавним переходом
        function toggleTheme() {
            // Отримуємо поточну тему
            const htmlElement = document.documentElement;
            const currentTheme = htmlElement.getAttribute('data-theme') || 'dark';
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';

            // Додаємо клас для плавного переходу
            document.body.style.transition = 'background-color 0.3s ease, color 0.3s ease';

            // Змінюємо тему безпосередньо
            htmlElement.setAttribute('data-theme', newTheme);

            // Зберігаємо стан в localStorage і cookie
            localStorage.setItem('theme', newTheme);
            document.cookie = `theme=${newTheme}; path=/; max-age=2592000`; // ~30 днів

            // Змінюємо всі кнопки перемикання теми
            const themeSwitchers = document.querySelectorAll('.theme-switch-btn, #toggleThemeButton');
            themeSwitchers.forEach(switcher => {
                const icon = switcher.querySelector('i');
                if (icon) {
                    icon.className = newTheme === 'dark' ? 'bi bi-sun' : 'bi bi-moon';
                }

                // Якщо є текст, оновлюємо його також
                const text = switcher.textContent;
                if (text && text.includes('тема')) {
                    switcher.innerHTML = `<i class="${newTheme === 'dark' ? 'bi bi-sun' : 'bi bi-moon'}"></i> ${newTheme === 'dark' ? 'Світла тема' : 'Темна тема'}`;
                }
            });

            // Зберігаємо поточний скрол
            const scrollPosition = window.pageYOffset;

            // Невелика затримка для анімації
            setTimeout(() => {
                // Після переходу - видаляємо стильове правило переходу
                document.body.style.transition = '';

                // Відновлюємо позицію скролу
                window.scrollTo(0, scrollPosition);
            }, 300);
        }

        // Додаємо обробники подій для перемикання теми
        if (themeSwitchBtn) {
            themeSwitchBtn.addEventListener('click', function(e) {
                e.preventDefault();
                toggleTheme();
            });
        }

        if (toggleThemeButton) {
            toggleThemeButton.addEventListener('click', function(e) {
                e.preventDefault();
                toggleTheme();

                // Закриваємо випадаюче меню
                if (userDropdownMenu) {
                    userDropdownMenu.classList.remove('show');
                }
            });
        }

        // Обробник для кнопки "Показати більше коментарів"
        const showMoreCommentsBtn = document.getElementById('showMoreCommentsBtn');
        if (showMoreCommentsBtn) {
            showMoreCommentsBtn.addEventListener('click', function() {
                // Показуємо всі приховані коментарі
                const hiddenComments = document.querySelectorAll('.hidden-comment');
                hiddenComments.forEach(comment => {
                    comment.classList.remove('hidden-comment');
                });

                // Приховуємо кнопку "Показати більше"
                this.parentNode.style.display = 'none';
            });
        }

        // Показуємо спливаюче повідомлення про успіх, якщо є параметр в URL
        const urlParams = new URLSearchParams(window.location.search);

        // Обробка повідомлення про додавання коментаря
        if (urlParams.has('comment_added') && urlParams.get('comment_added') === 'true') {
            if (successNotification) {
                successNotification.classList.add('show');

                // Автоматично приховуємо повідомлення через 4 секунди
                setTimeout(() => {
                    successNotification.classList.remove('show');
                }, 4000);

                // Оновлюємо URL без перезавантаження сторінки
                const newUrl = window.location.pathname + window.location.search.replace(/[?&]comment_added=true/, '');
                window.history.replaceState({}, document.title, newUrl);
            }
        }

        // Обробка повідомлення про створення замовлення
        if (urlParams.has('success') && urlParams.get('success') === 'created') {
            if (orderSuccessNotification) {
                // Отримуємо номер замовлення, якщо є
                const orderId = urlParams.get('id') || '';
                if (orderId) {
                    document.getElementById('orderSuccessMessage').textContent = `Замовлення #${orderId} успішно створено!`;
                }

                orderSuccessNotification.classList.add('show');

                // Автоматично приховуємо повідомлення через 4 секунди
                setTimeout(() => {
                    orderSuccessNotification.classList.remove('show');
                }, 4000);

                // Оновлюємо URL без перезавантаження сторінки, але зберігаємо id
                const newUrl = window.location.pathname + window.location.search.replace(/&success=created/, '');
                window.history.replaceState({}, document.title, newUrl);
            }
        }

        // Зміна розміру екрану - для адаптивного дизайну
        window.addEventListener('resize', function() {
            if (window.innerWidth >= 992 && sidebar) {
                if (sidebar.classList.contains('mobile-active')) {
                    sidebar.classList.remove('mobile-active');
                    overlay.classList.remove('active');
                    document.body.classList.remove('no-scroll');
                }
            }
        });
    });
</script>

</body>
</html>