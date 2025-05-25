<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Підключення необхідних файлів у правильному порядку
require_once '../dah/confi/database.php';
require_once '../dah/include/session.php'; // Підключаємо session.php раніше
require_once '../dah/include/functions.php';
require_once '../dah/include/auth.php';

// Створюємо директорію для логів, якщо вона не існує
$log_dir = $_SERVER['DOCUMENT_ROOT'] . '/dah/logs';
if (!is_dir($log_dir)) {
    mkdir($log_dir, 0755, true);
}
$log_file = $_SERVER['DOCUMENT_ROOT'] . '/dah/logs/dashboard.log';

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
    $_COOKIE['theme'] = $theme;

    // Перенаправлення назад на поточну сторінку без параметра теми
    $redirectUrl = strtok($_SERVER['REQUEST_URI'], '?');
    header("Location: $redirectUrl");
    exit;
}

$currentTheme = isset($_COOKIE['theme']) ? $_COOKIE['theme'] : 'dark'; // Темна тема за замовчуванням

// Отримання статистики по замовленням користувача
$database = new Database();
$db = $database->getConnection();

$query = "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'Новий' THEN 1 ELSE 0 END) as new,
            SUM(CASE WHEN status = 'В роботі' THEN 1 ELSE 0 END) as in_progress,
            SUM(CASE WHEN status = 'Очікується поставки товару' THEN 1 ELSE 0 END) as waiting,
            SUM(CASE WHEN status = 'Завершено' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status = 'Не можливо виконати' THEN 1 ELSE 0 END) as canceled
          FROM orders
          WHERE user_id = :user_id";

$stmt = $db->prepare($query);
$stmt->bindParam(':user_id', $user['id']);
$stmt->execute();

$stats = $stmt->fetch();

// Перевірка, чи є null або пусті значення в статистиці та ініціалізація як 0
$stats['total'] = !empty($stats['total']) ? $stats['total'] : 0;
$stats['new'] = !empty($stats['new']) ? $stats['new'] : 0;
$stats['in_progress'] = !empty($stats['in_progress']) ? $stats['in_progress'] : 0;
$stats['waiting'] = !empty($stats['waiting']) ? $stats['waiting'] : 0;
$stats['completed'] = !empty($stats['completed']) ? $stats['completed'] : 0;
$stats['canceled'] = !empty($stats['canceled']) ? $stats['canceled'] : 0;

// Отримання останніх замовлень
$query = "SELECT * FROM orders WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 5";
$stmt = $db->prepare($query);
$stmt->bindParam(':user_id', $user['id']);
$stmt->execute();

$recent_orders = $stmt->fetchAll();

// Функція для форматування часу
function formatTimeAgo($datetime) {
    $now = new DateTime();
    $date = new DateTime($datetime);
    $interval = $now->diff($date);

    if ($interval->y > 0) {
        return $interval->y . ' ' . ($interval->y == 1 ? 'рік' : 'роки') . ' тому';
    } elseif ($interval->m > 0) {
        return $interval->m . ' ' . ($interval->m == 1 ? 'місяць' : 'місяці') . ' тому';
    } elseif ($interval->d > 0) {
        return $interval->d . ' ' . ($interval->d == 1 ? 'день' : 'дні') . ' тому';
    } elseif ($interval->h > 0) {
        return $interval->h . ' ' . ($interval->h == 1 ? 'год' : 'год') . ' тому';
    } elseif ($interval->i > 0) {
        return $interval->i . ' хв тому';
    } else {
        return 'щойно';
    }
}

// Функція для отримання коментарів користувача
function getUserCommentsLocal($userId, $limit = 5) {
    $database = new Database();
    $db = $database->getConnection();

    $query = "SELECT c.*, o.id as order_id, o.service 
              FROM comments c 
              JOIN orders o ON c.order_id = o.id
              WHERE o.user_id = :user_id 
              ORDER BY c.created_at DESC 
              LIMIT :limit";

    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Функція для підрахунку непрочитаних коментарів
function getUnreadCommentsCountLocal($userId) {
    $database = new Database();
    $db = $database->getConnection();

    $query = "SELECT COUNT(*) as unread_count 
              FROM comments c
              JOIN orders o ON c.order_id = o.id
              WHERE o.user_id = :user_id AND c.is_read = 0";

    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
    $stmt->execute();

    $result = $stmt->fetch();
    $count = isset($result['unread_count']) ? intval($result['unread_count']) : 0;

    // Логуємо для відлагодження
    $log_file = $_SERVER['DOCUMENT_ROOT'] . '/dah/logs/dashboard.log';
    file_put_contents($log_file, date('Y-m-d H:i:s') . " - getUnreadCommentsCountLocal for user $userId, count: $count\n", FILE_APPEND);

    return $count;
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
        case 'Завершено':
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
        case 'Новий':
            return 'bi-hourglass';
        case 'В роботі':
            return 'bi-gear';
        case 'Очікується':
            return 'bi-clock';
        case 'Очікується поставки товару':
            return 'bi-truck';
        case 'Завершено':
        case 'Завершено':
            return 'bi-check-circle';
        case 'Не можливо виконати':
        case 'Скасовано':
            return 'bi-x-circle';
        default:
            return 'bi-circle';
    }
}

// Використовуємо нові функції для роботи з коментарями замість сповіщень
$notifications = getUserCommentsLocal($user['id'], 5);
$unread_count = getUnreadCommentsCountLocal($user['id']);

// Додаємо лог
file_put_contents($log_file, date('Y-m-d H:i:s') . " - Dashboard loaded for user {$user['id']}, unread count: $unread_count\n", FILE_APPEND);

// Отримання доступних сервісів
$services = getServices();
$service_categories = getServiceCategories();

// Встановлюємо заголовок сторінки
$page_title = "Особистий кабінет";

// Поточний час в UTC
$current_time = "2025-05-23 13:27:36";
?>

<!DOCTYPE html>
<html lang="uk" data-theme="<?php echo $currentTheme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title><?php echo $page_title; ?> - Lagodi Service</title>

    <!-- Блокуємо рендеринг сторінки до встановлення теми -->
    <script>
        (function() {
            const storedTheme = localStorage.getItem('theme') || '<?php echo $currentTheme; ?>';
            document.documentElement.setAttribute('data-theme', storedTheme);
        })();
    </script>

    <!-- CSS файли -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../style/dahm/user_dashboard.css">
    <link rel="stylesheet" href="../style/dahm/orders.css">
    <style>
        /* Вбудовані стилі для плавного переходу тем */
        html.color-theme-in-transition,
        html.color-theme-in-transition *,
        html.color-theme-in-transition *:before,
        html.color-theme-in-transition *:after {
            transition: all 750ms !important;
            transition-delay: 0 !important;
        }

        /* Додаткові стилі для нових сповіщень */
        @keyframes notification-pulse {
            0% { box-shadow: 0 0 0 0 rgba(0, 123, 255, 0.4); }
            70% { box-shadow: 0 0 0 10px rgba(0, 123, 255, 0); }
            100% { box-shadow: 0 0 0 0 rgba(0, 123, 255, 0); }
        }

        .notification-item.unread .notification-icon {
            animation: notification-pulse 2s infinite;
        }

        .notification-item.type-status_update .notification-icon {
            background-color: rgba(23, 162, 184, 0.3);
            color: var(--info-color);
        }

        .notification-item.type-comment .notification-icon {
            background-color: rgba(40, 167, 69, 0.3);
            color: var(--success-color);
        }

        .notification-item.type-waiting_delivery .notification-icon {
            background-color: rgba(255, 193, 7, 0.3);
            color: var(--warning-color);
        }

        .notification-item.type-system .notification-icon {
            background-color: rgba(108, 117, 125, 0.3);
            color: var(--secondary-color);
        }

        .notification-indicator {
            position: absolute;
            top: 0;
            right: 0;
            width: 8px;
            height: 8px;
            background-color: var(--danger-color);
            border-radius: 50%;
            display: block;
        }

        /* Статус "Очікується поставки товару" */
        .status-badge.status-очікується-поставки-товару {
            background-color: rgba(255, 193, 7, 0.2);
            color: #856404;
        }

        .status-badge.status-очікується-поставки-товару i {
            color: #f0ad4e;
        }

        /* Покращена аватарка користувача */
        .user-avatar-small {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 600;
            color: white;
            margin-right: 8px;
            background-color: #0d6efd;
            overflow: hidden; /* Щоб фото не виходило за межі кола */
        }

        .user-avatar-small img {
            width: 100%;
            height: 100%;
            object-fit: cover; /* Зберігає пропорції фото */
        }

        /* AJAX loader */
        .ajax-loader {
            display: inline-block;
            width: 12px;
            height: 12px;
            border: 2px solid rgba(0, 123, 255, 0.3);
            border-radius: 50%;
            border-top-color: var(--accent-primary);
            animation: spin 1s linear infinite;
            margin-right: 8px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .notification-empty {
            padding: 2rem;
            text-align: center;
            color: var(--text-secondary);
        }

        /* Стили для карточки статистики ожидания поставок */
        .stats-card.orange {
            background-color: #fd7e14;
            color: white;
        }

        .stats-card.orange::before {
            background-color: rgba(255, 255, 255, 0.2);
        }

        /* Стилі для випадаючих списків з orders.php */
        .filter-group {
            display: flex;
            min-width: 180px;
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
            background-color: transparent;
            color: var(--text-primary);
            min-width: 150px;
            appearance: none;
            background-position: right 12px center;
            background-repeat: no-repeat;
            background-size: 12px;
        }

        /* Стилі для фільтрів з orders.php */
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

        /* Стилі для статусів замовлень з orders.php */
        .status-badge {
            display: inline-block;
            padding: 6px 15px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
            text-align: center;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            transition: background-color 0.3s, color 0.3s, border-color 0.3s;
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

        /* Стилі для сповіщень з orders.php */
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

        /* Покращення статистики замовлень з orders.php */
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
            color: #888;
        }

        /* Стилі для випадаючого списку користувача як на зображенні */
        .user-dropdown-menu {
            position: absolute;
            top: 100%;
            right: 0;
            min-width: 220px;
            background-color: #1a1a1a;
            border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
            z-index: 1000;
            visibility: hidden;
            opacity: 0;
            transform: translateY(10px);
            transition: visibility 0.2s, opacity 0.2s, transform 0.2s;
            overflow: hidden;
        }

        .user-dropdown-menu.show {
            visibility: visible;
            opacity: 1;
            transform: translateY(0);
        }

        .user-dropdown-menu a {
            display: flex;
            align-items: center;
            padding: 12px 16px;
            color: #e2e8f0;
            text-decoration: none;
            font-size: 14px;
            transition: background-color 0.2s;
        }

        .user-dropdown-menu a:hover {
            background-color: #2a2a2a;
        }

        .user-dropdown-menu a i {
            margin-right: 12px;
            font-size: 16px;
        }

        .dropdown-divider {
            height: 1px;
            background-color: #333333;
            margin: 4px 0;
        }

        /* Стиль для кнопки користувача */
        .user-dropdown-btn {
            display: flex;
            align-items: center;
            background-color: #1a1a1a; /* Темний фон як на зображенні */
            border: none;
            border-radius: 8px;
            padding: 8px 12px;
            color: white;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .user-dropdown-btn:hover {
            background-color: #2a2a2a;
        }

        .user-name {
            font-size: 14px;
            font-weight: 500;
            margin-right: 8px;
        }

        /* Дополнительные стили для уведомлений */
        .notification-item {
            padding: 12px 16px;
            border-bottom: 1px solid rgba(0,0,0,0.1);
            display: flex;
            align-items: flex-start;
            text-decoration: none;
            color: var(--text-primary);
            transition: background-color 0.2s;
            position: relative;
        }

        .notification-item:hover {
            background-color: rgba(0,0,0,0.05);
        }

        .notification-item.unread {
            background-color: rgba(0, 123, 255, 0.05);
        }

        .notification-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background-color: rgba(0, 123, 255, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
            flex-shrink: 0;
        }

        .notification-content {
            flex-grow: 1;
        }

        .notification-content h4 {
            margin: 0 0 5px;
            font-size: 14px;
            font-weight: 600;
        }

        .notification-content p {
            margin: 0 0 5px;
            font-size: 13px;
            line-height: 1.4;
            color: var(--text-secondary);
        }

        .notification-time {
            font-size: 12px;
            color: var(--text-tertiary);
        }

        .notification-dropdown-menu {
            max-height: 400px;
            overflow-y: auto;
        }

        /* Стиль для фото профілю у вітальній картці */
        .welcome-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background-color: #0d6efd;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            font-weight: 600;
            color: white;
            margin-right: 1rem;
            overflow: hidden; /* Щоб фото не виходило за межі кола */
        }

        .welcome-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover; /* Зберігає пропорції фото */
        }

        /* Адаптивність для мобільних пристроїв */
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
    </style>
</head>
<body>
<div class="wrapper" id="mainWrapper">
    <!-- Сайдбар (ліва панель) з orders.php -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <a href="/dah/dashboard.php" class="logo">Lagodi Service</a>
            <button id="sidebarToggle" class="toggle-btn">
                <i class="bi bi-arrow-left"></i>
            </button>
        </div>

        <!-- Новий стилізований компонент користувача -->
        <div class="user-profile-widget">
            <div class="user-avatar">
                <?php if(isset($user['profile_pic']) && !empty($user['profile_pic'])): ?>
                    <img src="<?php echo htmlspecialchars($user['profile_pic']); ?>" alt="Фото профілю">
                <?php else: ?>
                    <div class="user-avatar-placeholder">
                        <?php echo strtoupper(substr($user['username'] ?? '', 0, 1)); ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="user-name">
                <?php echo htmlspecialchars($user['username']); ?>
            </div>
        </div>

        <nav class="sidebar-nav">
            <a href="/dah/dashboard.php" class="nav-link active">
                <i class="bi bi-speedometer2"></i>
                <span>Мій кабінет</span>
            </a>
            <a href="/dah/user/create-order.php" class="nav-link">
                <i class="bi bi-plus-circle"></i>
                <span>Нове замовлення</span>
            </a>
            <a href="/dah/user/orders.php" class="nav-link">
                <i class="bi bi-list-check"></i>
                <span>Мої замовлення</span>

            </a>
            <a href="/dah/user/notifications.php" class="nav-link">
                <i class="bi bi-bell"></i>
                <span>Коментарі</span>
                <?php if ($unread_count > 0): ?>
                    <span class="badge"><?php echo $unread_count; ?></span>
                <?php endif; ?>
            </a>
            <a href="/dah/user/profile.php" class="nav-link">
                <i class="bi bi-person"></i>
                <span>Профіль</span>
            </a>
            <a href="/dah/user/settings.php" class="nav-link">
                <i class="bi bi-gear"></i>
                <span>Налаштування</span>
            </a>
            <a href="/logout.php" class="nav-link logout">
                <i class="bi bi-box-arrow-right"></i>
                <span>Вихід</span>
            </a>
        </nav>
    </aside>

    <!-- Основний контент -->
    <main class="main-content" id="mainContent">
        <header class="main-header">
            <button id="menuToggle" class="menu-toggle">
                <i class="bi bi-list"></i>
            </button>
            <div class="header-title">
                <h1>Мій кабінет</h1>
            </div>
            <div class="header-actions">
                <button id="themeSwitchBtn" class="theme-switch-btn">
                    <i class="bi <?php echo $currentTheme === 'dark' ? 'bi-sun' : 'bi-moon'; ?>"></i>
                </button>

                <!-- Випадаюче меню для сповіщень -->
                <div class="notification-dropdown">
                    <button class="notification-btn">
                        <i class="bi bi-bell"></i>
                        <?php if ($unread_count > 0): ?>
                            <span class="badge"><?php echo $unread_count; ?></span>
                            <span class="notification-indicator"></span>
                        <?php endif; ?>
                    </button>
                    <div class="notification-dropdown-menu">
                        <div class="notification-header">
                            <h3>Коментарі</h3>
                            <button class="mark-all-read">
                                <span class="mark-all-read-text">Позначити все як прочитане</span>
                                <span class="ajax-loader" style="display: none;"></span>
                            </button>
                        </div>
                        <div class="notification-body">
                            <?php if (empty($notifications)): ?>
                                <div class="notification-empty">
                                    <p>У вас немає коментарів</p>
                                </div>
                            <?php else: ?>
                                <?php
                                foreach ($notifications as $notification):
                                    // Якщо коментар порожній - пропускаємо
                                    if (empty($notification)) continue;

                                    $isRead = !empty($notification['is_read']);
                                    $iconClass = 'bi-chat-dots'; // Default для коментарів

                                    // Визначаємо URL для перенаправлення
                                    $redirectUrl = "/dah/user/orders.php";
                                    if (isset($notification['order_id']) && !empty($notification['order_id'])) {
                                        $redirectUrl = "/dah/user/orders.php?id={$notification['order_id']}";
                                    }

                                    // Визначаємо заголовок - назва послуги із замовлення
                                    $title = isset($notification['service']) && !empty($notification['service'])
                                        ? htmlspecialchars($notification['service'])
                                        : 'Замовлення #' . $notification['order_id'];

                                    // Визначаємо вміст коментаря
                                    $content = isset($notification['content']) && !empty($notification['content'])
                                        ? htmlspecialchars($notification['content'])
                                        : '';

                                    // Якщо є прикріплений файл
                                    $hasAttachment = isset($notification['file_attachment']) && !empty($notification['file_attachment']);
                                    ?>
                                    <a href="#"
                                       class="notification-item <?= $isRead ? '' : 'unread' ?> type-comment"
                                       data-notification-id="<?= $notification['id'] ?>"
                                       data-redirect="<?= $redirectUrl ?>">
                                        <div class="notification-icon">
                                            <i class="bi <?= $iconClass ?> <?= $isRead ? '' : 'new' ?>"></i>
                                        </div>
                                        <div class="notification-content">
                                            <h4><?= $title ?></h4>
                                            <p><?= $content ?></p>
                                            <?php if ($hasAttachment): ?>
                                                <p><i class="bi bi-paperclip"></i> Файл</p>
                                            <?php endif; ?>
                                            <span class="notification-time"><?= isset($notification['created_at']) ? formatTimeAgo($notification['created_at']) : 'недавно' ?></span>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="notification-footer">
                            <a href="/dah/user/notifications.php" class="view-all">Переглянути всі коментарі</a>
                        </div>
                    </div>
                </div>

                <!-- Випадаюче меню користувача в стилі як на зображенні -->
                <div class="user-dropdown">
                    <button class="user-dropdown-btn">
                        <?php if(isset($user['profile_pic']) && !empty($user['profile_pic'])): ?>
                            <img src="<?php echo htmlspecialchars($user['profile_pic']); ?>" alt="Фото профілю" class="user-avatar-small">
                        <?php else: ?>
                            <div class="user-avatar-small">
                                <?php echo strtoupper(substr($user['username'], 0, 1)); ?>
                            </div>
                        <?php endif; ?>
                        <span class="user-name"><?php echo htmlspecialchars($user['username']); ?></span>
                        <i class="bi bi-chevron-down"></i>
                    </button>
                    <div class="user-dropdown-menu">
                        <a href="/dah/user/profile.php">
                            <i class="bi bi-person"></i> Профіль
                        </a>
                        <a href="/dah/user/settings.php">
                            <i class="bi bi-gear"></i> Налаштування
                        </a>
                        <div class="dropdown-divider"></div>
                        <a href="/logout.php">
                            <i class="bi bi-box-arrow-right"></i> Вихід
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <!-- Сповіщення із orders.php -->
        <div class="notification notification-success" id="successNotification">
            <i class="bi bi-check-circle"></i>
            <span id="successMessage">Операцію успішно виконано</span>
        </div>

        <div class="notification notification-error" id="errorNotification">
            <i class="bi bi-exclamation-triangle"></i>
            <span id="errorMessage">Сталася помилка</span>
        </div>

        <div class="notification notification-info" id="infoNotification">
            <i class="bi bi-info-circle"></i>
            <span id="infoMessage">Зачекайте, будь ласка...</span>
        </div>

        <div class="content-wrapper">
            <!-- Оновлений блок привітання -->
            <div class="welcome-card">
                <div class="welcome-info">
                    <div class="welcome-avatar">
                        <?php if(isset($user['profile_pic']) && !empty($user['profile_pic'])): ?>
                            <img src="<?php echo htmlspecialchars($user['profile_pic']); ?>" alt="Фото профілю">
                        <?php else: ?>
                            <?php echo strtoupper(substr($user['username'], 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    <div class="welcome-text">
                        <h2>Вітаємо, <?php echo htmlspecialchars(isset($user['first_name']) && !empty($user['first_name']) ? $user['first_name'] . ' ' . $user['last_name'] : $user['username']); ?>!</h2>
                        <p>Ласкаво просимо до особистого кабінету Lagodi Service. Тут ви можете керувати своїми замовленнями, переглядати їх статус та створювати нові.</p>
                    </div>
                </div>
            </div>

            <!-- Статистика замовлень в стилі orders.php -->
            <div class="orders-stats">
                <div class="stat-item">
                    <div class="stat-icon stat-icon-new">
                        <i class="bi bi-hourglass"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $stats['new']; ?></div>
                        <div class="stat-label">Нові</div>
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
                    <div class="stat-icon stat-icon-waiting-delivery">
                        <i class="bi bi-truck"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $stats['waiting']; ?></div>
                        <div class="stat-label">Очікується поставки</div>
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

            <!-- Останні замовлення з оновленим дизайном -->
            <div class="section-container">
                <div class="section-header">
                    <h2 class="section-title">Останні замовлення</h2>
                    <a href="/dah/user/orders.php" class="view-all-link">Усі замовлення</a>
                </div>

                <?php if (empty($recent_orders)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">
                            <i class="bi bi-clipboard-x"></i>
                        </div>
                        <h3>У вас поки немає замовлень</h3>
                        <p>Створіть нове замовлення, щоб почати користуватися сервісом</p>
                        <a href="/dah/user/create-order.php" class="btn-primary">Створити замовлення</a>
                    </div>
                <?php else: ?>
                    <div class="orders-table-container">
                        <table class="orders-table">
                            <thead>
                            <tr>
                                <th>№</th>
                                <th>Послуга</th>
                                <th>Статус</th>
                                <th>Дата</th>
                                <th>Дії</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($recent_orders as $order):
                                // Отримуємо клас та іконку для статусу
                                $statusClass = getStatusClass($order['status']);
                                $statusIcon = getStatusIcon($order['status']);
                                ?>
                                <tr>
                                    <td><?php echo $order['id']; ?></td>
                                    <td>
                                        <div class="service-name-cell">
                                            <?php echo htmlspecialchars($order['service']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo $statusClass; ?>">
                                            <i class="bi <?php echo $statusIcon; ?>"></i>
                                            <?php echo htmlspecialchars($order['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('d.m.Y H:i', strtotime($order['created_at'])); ?></td>
                                    <td>
                                        <a href="/dah/user/orders.php?id=<?php echo $order['id']; ?>" class="action-btn">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Мобільна версія таблиці -->
                    <div class="mobile-orders-list">
                        <?php foreach ($recent_orders as $order):
                            $statusClass = getStatusClass($order['status']);
                            $statusIcon = getStatusIcon($order['status']);
                            ?>
                            <div class="mobile-order-card">
                                <div class="mobile-order-header">
                                    <div class="mobile-order-id">#<?php echo $order['id']; ?></div>
                                    <span class="status-badge <?php echo $statusClass; ?>">
                                            <i class="bi <?php echo $statusIcon; ?>"></i>
                                            <?php echo htmlspecialchars($order['status']); ?>
                                        </span>
                                </div>
                                <div class="mobile-order-content">
                                    <div class="mobile-order-service">
                                        <strong>Послуга:</strong> <?php echo htmlspecialchars($order['service']); ?>
                                    </div>
                                    <div class="mobile-order-date">
                                        <strong>Дата:</strong> <?php echo date('d.m.Y H:i', strtotime($order['created_at'])); ?>
                                    </div>
                                </div>
                                <div class="mobile-order-footer">
                                    <a href="/dah/user/orders.php?id=<?php echo $order['id']; ?>" class="btn-primary btn-sm">
                                        <i class="bi bi-eye"></i> Детальніше
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Наші послуги з оновленим дизайном -->
            <div class="section-container">
                <div class="section-header">
                    <h2 class="section-title">Наші послуги</h2>
                </div>
                <div class="services-grid">
                    <?php foreach ($service_categories as $category): ?>
                        <div class="service-card">
                            <h3 class="service-title"><?php echo htmlspecialchars($category['name']); ?></h3>
                            <?php if (!empty($category['description'])): ?>
                                <p class="service-description"><?php echo htmlspecialchars($category['description']); ?></p>
                            <?php endif; ?>

                            <ul class="services-list">
                                <?php
                                $found = false;
                                foreach ($services as $service):
                                    if ($service['category'] == $category['name']):
                                        $found = true;
                                        ?>
                                        <li>
                                            <span class="service-name"><?php echo htmlspecialchars($service['name']); ?></span>
                                            <?php if (!empty($service['price_range'])): ?>
                                                <span class="price-badge"><?php echo htmlspecialchars($service['price_range']); ?></span>
                                            <?php endif; ?>
                                        </li>
                                    <?php
                                    endif;
                                endforeach;

                                if (!$found):
                                    ?>
                                    <li>Послуги цієї категорії поки недоступні</li>
                                <?php endif; ?>
                            </ul>
                            <a href="/dah/user/create-order.php?category=<?php echo urlencode($category['name']); ?>" class="btn-primary">Замовити</a>
                        </div>
                    <?php endforeach; ?>
                </div>
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

        // Функція для перемикання теми з плавним переходом
        function toggleTheme() {
            const htmlElement = document.documentElement;
            const currentTheme = htmlElement.getAttribute('data-theme');
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';

            // Додавання класу для плавного переходу
            htmlElement.classList.add('color-theme-in-transition');

            // Зміна теми
            htmlElement.setAttribute('data-theme', newTheme);

            // Зберігання вибору в cookie та localStorage
            document.cookie = `theme=${newTheme}; path=/; max-age=${60 * 60 * 24 * 30}`; // 30 днів
            localStorage.setItem('theme', newTheme);

            // Оновлення іконки в кнопці
            themeSwitchBtn.innerHTML = newTheme === 'dark'
                ? '<i class="bi bi-sun"></i>'
                : '<i class="bi bi-moon"></i>';

            // Видалення класу після завершення переходу
            window.setTimeout(function() {
                htmlElement.classList.remove('color-theme-in-transition');
            }, 800);
        }

        // Обробник кнопки перемикання теми
        themeSwitchBtn.addEventListener('click', toggleTheme);

        // Перевіряємо, чи є збережений стан сайдбара
        const sidebarCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';

        // Функція для скрывання/відображення сайдбару
        function toggleSidebar(collapse) {
            if (collapse) {
                mainWrapper.classList.add('sidebar-collapsed');
                localStorage.setItem('sidebarCollapsed', 'true');
                sidebarToggle.innerHTML = '<i class="bi bi-arrow-right"></i>';
            } else {
                mainWrapper.classList.remove('sidebar-collapsed');
                localStorage.setItem('sidebarCollapsed', 'false');
                sidebarToggle.innerHTML = '<i class="bi bi-arrow-left"></i>';
            }
        }

        // Встановлюємо початковий стан сайдбару
        toggleSidebar(sidebarCollapsed);

        // Обробник для кнопки згортання сайдбару
        sidebarToggle.addEventListener('click', function() {
            const isCollapsed = mainWrapper.classList.contains('sidebar-collapsed');
            toggleSidebar(!isCollapsed);
        });

        // На мобільних пристроях сайдбар виїжджає
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

        // Випадаюче меню користувача
        const userDropdownBtn = document.querySelector('.user-dropdown-btn');
        const userDropdownMenu = document.querySelector('.user-dropdown-menu');

        userDropdownBtn.addEventListener('click', function(event) {
            event.stopPropagation();
            userDropdownMenu.classList.toggle('show');

            // Закриваємо меню сповіщень, якщо воно відкрите
            const notificationDropdownMenu = document.querySelector('.notification-dropdown-menu');
            if (notificationDropdownMenu) {
                notificationDropdownMenu.classList.remove('show');
            }
        });

        // Випадаюче меню сповіщень
        const notificationBtn = document.querySelector('.notification-btn');
        const notificationDropdownMenu = document.querySelector('.notification-dropdown-menu');

        if (notificationBtn && notificationDropdownMenu) {
            notificationBtn.addEventListener('click', function(event) {
                event.stopPropagation();
                notificationDropdownMenu.classList.toggle('show');

                // Закриваємо меню користувача, якщо воно відкрите
                userDropdownMenu.classList.remove('show');

                // Видаляємо індикатор нових повідомлень після того, як користувач відкрив меню
                const indicator = document.querySelector('.notification-indicator');
                if (indicator) {
                    indicator.style.display = 'none';
                }
            });
        }

        // Функція для показу повідомлень користувачу
        function showNotification(type, message) {
            // Визначаємо, яке сповіщення використовувати
            let notification;
            if (type === 'success') {
                notification = document.getElementById('successNotification');
                document.getElementById('successMessage').textContent = message;
            } else if (type === 'error') {
                notification = document.getElementById('errorNotification');
                document.getElementById('errorMessage').textContent = message;
            } else if (type === 'info') {
                notification = document.getElementById('infoNotification');
                document.getElementById('infoMessage').textContent = message;
            }

            if (notification) {
                notification.classList.add('show');

                // Автоматично ховаємо через 5 секунд
                setTimeout(() => {
                    notification.classList.remove('show');
                }, 5000);
            }
        }

        // Функція для оновлення інтерфейсу після позначення всіх коментарів як прочитаних
        function updateUIAfterMarkingAllRead() {
            // Оновлюємо класи для непрочитаних коментарів
            document.querySelectorAll('.notification-item.unread').forEach(item => {
                item.classList.remove('unread');

                const icon = item.querySelector('.notification-icon i');
                if (icon && icon.classList.contains('new')) {
                    icon.classList.remove('new');
                }
            });

            // Приховуємо індикатори і бейджі
            const notificationBadge = document.querySelector('.notification-btn .badge');
            const notificationIndicator = document.querySelector('.notification-indicator');
            const sidebarBadge = document.querySelector('.sidebar-nav .nav-link i.bi-bell + span + .badge');

            if (notificationBadge) notificationBadge.style.display = 'none';
            if (notificationIndicator) notificationIndicator.style.display = 'none';
            if (sidebarBadge) sidebarBadge.style.display = 'none';
        }

        // Обробник для всіх посилань коментарів у випадаючому меню
        const notificationItems = document.querySelectorAll('.notification-item');
        if (notificationItems.length > 0) {
            notificationItems.forEach(item => {
                item.addEventListener('click', function(e) {
                    e.preventDefault();
                    const notificationId = this.dataset.notificationId;
                    const redirectUrl = this.dataset.redirect;

                    // Показуємо лоадер
                    const icon = this.querySelector('.notification-icon i');
                    if (icon) {
                        icon.style.display = 'none';

                        // Додаємо лоадер
                        const loader = document.createElement('span');
                        loader.className = 'ajax-loader';
                        this.querySelector('.notification-icon').appendChild(loader);
                    }

                    // Якщо коментар непрочитаний, позначаємо його як прочитаний
                    if (this.classList.contains('unread')) {
                        // Формуємо дані запиту
                        const formData = new FormData();
                        formData.append('comment_id', notificationId);

                        // Додаємо випадкове число для уникнення кешування
                        const randomParam = Math.floor(Math.random() * 1000000);

                        // Виконуємо запит
                        fetch('/dah/api/mark_notification_read.php?r=' + randomParam, {
                            method: 'POST',
                            body: formData,
                            credentials: 'same-origin'
                        })
                            .then(response => {
                                // Оновлюємо UI незалежно від результату
                                this.classList.remove('unread');
                                updateNotificationCounters();

                                // Повертаємо повний JSON і обробляємо його
                                return response.json().catch(e => {
                                    console.warn('Error parsing JSON:', e);
                                    return { success: false, error: 'Invalid JSON response' };
                                });
                            })
                            .then(data => {
                                if (data && data.success) {
                                    console.log('Comment marked as read successfully');
                                } else {
                                    console.warn('Failed to mark comment as read:', data ? data.error : 'Unknown error');
                                }

                                // Перенаправляємо на потрібну сторінку
                                window.location.href = redirectUrl;
                            })
                            .catch(error => {
                                console.error('Error in request:', error);
                                // Перенаправляємо навіть при помилці
                                window.location.href = redirectUrl;
                            });
                    } else {
                        // Якщо коментар вже прочитаний, просто перенаправляємо
                        window.location.href = redirectUrl;
                    }
                });
            });
        }

        // Обробник кнопки "Позначити все як прочитане" - скопійований з notifications.php
        const markAllReadBtn = document.querySelector('.mark-all-read');
        if (markAllReadBtn) {
            const markAllReadText = markAllReadBtn.querySelector('.mark-all-read-text');
            const markAllReadLoader = markAllReadBtn.querySelector('.ajax-loader');

            markAllReadBtn.addEventListener('click', function(event) {
                event.preventDefault();

                // Показуємо інформаційне повідомлення
                showNotification('info', 'Оновлюємо статус коментарів...');

                // Показуємо завантажувач
                if (markAllReadText) markAllReadText.style.display = 'none';
                if (markAllReadLoader) markAllReadLoader.style.display = 'inline-block';

                // В notifications.php використовується проста форма
                const formData = new FormData();
                formData.append('mark_all', 'true');
                formData.append('user_id', '<?php echo $user['id']; ?>');

                // Додаємо випадкове число для уникнення кешування
                const randomParam = Math.floor(Math.random() * 1000000);

                // Виконуємо запит
                fetch('/dah/api/mark_notification_read.php?r=' + randomParam, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                    .then(response => {
                        return response.json().catch(e => {
                            console.warn('Error parsing JSON:', e);
                            return { success: false, error: 'Invalid JSON response' };
                        });
                    })
                    .then(data => {
                        console.log('Response:', data);

                        if (data && data.success) {
                            // Оновлюємо UI
                            document.querySelectorAll('.notification-item.unread').forEach(item => {
                                item.classList.remove('unread');
                            });

                            // Приховуємо бейджі
                            const badge = document.querySelector('.notification-btn .badge');
                            const indicator = document.querySelector('.notification-indicator');
                            const sidebarBadge = document.querySelector('.sidebar-nav .nav-link i.bi-bell + span + .badge');

                            if (badge) badge.style.display = 'none';
                            if (indicator) indicator.style.display = 'none';
                            if (sidebarBadge) sidebarBadge.style.display = 'none';

                            showNotification('success', 'Всі коментарі позначено як прочитані');

                            // Закриваємо меню сповіщень
                            if (notificationDropdownMenu) {
                                notificationDropdownMenu.classList.remove('show');
                            }

                            // Перезавантажуємо сторінку після 1.5 секунди
                            setTimeout(() => {
                                window.location.reload();
                            }, 1500);
                        } else {
                            showNotification('error', data && data.error ? data.error : 'Помилка при оновленні коментарів');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showNotification('error', 'Помилка з\'єднання: ' + error.message);
                    })
                    .finally(() => {
                        // Повертаємо кнопку в початковий стан
                        if (markAllReadText) markAllReadText.style.display = 'inline';
                        if (markAllReadLoader) markAllReadLoader.style.display = 'none';
                    });
            });
        }

        // Функція для оновлення лічильників сповіщень
        function updateNotificationCounters() {
            // Оновлюємо лічильник в кнопці сповіщень
            const badgeElement = document.querySelector('.notification-btn .badge');
            if (badgeElement) {
                const currentCount = parseInt(badgeElement.textContent);
                if (currentCount > 1) {
                    badgeElement.textContent = currentCount - 1;
                } else {
                    badgeElement.style.display = 'none';
                    // Також ховаємо індикатор
                    const indicator = document.querySelector('.notification-indicator');
                    if (indicator) {
                        indicator.style.display = 'none';
                    }
                }
            }

            // Оновлюємо лічильник у сайдбарі
            const sidebarBadge = document.querySelector('.sidebar-nav .nav-link i.bi-bell + span + .badge');
            if (sidebarBadge) {
                const currentCount = parseInt(sidebarBadge.textContent);
                if (currentCount > 1) {
                    sidebarBadge.textContent = currentCount - 1;
                } else {
                    sidebarBadge.style.display = 'none';
                }
            }
        }

        // Клік поза меню закриває їх
        document.addEventListener('click', function(event) {
            if (userDropdownBtn && userDropdownMenu &&
                !userDropdownBtn.contains(event.target) &&
                !userDropdownMenu.contains(event.target)) {
                userDropdownMenu.classList.remove('show');
            }

            if (notificationBtn && notificationDropdownMenu &&
                !notificationBtn.contains(event.target) &&
                !notificationDropdownMenu.contains(event.target)) {
                notificationDropdownMenu.classList.remove('show');
            }
        });

        // Додаємо обробник для зміни розміру вікна
        window.addEventListener('resize', function() {
            if (window.innerWidth >= 992) {
                if (sidebar) {
                    sidebar.classList.remove('mobile-active');
                }
                if (overlay) {
                    overlay.classList.remove('active');
                }
                document.body.classList.remove('no-scroll');
            }
        });

        // Застосовуємо тему до елементів сайдбару
        const currentTheme = document.documentElement.getAttribute('data-theme');
        if (currentTheme) {
            const userProfileWidget = document.querySelector('.user-profile-widget');
            if (userProfileWidget) {
                userProfileWidget.style.backgroundColor = currentTheme === 'light' ? '#e9ecef' : '#232323';

                const userName = userProfileWidget.querySelector('.user-name');
                if (userName) {
                    userName.style.color = currentTheme === 'light' ? '#212529' : 'white';
                }
            }

            // Застосовуємо тему до випадаючого меню користувача
            const userDropdownMenuElem = document.querySelector('.user-dropdown-menu');
            if (userDropdownMenuElem) {
                if (currentTheme === 'light') {
                    userDropdownMenuElem.style.backgroundColor = '#ffffff';

                    const links = userDropdownMenuElem.querySelectorAll('a');
                    links.forEach(link => {
                        link.style.color = '#333333';
                    });

                    const dividers = userDropdownMenuElem.querySelectorAll('.dropdown-divider');
                    dividers.forEach(divider => {
                        divider.style.backgroundColor = '#e9ecef';
                    });
                }
            }
        }

        // Додаємо системну інформацію в консоль для розробників
        console.info("Система запущена: 2025-05-23 13:32:38");
        console.info("Поточний користувач: 1GodofErath");
    });
</script>
</body>
</html>