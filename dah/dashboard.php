<?php
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../vendor/autoload.php'; // Для PHPMailer та Firebase SDK

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\WebPushConfig;

// Перевірка авторизації
if (!isset($_SESSION['user_id'])) {
    header("Location: /../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

// Перевірка на неактивність користувача (автоматичне завершення сесії)
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 300)) { // 5 хвилин неактивності (300 секунд)
    session_unset();     // Видаляємо всі змінні сесії
    session_destroy();   // Знищуємо сесію
    header("Location: /login.php?message=timeout");
    exit();
}
$_SESSION['last_activity'] = time(); // Оновлюємо час останньої активності

// Перевірка блокування користувача
$block_data = checkUserBlock($conn, $user_id);
$block_message = "";
if ($block_data && $block_data['blocked_until'] && strtotime($block_data['blocked_until']) > time()) {
    $block_reason = $block_data['block_reason'] ?? 'Не вказано причину';
    $block_message = "Вітаємо, {$username}, ви заблоковані з такої причини: {$block_reason}";
    $_SESSION['blocked_until'] = $block_data['blocked_until'];
}

// Додаткова інформація користувача
$user_data = getUserData($conn, $user_id);

// Отримання налаштувань сповіщень користувача
$notification_preferences = json_decode($user_data['notification_preferences'] ?? '{}', true);
$email_notifications_enabled = $notification_preferences['email'] ?? true; // За замовчуванням включено
$push_notifications_enabled = $notification_preferences['push'] ?? true; // За замовчуванням включено

// Обробка повідомлень з сесії
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// Генерація CSRF токена
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Обробка всіх POST запитів
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Перевіряємо, чи користувач не заблокований
    if (!$block_message) {
        handlePostRequests($conn, $user_id, $username, $user_data, $email_notifications_enabled, $push_notifications_enabled);
    }
}

// Обробка AJAX запитів
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    handleAjaxRequests($conn, $user_id);
}

// Отримання замовлень з коментарями та файлами
$filter_status = $_GET['status'] ?? '';
$filter_service = $_GET['service'] ?? '';
$search_query = $_GET['search'] ?? '';
$orders = getOrders($conn, $user_id, $filter_status, $filter_service, $search_query);

// Отримуємо унікальні статуси та послуги для фільтрів
$statuses = getUniqueOrderStatuses($conn, $user_id);
$services = getUniqueOrderServices($conn, $user_id);

// Обчислення кількості активних замовлень
$active_orders_count = countActiveOrders($conn, $user_id);

// Отримуємо непрочитані сповіщення для відображення в панелі сповіщень
$unread_notifications = getUnreadNotifications($conn, $user_id, 20);

// Перевіряємо сповіщення для кожного замовлення користувача
foreach ($orders as &$order) {
    $notifications = processNotifications($conn, $user_id, $order['id']);
    $order['notifications'] = $notifications;
    $order['unread_count'] = $notifications['unread_count'];
}

// Підрахунок загальної кількості непрочитаних сповіщень
$total_notifications = countTotalUnreadNotifications($conn, $user_id);
?>

<!DOCTYPE html>
<html lang="uk" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="user-id" content="<?= $user_id ?>">
    <title>Особистий кабінет - <?= htmlspecialchars($username) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="/../style/dash2.css">
</head>
<body data-user-id="<?= $user_id ?>">
<!-- Сайдбар -->
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="logo">
            <span class="logo-text">Сервісний центр</span>
        </div>
        <button class="toggle-sidebar" id="toggle-sidebar">
            <i class="fas fa-bars"></i>
        </button>
    </div>

    <div class="user-info">
        <div class="user-avatar">
            <?php if (!empty($user_data['profile_pic']) && file_exists($user_data['profile_pic'])): ?>
                <img src="<?= htmlspecialchars($user_data['profile_pic']) ?>" alt="Фото профілю">
            <?php else: ?>
                <img src="assets/images/default_avatar.png" alt="Фото профілю за замовчуванням">
            <?php endif; ?>
        </div>
        <div class="user-name"><?= htmlspecialchars($username) ?></div>
    </div>

    <ul class="sidebar-menu">
        <li>
            <a href="#dashboard" class="active" data-tab="dashboard">
                <i class="fas fa-home icon"></i>
                <span class="menu-text">Головна</span>
            </a>
        </li>
        <li>
            <a href="#orders" data-tab="orders">
                <i class="fas fa-list-alt icon"></i>
                <span class="menu-text">Мої замовлення</span>
                <?php if ($total_notifications > 0): ?>
                    <span class="notification-badge"><?= $total_notifications > 9 ? '9+' : $total_notifications ?></span>
                <?php endif; ?>
            </a>
        </li>
        <li>
            <a href="#notifications" data-tab="notifications">
                <i class="fas fa-bell icon"></i>
                <span class="menu-text">Сповіщення</span>
                <?php if ($total_notifications > 0): ?>
                    <span class="notification-badge"><?= $total_notifications > 9 ? '9+' : $total_notifications ?></span>
                <?php endif; ?>
            </a>
        </li>
        <li>
            <a href="#new-order" data-tab="new-order">
                <i class="fas fa-plus icon"></i>
                <span class="menu-text">Створити замовлення</span>
            </a>
        </li>
        <li>
            <a href="#settings" data-tab="settings">
                <i class="fas fa-cog icon"></i>
                <span class="menu-text">Налаштування</span>
            </a>
        </li>
        <li>
            <a href="/../logout.php">
                <i class="fas fa-sign-out-alt icon"></i>
                <span class="menu-text">Вийти</span>
            </a>
        </li>
    </ul>
</div>

<!-- Основний контент -->
<div class="main-content">
    <div class="header">
        <h1>Особистий кабінет</h1>
        <div class="header-actions">
            <!-- Індикатор веб-сокет підключення -->
            <div class="websocket-status" id="websocket-status">
                <i class="fas fa-circle" style="font-size: 8px; margin-right: 5px;"></i>
                <span id="websocket-status-text">Підключено</span>
            </div>

            <!-- Іконка сповіщень -->
            <div class="notifications-icon" id="notifications-toggle">
                <i class="fas fa-bell"></i>
                <?php if ($total_notifications > 0): ?>
                    <span class="notifications-count"><?= $total_notifications > 9 ? '9+' : $total_notifications ?></span>
                <?php endif; ?>
            </div>

            <div class="current-time" id="current-time">
                <?= date('d.m.Y H:i') ?>
            </div>
            <div class="theme-selector">
                <div class="theme-option theme-option-light active" data-theme="light" title="Світла тема"></div>
                <div class="theme-option theme-option-dark" data-theme="dark" title="Темна тема"></div>
                <div class="theme-option theme-option-blue" data-theme="blue" title="Синя тема"></div>
            </div>
        </div>
    </div>

    <!-- Виспливаюче вікно сповіщень -->
    <div class="notifications-container" id="notifications-container">
        <div class="notifications-header">
            <span>Сповіщення</span>
            <?php if ($total_notifications > 0): ?>
                <button class="mark-all-read" id="mark-all-read">Позначити всі як прочитані</button>
            <?php endif; ?>
        </div>
        <div class="notifications-content">
            <?php if (!empty($unread_notifications)): ?>
                <?php foreach ($unread_notifications as $notification): ?>
                    <div class="notification-item unread" data-order-id="<?= $notification['order_id'] ?>" data-notification-id="<?= $notification['id'] ?>">
                        <div class="notification-title"><?= htmlspecialchars($notification['title']) ?></div>
                        <div class="notification-message"><?= htmlspecialchars($notification['description']) ?></div>
                        <div class="notification-service"><?= htmlspecialchars($notification['service']) ?></div>
                        <div class="notification-time">
                            <?= date('d.m.Y H:i', strtotime($notification['created_at'])) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-notifications">
                    <i class="fas fa-check-circle" style="font-size: 2rem; color: var(--success-color); margin-bottom: 10px;"></i>
                    <p>Немає нових сповіщень</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($block_message): ?>
        <div class="alert alert-block">
            <?= htmlspecialchars($block_message) ?>
            <p>Ваш обліковий запис буде розблоковано: <?= date('d.m.Y H:i', strtotime($_SESSION['blocked_until'])) ?></p>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <!-- Запит на дозвіл push-сповіщень -->
    <div id="push-permission-prompt">
        <h3>Отримуйте миттєві сповіщення</h3>
        <p>Дозвольте нам надсилати сповіщення про статус ваших замовлень та нові коментарі від адміністраторів</p>
        <div class="push-permission-buttons">
            <button id="allow-notifications" class="btn">Дозволити сповіщення</button>
            <button id="deny-notifications" class="btn-text">Не зараз</button>
        </div>
    </div>

    <!-- Контент вкладок -->
    <?php include 'templates/dashboard_tab.php'; ?>
    <?php include 'templates/orders_tab.php'; ?>
    <?php include 'templates/notifications_tab.php'; ?>
    <?php include 'templates/new_order_tab.php'; ?>
    <?php include 'templates/settings_tab.php'; ?>

    <!-- Мобільні елементи інтерфейсу -->
    <button class="mobile-toggle-sidebar" id="mobile-toggle-sidebar">
        <i class="fas fa-bars"></i>
    </button>

    <button class="mobile-notifications-btn" id="mobile-notifications-btn">
        <i class="fas fa-bell"></i>
        <?php if ($total_notifications > 0): ?>
            <span class="notifications-count"><?= $total_notifications > 9 ? '9+' : $total_notifications ?></span>
        <?php endif; ?>
    </button>
</div>

<!-- Модальні вікна -->
<?php include 'templates/modals.php'; ?>

<!-- Скрипти -->
<script type="text/javascript" src="https://www.gstatic.com/firebasejs/8.10.1/firebase-app.js"></script>
<script type="text/javascript" src="https://www.gstatic.com/firebasejs/8.10.1/firebase-messaging.js"></script>
<script src="/../jawa/dash2.js"></script>
</body>
</html>