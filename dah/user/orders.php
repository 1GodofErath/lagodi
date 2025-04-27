<?php
/**
 * Lagodi Service - Сторінка замовлень користувача
 * Версія: 1.0.0
 * Дата останнього оновлення: 2025-04-26
 * Автор: 1GodofErath
 */

// Підключення необхідних файлів
require_once $_SERVER['DOCUMENT_ROOT'] . '/dah/confi/database.php'; // Виправлений шлях включає /dah/
require_once $_SERVER['DOCUMENT_ROOT'] . '/dah/include/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dah/include/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dah/include/session.php';

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

// З'єднання з базою даних
$database = new Database();
$db = $database->getConnection();

// Перевірка, чи вказано ID замовлення
$order_id = isset($_GET['id']) ? intval($_GET['id']) : null;

// Отримання непрочитаних повідомлень
$unread_count = getUnreadNotificationsCount($user['id']);

// Встановлюємо заголовок сторінки
$page_title = $order_id ? "Деталі замовлення #$order_id" : "Мої замовлення";

// Якщо вказано ID замовлення, отримуємо детальну інформацію
if ($order_id) {
    // Отримуємо деталі замовлення
    $order = getOrderDetails($order_id, $user['id']);
    
    // Перевіряємо, чи існує замовлення та чи належить воно поточному користувачу
    if (!$order) {
        // Замовлення не знайдено або користувач не має до нього доступу
        header("Location: /user/orders.php?error=not_found");
        exit;
    }
    
    // Отримуємо коментарі до замовлення
    $comments = getOrderComments($order_id);
    
    // Отримуємо файли, прикріплені до замовлення
    $query = "SELECT * FROM order_media WHERE order_id = :order_id ORDER BY uploaded_at DESC";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':order_id', $order_id);
    $stmt->execute();
    $media_files = $stmt->fetchAll();
    
    // Отримуємо історію замовлення
    $query = "SELECT oh.*, u.username, u.first_name, u.last_name, u.role
              FROM order_history oh
              LEFT JOIN users u ON oh.user_id = u.id
              WHERE oh.order_id = :order_id
              ORDER BY oh.changed_at DESC";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':order_id', $order_id);
    $stmt->execute();
    $order_history = $stmt->fetchAll();
} else {
    // Отримуємо список замовлень з пагінацією та фільтрацією
    
    // Параметри пагінації
    $page = isset($_GET['page']) && is_numeric($_GET['page']) ? intval($_GET['page']) : 1;
    $per_page = 10;
    $offset = ($page - 1) * $per_page;

    // Параметри фільтрації
    $filters = [
        'status' => isset($_GET['status']) ? $_GET['status'] : '',
        'service' => isset($_GET['service']) ? $_GET['service'] : '',
        'date_from' => isset($_GET['date_from']) ? $_GET['date_from'] : '',
        'date_to' => isset($_GET['date_to']) ? $_GET['date_to'] : '',
        'search' => isset($_GET['search']) ? $_GET['search'] : ''
    ];

    // Отримуємо список замовлень
    $orders = getUserOrders($user['id'], $per_page, $offset, $filters);

    // Отримуємо загальну кількість замовлень для пагінації
    $query = "SELECT COUNT(*) as total FROM orders WHERE user_id = :user_id";
    $where_conditions = ["user_id = :user_id"];
    $params = [':user_id' => $user['id']];

    // Додаємо фільтри до запиту
    if (!empty($filters['status'])) {
        $where_conditions[] = "status = :status";
        $params[':status'] = $filters['status'];
    }

    if (!empty($filters['service'])) {
        $where_conditions[] = "service = :service";
        $params[':service'] = $filters['service'];
    }

    if (!empty($filters['date_from'])) {
        $where_conditions[] = "created_at >= :date_from";
        $params[':date_from'] = $filters['date_from'] . " 00:00:00";
    }

    if (!empty($filters['date_to'])) {
        $where_conditions[] = "created_at <= :date_to";
        $params[':date_to'] = $filters['date_to'] . " 23:59:59";
    }

    if (!empty($filters['search'])) {
        $where_conditions[] = "(id LIKE :search OR service LIKE :search_like OR details LIKE :search_like)";
        $params[':search'] = $filters['search'];
        $params[':search_like'] = '%' . $filters['search'] . '%';
    }

    $query = "SELECT COUNT(*) as total FROM orders WHERE " . implode(' AND ', $where_conditions);
    $stmt = $db->prepare($query);

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    $stmt->execute();
    $count_result = $stmt->fetch();
    $total_orders = $count_result['total'];

    $total_pages = ceil($total_orders / $per_page);

    // Отримуємо список статусів для фільтрації
    $query = "SELECT DISTINCT status FROM orders WHERE user_id = :user_id ORDER BY 
              CASE 
                WHEN status = 'Новий' THEN 1
                WHEN status = 'В роботі' THEN 2
                WHEN status = 'Очікується поставки товару' THEN 3
                WHEN status = 'Виконано' THEN 4
                ELSE 5
              END";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $user['id']);
    $stmt->execute();
    $statuses = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Отримуємо список сервісів для фільтрації
    $query = "SELECT DISTINCT service FROM orders WHERE user_id = :user_id ORDER BY service";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $user['id']);
    $stmt->execute();
    $services = $stmt->fetchAll(PDO::FETCH_COLUMN);
}
?>

<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Lagodi Service</title>

    <!-- CSS файли -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../style/dahm/dah2.css">
    <link rel="stylesheet" href="../style/dahm/dash1.css">

    <!-- Підключення теми користувача -->
    <?php if (isset($user['theme']) && $user['theme'] == 'dark'): ?>
        <link rel="stylesheet" href="../style/dahm/themes/dark.css">
    <?php else: ?>
        <link rel="stylesheet" href="../style/dahm/themes/light.css">
    <?php endif; ?>

    <!-- Додаткові стилі для деталей замовлення -->
    <?php if ($order_id): ?>
        <style>
            .comment {
                margin-bottom: 1rem;
                padding: 1rem;
                border-radius: 0.5rem;
                border: 1px solid #dee2e6;
            }

            .comment-header {
                display: flex;
                align-items: center;
                margin-bottom: 0.5rem;
            }

            .comment-avatar {
                width: 40px;
                height: 40px;
                border-radius: 50%;
                overflow: hidden;
                margin-right: 10px;
            }

            .comment-avatar img {
                width: 100%;
                height: 100%;
                object-fit: cover;
            }

            .avatar-placeholder {
                width: 100%;
                height: 100%;
                display: flex;
                align-items: center;
                justify-content: center;
                font-weight: bold;
                color: white;
                background-color: #007bff;
            }

            .comment-author {
                font-weight: bold;
            }

            .comment-time {
                font-size: 0.8rem;
                color: #6c757d;
            }

            .admin-comment {
                border-left: 4px solid #007bff;
            }

            .timeline {
                position: relative;
                margin-bottom: 1.5rem;
            }

            .timeline-item {
                position: relative;
                padding-left: 40px;
                margin-bottom: 20px;
            }

            .timeline-item::before {
                content: "";
                position: absolute;
                left: 10px;
                top: 0;
                bottom: 0;
                width: 2px;
                background-color: #dee2e6;
            }

            .timeline-item::after {
                content: "";
                position: absolute;
                left: 6px;
                top: 6px;
                width: 10px;
                height: 10px;
                border-radius: 50%;
                background-color: #007bff;
            }

            .media-gallery {
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
                margin-top: 1rem;
            }

            .media-item {
                width: 100px;
                height: 100px;
                border-radius: 0.25rem;
                overflow: hidden;
                position: relative;
            }

            .media-item img {
                width: 100%;
                height: 100%;
                object-fit: cover;
            }

            .media-item-overlay {
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.5);
                display: flex;
                align-items: center;
                justify-content: center;
                opacity: 0;
                transition: opacity 0.3s;
            }

            .media-item:hover .media-item-overlay {
                opacity: 1;
            }

            .media-item-icon {
                color: white;
                font-size: 24px;
            }

            .document-item {
                display: flex;
                align-items: center;
                padding: 0.5rem;
                border: 1px solid #dee2e6;
                border-radius: 0.25rem;
                margin-bottom: 0.5rem;
            }

            .document-icon {
                font-size: 24px;
                margin-right: 10px;
            }

            .document-info {
                flex-grow: 1;
            }

            .document-name {
                font-weight: bold;
                margin-bottom: 0;
            }

            .document-size {
                font-size: 0.8rem;
                color: #6c757d;
            }
        </style>
    <?php endif; ?>
</head>
<body>
<!-- Меню навігації -->
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container">
        <a class="navbar-brand" href="/">Lagodi Service</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link" href="/dah/user/dashboard.php">
                        <i class="bi bi-speedometer2"></i> Дашборд
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="/user/orders.php">
                        <i class="bi bi-list-check"></i> Мої замовлення
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="/dah/user/profile.php">
                        <i class="bi bi-person"></i> Профіль
                    </a>
                </li>
            </ul>
            <ul class="navbar-nav">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="notificationsDropdown" role="button" data-bs-toggle="dropdown">
                        <i class="bi bi-bell"></i> Сповіщення
                        <?php if ($unread_count > 0): ?>
                            <span class="badge bg-danger"><?php echo $unread_count; ?></span>
                        <?php endif; ?>
                    </a>
                    <div class="dropdown-menu dropdown-menu-end notification-dropdown" aria-labelledby="notificationsDropdown">
                        <!-- Вміст буде заповнено через JavaScript -->
                    </div>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
                        <?php if (!empty($user['profile_pic'])): ?>
                            <img src="<?php echo htmlspecialchars($user['profile_pic']); ?>" alt="Аватар" class="navbar-avatar rounded-circle" width="24" height="24">
                        <?php else: ?>
                            <i class="bi bi-person-circle"></i>
                        <?php endif; ?>
                        <?php echo htmlspecialchars($user['username']); ?>
                    </a>
                    <div class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                        <a class="dropdown-item" href="/dah/user/profile.php">
                            <i class="bi bi-person"></i> Профіль
                        </a>
                        <a class="dropdown-item" href="/user/settings.php">
                            <i class="bi bi-gear"></i> Налаштування
                        </a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item" href="/logout.php">
                            <i class="bi bi-box-arrow-right"></i> Вихід
                        </a>
                    </div>
                </li>
            </ul>
        </div>
    </div>
</nav>

<!-- Основний контент -->
<div class="container mt-4">
    <div class="row">
        <!-- Бокова панель -->
        <div class="col-lg-3 mb-4">
            <div class="card">
                <div class="card-body text-center">
                    <div class="user-avatar mb-3">
                        <?php if (!empty($user['profile_pic'])): ?>
                            <img src="<?php echo htmlspecialchars($user['profile_pic']); ?>" alt="Фото профілю" class="rounded-circle img-fluid">
                        <?php else: ?>
                            <div class="avatar-placeholder rounded-circle">
                                <?php echo strtoupper(substr($user['username'], 0, 1)); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <h5 class="card-title user-name">
                        <?php echo !empty($user['first_name']) ?
                            htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) :
                            htmlspecialchars($user['username']); ?>
                    </h5>
                    <p class="card-text text-muted user-email">
                        <?php echo htmlspecialchars($user['email']); ?>
                    </p>
                    <a href="/dah/user/profile.php" class="btn btn-sm btn-outline-primary">
                        Редагувати профіль
                    </a>
                </div>
                <ul class="list-group list-group-flush">
                    <li class="list-group-item">
                        <a href="/dah/user/dashboard.php" class="sidebar-link">
                            <i class="bi bi-speedometer2"></i> Дашборд
                        </a>
                    </li>
                    <li class="list-group-item">
                        <a href="/user/orders.php" class="sidebar-link active">
                            <i class="bi bi-list-check"></i> Мої замовлення
                        </a>
                    </li>
                    <li class="list-group-item">
                        <a href="/dah/user/notifications.php" class="sidebar-link">
                            <i class="bi bi-bell"></i> Сповіщення
                            <?php if ($unread_count > 0): ?>
                                <span class="badge bg-danger float-end"><?php echo $unread_count; ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li class="list-group-item">
                        <a href="/dah/user/profile.php" class="sidebar-link">
                            <i class="bi bi-person"></i> Профіль
                        </a>
                    </li>
                    <li class="list-group-item">
                        <a href="/user/settings.php" class="sidebar-link">
                            <i class="bi bi-gear"></i> Налаштування
                        </a>
                    </li>
                    <li class="list-group-item">
                        <a href="/logout.php" class="sidebar-link text-danger">
                            <i class="bi bi-box-arrow-right"></i> Вихід
                        </a>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Основний контент -->
        <div class="col-lg-9">
            <?php if ($order_id): ?>
                <!-- Деталі замовлення -->
                <nav aria-label="breadcrumb" class="mb-4">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="/user/orders.php">Мої замовлення</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Замовлення #<?php echo $order_id; ?></li>
                    </ol>
                </nav>

                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">Деталі замовлення #<?php echo $order_id; ?></h5>
                        <span class="badge
                            <?php
                        switch ($order['status']) {
                            case 'Новий':
                                echo 'bg-info';
                                break;
                            case 'В роботі':
                                echo 'bg-warning text-dark';
                                break;
                            case 'Очікується поставки товару':
                                echo 'bg-secondary';
                                break;
                            case 'Виконано':
                                echo 'bg-success';
                                break;
                            default:
                                echo 'bg-primary';
                                break;
                        }
                        ?>">
                            <?php echo htmlspecialchars($order['status']); ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6>Інформація про замовлення</h6>
                                <table class="table table-sm">
                                    <tr>
                                        <th style="width: 35%">Послуга:</th>
                                        <td><?php echo htmlspecialchars($order['service']); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Тип пристрою:</th>
                                        <td><?php echo htmlspecialchars($order['device_type'] ?? 'Не вказано'); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Дата створення:</th>
                                        <td><?php echo date('d.m.Y H:i', strtotime($order['created_at'])); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Статус:</th>
                                        <td>
                                            <span class="badge
                                                <?php
                                            switch ($order['status']) {
                                                case 'Новий':
                                                    echo 'bg-info';
                                                    break;
                                                case 'В роботі':
                                                    echo 'bg-warning text-dark';
                                                    break;
                                                case 'Очікується поставки товару':
                                                    echo 'bg-secondary';
                                                    break;
                                                case 'Виконано':
                                                    echo 'bg-success';
                                                    break;
                                                default:
                                                    echo 'bg-primary';
                                                    break;
                                            }
                                            ?>">
                                                <?php echo htmlspecialchars($order['status']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php if (!empty($order['estimated_completion_date'])): ?>
                                        <tr>
                                            <th>Орієнтовна дата завершення:</th>
                                            <td><?php echo date('d.m.Y', strtotime($order['estimated_completion_date'])); ?></td>
                                        </tr>
                                    <?php endif; ?>
                                    <tr>
                                        <th>Пріоритет:</th>
                                        <td>
                                            <span class="badge
                                                <?php
                                            switch ($order['priority']) {
                                                case 'low':
                                                    echo 'bg-success';
                                                    break;
                                                case 'medium':
                                                    echo 'bg-warning text-dark';
                                                    break;
                                                case 'high':
                                                    echo 'bg-danger';
                                                    break;
                                                default:
                                                    echo 'bg-secondary';
                                                    break;
                                            }
                                            ?>">
                                                <?php
                                                switch ($order['priority']) {
                                                    case 'low':
                                                        echo 'Низький';
                                                        break;
                                                    case 'medium':
                                                        echo 'Середній';
                                                        break;
                                                    case 'high':
                                                        echo 'Високий';
                                                        break;
                                                    default:
                                                        echo 'Не визначено';
                                                        break;
                                                }
                                                ?>
                                            </span>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <h6>Контактна інформація</h6>
                                <table class="table table-sm">
                                    <tr>
                                        <th style="width: 35%">Контактна особа:</th>
                                        <td>
                                            <?php
                                            if (!empty($order['first_name']) || !empty($order['last_name'])) {
                                                echo htmlspecialchars(trim($order['first_name'] . ' ' . $order['last_name']));
                                            } else {
                                                echo htmlspecialchars($order['username']);
                                            }
                                            ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Телефон:</th>
                                        <td><?php echo htmlspecialchars($order['phone']); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Спосіб доставки:</th>
                                        <td><?php echo htmlspecialchars($order['delivery_method']); ?></td>
                                    </tr>
                                    <?php if (!empty($order['address'])): ?>
                                        <tr>
                                            <th>Адреса:</th>
                                            <td><?php echo nl2br(htmlspecialchars($order['address'])); ?></td>
                                        </tr>
                                    <?php endif; ?>
                                </table>
                            </div>
                        </div>

                        <h6>Опис проблеми</h6>
                        <div class="card mb-3">
                            <div class="card-body">
                                <?php echo nl2br(htmlspecialchars($order['details'])); ?>
                            </div>
                        </div>

                        <?php if (!empty($order['user_comment'])): ?>
                            <h6>Додатковий коментар</h6>
                            <div class="card mb-3">
                                <div class="card-body">
                                    <?php echo nl2br(htmlspecialchars($order['user_comment'])); ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($media_files)): ?>
                            <h6>Медіа файли</h6>
                            <div class="card mb-3">
                                <div class="card-body">
                                    <div class="media-gallery">
                                        <?php foreach ($media_files as $file): ?>
                                            <?php
                                            $file_type = $file['file_type'] ?? pathinfo($file['file_path'], PATHINFO_EXTENSION);
                                            $is_image = strpos($file_type, 'image/') !== false ||
                                                in_array(strtolower(pathinfo($file['file_path'], PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif', 'webp']);
                                            $is_video = strpos($file_type, 'video/') !== false ||
                                                in_array(strtolower(pathinfo($file['file_path'], PATHINFO_EXTENSION)), ['mp4', 'webm', 'ogg']);

                                            if ($is_image):
                                                ?>
                                                <div class="media-item">
                                                    <img src="<?php echo htmlspecialchars($file['file_path']); ?>" alt="<?php echo htmlspecialchars($file['original_name'] ?? $file['file_name']); ?>">
                                                    <div class="media-item-overlay">
                                                        <a href="<?php echo htmlspecialchars($file['file_path']); ?>" target="_blank" class="media-item-icon">
                                                            <i class="bi bi-zoom-in"></i>
                                                        </a>
                                                    </div>
                                                </div>
                                            <?php elseif ($is_video): ?>
                                                <div class="media-item">
                                                    <div style="background-color: #000; width: 100%; height: 100%; display: flex; align-items: center; justify-content: center;">
                                                        <i class="bi bi-play-circle" style="font-size: 32px; color: #fff;"></i>
                                                    </div>
                                                    <div class="media-item-overlay">
                                                        <a href="<?php echo htmlspecialchars($file['file_path']); ?>" target="_blank" class="media-item-icon">
                                                            <i class="bi bi-play"></i>
                                                        </a>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <div class="document-item">
                                                    <div class="document-icon">
                                                        <i class="bi bi-file-earmark"></i>
                                                    </div>
                                                    <div class="document-info">
                                                        <p class="document-name"><?php echo htmlspecialchars($file['original_name'] ?? $file['file_name']); ?></p>
                                                        <p class="document-size">
                                                            <?php
                                                            // Відображення розміру файлу
                                                            $size = $file['file_size'] ?? 0;
                                                            if ($size < 1024) {
                                                                echo $size . ' B';
                                                            } elseif ($size < 1024 * 1024) {
                                                                echo round($size / 1024, 2) . ' KB';
                                                            } else {
                                                                echo round($size / (1024 * 1024), 2) . ' MB';
                                                            }
                                                            ?>
                                                        </p>
                                                    </div>
                                                    <a href="<?php echo htmlspecialchars($file['file_path']); ?>" class="btn btn-sm btn-outline-primary ms-auto" target="_blank" download>
                                                        <i class="bi bi-download"></i>
                                                    </a>
                                                </div>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($order_history)): ?>
                            <h6>Історія замовлення</h6>
                            <div class="card mb-3">
                                <div class="card-body">
                                    <div class="timeline">
                                        <?php foreach ($order_history as $history): ?>
                                            <div class="timeline-item">
                                                <div class="timeline-item-header">
                                                    <strong>
                                                        <?php
                                                        $displayed_name = $history['first_name'] ? $history['first_name'] . ' ' . $history['last_name'] : $history['username'];
                                                        echo htmlspecialchars($displayed_name);

                                                        if ($history['role'] === 'admin' || $history['role'] === 'junior_admin') {
                                                            echo ' <span class="badge bg-primary">Адміністратор</span>';
                                                        }
                                                        ?>
                                                    </strong>
                                                    <span class="text-muted ms-2">
                                                    <?php echo date('d.m.Y H:i', strtotime($history['changed_at'])); ?>
                                                </span>
                                                </div>
                                                <div class="timeline-item-body mt-1">
                                                    <?php if (!empty($history['previous_status'])): ?>
                                                        Змінено статус:
                                                        <span class="badge bg-secondary"><?php echo htmlspecialchars($history['previous_status']); ?></span>
                                                        <i class="bi bi-arrow-right mx-1"></i>
                                                        <span class="badge
                                                        <?php
                                                        switch ($history['new_status']) {
                                                            case 'Новий':
                                                                echo 'bg-info';
                                                                break;
                                                            case 'В роботі':
                                                                echo 'bg-warning text-dark';
                                                                break;
                                                            case 'Очікується поставки товару':
                                                                echo 'bg-secondary';
                                                                break;
                                                            case 'Виконано':
                                                                echo 'bg-success';
                                                                break;
                                                            default:
                                                                echo 'bg-primary';
                                                                break;
                                                        }
                                                        ?>">
                                                        <?php echo htmlspecialchars($history['new_status']); ?>
                                                    </span>
                                                    <?php else: ?>
                                                        Створено замовлення зі статусом:
                                                        <span class="badge
                                                        <?php
                                                        switch ($history['new_status']) {
                                                            case 'Новий':
                                                                echo 'bg-info';
                                                                break;
                                                            case 'В роботі':
                                                                echo 'bg-warning text-dark';
                                                                break;
                                                            case 'Очікується поставки товару':
                                                                echo 'bg-secondary';
                                                                break;
                                                            case 'Виконано':
                                                                echo 'bg-success';
                                                                break;
                                                            default:
                                                                echo 'bg-primary';
                                                                break;
                                                        }
                                                        ?>">
                                                        <?php echo htmlspecialchars($history['new_status']); ?>
                                                    </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Коментарі -->
                        <h6>Коментарі</h6>
                        <div class="card mb-3">
                            <div class="card-body">
                                <div id="commentsContainer">
                                    <?php if (empty($comments)): ?>
                                        <div class="text-center my-4">Немає коментарів</div>
                                    <?php else: ?>
                                        <?php foreach ($comments as $comment): ?>
                                            <?php
                                            $is_admin = $comment['role'] === 'admin' || $comment['role'] === 'junior_admin';
                                            ?>
                                            <div class="comment mb-3 <?php echo $is_admin ? 'admin-comment' : ''; ?>">
                                                <div class="comment-header">
                                                    <div class="comment-avatar">
                                                        <?php if (!empty($comment['profile_pic'])): ?>
                                                            <img src="<?php echo htmlspecialchars($comment['profile_pic']); ?>" alt="Аватар">
                                                        <?php else: ?>
                                                            <div class="avatar-placeholder">
                                                                <?php echo strtoupper(substr($comment['username'], 0, 1)); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="ms-2">
                                                        <div class="comment-author">
                                                            <?php if ($is_admin): ?>
                                                                <span class="badge bg-primary me-1">Адмін</span>
                                                            <?php endif; ?>
                                                            <?php echo !empty($comment['first_name']) ?
                                                                htmlspecialchars($comment['first_name'] . ' ' . $comment['last_name']) :
                                                                htmlspecialchars($comment['username']); ?>
                                                        </div>
                                                        <div class="comment-time">
                                                            <?php echo date('d.m.Y H:i', strtotime($comment['created_at'])); ?>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="comment-body mt-2">
                                                    <?php echo nl2br(htmlspecialchars($comment['content'])); ?>
                                                </div>
                                                <?php if (!empty($comment['file_attachment'])): ?>
                                                    <div class="comment-attachment mt-2">
                                                        <a href="<?php echo htmlspecialchars($comment['file_attachment']); ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
                                                            <i class="bi bi-paperclip"></i> Вкладений файл
                                                        </a>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>

                                <!-- Форма додавання коментаря -->
                                <form id="commentForm" class="mt-4" onsubmit="return submitComment(this);">
                                    <input type="hidden" name="order_id" value="<?php echo $order_id; ?>">
                                    <div class="mb-3">
                                        <label for="commentContent" class="form-label">Додати коментар</label>
                                        <textarea class="form-control" id="commentContent" name="content" rows="3" required></textarea>
                                    </div>
                                    <div class="mb-3">
                                        <label for="commentFile" class="form-label">Додати файл (необов'язково)</label>
                                        <input type="file" class="form-control" id="commentFile" name="file_attachment">
                                        <div class="form-text">Підтримуються файли розміром до 5 МБ: зображення, документи, аудіо та відео.</div>
                                    </div>
                                    <button type="submit" class="btn btn-primary">Надіслати коментар</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <!-- Список замовлень -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Мої замовлення</h5>
                    </div>
                    <div class="card-body">
                        <!-- Фільтри -->
                        <form method="get" action="/user/orders.php" class="mb-4">
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label for="status" class="form-label">Статус</label>
                                    <select class="form-select" id="status" name="status">
                                        <option value="">Всі статуси</option>
                                        <?php foreach ($statuses as $status): ?>
                                            <option value="<?php echo htmlspecialchars($status); ?>" <?php echo $filters['status'] === $status ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($status); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label for="service" class="form-label">Послуга</label>
                                    <select class="form-select" id="service" name="service">
                                        <option value="">Всі послуги</option>
                                        <?php foreach ($services as $service): ?>
                                            <option value="<?php echo htmlspecialchars($service); ?>" <?php echo $filters['service'] === $service ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($service); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label for="date_from" class="form-label">Дата від</label>
                                    <input type="date" class="form-select" id="date_from" name="date_from" value="<?php echo htmlspecialchars($filters['date_from']); ?>">
                                </div>
                                <div class="col-md-3">
                                    <label for="date_to" class="form-label">Дата до</label>
                                    <input type="date" class="form-select" id="date_to" name="date_to" value="<?php echo htmlspecialchars($filters['date_to']); ?>">
                                </div>
                                <div class="col-md-9">
                                    <label for="search" class="form-label">Пошук</label>
                                    <input type="text" class="form-control" id="search" name="search" placeholder="Введіть номер замовлення, назву послуги або ключове слово" value="<?php echo htmlspecialchars($filters['search']); ?>">
                                </div>
                                <div class="col-md-3 d-flex align-items-end">
                                    <button type="submit" class="btn btn-primary me-2">Застосувати</button>
                                    <a href="/user/orders.php" class="btn btn-outline-secondary">Скинути</a>
                                </div>
                            </div>
                        </form>

                        <!-- Таблиця замовлень -->
                        <?php if (empty($orders)): ?>
                            <div class="alert alert-info">
                                <?php if (!empty($filters['status']) || !empty($filters['service']) || !empty($filters['date_from']) || !empty($filters['date_to']) || !empty($filters['search'])): ?>
                                    За вашим запитом не знайдено замовлень.
                                <?php else: ?>
                                    У вас ще немає замовлень. Створіть нове замовлення на сторінці дашборду.
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                    <tr>
                                        <th>№</th>
                                        <th>Послуга</th>
                                        <th>Статус</th>
                                        <th>Дата створення</th>
                                        <th>Остання зміна</th>
                                        <th>Дії</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($orders as $order): ?>
                                        <tr>
                                            <td><?php echo $order['id']; ?></td>
                                            <td><?php echo htmlspecialchars($order['service']); ?></td>
                                            <td>
                                                    <span class="badge
                                                        <?php
                                                    switch ($order['status']) {
                                                        case 'Новий':
                                                            echo 'bg-info';
                                                            break;
                                                        case 'В роботі':
                                                            echo 'bg-warning text-dark';
                                                            break;
                                                        case 'Очікується поставки товару':
                                                            echo 'bg-secondary';
                                                            break;
                                                        case 'Виконано':
                                                            echo 'bg-success';
                                                            break;
                                                        default:
                                                            echo 'bg-primary';
                                                            break;
                                                    }
                                                    ?>">
                                                        <?php echo htmlspecialchars($order['status']); ?>
                                                    </span>
                                            </td>
                                            <td><?php echo date('d.m.Y H:i', strtotime($order['created_at'])); ?></td>
                                            <td><?php echo date('d.m.Y H:i', strtotime($order['updated_at'])); ?></td>
                                            <td>
                                                <a href="/user/orders.php?id=<?php echo $order['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                    <i class="bi bi-eye"></i> Деталі
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Пагінація -->
                            <?php if ($total_pages > 1): ?>
                                <nav aria-label="Навігація по сторінках" class="mt-4">
                                    <ul class="pagination justify-content-center">
                                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="<?php echo $page <= 1 ? '#' : '?' . http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" aria-label="Попередня">
                                                <span aria-hidden="true">&laquo;</span>
                                            </a>
                                        </li>

                                        <?php
                                        // Визначаємо діапазон сторінок для відображення
                                        $start_page = max(1, $page - 2);
                                        $end_page = min($total_pages, $start_page + 4);

                                        if ($end_page - $start_page < 4) {
                                            $start_page = max(1, $end_page - 4);
                                        }

                                        for ($i = $start_page; $i <= $end_page; $i++):
                                            ?>
                                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                                <a class="page-link" href="<?php echo '?' . http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                                    <?php echo $i; ?>
                                                </a>
                                            </li>
                                        <?php endfor; ?>

                                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="<?php echo $page >= $total_pages ? '#' : '?' . http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" aria-label="Наступна">
                                                <span aria-hidden="true">&raquo;</span>
                                            </a>
                                        </li>
                                    </ul>
                                </nav>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Футер -->
<footer class="bg-dark text-white py-4 mt-5">
    <div class="container">
        <div class="row">
            <div class="col-md-4">
                <h5>Lagodi Service</h5>
                <p>Сервісний центр з ремонту та обслуговування техніки</p>
            </div>
            <div class="col-md-4">
                <h5>Контакти</h5>
                <ul class="list-unstyled">
                    <li><i class="bi bi-telephone"></i> +380 123 456 789</li>
                    <li><i class="bi bi-envelope"></i> info@lagodi.com</li>
                    <li><i class="bi bi-geo-alt"></i> м. Київ, вул. Прикладна, 123</li>
                </ul>
            </div>
            <div class="col-md-4">
                <h5>Посилання</h5>
                <ul class="list-unstyled">
                    <li><a href="/" class="text-white">Головна</a></li>
                    <li><a href="/services.php" class="text-white">Послуги</a></li>
                    <li><a href="/contacts.php" class="text-white">Контакти</a></li>
                </ul>
            </div>
        </div>
        <hr>
        <div class="text-center">
            <p>&copy; <?php echo date('Y'); ?> Lagodi Service. Всі права захищені.</p>
        </div>
    </div>
</footer>

<!-- Контейнер для сповіщень -->
<div id="toastContainer" class="position-fixed bottom-0 end-0 p-3" style="z-index: 1050;"></div>

<!-- JavaScript файли -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
<script src="/assets/js/dashboard.js"></script>
<script src="/assets/js/notifications.js"></script>

<?php if ($order_id): ?>
    <script>
        // Функція для відправки коментаря
        function submitComment(form) {
            const formData = new FormData(form);

            // Перевірка вмісту
            const content = formData.get('content');
            if (!content || content.trim() === '') {
                showNotification('Коментар не може бути порожнім', 'danger');
                return false;
            }

            // Перевірка файлу, якщо він є
            const file = form.querySelector('input[type="file"]').files[0];
            if (file) {
                // Перевірка розміру файлу (макс. 5 MB)
                const maxSize = 5 * 1024 * 1024; // 5 MB
                if (file.size > maxSize) {
                    showNotification('Розмір файлу не повинен перевищувати 5 МБ', 'danger');
                    return false;
                }
            }

            // Показуємо індикатор завантаження
            const submitButton = form.querySelector('button[type="submit"]');
            const originalButtonText = submitButton.innerText;
            submitButton.disabled = true;
            submitButton.innerHTML = `
                <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                <span class="ms-2">Надсилання...</span>
            `;

            // Відправляємо форму
            fetch('/api/comments/add.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Очищаємо форму
                        form.reset();

                        // Оновлюємо список коментарів
                        loadComments(<?php echo $order_id; ?>);

                        // Показуємо повідомлення про успіх
                        showNotification('Коментар успішно додано', 'success');
                    } else {
                        // Показуємо повідомлення про помилку
                        showNotification(data.message || 'Помилка при додаванні коментаря', 'danger');
                    }
                })
                .catch(error => {
                    console.error('Помилка при додаванні коментаря:', error);
                    showNotification('Помилка при з\'єднанні з сервером', 'danger');
                })
                .finally(() => {
                    // Відновлюємо кнопку
                    submitButton.disabled = false;
                    submitButton.innerText = originalButtonText;
                });

            return false;
        }

        // Функція для завантаження коментарів
        function loadComments(orderId) {
            const commentsContainer = document.getElementById('commentsContainer');

            if (!commentsContainer) return;

            // Показуємо індикатор завантаження
            commentsContainer.innerHTML = `
                <div class="text-center my-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Завантаження...</span>
                    </div>
                    <p class="mt-2">Завантаження коментарів...</p>
                </div>
            `;

            // Завантажуємо коментарі
            fetch(`/api/comments/get.php?order_id=${orderId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        if (data.comments.length === 0) {
                            commentsContainer.innerHTML = '<div class="text-center my-4">Немає коментарів</div>';
                            return;
                        }

                        // Очищаємо контейнер
                        commentsContainer.innerHTML = '';

                        // Додаємо коментарі
                        data.comments.forEach(comment => {
                            const isAdmin = comment.role === 'admin' || comment.role === 'junior_admin';
                            const displayName = comment.first_name ?
                                `${comment.first_name} ${comment.last_name}` :
                                comment.username;

                            const commentHtml = `
                                <div class="comment mb-3 ${isAdmin ? 'admin-comment' : ''}">
                                    <div class="comment-header">
                                        <div class="comment-avatar">
                                            ${comment.profile_pic ?
                                `<img src="${comment.profile_pic}" alt="Аватар">` :
                                `<div class="avatar-placeholder">${comment.username.charAt(0).toUpperCase()}</div>`
                            }
                                        </div>
                                        <div class="ms-2">
                                            <div class="comment-author">
                                                ${isAdmin ? '<span class="badge bg-primary me-1">Адмін</span>' : ''}
                                                ${displayName}
                                            </div>
                                            <div class="comment-time">
                                                ${new Date(comment.created_at).toLocaleString('uk-UA')}
                                            </div>
                                        </div>
                                    </div>
                                    <div class="comment-body mt-2">
                                        ${comment.content.replace(/\n/g, '<br>')}
                                    </div>
                                    ${comment.file_attachment ?
                                `<div class="comment-attachment mt-2">
                                            <a href="${comment.file_attachment}" target="_blank" class="btn btn-sm btn-outline-secondary">
                                                <i class="bi bi-paperclip"></i> Вкладений файл
                                            </a>
                                        </div>` :
                                ''
                            }
                                </div>
                            `;

                            commentsContainer.innerHTML += commentHtml;
                        });
                    } else {
                        commentsContainer.innerHTML = `
                            <div class="alert alert-danger">
                                ${data.message || 'Помилка при завантаженні коментарів'}
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Помилка при завантаженні коментарів:', error);
                    commentsContainer.innerHTML = `
                        <div class="alert alert-danger">
                            Помилка при з'єднанні з сервером
                        </div>
                    `;
                });
        }

        // Функція для відображення сповіщення
        function showNotification(message, type = 'info', duration = 3000) {
            const toastContainer = document.getElementById('toastContainer');

            const toastElement = document.createElement('div');
            toastElement.className = `toast align-items-center text-white bg-${type}`;
            toastElement.role = 'alert';
            toastElement.ariaLive = 'assertive';
            toastElement.ariaAtomic = 'true';
            toastElement.innerHTML = `
                <div class="d-flex">
                    <div class="toast-body">
                        ${message}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Закрити"></button>
                </div>
            `;

            toastContainer.appendChild(toastElement);

            const toast = new bootstrap.Toast(toastElement, {
                autohide: true,
                delay: duration
            });

            toast.show();

            // Видаляємо елемент після закриття
            toastElement.addEventListener('hidden.bs.toast', function() {
                toastElement.remove();
            });
        }

        // Ініціалізація галереї медіа файлів
        document.addEventListener('DOMContentLoaded', function() {
            // Перегляд зображень у повному розмірі при кліку
            const mediaItems = document.querySelectorAll('.media-item');
            mediaItems.forEach(item => {
                const img = item.querySelector('img');
                const link = item.querySelector('a');

                if (img && link) {
                    link.addEventListener('click', function(e) {
                        e.preventDefault();

                        // Створюємо модальне вікно для перегляду
                        const modal = document.createElement('div');
                        modal.className = 'modal fade';
                        modal.id = 'mediaModal';
                        modal.tabIndex = '-1';
                        modal.ariaHidden = 'true';

                        modal.innerHTML = `
                            <div class="modal-dialog modal-dialog-centered modal-lg">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Перегляд файлу</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрити"></button>
                                    </div>
                                    <div class="modal-body text-center">
                                        <img src="${link.href}" class="img-fluid" alt="Зображення">
                                    </div>
                                    <div class="modal-footer">
                                        <a href="${link.href}" class="btn btn-primary" download>Завантажити</a>
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрити</button>
                                    </div>
                                </div>
                            </div>
                        `;

                        document.body.appendChild(modal);

                        const modalInstance = new bootstrap.Modal(modal);
                        modalInstance.show();

                        // Видаляємо модальне вікно після закриття
                        modal.addEventListener('hidden.bs.modal', function() {
                            modal.remove();
                        });
                    });
                }
            });
        });
    </script>
<?php endif; ?>
</body>
</html>