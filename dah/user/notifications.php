<?php
/**
 * Lagodi Service - Сторінка сповіщень користувача
 * Версія: 1.0.0
 * Дата останнього оновлення: 2025-04-27 12:26:01
 * Автор: 1GodofErath
 */

// Підключення необхідних файлів з використанням абсолютних шляхів
require_once $_SERVER['DOCUMENT_ROOT'] . '/dah/confi/database.php'; // Виправлено шлях до папки confi
require_once $_SERVER['DOCUMENT_ROOT'] . '/dah/include/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dah/include/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/dah/include/session.php';

// Перевірка авторизації через JavaScript щоб уникнути Headers already sent
if (!isLoggedIn()) {
    echo '<script>window.location.href = "/login.php";</script>';
    exit;
}

// Отримання поточного користувача
$user = getCurrentUser();

// Перевірка, чи заблокований користувач
if (isUserBlocked($user['id'])) {
    echo '<script>window.location.href = "/logout.php?reason=blocked";</script>';
    exit;
}

// Підключення до бази даних
$database = new Database();
$db = $database->getConnection();

// Отримання непрочитаних повідомлень
$unread_count = getUnreadNotificationsCount($user['id']);

// Перевірка, чи передано ID конкретного сповіщення
$notification_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$notification = null;

// Параметри для фільтрації та пагінації
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Якщо передано ID сповіщення, отримуємо інформацію про нього
if ($notification_id > 0) {
    $query = "SELECT * FROM notifications WHERE id = :notification_id AND user_id = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':notification_id', $notification_id);
    $stmt->bindParam(':user_id', $user['id']);
    $stmt->execute();

    $notification = $stmt->fetch();

    if ($notification) {
        // Позначаємо сповіщення як прочитане
        if (!$notification['is_read']) {
            markNotificationAsRead($notification_id, $user['id']);
            $notification['is_read'] = 1;
        }

        $page_title = "Сповіщення: " . $notification['title'];
    } else {
        // Якщо сповіщення не знайдено або не належить користувачу
        $notification_id = 0;
        $page_title = "Сповіщення не знайдено";
    }
} else {
    // Отримуємо список сповіщень користувача з пагінацією та фільтрацією
    $whereClause = "WHERE user_id = :user_id";

    if ($filter === 'unread') {
        $whereClause .= " AND is_read = 0";
    } elseif ($filter === 'read') {
        $whereClause .= " AND is_read = 1";
    }

    $query = "SELECT * FROM notifications $whereClause ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
    $stmt = $db->prepare($query);

    $stmt->bindParam(':user_id', $user['id']);
    $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);

    $stmt->execute();

    $notifications = $stmt->fetchAll();

    // Отримуємо загальну кількість сповіщень для пагінації
    $query = "SELECT COUNT(*) as total FROM notifications $whereClause";
    $stmt = $db->prepare($query);

    $stmt->bindParam(':user_id', $user['id']);
    $stmt->execute();

    $total = $stmt->fetch();

    $total_pages = ceil($total['total'] / $limit);

    $page_title = "Мої сповіщення";
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
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/dashboard.css">

    <!-- Підключення теми користувача -->
    <?php if (isset($user['theme']) && $user['theme'] == 'dark'): ?>
        <link rel="stylesheet" href="/assets/css/themes/dark.css">
    <?php else: ?>
        <link rel="stylesheet" href="/assets/css/themes/light.css">
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
                    <a class="nav-link" href="/user/orders.php">
                        <i class="bi bi-list-check"></i> Мої замовлення
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="/user/profile.php">
                        <i class="bi bi-person"></i> Профіль
                    </a>
                </li>
            </ul>
            <ul class="navbar-nav">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle active" href="#" id="notificationsDropdown" role="button" data-bs-toggle="dropdown">
                        <i class="bi bi-bell"></i> Сповіщення
                        <?php if ($unread_count > 0): ?>
                            <span class="badge bg-danger"><?php echo $unread_count; ?></span>
                        <?php endif; ?>
                    </a>
                    <div class="dropdown-menu dropdown-menu-end notification-dropdown" aria-labelledby="notificationsDropdown">
                        <!-- Вміст буде динамічно завантажено через JavaScript -->
                        <h6 class="dropdown-header">Завантаження сповіщень...</h6>
                    </div>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle"></i>
                        <?php echo htmlspecialchars($user['username']); ?>
                    </a>
                    <div class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                        <a class="dropdown-item" href="/user/profile.php">
                            <i class="bi bi-person"></i> Профіль
                        </a>
                        <a class="dropdown-item" href="/user/settings.php">
                            <i class="bi bi-gear"></i> Налаштування
                        </a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item" href="#" id="logoutButton">
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
                    <h5 class="card-title">
                        <?php echo !empty($user['first_name']) ?
                            htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) :
                            htmlspecialchars($user['username']); ?>
                    </h5>
                    <p class="card-text text-muted">
                        <?php echo htmlspecialchars($user['email']); ?>
                    </p>
                </div>
                <ul class="list-group list-group-flush">
                    <li class="list-group-item">
                        <a href="/dah/user/dashboard.php" class="sidebar-link">
                            <i class="bi bi-speedometer2"></i> Дашборд
                        </a>
                    </li>
                    <li class="list-group-item">
                        <a href="/user/orders.php" class="sidebar-link">
                            <i class="bi bi-list-check"></i> Мої замовлення
                        </a>
                    </li>
                    <li class="list-group-item">
                        <a href="/user/notifications.php" class="sidebar-link active">
                            <i class="bi bi-bell"></i> Сповіщення
                            <?php if ($unread_count > 0): ?>
                                <span class="badge bg-danger float-end"><?php echo $unread_count; ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li class="list-group-item">
                        <a href="/user/profile.php" class="sidebar-link">
                            <i class="bi bi-person"></i> Профіль
                        </a>
                    </li>
                    <li class="list-group-item">
                        <a href="/user/settings.php" class="sidebar-link">
                            <i class="bi bi-gear"></i> Налаштування
                        </a>
                    </li>
                    <li class="list-group-item">
                        <a href="#" class="sidebar-link text-danger" id="sidebarLogoutButton">
                            <i class="bi bi-box-arrow-right"></i> Вихід
                        </a>
                    </li>
                </ul>
            </div>

            <?php if (!$notification_id): ?>
                <!-- Фільтри для списку сповіщень -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Фільтри</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="/user/notifications.php?filter=all" class="btn <?php echo $filter === 'all' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                                Всі сповіщення
                            </a>
                            <a href="/user/notifications.php?filter=unread" class="btn <?php echo $filter === 'unread' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                                Непрочитані
                            </a>
                            <a href="/user/notifications.php?filter=read" class="btn <?php echo $filter === 'read' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                                Прочитані
                            </a>
                        </div>

                        <?php if ($unread_count > 0): ?>
                            <div class="d-grid mt-3">
                                <button type="button" class="btn btn-success" id="markAllAsReadBtn">
                                    <i class="bi bi-check-all"></i> Позначити всі як прочитані
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Основний контент -->
        <div class="col-lg-9">
            <?php if ($notification_id && $notification): ?>
                <!-- Детальна інформація про сповіщення -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">
                            <?php echo htmlspecialchars($notification['title']); ?>
                        </h5>
                        <a href="/user/notifications.php" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-arrow-left"></i> Назад до списку
                        </a>
                    </div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between mb-3">
                            <span class="text-muted">
                                <i class="bi bi-clock"></i>
                                <?php echo date('d.m.Y H:i', strtotime($notification['created_at'])); ?>
                            </span>
                            <span class="badge <?php echo $notification['is_read'] ? 'bg-secondary' : 'bg-primary'; ?>">
                                <?php echo $notification['is_read'] ? 'Прочитано' : 'Непрочитано'; ?>
                            </span>
                        </div>

                        <?php if ($notification['order_id']): ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i>
                                Це сповіщення пов'язане із замовленням
                                <a href="/user/orders.php?id=<?php echo $notification['order_id']; ?>" class="alert-link">
                                    #<?php echo $notification['order_id']; ?>
                                </a>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($notification['content'])): ?>
                            <div class="card mb-3">
                                <div class="card-body">
                                    <?php echo nl2br(htmlspecialchars($notification['content'])); ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($notification['description'])): ?>
                            <h6>Додаткова інформація</h6>
                            <div class="card mb-3">
                                <div class="card-body">
                                    <?php echo nl2br(htmlspecialchars($notification['description'])); ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($notification['comment_id'] && $notification['type'] === 'new_comment'): ?>
                            <h6>Коментар</h6>
                            <div class="card mb-3">
                                <div class="card-header">
                                    <div class="d-flex align-items-center">
                                        <i class="bi bi-person-circle me-2"></i>
                                        <span>
                                        <?php echo htmlspecialchars($notification['comment_author'] ?? 'Користувач'); ?>
                                    </span>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <?php echo nl2br(htmlspecialchars($notification['content'])); ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($notification['order_id']): ?>
                            <div class="d-grid">
                                <a href="/user/orders.php?id=<?php echo $notification['order_id']; ?>" class="btn btn-primary">
                                    <i class="bi bi-eye"></i> Переглянути замовлення
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <!-- Список сповіщень -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">
                            Мої сповіщення
                            <?php if ($filter === 'unread' && $unread_count > 0): ?>
                                <span class="badge bg-danger"><?php echo $unread_count; ?></span>
                            <?php endif; ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($notifications)): ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i>
                                У вас поки немає сповіщень<?php echo $filter !== 'all' ? ' з вибраним фільтром' : ''; ?>.
                            </div>
                        <?php else: ?>
                            <div class="list-group">
                                <?php foreach ($notifications as $item): ?>
                                    <a href="/user/notifications.php?id=<?php echo $item['id']; ?>" class="list-group-item list-group-item-action <?php echo $item['is_read'] ? '' : 'unread'; ?>">
                                        <div class="d-flex w-100 justify-content-between">
                                            <h6 class="mb-1"><?php echo htmlspecialchars($item['title']); ?></h6>
                                            <small class="text-muted"><?php echo date('d.m.Y H:i', strtotime($item['created_at'])); ?></small>
                                        </div>
                                        <p class="mb-1">
                                            <?php echo htmlspecialchars(substr($item['content'], 0, 100)) . (strlen($item['content']) > 100 ? '...' : ''); ?>
                                        </p>
                                        <div class="d-flex justify-content-between align-items-center">
                                            <small>
                                                <?php if ($item['order_id']): ?>
                                                    <i class="bi bi-box-seam"></i> Замовлення #<?php echo $item['order_id']; ?>
                                                <?php endif; ?>
                                            </small>
                                            <span class="badge <?php echo $item['is_read'] ? 'bg-secondary' : 'bg-primary'; ?>">
                                            <?php echo $item['is_read'] ? 'Прочитано' : 'Непрочитано'; ?>
                                        </span>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </div>

                            <!-- Пагінація -->
                            <?php if ($total_pages > 1): ?>
                                <nav aria-label="Пагінація сповіщень" class="mt-4">
                                    <ul class="pagination justify-content-center">
                                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?filter=<?php echo $filter; ?>&page=<?php echo $page - 1; ?>" aria-label="Попередня">
                                                <span aria-hidden="true">&laquo;</span>
                                            </a>
                                        </li>

                                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                                <a class="page-link" href="?filter=<?php echo $filter; ?>&page=<?php echo $i; ?>">
                                                    <?php echo $i; ?>
                                                </a>
                                            </li>
                                        <?php endfor; ?>

                                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?filter=<?php echo $filter; ?>&page=<?php echo $page + 1; ?>" aria-label="Наступна">
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

<!-- Модальне вікно для підтвердження виходу -->
<div class="modal fade" id="logoutConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Підтвердження виходу</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Ви дійсно бажаєте вийти з системи?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Скасувати</button>
                <a href="/logout.php" class="btn btn-danger">Вийти</a>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript файли -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
<script src="/assets/js/notifications.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Обробник для кнопки виходу
        document.getElementById('logoutButton').addEventListener('click', function(e) {
            e.preventDefault();
            const logoutModal = new bootstrap.Modal(document.getElementById('logoutConfirmModal'));
            logoutModal.show();
        });

        document.getElementById('sidebarLogoutButton').addEventListener('click', function(e) {
            e.preventDefault();
            const logoutModal = new bootstrap.Modal(document.getElementById('logoutConfirmModal'));
            logoutModal.show();
        });

        // Обробник для кнопки "Позначити всі як прочитані"
        const markAllBtn = document.getElementById('markAllAsReadBtn');
        if (markAllBtn) {
            markAllBtn.addEventListener('click', function() {
                fetch('/api/notifications/mark-all-read.php', {
                    method: 'POST'
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Оновлюємо сторінку
                            window.location.reload();
                        } else {
                            showNotification(data.message || 'Помилка при позначенні сповіщень як прочитаних', 'danger');
                        }
                    })
                    .catch(error => {
                        console.error('Помилка при позначенні сповіщень як прочитаних:', error);
                        showNotification('Помилка при з\'єднанні з сервером', 'danger');
                    });
            });
        }
    });

    function showNotification(message, type = 'info', duration = 3000) {
        const toastContainer = document.getElementById('toastContainer');

        const toastElement = document.createElement('div');
        toastElement.className = `toast align-items-center text-white bg-${type}`;
        toastElement.setAttribute('role', 'alert');
        toastElement.setAttribute('aria-live', 'assertive');
        toastElement.setAttribute('aria-atomic', 'true');

        toastElement.innerHTML = `
            <div class="d-flex">
                <div class="toast-body">${message}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Закрити"></button>
            </div>
        `;

        toastContainer.appendChild(toastElement);

        const toast = new bootstrap.Toast(toastElement, {
            autohide: true,
            delay: duration
        });

        toast.show();

        toastElement.addEventListener('hidden.bs.toast', function() {
            toastElement.remove();
        });
    }
</script>
</body>
</html>