<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
/**
 * Lagodi Service - Профіль користувача
 * Версія: 1.0.0
 * Дата останнього оновлення: 2025-04-27 11:18:55
 * Автор: 1GodofErath
 */

// Підключення необхідних файлів з абсолютними шляхами
require_once $_SERVER['DOCUMENT_ROOT'] . '/dah/confi/database.php'; // Виправлений шлях включає /dah/
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

// Отримання непрочитаних повідомлень
$unread_count = getUnreadNotificationsCount($user['id']);

// Отримання додаткових полів користувача
$additional_fields = getUserAdditionalFields($user['id']);

// Встановлюємо заголовок сторінки
$page_title = "Профіль користувача";
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
    <link rel="stylesheet" href="../style/dahm/dah1.css">
    <link rel="stylesheet" href="../style/dahm/dash2.css">

    <!-- Підключення теми користувача -->
    <?php if (isset($user['theme']) && $user['theme'] == 'dark'): ?>
        <link rel="stylesheet" href="../style/dahm/themes/dark.css">
    <?php else: ?>
        <link rel="stylesheet" href="../style/dahm/themes/light.css">
    <?php endif; ?>
</head>
<body>
<!-- Меню навігації -->
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container">
        <a class="navbar-brand" href="/dah/dashboard.php">Lagodi Service</a>
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
                    <a class="nav-link" href="/dah/user/orders.php">
                        <i class="bi bi-list-check"></i> Мої замовлення
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="/dah/user/profile.php">
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
                        <a class="dropdown-item" href="/dah/user/profile.php">
                            <i class="bi bi-person"></i> Профіль
                        </a>
                        <a class="dropdown-item" href="/dah/user/settings.php">
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
                    <h5 class="card-title user-name">
                        <?php echo !empty($user['first_name']) ?
                            htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) :
                            htmlspecialchars($user['username']); ?>
                    </h5>
                    <p class="card-text text-muted user-email">
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
                        <a href="/dah/user/orders.php" class="sidebar-link">
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
                        <a href="/dah/user/profile.php" class="sidebar-link active">
                            <i class="bi bi-person"></i> Профіль
                        </a>
                    </li>
                    <li class="list-group-item">
                        <a href="/dah/user/settings.php" class="sidebar-link">
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

            <!-- Тема сайту -->
            <div class="card mt-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Тема сайту</h5>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <button type="button" class="btn btn-outline-primary theme-switcher" data-theme="light">
                            <i class="bi bi-sun"></i> Світла
                        </button>
                        <button type="button" class="btn btn-outline-dark theme-switcher" data-theme="dark">
                            <i class="bi bi-moon"></i> Темна
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Основний контент -->
        <div class="col-lg-9">
            <!-- Навігація по вкладках -->
            <ul class="nav nav-tabs mb-4" id="profileTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="profile-tab" data-bs-toggle="tab" data-bs-target="#profile" type="button" role="tab" aria-controls="profile" aria-selected="true">
                        <i class="bi bi-person"></i> Особисті дані
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="security-tab" data-bs-toggle="tab" data-bs-target="#security" type="button" role="tab" aria-controls="security" aria-selected="false">
                        <i class="bi bi-shield-lock"></i> Безпека
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="notifications-tab" data-bs-toggle="tab" data-bs-target="#notifications" type="button" role="tab" aria-controls="notifications" aria-selected="false">
                        <i class="bi bi-bell"></i> Сповіщення
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="additional-tab" data-bs-toggle="tab" data-bs-target="#additional" type="button" role="tab" aria-controls="additional" aria-selected="false">
                        <i class="bi bi-card-list"></i> Додаткова інформація
                    </button>
                </li>
            </ul>

            <!-- Вміст вкладок -->
            <div class="tab-content" id="profileTabsContent">
                <!-- Вкладка з особистими даними -->
                <div class="tab-pane fade show active" id="profile" role="tabpanel" aria-labelledby="profile-tab">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Особисті дані</h5>
                        </div>
                        <div class="card-body">
                            <form id="formProfile">
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label for="first_name" class="form-label">Ім'я</label>
                                        <input type="text" class="form-control" id="first_name" name="first_name" value="<?php echo htmlspecialchars($user['first_name'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label for="last_name" class="form-label">Прізвище</label>
                                        <input type="text" class="form-control" id="last_name" name="last_name" value="<?php echo htmlspecialchars($user['last_name'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label for="middle_name" class="form-label">По батькові</label>
                                        <input type="text" class="form-control" id="middle_name" name="middle_name" value="<?php echo htmlspecialchars($user['middle_name'] ?? ''); ?>">
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label for="username" class="form-label">Ім'я користувача</label>
                                    <input type="text" class="form-control" id="username" value="<?php echo htmlspecialchars($user['username']); ?>" readonly>
                                    <div class="form-text">Ім'я користувача не можна змінити</div>
                                </div>

                                <div class="mb-3">
                                    <label for="profile_phone" class="form-label">Телефон</label>
                                    <input type="tel" class="form-control" id="profile_phone" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                                </div>

                                <div class="mb-3">
                                    <label for="address" class="form-label">Адреса</label>
                                    <textarea class="form-control" id="address" name="address" rows="3"><?php echo htmlspecialchars($user['address'] ?? ''); ?></textarea>
                                </div>

                                <div class="mb-3">
                                    <label for="delivery_method" class="form-label">Бажаний спосіб доставки</label>
                                    <select class="form-select" id="delivery_method" name="delivery_method">
                                        <option value="">Виберіть спосіб доставки</option>
                                        <option value="Самовивіз" <?php echo ($user['delivery_method'] ?? '') === 'Самовивіз' ? 'selected' : ''; ?>>Самовивіз</option>
                                        <option value="Нова пошта" <?php echo ($user['delivery_method'] ?? '') === 'Нова пошта' ? 'selected' : ''; ?>>Нова пошта</option>
                                        <option value="Укрпошта" <?php echo ($user['delivery_method'] ?? '') === 'Укрпошта' ? 'selected' : ''; ?>>Укрпошта</option>
                                        <option value="Кур'єр" <?php echo ($user['delivery_method'] ?? '') === 'Кур\'єр' ? 'selected' : ''; ?>>Кур'єр</option>
                                    </select>
                                </div>

                                <button type="submit" class="btn btn-primary" data-original-text="Зберегти зміни">Зберегти зміни</button>
                            </form>
                        </div>
                    </div>

                    <!-- Форма для завантаження аватара -->
                    <div class="card mt-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Фото профілю</h5>
                        </div>
                        <div class="card-body">
                            <form id="formAvatar" enctype="multipart/form-data">
                                <div class="mb-3">
                                    <label for="profile_pic" class="form-label">Завантажити нове фото</label>
                                    <input type="file" class="form-control" id="profile_pic" name="profile_pic" accept="image/*">
                                    <div class="form-text">Підтримувані формати: JPEG, PNG, GIF, WebP. Максимальний розмір: 5 МБ</div>
                                </div>

                                <div class="mb-3">
                                    <img id="avatarPreview" class="img-thumbnail d-none" alt="Превью" style="max-width: 200px; max-height: 200px;">
                                </div>

                                <button type="submit" class="btn btn-primary" data-original-text="Завантажити фото">Завантажити фото</button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Вкладка з безпекою -->
                <div class="tab-pane fade" id="security" role="tabpanel" aria-labelledby="security-tab">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Зміна пароля</h5>
                        </div>
                        <div class="card-body">
                            <form id="formPassword">
                                <div class="mb-3">
                                    <label for="current_password" class="form-label">Поточний пароль</label>
                                    <input type="password" class="form-control" id="current_password" name="current_password" required>
                                </div>

                                <div class="mb-3">
                                    <label for="new_password" class="form-label">Новий пароль</label>
                                    <input type="password" class="form-control" id="new_password" name="new_password" required>
                                    <div class="form-text">Пароль повинен містити не менше 8 символів</div>
                                </div>

                                <div class="mb-3">
                                    <label for="confirm_password" class="form-label">Підтвердження нового пароля</label>
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                </div>

                                <button type="submit" class="btn btn-primary" data-original-text="Змінити пароль">Змінити пароль</button>
                            </form>
                        </div>
                    </div>

                    <div class="card mt-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Зміна електронної пошти</h5>
                        </div>
                        <div class="card-body">
                            <form id="formEmail">
                                <div class="mb-3">
                                    <label for="current_email" class="form-label">Поточна електронна пошта</label>
                                    <input type="email" class="form-control" id="current_email" value="<?php echo htmlspecialchars($user['email']); ?>" readonly>
                                </div>

                                <div class="mb-3">
                                    <label for="new_email" class="form-label">Нова електронна пошта</label>
                                    <input type="email" class="form-control" id="new_email" name="new_email" required>
                                </div>

                                <div class="mb-3">
                                    <label for="password" class="form-label">Пароль для підтвердження</label>
                                    <input type="password" class="form-control" id="password" name="password" required>
                                </div>

                                <button type="submit" class="btn btn-primary" data-original-text="Змінити електронну пошту">Змінити електронну пошту</button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Решта вкладок -->
                <!-- Вкладка з налаштуваннями сповіщень -->
                <div class="tab-pane fade" id="notifications" role="tabpanel" aria-labelledby="notifications-tab">
                    <!-- Вміст вкладки з налаштуваннями сповіщень -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Налаштування сповіщень</h5>
                        </div>
                        <div class="card-body">
                            <form id="formSettings">
                                <!-- Вміст форми налаштувань сповіщень -->
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Вкладка з додатковою інформацією -->
                <div class="tab-pane fade" id="additional" role="tabpanel" aria-labelledby="additional-tab">
                    <!-- Вміст вкладки з додатковою інформацією -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Додаткова інформація</h5>
                        </div>
                        <div class="card-body">
                            <form id="formAdditionalFields">
                                <!-- Вміст форми з додатковою інформацією -->
                            </form>
                        </div>
                    </div>
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
                    <li><a href="/dah/" class="text-white">Головна</a></li>
                    <li><a href="/dah/services.php" class="text-white">Послуги</a></li>
                    <li><a href="/dah/contacts.php" class="text-white">Контакти</a></li>
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
<script src="/dah/assets/js/profile.js"></script>
<script src="../jawa/dahj/notifications.js"></script>
<script>
    // Додаткові скрипти для сторінки профілю
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
    });
</script>
</body>
</html>