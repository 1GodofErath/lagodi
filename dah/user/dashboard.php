<?php
/**
 * Lagodi Service - Особистий кабінет користувача
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

// Отримання статистики по замовленням користувача
$database = new Database();
$db = $database->getConnection();

$query = "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'Новий' THEN 1 ELSE 0 END) as new,
            SUM(CASE WHEN status = 'В роботі' THEN 1 ELSE 0 END) as in_progress,
            SUM(CASE WHEN status = 'Очікується поставки товару' THEN 1 ELSE 0 END) as waiting,
            SUM(CASE WHEN status = 'Виконано' THEN 1 ELSE 0 END) as completed
          FROM orders
          WHERE user_id = :user_id";

$stmt = $db->prepare($query);
$stmt->bindParam(':user_id', $user['id']);
$stmt->execute();

$stats = $stmt->fetch();

// Отримання останніх замовлень
$query = "SELECT * FROM orders WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 5";
$stmt = $db->prepare($query);
$stmt->bindParam(':user_id', $user['id']);
$stmt->execute();

$recent_orders = $stmt->fetchAll();

// Отримання непрочитаних повідомлень
$unread_count = getUnreadNotificationsCount($user['id']);

// Отримання останніх повідомлень
$notifications = getUserNotifications($user['id'], 5);

// Отримання доступних сервісів
$services = getServices();
$service_categories = getServiceCategories();

// Встановлюємо заголовок сторінки
$page_title = "Особистий кабінет";
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
                        <a class="nav-link active" href="/dah/dashboard.php">
                            <i class="bi bi-speedometer2"></i> Дашборд
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/dah/user/orders.php">
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
                        <a class="nav-link dropdown-toggle" href="#" id="notificationsDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-bell"></i> Сповіщення
                            <?php if ($unread_count > 0): ?>
                            <span class="badge bg-danger"><?php echo $unread_count; ?></span>
                            <?php endif; ?>
                        </a>
                        <div class="dropdown-menu dropdown-menu-end notification-dropdown" aria-labelledby="notificationsDropdown">
                            <h6 class="dropdown-header">Сповіщення</h6>
                            <?php if (empty($notifications)): ?>
                                <div class="dropdown-item text-center">Немає нових сповіщень</div>
                            <?php else: ?>
                                <?php foreach ($notifications as $notification): ?>
                                <a class="dropdown-item <?php echo $notification['is_read'] ? '' : 'unread'; ?>" 
                                   href="/dah/user/notifications.php?id=<?php echo $notification['id']; ?>">
                                    <div class="notification-item">
                                        <div class="notification-title"><?php echo htmlspecialchars($notification['title']); ?></div>
                                        <div class="notification-time">
                                            <?php echo date('d.m.Y H:i', strtotime($notification['created_at'])); ?>
                                        </div>
                                        <div class="notification-text">
                                            <?php echo htmlspecialchars(substr($notification['content'], 0, 50)) . 
                                                (strlen($notification['content']) > 50 ? '...' : ''); ?>
                                        </div>
                                    </div>
                                </a>
                                <?php endforeach; ?>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item text-center" href="/dah/user/notifications.php">
                                    Усі сповіщення
                                </a>
                            <?php endif; ?>
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
                            <a class="dropdown-item" href="/user/profile.php">
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
                        <a href="/user/profile.php" class="btn btn-sm btn-outline-primary">
                            Редагувати профіль
                        </a>
                    </div>
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item">
                            <a href="/dah/dashboard.php" class="sidebar-link active">
                                <i class="bi bi-speedometer2"></i> Дашборд
                            </a>
                        </li>
                        <li class="list-group-item">
                            <a href="/dah/user/orders.php" class="sidebar-link">
                                <i class="bi bi-list-check"></i> Мої замовлення
                                <span class="badge bg-primary float-end"><?php echo $stats['total']; ?></span>
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
                            <a href="/logout.php" class="sidebar-link text-danger">
                                <i class="bi bi-box-arrow-right"></i> Вихід
                            </a>
                        </li>
                    </ul>
                </div>
                
                <!-- Перемикач теми -->
                <div class="card mt-3">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Тема сайту</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <button class="btn btn-sm theme-switcher <?php echo $user['theme'] !== 'dark' ? 'btn-primary' : 'btn-outline-primary'; ?>" data-theme="light">
                                <i class="bi bi-sun"></i> Світла
                            </button>
                            <button class="btn btn-sm theme-switcher <?php echo $user['theme'] === 'dark' ? 'btn-primary' : 'btn-outline-primary'; ?>" data-theme="dark">
                                <i class="bi bi-moon"></i> Темна
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Основний контент -->
            <div class="col-lg-9">
                <!-- Вітальне повідомлення -->
                <div class="card mb-4">
                    <div class="card-body">
                        <h4 class="card-title">
                            <i class="bi bi-emoji-smile"></i> 
                            Вітаємо, <?php echo !empty($user['first_name']) ? 
                                htmlspecialchars($user['first_name']) : 
                                htmlspecialchars($user['username']); ?>!
                        </h4>
                        <p class="card-text">Ласкаво просимо до особистого кабінету Lagodi Service. Тут ви можете керувати своїми замовленнями, переглядати історію та змінювати налаштування свого профілю.</p>
                    </div>
                </div>
                
                <!-- Статистика замовлень -->
                <div class="row mb-4">
                    <div class="col-md-3 mb-3">
                        <div class="card bg-primary text-white h-100">
                            <div class="card-body">
                                <h5 class="card-title">Всього замовлень</h5>
                                <h2 class="display-4"><?php echo $stats['total']; ?></h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="card bg-info text-white h-100">
                            <div class="card-body">
                                <h5 class="card-title">Нові</h5>
                                <h2 class="display-4"><?php echo $stats['new']; ?></h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="card bg-warning text-dark h-100">
                            <div class="card-body">
                                <h5 class="card-title">В роботі</h5>
                                <h2 class="display-4"><?php echo $stats['in_progress']; ?></h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="card bg-success text-white h-100">
                            <div class="card-body">
                                <h5 class="card-title">Виконано</h5>
                                <h2 class="display-4"><?php echo $stats['completed']; ?></h2>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Останні замовлення -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">Останні замовлення</h5>
                        <a href="/dah/user/orders.php" class="btn btn-sm btn-outline-primary">Усі замовлення</a>
                    </div>
                    <div class="card-body">
                        <?php if (empty($recent_orders)): ?>
                            <div class="alert alert-info">
                                У вас поки немає замовлень. Створіть нове замовлення, щоб почати.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
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
                                        <?php foreach ($recent_orders as $order): ?>
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
                                            <td>
                                                <?php echo date('d.m.Y H:i', strtotime($order['created_at'])); ?>
                                            </td>
                                            <td>
                                                <a href="/dah/user/orders.php?id=<?php echo $order['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Створення нового замовлення -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Створити нове замовлення</h5>
                    </div>
                    <div class="card-body">
                        <form id="newOrderForm" action="/dah/user/orders.php" method="post" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label for="service" class="form-label">Послуга</label>
                                <select class="form-select" id="service" name="service" required>
                                    <option value="">Виберіть послугу</option>
                                    <?php foreach ($service_categories as $category): ?>
                                        <optgroup label="<?php echo htmlspecialchars($category['name']); ?>">
                                            <?php foreach ($services as $service): ?>
                                                <?php if ($service['category'] == $category['name']): ?>
                                                    <option value="<?php echo htmlspecialchars($service['name']); ?>">
                                                        <?php echo htmlspecialchars($service['name']); ?>
                                                        <?php if (!empty($service['price_range'])): ?>
                                                            (<?php echo htmlspecialchars($service['price_range']); ?>)
                                                        <?php endif; ?>
                                                    </option>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label for="device_type" class="form-label">Тип пристрою</label>
                                <select class="form-select" id="device_type" name="device_type">
                                    <option value="">Виберіть тип пристрою</option>
                                    <option value="МФУ">МФУ</option>
                                    <option value="Телефон сенсорний">Телефон сенсорний</option>
                                    <option value="Телефон кнопковий">Телефон кнопковий</option>
                                    <option value="Ноутбук">Ноутбук</option>
                                    <option value="Планшет">Планшет</option>
                                    <option value="Системний блок">Системний блок</option>
                                    <option value="Монітор">Монітор</option>
                                    <option value="Інше">Інше</option>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label for="details" class="form-label">Опис проблеми</label>
                                <textarea class="form-control" id="details" name="details" rows="4" required></textarea>
                                <div class="form-text">Детально опишіть проблему, з якою ви звертаєтесь</div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="first_name" class="form-label">Ім'я</label>
                                    <input type="text" class="form-control" id="first_name" name="first_name" 
                                           value="<?php echo htmlspecialchars($user['first_name'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="last_name" class="form-label">Прізвище</label>
                                    <input type="text" class="form-control" id="last_name" name="last_name"
                                           value="<?php echo htmlspecialchars($user['last_name'] ?? ''); ?>">
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="phone" class="form-label">Телефон</label>
                                <input type="tel" class="form-control" id="phone" name="phone" 
                                       value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="delivery_method" class="form-label">Спосіб доставки</label>
                                <select class="form-select" id="delivery_method" name="delivery_method" required>
                                    <option value="">Виберіть спосіб доставки</option>
                                    <option value="Самовивіз">Самовивіз</option>
                                    <option value="Нова пошта">Нова пошта</option>
                                    <option value="Укрпошта">Укрпошта</option>
                                    <option value="Кур'єр">Кур'єр</option>
                                </select>
                            </div>
                            
                            <div class="mb-3" id="addressBlock" style="display: none;">
                                <label for="address" class="form-label">Адреса доставки</label>
                                <textarea class="form-control" id="address" name="address" rows="2"></textarea>
                            </div>
                            
                            <div class="mb-3">
                                <label for="media_files" class="form-label">Додати фото/відео</label>
                                <input type="file" class="form-control" id="media_files" name="media_files[]" multiple accept="image/*,video/*">
                                <div class="form-text">Ви можете додати до 5 файлів (макс. розмір файлу: 5 МБ)</div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="user_comment" class="form-label">Додатковий коментар</label>
                                <textarea class="form-control" id="user_comment" name="user_comment" rows="2"></textarea>
                            </div>
                            
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary">Створити замовлення</button>
                            </div>
                        </form>
                    </div>
                </div>
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
    
    <!-- Модальні вікна -->
    <div class="modal fade" id="notificationModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Повідомлення</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p id="notificationMessage"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрити</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Контейнер для сповіщень -->
    <div id="toastContainer" class="position-fixed bottom-0 end-0 p-3" style="z-index: 1050;"></div>
    
    <!-- JavaScript файли -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
    <script src="/assets/js/dashboard.js"></script>
    <script src="/assets/js/notifications.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Показати/приховати поле адреси в залежності від вибраного способу доставки
        const deliveryMethodSelect = document.getElementById('delivery_method');
        const addressBlock = document.getElementById('addressBlock');
        const addressField = document.getElementById('address');
        
        if (deliveryMethodSelect && addressBlock) {
            deliveryMethodSelect.addEventListener('change', function() {
                const method = this.value;
                if (method === 'Нова пошта' || method === 'Укрпошта' || method === 'Кур\'єр') {
                    addressBlock.style.display = 'block';
                    if (addressField) addressField.setAttribute('required', 'required');
                } else {
                    addressBlock.style.display = 'none';
                    if (addressField) addressField.removeAttribute('required');
                }
            });
        }
        
        // Обробка відправки форми нового замовлення
        const newOrderForm = document.getElementById('newOrderForm');
        if (newOrderForm) {
            newOrderForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                const formData = new FormData(this);
                
                // Показуємо індикатор завантаження
                const submitButton = this.querySelector('button[type="submit"]');
                const originalButtonText = submitButton.innerHTML;
                submitButton.disabled = true;
                submitButton.innerHTML = `
                    <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                    <span class="ms-2">Обробка...</span>
                `;
                
                fetch('/api/orders/create.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Показуємо повідомлення про успіх
                        showNotification(data.message, 'success');
                        
                        // Очищаємо форму
                        newOrderForm.reset();
                        
                        // Перенаправляємо на сторінку замовлення
                        setTimeout(() => {
                            window.location.href = '/dah/user/orders.php?id=' + data.order_id;
                        }, 2000);
                    } else {
                        // Показуємо повідомлення про помилку
                        showNotification(data.message || 'Сталася помилка при створенні замовлення', 'danger');
                    }
                })
                .catch(error => {
                    console.error('Помилка при створенні замовлення:', error);
                    showNotification('Помилка при з\'єднанні з сервером', 'danger');
                })
                .finally(() => {
                    // Відновлюємо кнопку
                    submitButton.disabled = false;
                    submitButton.innerHTML = originalButtonText;
                });
            });
        }
    });
    
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
    </script>
</body>
</html>