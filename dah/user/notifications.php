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

// Логування функцій для дебагу
function logDebug($message) {
    $logDir = __DIR__ . '/logs/';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0777, true);
    }
    file_put_contents($logDir . 'notifications_debug.log', date('Y-m-d H:i:s') . ': ' . $message . "\n", FILE_APPEND);
}

// Обробка помилок PDO
function handlePDOException($e, $message = "Database Error") {
    logDebug($message . ': ' . $e->getMessage());
    return false;
}

// Функція для позначення одного коментаря як прочитаного
if (isset($_GET['mark_read']) && is_numeric($_GET['mark_read'])) {
    $commentId = (int)$_GET['mark_read'];

    try {
        // Спочатку перевіряємо, чи коментар існує і належить до замовлення користувача
        $checkQuery = "SELECT c.id FROM comments c
                      JOIN orders o ON c.order_id = o.id
                      WHERE c.id = :comment_id 
                      AND o.user_id = :user_id";

        $checkStmt = $db->prepare($checkQuery);
        $checkStmt->bindParam(':comment_id', $commentId);
        $checkStmt->bindParam(':user_id', $user['id']);
        $checkStmt->execute();

        if ($checkStmt->rowCount() > 0) {
            // Позначаємо коментар як прочитаний
            $query = "UPDATE comments c
                     SET c.is_read = 1
                     WHERE c.id = :comment_id";

            $stmt = $db->prepare($query);
            $stmt->bindParam(':comment_id', $commentId);
            $stmt->execute();

            // Оновлюємо лічильник непрочитаних коментарів для цього замовлення
            $updateOrderQuery = "UPDATE orders o
                                SET o.unread_count = (
                                    SELECT COUNT(*) FROM comments c 
                                    WHERE c.order_id = o.id AND c.is_read = 0
                                    AND ((c.admin_id IS NOT NULL) OR EXISTS (
                                        SELECT 1 FROM users u WHERE u.id = c.user_id AND u.role = 'admin'
                                    ))
                                )
                                WHERE o.id = (
                                    SELECT order_id FROM comments WHERE id = :comment_id
                                )";
            $updateOrderStmt = $db->prepare($updateOrderQuery);
            $updateOrderStmt->bindParam(':comment_id', $commentId);
            $updateOrderStmt->execute();

            // Зберігаємо фільтр і сторінку при поверненні
            $returnUrl = 'notifications.php';
            if (isset($_GET['filter'])) {
                $returnUrl .= '?filter=' . htmlspecialchars($_GET['filter']);
                if (isset($_GET['page'])) {
                    $returnUrl .= '&page=' . (int)$_GET['page'];
                }
            }
            $returnUrl .= (strpos($returnUrl, '?') === false ? '?' : '&') . 'success=marked_read';

            header("Location: $returnUrl");
            exit;
        } else {
            header('Location: notifications.php?error=not_found');
            exit;
        }
    } catch (PDOException $e) {
        handlePDOException($e, "Error marking comment as read");
        header('Location: notifications.php?error=db_error');
        exit;
    }
}

// Функція для позначення всіх коментарів як прочитаних
if (isset($_GET['mark_all_read'])) {
    try {
        // Позначаємо всі коментарі як прочитані
        $query = "UPDATE comments c
                 JOIN orders o ON c.order_id = o.id
                 SET c.is_read = 1
                 WHERE o.user_id = :user_id
                 AND c.is_read = 0";

        $stmt = $db->prepare($query);
        $stmt->bindParam(':user_id', $user['id']);
        $stmt->execute();

        // Оновлюємо лічильники непрочитаних повідомлень
        $updateOrdersQuery = "UPDATE orders 
                             SET unread_count = 0
                             WHERE user_id = :user_id";
        $updateOrdersStmt = $db->prepare($updateOrdersQuery);
        $updateOrdersStmt->bindParam(':user_id', $user['id']);
        $updateOrdersStmt->execute();

        // Зберігаємо фільтр і сторінку при поверненні
        $returnUrl = 'notifications.php';
        if (isset($_GET['filter'])) {
            $returnUrl .= '?filter=' . htmlspecialchars($_GET['filter']);
            if (isset($_GET['page'])) {
                $returnUrl .= '&page=' . (int)$_GET['page'];
            }
        }
        $returnUrl .= (strpos($returnUrl, '?') === false ? '?' : '&') . 'success=all_marked_read';

        header("Location: $returnUrl");
        exit;
    } catch (PDOException $e) {
        handlePDOException($e, "Error marking all comments as read");
        header('Location: notifications.php?error=db_error');
        exit;
    }
}

// Обробка фільтрів та пагінації
$currentFilter = isset($_GET['filter']) ? htmlspecialchars($_GET['filter']) : 'default';
$currentPage = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$itemsPerPage = 10; // Кількість елементів на сторінці
$offset = ($currentPage - 1) * $itemsPerPage;

// Зміна заголовка сторінки в залежності від фільтра
$pageTitle = "Коментарі";
$showAll = false;

switch ($currentFilter) {
    case 'unread':
        $pageTitle = "Непрочитані коментарі";
        $showAll = true;
        break;
    case 'read':
        $pageTitle = "Прочитані коментарі";
        $showAll = true;
        break;
    case 'all':
        $pageTitle = "Всі коментарі";
        $showAll = true;
        break;
    case 'status':
        $pageTitle = "Зміни статусу замовлень";
        $showAll = true;
        break;
    case 'orders_with_comments':
        $pageTitle = "Замовлення з коментарями";
        $showAll = true;
        break;
}

// Ініціалізація змінних для уникнення помилок
$totalComments = 0;
$comments = [];
$recentComments = [];
$unread_count = 0;
$totalPages = 1;
$ordersWithCommentsCount = 0;

// Безпечне отримання даних
try {
    // Отримання замовлень з коментарями
    $ordersWithCommentsQuery = "SELECT COUNT(DISTINCT o.id) as total
                              FROM orders o
                              JOIN comments c ON c.order_id = o.id
                              WHERE o.user_id = :user_id";

    $ordersWithCommentsStmt = $db->prepare($ordersWithCommentsQuery);
    $ordersWithCommentsStmt->bindParam(':user_id', $user['id']);
    $ordersWithCommentsStmt->execute();

    $ordersWithCommentsCount = $ordersWithCommentsStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
} catch (PDOException $e) {
    handlePDOException($e, "Error getting orders with comments count");
    $ordersWithCommentsCount = 0;
}

// Отримання коментарів з урахуванням фільтрів та пагінації
try {
    // Підрахунок загальної кількості коментарів
    $countQuery = "SELECT COUNT(*) as total 
                  FROM comments c
                  JOIN orders o ON c.order_id = o.id
                  WHERE o.user_id = :user_id";

    $countStmt = $db->prepare($countQuery);
    $countStmt->bindParam(':user_id', $user['id']);
    $countStmt->execute();

    $totalComments = $countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

    // Підрахунок непрочитаних коментарів
    $unreadQuery = "SELECT COUNT(*) as total 
                   FROM comments c
                   JOIN orders o ON c.order_id = o.id
                   WHERE o.user_id = :user_id 
                   AND c.is_read = 0";

    $unreadStmt = $db->prepare($unreadQuery);
    $unreadStmt->bindParam(':user_id', $user['id']);
    $unreadStmt->execute();

    $unread_count = $unreadStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

    // Отримання 3 останніх коментарів
    $recentCommentsQuery = "SELECT c.id, c.content, c.created_at, c.is_read, c.admin_id, c.user_id,
                                   o.id as order_id, o.service, o.status,
                                   CASE WHEN o.status = 'Завершено' THEN 1 ELSE 0 END as is_completed,
                                   u.username, u.first_name, u.last_name, u.role, u.profile_pic
                           FROM comments c
                           JOIN orders o ON c.order_id = o.id
                           LEFT JOIN users u ON c.user_id = u.id
                           WHERE o.user_id = :user_id 
                           ORDER BY c.created_at DESC
                           LIMIT 3";

    $recentCommentsStmt = $db->prepare($recentCommentsQuery);
    $recentCommentsStmt->bindParam(':user_id', $user['id']);
    $recentCommentsStmt->execute();

    $recentComments = $recentCommentsStmt->fetchAll(PDO::FETCH_ASSOC) ?? [];

    // Якщо потрібно показати всі коментарі з пагінацією
    if ($showAll) {
        // Формуємо умову фільтрації
        $whereClause = 'o.user_id = :user_id';

        if ($currentFilter === 'read') {
            $whereClause .= ' AND c.is_read = 1';
        } elseif ($currentFilter === 'unread') {
            $whereClause .= ' AND c.is_read = 0';
        }

        // Підраховуємо загальну кількість відповідно до фільтру
        $filteredCountQuery = "SELECT COUNT(*) as total 
                              FROM comments c
                              JOIN orders o ON c.order_id = o.id
                              WHERE $whereClause";

        $filteredCountStmt = $db->prepare($filteredCountQuery);
        $filteredCountStmt->bindParam(':user_id', $user['id']);
        $filteredCountStmt->execute();

        $filteredTotalComments = $filteredCountStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

        // Запит для отримання коментарів з пагінацією
        $commentsQuery = "SELECT c.id, c.content, c.created_at, c.is_read, c.admin_id, c.user_id,
                                o.id as order_id, o.service, o.status,
                                CASE WHEN o.status = 'Завершено' THEN 1 ELSE 0 END as is_completed,
                                u.username, u.first_name, u.last_name, u.role, u.profile_pic
                          FROM comments c
                          JOIN orders o ON c.order_id = o.id
                          LEFT JOIN users u ON c.user_id = u.id
                          WHERE $whereClause
                          ORDER BY is_completed ASC, c.created_at DESC
                          LIMIT :offset, :limit";

        $commentsStmt = $db->prepare($commentsQuery);
        $commentsStmt->bindParam(':user_id', $user['id']);
        $commentsStmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $commentsStmt->bindParam(':limit', $itemsPerPage, PDO::PARAM_INT);
        $commentsStmt->execute();

        $comments = $commentsStmt->fetchAll(PDO::FETCH_ASSOC) ?? [];

        // Розрахунок загальної кількості сторінок
        $totalPages = ceil($filteredTotalComments / $itemsPerPage);
    }
} catch (PDOException $e) {
    handlePDOException($e, "Error getting comments");
    $comments = [];
    $recentComments = [];
    $unread_count = 0;
    $totalPages = 1;
}

// Функція для форматування дати у відносному форматі
function formatTimeAgo($datetime) {
    try {
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
    } catch (Exception $e) {
        return 'невідомо коли';
    }
}

// Функція для створення URL пагінації зі збереженням фільтрів
function getPaginationUrl($page, $filter = null) {
    $url = 'notifications.php';
    $params = [];

    if ($filter) {
        $params['filter'] = $filter;
    }

    $params['page'] = $page;

    return $url . '?' . http_build_query($params);
}

// Отримання статусів мовою інтерфейсу
$statusTranslations = [
    'Новий' => 'Новий',
    'В роботі' => 'В роботі',
    'Очікується поставки товару' => 'Очікується поставки',
    'Завершено' => 'Завершено',
    'Скасовано' => 'Скасовано',
    'Не можливо виконати' => 'Не можливо виконати'
];

// Кольори для статусів
$statusColors = [
    'Новий' => 'primary',
    'В роботі' => 'info',
    'Очікується поставки товару' => 'warning',
    'Завершено' => 'success',
    'Скасовано' => 'danger',
    'Не можливо виконати' => 'secondary'
];

// Функція для безпечного виведення тексту
function safeEcho($text, $default = '') {
    return htmlspecialchars($text ?? $default, ENT_QUOTES, 'UTF-8');
}

// Функція для отримання статусу стилю
function getStatusStyle($status) {
    switch ($status) {
        case 'Завершено': return 'comment-status-completed';
        case 'В роботі': return 'comment-status-inprogress';
        default: return 'comment-status-new';
    }
}

// Функція для отримання імені автора
function getAuthorName($comment) {
    if (isset($comment['admin_id']) && !empty($comment['admin_id'])) {
        return 'Адміністратор';
    }

    if (!empty($comment['first_name']) || !empty($comment['last_name'])) {
        return trim(safeEcho($comment['first_name'] ?? '') . ' ' . safeEcho($comment['last_name'] ?? ''));
    }

    if (!empty($comment['username'])) {
        return safeEcho($comment['username']);
    }

    return 'Адміністратор';
}

// Функція для отримання ролі автора коментаря
function getAuthorRole($comment) {
    if (isset($comment['role']) && $comment['role'] === 'junior_admin') {
        return 'Молодший Адмін';
    }

    if (isset($comment['admin_id']) && !empty($comment['admin_id']) ||
        (isset($comment['role']) && $comment['role'] === 'admin')) {
        return 'Адмін';
    }

    return '';
}

// Функція для отримання аватара автора
function getAuthorAvatar($comment) {
    // Спочатку перевіряємо, чи є у користувача profile_pic
    if (isset($comment['profile_pic']) && !empty($comment['profile_pic'])) {
        return '<img src="' . safeEcho($comment['profile_pic']) . '" alt="Фото профілю" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">';
    }

    // Якщо немає фото, показуємо першу літеру імені користувача
    $initial = strtoupper(substr($comment['username'] ?? 'A', 0, 1));
    return $initial;
}

// Функція для отримання класу автора залежно від ролі
function getAuthorClass($comment) {
    if (isset($comment['role']) && $comment['role'] === 'junior_admin') {
        return 'junior-admin-author';
    }

    if (isset($comment['admin_id']) && !empty($comment['admin_id']) ||
        (isset($comment['role']) && $comment['role'] === 'admin')) {
        return 'admin-author';
    }

    return '';
}
?>

<!DOCTYPE html>
<html lang="uk" data-theme="<?php echo $currentTheme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title><?php echo safeEcho($pageTitle); ?> - Lagodi Service</title>

    <!-- Блокуємо рендеринг сторінки до встановлення теми -->
    <script>
        (function() {
            // Check localStorage first
            let theme = localStorage.getItem('theme');

            // If not in localStorage, check cookies
            if (!theme) {
                const cookies = document.cookie.split(';');
                for (let i = 0; i < cookies.length; i++) {
                    const cookie = cookies[i].trim();
                    if (cookie.startsWith('theme=')) {
                        theme = cookie.substring(6);
                        break;
                    }
                }
            }

            // If still no theme, use the system preference or default to dark
            if (!theme) {
                if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                    theme = 'dark';
                } else {
                    theme = 'light';
                }
            }

            // Apply theme to document
            document.documentElement.setAttribute('data-theme', theme);

            // Також застосовуємо тему до сайдбару при завантаженні
            document.addEventListener('DOMContentLoaded', function() {
                const sidebar = document.getElementById('sidebar');
                if (sidebar) {
                    sidebar.setAttribute('data-theme', theme);
                }

                // Встановлюємо відповідні стилі для компонентів користувача
                const userProfileWidget = document.querySelector('.user-profile-widget');
                if (userProfileWidget && theme === 'light') {
                    userProfileWidget.style.backgroundColor = '#e9ecef';

                    const userName = userProfileWidget.querySelector('.user-name');
                    if (userName) {
                        userName.style.color = '#212529';
                    }
                }
            });
        })();
    </script>

    <!-- CSS файли -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../../style/dahm/user_dashboard.css">
    <link rel="stylesheet" href="../../style/dahm/notifications.css">

    <style>
        /* Оновлені стилі для сайдбару з підтримкою світлої/темної теми */
        .sidebar {
            transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease;
        }

        :root[data-theme="light"] .sidebar {
            background-color: #f8f9fa;
            border-right: 1px solid #dee2e6;
        }

        :root[data-theme="light"] .logo {
            color: #1d9bf0;
        }

        :root[data-theme="light"] .nav-link {
            color: #4b5563;
        }

        :root[data-theme="light"] .nav-link:hover {
            background-color: #edf2f7;
            color: #1a202c;
        }

        :root[data-theme="light"] .nav-link.active {
            background-color: #e6f7ff;
            color: #1d9bf0;
        }

        :root[data-theme="light"] .nav-divider {
            background-color: #dee2e6;
        }

        :root[data-theme="light"] .sidebar-header {
            border-bottom: 1px solid #dee2e6;
        }

        :root[data-theme="light"] .user-profile-widget {
            background-color: #e9ecef;
        }

        :root[data-theme="light"] .user-profile-widget .user-name {
            color: #212529;
        }

        :root[data-theme="light"] .toggle-btn {
            color: #4b5563;
        }

        :root[data-theme="light"] .user-avatar-placeholder {
            background-color: #7a3bdf;
            color: white;
        }

        /* Темна тема для сайдбару (вже повинна бути за замовчуванням) */
        :root[data-theme="dark"] .user-profile-widget {
            background-color: #232323;
        }

        :root[data-theme="dark"] .user-profile-widget .user-name {
            color: white;
        }

        :root[data-theme="dark"] .user-avatar-placeholder {
            background-color: #7a3bdf;
            color: white;
        }

        :root[data-theme="dark"] .logo {
            color: #1d9bf0;
        }

        /* Стилізований компонент користувача в лівому сайдбарі */
        .user-profile-widget {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            background-color: #232323;
            border-radius: 10px;
            width: 100%;
            margin-bottom: 10px;
            transition: background-color 0.2s ease;
        }

        .user-profile-widget:hover {
            background-color: #2a2a2a;
        }

        .user-profile-widget .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            overflow: hidden;
            flex-shrink: 0;
        }

        .user-profile-widget .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .user-profile-widget .user-name {
            font-size: 16px;
            font-weight: 500;
            color: white;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            flex-grow: 1;
        }

        /* Кнопки з належним контрастом у світлій темі */
        .filter-btn.active,
        .filter-btn:hover,
        .btn-view:hover,
        .btn-read:hover,
        .view-more-btn:hover {
            background-color: var(--primary-color);
            color: white;
        }

        :root[data-theme="light"] .filter-btn.active,
        :root[data-theme="light"] .filter-btn:hover,
        :root[data-theme="light"] .btn-view:hover,
        :root[data-theme="light"] .btn-read:hover,
        :root[data-theme="light"] .view-more-btn:hover {
            background-color: #1d9bf0;
            color: white;
        }

        :root[data-theme="dark"] .filter-btn.active,
        :root[data-theme="dark"] .filter-btn:hover,
        :root[data-theme="dark"] .btn-view:hover,
        :root[data-theme="dark"] .btn-read:hover,
        :root[data-theme="dark"] .view-more-btn:hover {
            background-color: #1d9bf0;
            color: white;
        }

        /* Стилі для відображення автора та його аватара */
        .comment-author .author-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            overflow: hidden;
            background-color: #7a3bdf;
            color: white;
        }

        .comment-author.admin-author .author-avatar {
            background-color: #e74c3c;
            color: white;
        }

        .comment-author.junior-admin-author .author-avatar {
            background-color: #3498db;
            color: white;
        }

        .author-badge {
            background-color: #e74c3c;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-left: 8px;
        }

        .junior-admin-author .author-badge {
            background-color: #3498db;
        }

        /* Додаткові стилі для відображення фото профілю в сайдбарі */
        .user-profile-widget .user-avatar img,
        .user-dropdown-btn .user-avatar-small img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }
    </style>
</head>
<body>
<div class="wrapper" id="mainWrapper">
    <!-- Сайдбар (ліва панель) з новим стилем як у settings.php -->
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
                    <img src="<?php echo safeEcho($user['profile_pic']); ?>" alt="Фото профілю">
                <?php else: ?>
                    <div class="user-avatar-placeholder">
                        <?php echo strtoupper(substr($user['username'] ?? '', 0, 1)); ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="user-name">
                <?php echo safeEcho($user['username']); ?>
            </div>
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
            <a href="/dah/user/orders.php" class="nav-link">
                <i class="bi bi-list-check"></i>
                <span>Мої замовлення</span>
            </a>
            <a href="/dah/user/notifications.php" class="nav-link active">
                <i class="bi bi-bell"></i>
                <span>Коментарі</span>
                <?php if ($unread_count > 0): ?>
                    <span class="notification-badge"><?php echo $unread_count; ?></span>
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
            <div class="nav-divider"></div>
        </nav>
    </aside>

    <!-- Основний контент -->
    <main class="main-content" id="mainContent">
        <header class="main-header">
            <button id="menuToggle" class="menu-toggle">
                <i class="bi bi-list"></i>
            </button>
            <div class="header-title">
                <h1>Коментарі</h1>
            </div>
            <div class="header-actions">
                <button id="themeSwitchBtn" class="theme-switch-btn">
                    <i class="bi <?php echo $currentTheme === 'dark' ? 'bi-sun' : 'bi-moon'; ?>"></i>
                </button>

                <div class="user-dropdown">
                    <button class="user-dropdown-btn">
                        <div class="user-avatar-small">
                            <?php if(isset($user['profile_pic']) && !empty($user['profile_pic'])): ?>
                                <img src="<?php echo safeEcho($user['profile_pic']); ?>" alt="Фото профілю" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                            <?php else: ?>
                                <?php echo strtoupper(substr($user['username'] ?? '', 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                        <span class="user-name"><?php echo safeEcho($user['username']); ?></span>
                        <i class="bi bi-chevron-down"></i>
                    </button>
                    <div class="user-dropdown-menu">
                        <a href="/dah/user/profile.php"><i class="bi bi-person"></i> Профіль</a>
                        <a href="/dah/user/settings.php"><i class="bi bi-gear"></i> Налаштування</a>
                        <div class="dropdown-divider"></div>
                        <a href="/logout.php"><i class="bi bi-box-arrow-right"></i> Вихід</a>
                    </div>
                </div>
            </div>
        </header>

        <div class="content-wrapper">
            <div class="section-container">
                <!-- Нові вкладки для коментарів у стилі карток -->
                <div class="comments-tabs">
                    <a href="notifications.php" class="tab-item all-comments <?php echo $currentFilter === 'default' ? 'active' : ''; ?>">
                        <div class="tab-label">Всього коментарів</div>
                        <div class="tab-count"><?php echo $totalComments; ?></div>
                    </a>
                    <a href="?filter=unread" class="tab-item unread-comments <?php echo $currentFilter === 'unread' ? 'active' : ''; ?>">
                        <div class="tab-label">Непрочитані</div>
                        <div class="tab-count"><?php echo $unread_count; ?></div>
                    </a>
                    <a href="?filter=orders_with_comments" class="tab-item with-comments <?php echo $currentFilter === 'orders_with_comments' ? 'active' : ''; ?>">
                        <div class="tab-label">Замовлень з коментарями</div>
                        <div class="tab-count"><?php echo $ordersWithCommentsCount; ?></div>
                    </a>
                </div>

                <!-- Пошук та фільтри в одному рядку -->
                <div class="search-and-filters">
                    <div class="search-container">
                        <i class="bi bi-search search-icon"></i>
                        <input type="text" id="comment-search" placeholder="Пошук в коментарях...">
                    </div>

                    <div class="filter-buttons">
                        <a href="?filter=unread" class="filter-btn <?php echo $currentFilter === 'unread' ? 'active' : ''; ?>">
                            <i class="bi bi-envelope"></i> Непрочитані
                        </a>
                        <a href="notifications.php" class="filter-btn <?php echo $currentFilter === 'default' ? 'active' : ''; ?>">
                            <i class="bi bi-x-circle"></i> Очистити
                        </a>
                        <?php if ($unread_count > 0): ?>
                            <a href="?mark_all_read=1" class="filter-btn btn-clear">
                                <i class="bi bi-check-all"></i> Позначити всі прочитаними
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Список коментарів -->
                <div class="comments-list-header">
                    <div class="comments-list-title">Список коментарів</div>
                </div>

                <?php if (empty($recentComments) && ($currentFilter == 'default' || !$showAll)): ?>
                    <!-- Порожній стан для головної сторінки -->
                    <div class="empty-state">
                        <i class="bi bi-bell-slash empty-icon"></i>
                        <div class="empty-title">У вас немає коментарів</div>
                        <p class="empty-description">Коментарі від адміністраторів будуть з'являтися тут</p>
                    </div>
                <?php elseif ($currentFilter != 'default' && $showAll && empty($comments)): ?>
                    <!-- Порожній стан для сторінки з фільтром -->
                    <div class="empty-state">
                        <i class="bi bi-filter-circle empty-icon"></i>
                        <div class="empty-title">Немає коментарів за вибраним фільтром</div>
                        <p class="empty-description">Спробуйте змінити параметри фільтрації</p>
                    </div>
                <?php else: ?>
                    <?php if ($currentFilter == 'default' || !$showAll): ?>
                        <!-- Останні 3 коментарі для головної сторінки -->
                        <div class="comments-list">
                            <?php foreach ($recentComments as $comment): ?>
                                <div class="comment-item">
                                    <div class="comment-header">
                                        <div class="comment-title-group">
                                            <i class="bi bi-file-text"></i>
                                            <a href="orders.php?id=<?php echo isset($comment['order_id']) ? (int)$comment['order_id'] : 0; ?>" class="comment-service-link">
                                                <?php echo safeEcho($comment['service'], 'Замовлення'); ?>
                                            </a>
                                            <span class="comment-status-badge <?php echo getStatusStyle($comment['status'] ?? ''); ?>">
                                        <?php
                                        $status = $comment['status'] ?? '';
                                        echo safeEcho($statusTranslations[$status] ?? $status, 'Невідомий статус');
                                        ?>
                                    </span>
                                        </div>
                                        <div class="comment-date">
                                            <?php echo formatTimeAgo($comment['created_at'] ?? ''); ?>
                                        </div>
                                    </div>
                                    <div class="comment-body">
                                        <div class="comment-number"><?php echo isset($comment['id']) ? (int)$comment['id'] : ''; ?></div>
                                        <div class="comment-author <?php echo getAuthorClass($comment); ?>">
                                            <div class="author-avatar">
                                                <?php echo getAuthorAvatar($comment); ?>
                                            </div>
                                            <div>
                                                <span class="author-name">
                                                    <?php echo getAuthorName($comment); ?>
                                                </span>
                                                <?php $role = getAuthorRole($comment); if (!empty($role)): ?>
                                                    <span class="author-badge"><?php echo $role; ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="comment-text">
                                            <?php echo nl2br(safeEcho($comment['content'], 'Текст коментаря відсутній')); ?>
                                        </div>
                                        <div class="comment-actions">
                                            <?php if (isset($comment['is_read']) && $comment['is_read'] == 0): ?>
                                                <a href="?mark_read=<?php echo isset($comment['id']) ? (int)$comment['id'] : 0; ?>" class="btn-read">
                                                    <i class="bi bi-check"></i> Позначити як прочитане
                                                </a>
                                            <?php endif; ?>
                                            <a href="orders.php?id=<?php echo isset($comment['order_id']) ? (int)$comment['order_id'] : 0; ?>" class="btn-view">
                                                <i class="bi bi-eye"></i> Перейти до замовлення
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- ВИПРАВЛЕНО: Кнопка "Переглянути більше" тепер коректно відкриває всі коментарі -->
                        <div class="view-more-container">
                            <a href="?filter=all" class="view-more-btn">
                                <i class="bi bi-list"></i> Переглянути більше коментарів (<?php echo $totalComments; ?>)
                            </a>
                        </div>
                    <?php else: ?>
                        <!-- Повний список коментарів з пагінацією для сторінки з фільтром -->
                        <div class="comments-list">
                            <?php foreach ($comments as $comment): ?>
                                <div class="comment-item">
                                    <div class="comment-header">
                                        <div class="comment-title-group">
                                            <i class="bi bi-file-text"></i>
                                            <a href="orders.php?id=<?php echo isset($comment['order_id']) ? (int)$comment['order_id'] : 0; ?>" class="comment-service-link">
                                                <?php echo safeEcho($comment['service'], 'Замовлення'); ?>
                                            </a>
                                            <span class="comment-status-badge <?php echo getStatusStyle($comment['status'] ?? ''); ?>">
                                        <?php
                                        $status = $comment['status'] ?? '';
                                        echo safeEcho($statusTranslations[$status] ?? $status, 'Невідомий статус');
                                        ?>
                                    </span>
                                        </div>
                                        <div class="comment-date">
                                            <?php echo formatTimeAgo($comment['created_at'] ?? ''); ?>
                                        </div>
                                    </div>
                                    <div class="comment-body">
                                        <div class="comment-number"><?php echo isset($comment['id']) ? (int)$comment['id'] : ''; ?></div>
                                        <div class="comment-author <?php echo getAuthorClass($comment); ?>">
                                            <div class="author-avatar">
                                                <?php echo getAuthorAvatar($comment); ?>
                                            </div>
                                            <div>
                                                <span class="author-name">
                                                    <?php echo getAuthorName($comment); ?>
                                                </span>
                                                <?php $role = getAuthorRole($comment); if (!empty($role)): ?>
                                                    <span class="author-badge"><?php echo $role; ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="comment-text">
                                            <?php echo nl2br(safeEcho($comment['content'], 'Текст коментаря відсутній')); ?>
                                        </div>
                                        <div class="comment-actions">
                                            <?php if (isset($comment['is_read']) && $comment['is_read'] == 0): ?>
                                                <a href="?filter=<?php echo $currentFilter; ?>&page=<?php echo $currentPage; ?>&mark_read=<?php echo isset($comment['id']) ? (int)$comment['id'] : 0; ?>" class="btn-read">
                                                    <i class="bi bi-check"></i> Позначити як прочитане
                                                </a>
                                            <?php endif; ?>
                                            <a href="orders.php?id=<?php echo isset($comment['order_id']) ? (int)$comment['order_id'] : 0; ?>" class="btn-view">
                                                <i class="bi bi-eye"></i> Перейти до замовлення
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($showAll && $totalPages > 1): ?>
                        <!-- Пагінація -->
                        <div class="pagination">
                            <?php if ($currentPage > 1): ?>
                                <a href="<?php echo getPaginationUrl($currentPage - 1, $currentFilter); ?>" class="page-item">
                                    <i class="bi bi-chevron-left"></i>
                                </a>
                            <?php else: ?>
                                <span class="page-item disabled">
                            <i class="bi bi-chevron-left"></i>
                        </span>
                            <?php endif; ?>

                            <?php
                            $startPage = max(1, $currentPage - 2);
                            $endPage = min($totalPages, $currentPage + 2);

                            if ($startPage > 1) {
                                echo '<a href="' . getPaginationUrl(1, $currentFilter) . '" class="page-item">1</a>';
                                if ($startPage > 2) {
                                    echo '<span class="page-item disabled">...</span>';
                                }
                            }

                            for ($i = $startPage; $i <= $endPage; $i++) {
                                if ($i == $currentPage) {
                                    echo '<span class="page-item active">' . $i . '</span>';
                                } else {
                                    echo '<a href="' . getPaginationUrl($i, $currentFilter) . '" class="page-item">' . $i . '</a>';
                                }
                            }

                            if ($endPage < $totalPages) {
                                if ($endPage < $totalPages - 1) {
                                    echo '<span class="page-item disabled">...</span>';
                                }
                                echo '<a href="' . getPaginationUrl($totalPages, $currentFilter) . '" class="page-item">' . $totalPages . '</a>';
                            }
                            ?>

                            <?php if ($currentPage < $totalPages): ?>
                                <a href="<?php echo getPaginationUrl($currentPage + 1, $currentFilter); ?>" class="page-item">
                                    <i class="bi bi-chevron-right"></i>
                                </a>
                            <?php else: ?>
                                <span class="page-item disabled">
                            <i class="bi bi-chevron-right"></i>
                        </span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- Затемнення фону при відкритті мобільного меню -->
    <div class="overlay" id="overlay"></div>

    <!-- Toast-сповіщення -->
    <div class="toast-container" id="toast-container"></div>
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

        // Функція для застосування теми до всіх елементів сайту
        function applyTheme(theme) {
            document.documentElement.setAttribute('data-theme', theme);
            localStorage.setItem('theme', theme);

            // Встановлюємо куки, щоб тема зберігалась після перезавантаження
            let date = new Date();
            date.setTime(date.getTime() + (365 * 24 * 60 * 60 * 1000)); // 1 рік
            let expires = "expires=" + date.toUTCString();
            document.cookie = "theme=" + theme + ";" + expires + ";path=/";

            // Оновлюємо вигляд кнопки перемикання теми
            const themeSwitchBtn = document.getElementById('themeSwitchBtn');
            if (themeSwitchBtn) {
                const icon = themeSwitchBtn.querySelector('i');
                if (icon) {
                    icon.className = theme === 'dark' ? 'bi bi-sun' : 'bi bi-moon';
                }
            }

            // Застосовуємо відповідні стилі до сайдбару
            const sidebar = document.getElementById('sidebar');
            if (sidebar) {
                sidebar.setAttribute('data-theme', theme);
            }

            // Застосовуємо стилі до компонента користувача
            const userProfileWidget = document.querySelector('.user-profile-widget');
            if (userProfileWidget) {
                if (theme === 'light') {
                    userProfileWidget.style.backgroundColor = '#e9ecef';
                } else {
                    userProfileWidget.style.backgroundColor = '#232323';
                }
            }

            // Оновлюємо колір імені користувача
            const userName = document.querySelector('.user-profile-widget .user-name');
            if (userName) {
                userName.style.color = theme === 'light' ? '#212529' : 'white';
            }
        }

        // Функція для перемикання теми з плавним переходом
        function toggleTheme() {
            // Отримуємо поточну тему
            const htmlElement = document.documentElement;
            const currentTheme = htmlElement.getAttribute('data-theme') || 'dark';
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';

            // Застосовуємо нову тему через нашу функцію
            applyTheme(newTheme);

            // Додаємо клас для плавного переходу
            document.body.style.transition = 'background-color 0.3s ease, color 0.3s ease';

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

        // Застосовуємо поточну тему при завантаженні сторінки
        const currentTheme = document.documentElement.getAttribute('data-theme');
        if (currentTheme) {
            applyTheme(currentTheme);
        }

        // Додаємо обробник для перемикача теми в меню користувача
        const toggleThemeButton = document.getElementById('toggleThemeButton');
        if (toggleThemeButton) {
            toggleThemeButton.addEventListener('click', function(e) {
                e.preventDefault();
                toggleTheme();

                // Оновлюємо текст та іконку кнопки
                const icon = this.querySelector('i');
                if (icon) {
                    const newTheme = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
                    icon.className = newTheme === 'dark' ? 'bi bi-moon' : 'bi bi-sun';
                    this.textContent = ' ' + (newTheme === 'dark' ? 'Світла тема' : 'Темна тема');
                    this.insertBefore(icon, this.firstChild);
                }

                // Закриваємо меню користувача
                if (userDropdownMenu) {
                    userDropdownMenu.classList.remove('show');
                }
            });
        }
    });
</script>

<script src="../../jawa/dahj/notifications.js"></script>

</body>
</html>