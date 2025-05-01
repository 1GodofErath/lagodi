<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
// Підключення необхідних файлів
require_once '../dah/confi/database.php';
require_once '../dah/include/functions.php';
require_once '../dah/include/auth.php';
require_once '../dah/include/session.php';

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

$currentTheme = isset($_COOKIE['theme']) ? $_COOKIE['theme'] : 'light';

// Отримання статистики по замовленням користувача
$database = new Database();
$db = $database->getConnection();

$query = "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'Новий' THEN 1 ELSE 0 END) as new,
            SUM(CASE WHEN status = 'В роботі' THEN 1 ELSE 0 END) as in_progress,
            SUM(CASE WHEN status = 'Очікується поставки товару' THEN 1 ELSE 0 END) as waiting,
            SUM(CASE WHEN status = 'Виконано' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status = 'Неможливо виконати' THEN 1 ELSE 0 END) as canceled
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

// Отримання повідомлень
function getUserNotificationsLocal($userId, $limit = 5) {
    $database = new Database();
    $db = $database->getConnection();

    $query = "SELECT * FROM notifications 
              WHERE user_id = :user_id 
              ORDER BY created_at DESC 
              LIMIT :limit";

    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Функція для підрахунку непрочитаних повідомлень
function getUnreadNotificationsCountLocal($userId) {
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

// Используем локальные функции вместо функций из functions.php
$notifications = getUserNotificationsLocal($user['id'], 5);
$unread_count = getUnreadNotificationsCountLocal($user['id']);

// Отримання доступних сервісів
$services = getServices();
$service_categories = getServiceCategories();

// Встановлюємо заголовок сторінки
$page_title = "Особистий кабінет";

// Поточний час в UTC
$current_time = gmdate("Y-m-d H:i:s");
?>

<!DOCTYPE html>
<html lang="uk" data-theme="<?php echo $currentTheme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title><?php echo $page_title; ?> - Lagodi Service</title>

    <!-- CSS файли -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../style/dahm/user_dashboard.css">
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
            font-size: 0.75rem;
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

        /* Просмотр логов для отладки */
        .debug-log {
            margin: 20px;
            padding: 15px;
            background-color: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-family: monospace;
            white-space: pre-wrap;
            font-size: 12px;
        }
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
                <?php echo strtoupper(substr($user['username'], 0, 1)); ?>
            </div>
            <div class="user-details">
                <h3><?php echo htmlspecialchars($user['username']); ?></h3>
                <p><?php echo htmlspecialchars($user['email']); ?></p>
            </div>
            <a href="../dah/user/profile.php" class="edit-profile-btn">Редагувати профіль</a>
        </div>
        <nav class="sidebar-nav">
            <a href="/dah/dashboard.php" class="nav-link active">
                <i class="bi bi-speedometer2"></i>
                <span>Дашборд</span>
            </a>
            <a href="/dah/user/create-order.php" class="nav-link">
                <i class="bi bi-plus-circle"></i>
                <span>Нове замовлення</span>
            </a>
            <a href="/dah/user/orders.php" class="nav-link">
                <i class="bi bi-list-check"></i>
                <span>Мої замовлення</span>
                <?php if ($stats['total'] > 0): ?>
                    <span class="badge"><?php echo $stats['total']; ?></span>
                <?php endif; ?>
            </a>
            <a href="/dah/user/notifications.php" class="nav-link">
                <i class="bi bi-bell"></i>
                <span>Сповіщення</span>
                <?php if ($unread_count > 0): ?>
                    <span class="badge"><?php echo $unread_count; ?></span>
                <?php endif; ?>
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
                    <a href="?theme=light" class="theme-option <?php echo $currentTheme === 'light' ? 'active' : ''; ?>">
                        <i class="bi bi-sun"></i> Світла
                    </a>
                    <a href="?theme=dark" class="theme-option <?php echo $currentTheme === 'dark' ? 'active' : ''; ?>">
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
                <h1>Дашборд</h1>
            </div>
            <div class="header-actions">
                <button id="themeSwitchBtn" class="theme-switch-btn">
                    <i class="bi <?php echo $currentTheme === 'dark' ? 'bi-sun' : 'bi-moon'; ?>"></i>
                </button>

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
                            <h3>Сповіщення</h3>
                            <button class="mark-all-read">
                                <span class="mark-all-read-text">Позначити все як прочитане</span>
                                <span class="ajax-loader" style="display: none;"></span>
                            </button>
                        </div>
                        <div class="notification-body">
                            <?php if (empty($notifications)): ?>
                                <div class="notification-empty">
                                    <p>У вас немає сповіщень</p>
                                </div>
                            <?php else: ?>
                                <?php
                                // Для отладки: раскомментировать, чтобы увидеть содержимое массива
                                // echo '<div class="debug-log">';
                                // print_r($notifications);
                                // echo '</div>';

                                foreach ($notifications as $notification):
                                    // Если уведомление пустое - пропускаем
                                    if (empty($notification)) continue;

                                    $isRead = !empty($notification['is_read']);
                                    $iconClass = 'bi-envelope' . ($isRead ? '-open' : '');

                                    // Проверяем тип уведомления и устанавливаем соответствующий класс
                                    if (isset($notification['type']) && !empty($notification['type'])) {
                                        switch ($notification['type']) {
                                            case 'status_update':
                                                $iconClass = 'bi-arrow-clockwise';
                                                break;
                                            case 'comment':
                                                $iconClass = 'bi-chat-dots';
                                                break;
                                            case 'waiting_delivery':
                                                $iconClass = 'bi-truck';
                                                break;
                                            case 'system':
                                                $iconClass = 'bi-gear';
                                                break;
                                        }
                                    }

                                    // Определяем URL для перенаправления
                                    $redirectUrl = "/dah/user/notifications.php";
                                    if (isset($notification['order_id']) && !empty($notification['order_id'])) {
                                        $redirectUrl = "/dah/user/orders.php?id={$notification['order_id']}";
                                    }

                                    // Определяем заголовок и содержимое
                                    $title = isset($notification['title']) && !empty($notification['title'])
                                        ? htmlspecialchars($notification['title'])
                                        : 'Уведомление';

                                    $content = '';
                                    if (isset($notification['content']) && !empty($notification['content'])) {
                                        $content = htmlspecialchars($notification['content']);
                                    } elseif (isset($notification['description']) && !empty($notification['description'])) {
                                        $content = htmlspecialchars($notification['description']);
                                    }
                                    ?>
                                    <a href="#"
                                       class="notification-item <?= $isRead ? '' : 'unread' ?> <?= isset($notification['type']) ? 'type-'.$notification['type'] : '' ?>"
                                       data-notification-id="<?= $notification['id'] ?>"
                                       data-redirect="<?= $redirectUrl ?>">
                                        <div class="notification-icon">
                                            <i class="bi <?= $iconClass ?> <?= $isRead ? '' : 'new' ?>"></i>
                                        </div>
                                        <div class="notification-content">
                                            <h4><?= $title ?></h4>
                                            <p><?= $content ?></p>
                                            <span class="notification-time"><?= isset($notification['created_at']) ? formatTimeAgo($notification['created_at']) : 'недавно' ?></span>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="notification-footer">
                            <a href="/dah/user/notifications.php" class="view-all">Переглянути всі сповіщення</a>
                        </div>
                    </div>
                </div>

                <div class="user-dropdown">
                    <button class="user-dropdown-btn">
                        <div class="user-avatar-small">
                            <?php echo strtoupper(substr($user['username'], 0, 1)); ?>
                        </div>
                        <span class="user-name"><?php echo htmlspecialchars($user['username']); ?></span>
                        <i class="bi bi-chevron-down"></i>
                    </button>
                    <div class="user-dropdown-menu">
                        <a href="/dah/user/profile.php"><i class="bi bi-person"></i> Профіль</a>
                        <a href="/user/settings.php"><i class="bi bi-gear"></i> Налаштування</a>
                        <div class="dropdown-divider"></div>
                        <a href="?theme=<?php echo $currentTheme === 'dark' ? 'light' : 'dark'; ?>">
                            <i class="bi <?php echo $currentTheme === 'dark' ? 'bi-sun' : 'bi-moon'; ?>"></i>
                            <?php echo $currentTheme === 'dark' ? 'Світла тема' : 'Темна тема'; ?>
                        </a>
                        <div class="dropdown-divider"></div>
                        <a href="/logout.php"><i class="bi bi-box-arrow-right"></i> Вихід</a>
                    </div>
                </div>
            </div>
        </header>

        <div class="content-wrapper">
            <!-- Блок привітання з новим дизайном -->
            <div class="welcome-card">
                <div class="welcome-info">
                    <div class="welcome-avatar">
                        <?php echo strtoupper(substr($user['username'], 0, 1)); ?>
                    </div>
                    <div class="welcome-text">
                        <h2>Вітаємо, <?php echo htmlspecialchars($user['username']); ?>!</h2>
                        <p>Ласкаво просимо до особистого кабінету Lagodi Service. Тут ви можете керувати своїми замовленнями та отримувати сповіщення про їх статус.</p>
                    </div>
                </div>
            </div>

            <!-- Статистика замовлень з новим дизайном -->
            <div class="stats-container">
                <div class="stats-card blue">
                    <div class="stats-label">Всього замовлень</div>
                    <div class="stats-value"><?php echo $stats['total']; ?></div>
                </div>
                <div class="stats-card teal">
                    <div class="stats-label">Нові</div>
                    <div class="stats-value"><?php echo $stats['new']; ?></div>
                </div>
                <div class="stats-card yellow">
                    <div class="stats-label">В роботі</div>
                    <div class="stats-value"><?php echo $stats['in_progress']; ?></div>
                </div>
                <div class="stats-card orange">
                    <div class="stats-label">Очікується</div>
                    <div class="stats-value"><?php echo $stats['waiting']; ?></div>
                </div>
                <div class="stats-card green">
                    <div class="stats-label">Виконано</div>
                    <div class="stats-value"><?php echo $stats['completed']; ?></div>
                </div>
                <div class="stats-card red">
                    <div class="stats-label">Не виконано</div>
                    <div class="stats-value"><?php echo $stats['canceled']; ?></div>
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
                                // Определение статуса для CSS классов
                                $statusClass = mb_strtolower(str_replace(' ', '-', $order['status']));

                                // Определение иконки для статуса
                                $statusIcon = '';
                                if (strpos(mb_strtolower($order['status']), 'нов') !== false) {
                                    $statusIcon = 'bi-file-earmark-plus';
                                } elseif (strpos(mb_strtolower($order['status']), 'робот') !== false) {
                                    $statusIcon = 'bi-tools';
                                } elseif (strpos(mb_strtolower($order['status']), 'викон') !== false ||
                                    strpos(mb_strtolower($order['status']), 'заверш') !== false) {
                                    $statusIcon = 'bi-check-circle';
                                } elseif (strpos(mb_strtolower($order['status']), 'неможлив') !== false) {
                                    $statusIcon = 'bi-x-circle';
                                } elseif (strpos(mb_strtolower($order['status']), 'очік') !== false ||
                                    strpos(mb_strtolower($order['status']), 'постав') !== false) {
                                    $statusIcon = 'bi-truck';
                                } else {
                                    $statusIcon = 'bi-clock-history';
                                }
                                ?>
                                <tr>
                                    <td><?php echo $order['id']; ?></td>
                                    <td>
                                        <div class="service-name-cell">
                                            <?php echo htmlspecialchars($order['service']); ?>
                                        </div>
                                    </td>
                                    <td>
                                                <span class="status-badge status-<?php echo $statusClass; ?>">
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
                            $statusClass = mb_strtolower(str_replace(' ', '-', $order['status']));

                            // Определение иконки для статуса
                            $statusIcon = '';
                            if (strpos(mb_strtolower($order['status']), 'нов') !== false) {
                                $statusIcon = 'bi-file-earmark-plus';
                            } elseif (strpos(mb_strtolower($order['status']), 'робот') !== false) {
                                $statusIcon = 'bi-tools';
                            } elseif (strpos(mb_strtolower($order['status']), 'викон') !== false ||
                                strpos(mb_strtolower($order['status']), 'заверш') !== false) {
                                $statusIcon = 'bi-check-circle';
                            } elseif (strpos(mb_strtolower($order['status']), 'неможлив') !== false) {
                                $statusIcon = 'bi-x-circle';
                            } elseif (strpos(mb_strtolower($order['status']), 'очік') !== false ||
                                strpos(mb_strtolower($order['status']), 'постав') !== false) {
                                $statusIcon = 'bi-truck';
                            } else {
                                $statusIcon = 'bi-clock-history';
                            }
                            ?>
                            <div class="mobile-order-card">
                                <div class="mobile-order-header">
                                    <div class="mobile-order-id">#<?php echo $order['id']; ?></div>
                                    <span class="status-badge status-<?php echo $statusClass; ?>">
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

            // Зберігання вибору в cookie
            document.cookie = `theme=${newTheme}; path=/; max-age=${60 * 60 * 24 * 30}`; // 30 днів

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
            notificationDropdownMenu.classList.remove('show');
        });

        // Випадаюче меню сповіщень
        const notificationBtn = document.querySelector('.notification-btn');
        const notificationDropdownMenu = document.querySelector('.notification-dropdown-menu');

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

        // Обработчик для всех ссылок уведомлений
        document.querySelectorAll('.notification-item').forEach(item => {
            item.addEventListener('click', function(e) {
                e.preventDefault();
                const notificationId = this.dataset.notificationId;
                const redirectUrl = this.dataset.redirect;

                console.log('Clicked notification:', notificationId, 'redirect to:', redirectUrl);

                // Показываем лоадер на кнопке
                const icon = this.querySelector('.notification-icon i');
                if (icon) {
                    icon.style.display = 'none';

                    // Создаем и добавляем лоадер
                    const loader = document.createElement('span');
                    loader.className = 'ajax-loader';
                    this.querySelector('.notification-icon').appendChild(loader);
                }

                // Если уведомление не отмечено как прочитанное
                if (this.classList.contains('unread')) {
                    // Делаем AJAX-запрос для отметки уведомления как прочитанного
                    fetch('/dah/api/mark_notification_read.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            notification_id: notificationId
                        })
                    })
                        .then(response => {
                            if (!response.ok) {
                                throw new Error('Network response was not ok: ' + response.status);
                            }
                            return response.json();
                        })
                        .then(data => {
                            console.log('Mark as read response:', data);

                            // Меняем визуальный статус в интерфейсе
                            this.classList.remove('unread');

                            // Перенаправляем на указанный URL
                            window.location.href = redirectUrl;
                        })
                        .catch(error => {
                            console.error('Error marking notification as read:', error);

                            // В случае ошибки - просто перенаправляем
                            window.location.href = redirectUrl;
                        });
                } else {
                    // Если уже прочитано, просто перенаправляем
                    window.location.href = redirectUrl;
                }
            });
        });

        // Позначити всі як прочитані
        const markAllReadBtn = document.querySelector('.mark-all-read');
        if (markAllReadBtn) {
            const markAllReadText = document.querySelector('.mark-all-read-text');
            const markAllReadLoader = document.querySelector('.mark-all-read .ajax-loader');

            markAllReadBtn.addEventListener('click', function(event) {
                event.preventDefault();
                event.stopPropagation();

                console.log('Marking all as read...');

                // Показываем лоадер
                markAllReadText.style.display = 'none';
                markAllReadLoader.style.display = 'inline-block';

                // Запрос на сервер для отметки всех как прочитанных
                fetch('/dah/api/mark_notification_read.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        mark_all: true
                    })
                })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok: ' + response.status);
                        }
                        return response.json();
                    })
                    .then(data => {
                        console.log('Mark all as read response:', data);

                        if (data.success) {
                            // Обновляем UI
                            const unreadNotifications = document.querySelectorAll('.notification-item.unread');
                            unreadNotifications.forEach(item => {
                                item.classList.remove('unread');

                                const icon = item.querySelector('.notification-icon i');
                                if(icon && icon.classList.contains('new')) {
                                    icon.classList.remove('new');
                                }
                                if(icon && icon.classList.contains('bi-envelope')) {
                                    icon.classList.replace('bi-envelope', 'bi-envelope-open');
                                }
                            });

                            // Скрываем значки уведомлений
                            const notificationBadge = document.querySelector('.notification-btn .badge');
                            const notificationIndicator = document.querySelector('.notification-indicator');

                            if(notificationBadge) {
                                notificationBadge.style.display = 'none';
                            }

                            if(notificationIndicator) {
                                notificationIndicator.style.display = 'none';
                            }
                        }

                        // В любом случае возвращаем кнопку в исходное состояние
                        markAllReadText.style.display = 'inline';
                        markAllReadLoader.style.display = 'none';
                    })
                    .catch(error => {
                        console.error('Error marking all notifications as read:', error);
                        // Возвращаем кнопку в исходное состояние
                        markAllReadText.style.display = 'inline';
                        markAllReadLoader.style.display = 'none';
                    });
            });
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
                sidebar.classList.remove('mobile-active');
                overlay.classList.remove('active');
                document.body.classList.remove('no-scroll');
            }
        });
    });
</script>
</body>
</html>