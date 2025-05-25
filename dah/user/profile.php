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

// Отримання статистики замовлень користувача
try {
    // Загальна кількість замовлень
    $orderCountQuery = "SELECT COUNT(*) as total_orders FROM orders WHERE user_id = :user_id";
    $orderCountStmt = $db->prepare($orderCountQuery);
    $orderCountStmt->bindParam(':user_id', $user['id']);
    $orderCountStmt->execute();
    $totalOrders = $orderCountStmt->fetch(PDO::FETCH_ASSOC)['total_orders'];

    // Кількість коментарів до замовлень
    $commentsQuery = "SELECT COUNT(*) as total_comments FROM comments 
                      WHERE order_id IN (SELECT id FROM orders WHERE user_id = :user_id)";
    $commentsStmt = $db->prepare($commentsQuery);
    $commentsStmt->bindParam(':user_id', $user['id']);
    $commentsStmt->execute();
    $totalComments = $commentsStmt->fetch(PDO::FETCH_ASSOC)['total_comments'];

    // Кількість нових коментарів (непрочитаних)
    $newCommentsQuery = "SELECT COUNT(*) as new_comments FROM comments 
                        WHERE order_id IN (SELECT id FROM orders WHERE user_id = :user_id) 
                        AND is_read = 0 AND user_id != :user_id";
    $newCommentsStmt = $db->prepare($newCommentsQuery);
    $newCommentsStmt->bindParam(':user_id', $user['id']);
    $newCommentsStmt->execute();
    $newComments = $newCommentsStmt->fetch(PDO::FETCH_ASSOC)['new_comments'];

    // Статистика замовлень за статусами
    $orderStatsQuery = "SELECT status, COUNT(*) as count FROM orders 
                      WHERE user_id = :user_id GROUP BY status";
    $orderStatsStmt = $db->prepare($orderStatsQuery);
    $orderStatsStmt->bindParam(':user_id', $user['id']);
    $orderStatsStmt->execute();
    $orderStats = $orderStatsStmt->fetchAll(PDO::FETCH_ASSOC);

    $statusCounts = [
        'new' => 0,
        'in_progress' => 0,
        'waiting' => 0,
        'waiting_delivery' => 0,
        'completed' => 0,
        'canceled' => 0
    ];

    foreach ($orderStats as $stat) {
        switch (trim($stat['status'])) {
            case 'Новий':
                $statusCounts['new'] = $stat['count'];
                break;
            case 'В роботі':
                $statusCounts['in_progress'] = $stat['count'];
                break;
            case 'Очікується':
                $statusCounts['waiting'] = $stat['count'];
                break;
            case 'Очікується поставки товару':
                $statusCounts['waiting_delivery'] = $stat['count'];
                break;
            case 'Завершено':
            case 'Виконано':
                $statusCounts['completed'] += $stat['count']; // Використовуємо += для додавання
                break;
            case 'Не можливо виконати':
            case 'Скасовано':
                $statusCounts['canceled'] += $stat['count']; // Використовуємо += для додавання
                break;
        }
    }

    // Отримання дати реєстрації
    $registrationDateQuery = "SELECT created_at FROM users WHERE id = :user_id";
    $registrationDateStmt = $db->prepare($registrationDateQuery);
    $registrationDateStmt->bindParam(':user_id', $user['id']);
    $registrationDateStmt->execute();
    $registrationDate = $registrationDateStmt->fetch(PDO::FETCH_ASSOC)['created_at'];

    // Останні 5 замовлень
    $latestOrdersQuery = "SELECT o.*, c.name as category_name 
                      FROM orders o
                      LEFT JOIN service_categories c ON o.category_id = c.id 
                      WHERE o.user_id = :user_id 
                      ORDER BY o.created_at DESC LIMIT 5";
    $latestOrdersStmt = $db->prepare($latestOrdersQuery);
    $latestOrdersStmt->bindParam(':user_id', $user['id']);
    $latestOrdersStmt->execute();
    $latestOrders = $latestOrdersStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $errorMessage = "Помилка при отриманні статистики: " . $e->getMessage();
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
    $status = trim($status); // Видаляємо пробіли для точного порівняння

    switch($status) {
        case 'Новий':
            return 'status-new';
        case 'В обробці':
            return 'status-processing';
        case 'В роботі':
            return 'status-in-progress';
        case 'Виконано':
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

// Функція для обчислення часу з дати реєстрації
function getUserAge($registrationDate) {
    $registration = new DateTime($registrationDate);
    $now = new DateTime();
    $diff = $registration->diff($now);

    if ($diff->y > 0) {
        return $diff->y . ' ' . getYearText($diff->y) . ' ' . $diff->m . ' ' . getMonthText($diff->m);
    } elseif ($diff->m > 0) {
        return $diff->m . ' ' . getMonthText($diff->m) . ' ' . $diff->d . ' ' . getDayText($diff->d);
    } else {
        return $diff->d . ' ' . getDayText($diff->d);
    }
}

function getYearText($years) {
    if ($years == 1) return 'рік';
    if ($years >= 2 && $years <= 4) return 'роки';
    return 'років';
}

function getMonthText($months) {
    if ($months == 1) return 'місяць';
    if ($months >= 2 && $months <= 4) return 'місяці';
    return 'місяців';
}

function getDayText($days) {
    if ($days == 1 || $days == 21 || $days == 31) return 'день';
    if (($days >= 2 && $days <= 4) || ($days >= 22 && $days <= 24)) return 'дні';
    return 'днів';
}
?>

<!DOCTYPE html>
<html lang="uk" data-theme="<?php echo $currentTheme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title>Мій профіль - Lagodi Service</title>

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
    <link rel="stylesheet" href="../../style/dahm/orders.css">

    <style>
        /* Стилі для сторінки профілю */
        .profile-container {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 20px;
        }

        @media (max-width: 768px) {
            .profile-container {
                grid-template-columns: 1fr;
            }
        }

        /* Ліва колонка - інформація про користувача */
        .user-profile-card {
            background-color: var(--card-bg);
            border-radius: 12px;
            border: 1px solid var(--border-color);
            padding: 25px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .user-profile-avatar {
            width: 120px;
            height: 120px;
            background-color: var(--primary-color);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            font-weight: 600;
            margin: 0 auto 20px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }

        .user-profile-name {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 5px;
            color: var(--text-primary);
        }

        .user-profile-role {
            color: var(--primary-color);
            font-size: 0.9rem;
            margin-bottom: 20px;
            display: inline-block;
            background-color: rgba(52, 152, 219, 0.1);
            padding: 4px 12px;
            border-radius: 20px;
        }

        .user-profile-id {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 25px;
            gap: 5px;
            color: var(--text-secondary, #888);
            font-size: 0.85rem;
        }

        .user-profile-info {
            text-align: left;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid var(--border-color);
        }

        .user-profile-item {
            display: flex;
            align-items: center;
            margin-bottom: 12px;
            color: var(--text-primary);
        }

        .user-profile-item i {
            width: 20px;
            color: var(--primary-color);
            margin-right: 10px;
        }

        .user-profile-label {
            font-weight: 500;
            width: 30%;
            color: var(--text-secondary, #888);
        }

        .user-profile-value {
            flex: 1;
            word-break: break-word;
        }

        .user-stats {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid var(--border-color);
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .user-stat-item {
            background-color: rgba(0, 0, 0, 0.05);
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            transition: transform 0.2s, box-shadow 0.2s;
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        .user-stat-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .user-stat-number {
            font-size: 1.8rem;
            font-weight: 600;
            margin-bottom: 5px;
            color: var(--primary-color);
        }

        .user-stat-label {
            font-size: 0.85rem;
            color: var(--text-secondary, #888);
        }

        .user-actions {
            margin-top: 25px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .user-action-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 10px 15px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            gap: 8px;
            transition: all 0.2s;
            text-decoration: none;
        }

        /* Оновлені стилі для кнопки редагування профілю при різних темах */
        .user-action-primary {
            background-color: var(--primary-color);
            color: white;
        }

        /* Світла тема - забезпечує контраст для кнопки */
        :root[data-theme="light"] .user-action-primary {
            background-color: #1d9bf0; /* Синій кольор, як на скріншоті */
            color: white;
        }

        :root[data-theme="light"] .user-action-primary:hover {
            background-color: #0c7abf; /* Темніший синій при наведенні */
            transform: translateY(-2px);
        }

        /* Темна тема - забезпечує належний вигляд кнопки */
        :root[data-theme="dark"] .user-action-primary {
            background-color: #1d9bf0;
            color: white;
        }

        :root[data-theme="dark"] .user-action-primary:hover {
            background-color: #0c7abf;
            transform: translateY(-2px);
        }

        .user-action-secondary {
            background-color: transparent;
            color: var(--text-primary);
            border: 1px solid var(--border-color);
        }

        .user-action-secondary:hover {
            background-color: rgba(0, 0, 0, 0.05);
            transform: translateY(-2px);
        }

        /* Права колонка - статистика та активність */
        .user-activity-card {
            background-color: var(--card-bg);
            border-radius: 12px;
            border: 1px solid var(--border-color);
            padding: 25px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .activity-title {
            font-size: 1.4rem;
            font-weight: 600;
            margin-bottom: 20px;
            color: var(--text-primary);
            display: flex;
            align-items: center;
        }

        .activity-title i {
            margin-right: 10px;
            color: var(--primary-color);
        }

        .activity-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 30px;
        }

        @media (max-width: 992px) {
            .activity-stats {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 576px) {
            .activity-stats {
                grid-template-columns: 1fr;
            }
        }

        .activity-stat-item {
            background-color: rgba(0, 0, 0, 0.05);
            border-radius: 10px;
            padding: 15px;
            display: flex;
            align-items: center;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .activity-stat-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .activity-stat-icon {
            width: 45px;
            height: 45px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            font-size: 1.4rem;
        }

        .activity-stat-icon-blue {
            background-color: rgba(52, 152, 219, 0.2);
            color: #3498db;
        }

        .activity-stat-icon-green {
            background-color: rgba(46, 204, 113, 0.2);
            color: #2ecc71;
        }

        .activity-stat-icon-purple {
            background-color: rgba(142, 68, 173, 0.2);
            color: #8e44ad;
        }

        .activity-stat-icon-orange {
            background-color: rgba(243, 156, 18, 0.2);
            color: #f39c12;
        }

        .activity-stat-icon-red {
            background-color: rgba(231, 76, 60, 0.2);
            color: #e74c3c;
        }

        .activity-stat-icon-teal {
            background-color: rgba(26, 188, 156, 0.2);
            color: #1abc9c;
        }

        .activity-stat-content {
            flex: 1;
        }

        .activity-stat-number {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--text-primary);
            line-height: 1.2;
        }

        .activity-stat-label {
            font-size: 0.85rem;
            color: var(--text-secondary, #888);
        }

        .chart-container {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid var(--border-color);
        }

        .chart-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 15px;
            color: var(--text-primary);
        }

        .status-progress-container {
            margin-bottom: 30px;
        }

        .status-progress-item {
            margin-bottom: 12px;
        }

        .status-progress-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
        }

        .status-progress-label {
            font-weight: 500;
            color: var(--text-primary);
            font-size: 0.9rem;
        }

        .status-progress-value {
            font-weight: 600;
            color: var(--primary-color);
            font-size: 0.9rem;
        }

        .status-progress-bar {
            height: 8px;
            border-radius: 4px;
            background-color: rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .status-progress-fill {
            height: 100%;
            border-radius: 4px;
        }

        .status-new-fill {
            background-color: #f39c12;
        }

        .status-in-progress-fill {
            background-color: #3498db;
        }

        .status-waiting-fill {
            background-color: #8e44ad;
        }

        .status-waiting-delivery-fill {
            background-color: #ff9800;
        }

        .status-completed-fill {
            background-color: #2ecc71;
        }

        .status-canceled-fill {
            background-color: #e74c3c;
        }

        /* Нижче статистики - останні замовлення */
        .recent-orders {
            margin-top: 30px;
        }

        .orders-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        .orders-table th,
        .orders-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .orders-table th {
            font-weight: 600;
            color: var(--text-secondary, #888);
            font-size: 0.9rem;
        }

        .orders-table tr:last-child td {
            border-bottom: none;
        }

        .orders-table tr:hover td {
            background-color: rgba(0, 0, 0, 0.02);
        }

        .order-id {
            color: var(--primary-color);
            font-weight: 600;
        }

        .order-date {
            font-size: 0.85rem;
            color: var(--text-secondary, #888);
        }

        .order-link {
            color: var(--primary-color);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: color 0.2s;
        }

        .order-link:hover {
            color: var(--primary-color-dark, #2980b9);
            text-decoration: underline;
        }

        /* Стилі для бейджів статусу */
        .status-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
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

        /* Стилі для повідомлень */
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background-color: rgba(46, 204, 113, 0.1);
            border: 1px solid rgba(46, 204, 113, 0.3);
            color: #2ecc71;
        }

        .alert-danger {
            background-color: rgba(231, 76, 60, 0.1);
            border: 1px solid rgba(231, 76, 60, 0.3);
            color: #e74c3c;
        }

        /* Плавний перехід для зміни стилів при зміні теми */
        .theme-transition {
            transition: color 0.3s ease, background-color 0.3s ease, border-color 0.3s ease;
        }

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

        /* Додаткова адаптивність */
        @media (max-width: 576px) {
            .activity-stat-item {
                flex-direction: column;
                text-align: center;
            }

            .activity-stat-icon {
                margin-right: 0;
                margin-bottom: 10px;
            }
        }
    </style>
</head>
<body>
<div class="wrapper" id="mainWrapper">
    <!-- Сайдбар (ліва панель) з налаштувань -->
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
            <a href="/dah/user/notifications.php" class="nav-link">
                <i class="bi bi-bell"></i>
                <span>Коментарі</span>
                <?php if ($newComments > 0): ?>
                    <span class="notification-badge"><?php echo $newComments; ?></span>
                <?php endif; ?>
            </a>
            <a href="/dah/user/profile.php" class="nav-link active">
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
                <h1>Мій профіль</h1>
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

        <!-- Сповіщення -->
        <?php if ($successMessage): ?>
            <div class="alert alert-success">
                <i class="bi bi-check-circle"></i> <?php echo $successMessage; ?>
            </div>
        <?php endif; ?>

        <?php if ($errorMessage): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle"></i> <?php echo $errorMessage; ?>
            </div>
        <?php endif; ?>

        <div class="content-wrapper">
            <div class="section-container">
                <div class="profile-container">
                    <!-- Ліва колонка - картка профілю -->
                    <div class="user-profile-card">
                        <div class="user-profile-avatar">
                            <?php if(isset($user['profile_pic']) && !empty($user['profile_pic'])): ?>
                                <img src="<?php echo safeEcho($user['profile_pic']); ?>" alt="Фото профілю" style="width:100%; height:100%; object-fit:cover; border-radius:50%;">
                            <?php else: ?>
                                <?php echo strtoupper(substr($user['username'] ?? '', 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                        <div class="user-profile-name"><?php echo safeEcho($user['username']); ?></div>
                        <div class="user-profile-role">
                            <?php
                            $roleName = "Користувач";
                            if (isset($user['role']) && $user['role'] === 'admin') {
                                $roleName = "Адміністратор";
                            } elseif (isset($user['role']) && $user['role'] === 'manager') {
                                $roleName = "Менеджер";
                            }
                            echo $roleName;
                            ?>
                        </div>
                        <div class="user-profile-id">
                            <i class="bi bi-person-badge"></i>
                            ID: <?php echo $user['id']; ?>
                        </div>

                        <div class="user-stats">
                            <div class="user-stat-item">
                                <div class="user-stat-number"><?php echo $totalOrders; ?></div>
                                <div class="user-stat-label">Всього замовлень</div>
                            </div>
                            <div class="user-stat-item">
                                <div class="user-stat-number"><?php echo $totalComments; ?></div>
                                <div class="user-stat-label">Коментарів</div>
                            </div>
                        </div>

                        <div class="user-profile-info">
                            <div class="user-profile-item">
                                <i class="bi bi-envelope"></i>
                                <div class="user-profile-label">Email:</div>
                                <div class="user-profile-value"><?php echo safeEcho($user['email']); ?></div>
                            </div>
                            <div class="user-profile-item">
                                <i class="bi bi-telephone"></i>
                                <div class="user-profile-label">Телефон:</div>
                                <div class="user-profile-value">
                                    <?php echo !empty($user['phone']) ? safeEcho($user['phone']) : 'Не вказано'; ?>
                                </div>
                            </div>
                            <div class="user-profile-item">
                                <i class="bi bi-calendar-check"></i>
                                <div class="user-profile-label">Зареєстрований:</div>
                                <div class="user-profile-value">
                                    <?php echo formatDate($registrationDate); ?>
                                </div>
                            </div>
                            <div class="user-profile-item">
                                <i class="bi bi-clock-history"></i>
                                <div class="user-profile-label">З нами:</div>
                                <div class="user-profile-value">
                                    <?php echo getUserAge($registrationDate); ?>
                                </div>
                            </div>
                        </div>

                        <div class="user-actions">
                            <a href="settings.php" class="user-action-btn user-action-primary">
                                <i class="bi bi-pencil-square"></i> Редагувати профіль
                            </a>
                            <a href="orders.php" class="user-action-btn user-action-secondary">
                                <i class="bi bi-list-check"></i> Мої замовлення
                            </a>
                            <a href="create-order.php" class="user-action-btn user-action-secondary">
                                <i class="bi bi-plus-circle"></i> Створити замовлення
                            </a>
                        </div>
                    </div>

                    <!-- Права колонка - статистика та активність -->
                    <div class="user-activity-card">
                        <div class="activity-title">
                            <i class="bi bi-graph-up"></i> Статистика замовлень
                        </div>

                        <div class="activity-stats">
                            <div class="activity-stat-item">
                                <div class="activity-stat-icon activity-stat-icon-orange">
                                    <i class="bi bi-hourglass"></i>
                                </div>
                                <div class="activity-stat-content">
                                    <div class="activity-stat-number"><?php echo $statusCounts['new']; ?></div>
                                    <div class="activity-stat-label">Нові</div>
                                </div>
                            </div>
                            <div class="activity-stat-item">
                                <div class="activity-stat-icon activity-stat-icon-blue">
                                    <i class="bi bi-gear"></i>
                                </div>
                                <div class="activity-stat-content">
                                    <div class="activity-stat-number"><?php echo $statusCounts['in_progress']; ?></div>
                                    <div class="activity-stat-label">В роботі</div>
                                </div>
                            </div>
                            <div class="activity-stat-item">
                                <div class="activity-stat-icon activity-stat-icon-purple">
                                    <i class="bi bi-clock"></i>
                                </div>
                                <div class="activity-stat-content">
                                    <div class="activity-stat-number"><?php echo $statusCounts['waiting']; ?></div>
                                    <div class="activity-stat-label">Очікується</div>
                                </div>
                            </div>
                            <div class="activity-stat-item">
                                <div class="activity-stat-icon activity-stat-icon-teal">
                                    <i class="bi bi-truck"></i>
                                </div>
                                <div class="activity-stat-content">
                                    <div class="activity-stat-number"><?php echo $statusCounts['waiting_delivery']; ?></div>
                                    <div class="activity-stat-label">Очікується поставки</div>
                                </div>
                            </div>
                            <div class="activity-stat-item">
                                <div class="activity-stat-icon activity-stat-icon-green">
                                    <i class="bi bi-check-circle"></i>
                                </div>
                                <div class="activity-stat-content">
                                    <div class="activity-stat-number"><?php echo $statusCounts['completed']; ?></div>
                                    <div class="activity-stat-label">Завершено</div>
                                </div>
                            </div>
                            <div class="activity-stat-item">
                                <div class="activity-stat-icon activity-stat-icon-red">
                                    <i class="bi bi-x-circle"></i>
                                </div>
                                <div class="activity-stat-content">
                                    <div class="activity-stat-number"><?php echo $statusCounts['canceled']; ?></div>
                                    <div class="activity-stat-label">Не можливо виконати</div>
                                </div>
                            </div>
                        </div>

                        <div class="chart-container">
                            <div class="chart-title">Розподіл замовлень за статусом</div>

                            <div class="status-progress-container">
                                <?php if ($totalOrders > 0): ?>
                                    <div class="status-progress-item">
                                        <div class="status-progress-header">
                                            <div class="status-progress-label">Нові</div>
                                            <div class="status-progress-value"><?php echo $statusCounts['new']; ?> з <?php echo $totalOrders; ?></div>
                                        </div>
                                        <div class="status-progress-bar">
                                            <div class="status-progress-fill status-new-fill" style="width: <?php echo ($statusCounts['new'] / $totalOrders) * 100; ?>%"></div>
                                        </div>
                                    </div>

                                    <div class="status-progress-item">
                                        <div class="status-progress-header">
                                            <div class="status-progress-label">В роботі</div>
                                            <div class="status-progress-value"><?php echo $statusCounts['in_progress']; ?> з <?php echo $totalOrders; ?></div>
                                        </div>
                                        <div class="status-progress-bar">
                                            <div class="status-progress-fill status-in-progress-fill" style="width: <?php echo ($statusCounts['in_progress'] / $totalOrders) * 100; ?>%"></div>
                                        </div>
                                    </div>

                                    <div class="status-progress-item">
                                        <div class="status-progress-header">
                                            <div class="status-progress-label">Очікується</div>
                                            <div class="status-progress-value"><?php echo $statusCounts['waiting']; ?> з <?php echo $totalOrders; ?></div>
                                        </div>
                                        <div class="status-progress-bar">
                                            <div class="status-progress-fill status-waiting-fill" style="width: <?php echo ($statusCounts['waiting'] / $totalOrders) * 100; ?>%"></div>
                                        </div>
                                    </div>

                                    <div class="status-progress-item">
                                        <div class="status-progress-header">
                                            <div class="status-progress-label">Очікується поставки</div>
                                            <div class="status-progress-value"><?php echo $statusCounts['waiting_delivery']; ?> з <?php echo $totalOrders; ?></div>
                                        </div>
                                        <div class="status-progress-bar">
                                            <div class="status-progress-fill status-waiting-delivery-fill" style="width: <?php echo ($statusCounts['waiting_delivery'] / $totalOrders) * 100; ?>%"></div>
                                        </div>
                                    </div>

                                    <div class="status-progress-item">
                                        <div class="status-progress-header">
                                            <div class="status-progress-label">Завершено</div>
                                            <div class="status-progress-value"><?php echo $statusCounts['completed']; ?> з <?php echo $totalOrders; ?></div>
                                        </div>
                                        <div class="status-progress-bar">
                                            <div class="status-progress-fill status-completed-fill" style="width: <?php echo ($statusCounts['completed'] / $totalOrders) * 100; ?>%"></div>
                                        </div>
                                    </div>

                                    <div class="status-progress-item">
                                        <div class="status-progress-header">
                                            <div class="status-progress-label">Не можливо виконати</div>
                                            <div class="status-progress-value"><?php echo $statusCounts['canceled']; ?> з <?php echo $totalOrders; ?></div>
                                        </div>
                                        <div class="status-progress-bar">
                                            <div class="status-progress-fill status-canceled-fill" style="width: <?php echo ($statusCounts['canceled'] / $totalOrders) * 100; ?>%"></div>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="no-data-message">
                                        <p>Немає даних для відображення статистики.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="recent-orders">
                            <div class="activity-title">
                                <i class="bi bi-clock-history"></i> Останні замовлення
                            </div>

                            <?php if (!empty($latestOrders)): ?>
                                <table class="orders-table">
                                    <thead>
                                    <tr>
                                        <th>№</th>
                                        <th>Послуга</th>
                                        <th>Статус</th>
                                        <th>Дата</th>
                                        <th></th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($latestOrders as $order): ?>
                                        <tr>
                                            <td class="order-id">#<?php echo $order['id']; ?></td>
                                            <td><?php echo safeEcho($order['service']); ?></td>
                                            <td>
                                                <span class="status-badge <?php echo getStatusClass($order['status']); ?>">
                                                    <?php echo safeEcho($order['status']); ?>
                                                </span>
                                            </td>
                                            <td class="order-date"><?php echo formatDate($order['created_at']); ?></td>
                                            <td>
                                                <a href="orders.php?id=<?php echo $order['id']; ?>" class="order-link">
                                                    <i class="bi bi-eye"></i> Деталі
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>

                                <div style="text-align: center; margin-top: 20px;">
                                    <a href="orders.php" class="user-action-btn user-action-secondary" style="display: inline-flex;">
                                        <i class="bi bi-list-ul"></i> Переглянути всі замовлення
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="no-data-message" style="text-align: center; padding: 20px;">
                                    <i class="bi bi-clipboard-x" style="font-size: 2rem; color: #888; margin-bottom: 10px; display: block;"></i>
                                    <p>У вас ще немає замовлень.</p>
                                    <a href="create-order.php" class="user-action-btn user-action-primary" style="display: inline-flex; margin-top: 10px;">
                                        <i class="bi bi-plus-circle"></i> Створити замовлення
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
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

        // Автоматичне закриття сповіщень
        const alerts = document.querySelectorAll('.alert');
        if (alerts.length > 0) {
            setTimeout(function() {
                alerts.forEach(alert => {
                    alert.style.opacity = '0';
                    setTimeout(() => {
                        alert.style.display = 'none';
                    }, 300);
                });
            }, 5000);
        }

        // Застосовуємо поточну тему при завантаженні сторінки
        const currentTheme = document.documentElement.getAttribute('data-theme');
        if (currentTheme) {
            applyTheme(currentTheme);
        }
    });
</script>
</body>
</html>