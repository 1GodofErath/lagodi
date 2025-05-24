<?php
// Вилучаємо session_start(), оскільки сесія вже запущена в admin_dashboard.php
// session_start();

// Перевірка прав доступу - тільки адміністратори можуть керувати користувачами
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo '<div class="alert alert-danger">У вас недостатньо прав для доступу до цієї сторінки</div>';
    exit();
}

// Параметри для пошуку та фільтрації
$userSearch = $_GET['user_search'] ?? '';
$userIdSearch = $_GET['user_id_search'] ?? '';

// Отримання списку користувачів з пошуком за ім'ям або ID
$users = [];
try {
    // Базовий запит
    $sql = "SELECT id, username, role, email, blocked_until, block_reason
            FROM users 
            WHERE 1=1";

    $params = [];
    $types = "";

    // Додаємо умови пошуку, якщо вони задані
    if (!empty($userSearch)) {
        $sql .= " AND username LIKE ?";
        $userSearchParam = "%{$userSearch}%";
        $params[] = $userSearchParam;
        $types .= "s";
    }

    if (!empty($userIdSearch)) {
        $sql .= " AND id = ?";
        $params[] = $userIdSearch;
        $types .= "i";
    }

    // Сортування
    $sql .= " ORDER BY FIELD(role, 'admin', 'junior_admin') DESC, username ASC";

    $stmt = $conn->prepare($sql);

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $users = $result->fetch_all(MYSQLI_ASSOC);
} catch (Exception $e) {
    $errorMessage = "Помилка отримання користувачів: " . $e->getMessage();
}

// Обробка форми додавання користувача
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    try {
        // Перевірка CSRF токена
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception("Недійсний CSRF-токен");
        }

        // Отримання і валідація даних
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        $confirmPassword = $_POST['confirm_password'];
        $role = $_POST['role'];
        $email = trim($_POST['email'] ?? '');

        // Перевірка заповнення полів
        if (empty($username) || empty($password)) {
            throw new Exception("Логін та пароль обов'язкові для заповнення");
        }

        // Перевірка співпадіння паролів
        if ($password !== $confirmPassword) {
            throw new Exception("Паролі не співпадають");
        }

        // Перевірка на унікальність логіна
        $checkStmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $checkStmt->bind_param("s", $username);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();

        if ($checkResult->num_rows > 0) {
            throw new Exception("Користувач з таким логіном вже існує");
        }

        // Хешування пароля
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        // Додавання нового користувача
        $insertStmt = $conn->prepare("INSERT INTO users (username, password, role, email, created_at) VALUES (?, ?, ?, ?, NOW())");
        $insertStmt->bind_param("ssss", $username, $hashedPassword, $role, $email);
        $insertStmt->execute();

        if ($insertStmt->affected_rows > 0) {
            // Запис в лог
            $logStmt = $conn->prepare("INSERT INTO logs (user_id, action, created_at) VALUES (?, ?, NOW())");
            $userId = $_SESSION['user_id'];
            $action = "Додано нового користувача: {$username} (роль: {$role})";
            $logStmt->bind_param("is", $userId, $action);
            $logStmt->execute();

            $successMessage = "Користувача успішно додано";
            header("Location: admin_dashboard.php?section=users&success=" . urlencode($successMessage));
            exit();
        } else {
            throw new Exception("Помилка при додаванні користувача");
        }
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
    }
}

// Обробка форми зміни пароля
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    try {
        // Перевірка CSRF токена
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception("Недійсний CSRF-токен");
        }

        // Отримання і валідація даних
        $userId = (int)$_POST['user_id'];
        $newPassword = $_POST['new_password'];
        $confirmPassword = $_POST['confirm_password'];

        // Перевірка заповнення полів
        if (empty($newPassword)) {
            throw new Exception("Новий пароль обов'язковий для заповнення");
        }

        // Перевірка співпадіння паролів
        if ($newPassword !== $confirmPassword) {
            throw new Exception("Паролі не співпадають");
        }

        // Перевірка надійності пароля
        if (strlen($newPassword) < 8) {
            throw new Exception("Пароль повинен містити не менше 8 символів");
        }

        // Хешування пароля
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

        // Оновлення пароля
        $updateStmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $updateStmt->bind_param("si", $hashedPassword, $userId);
        $updateStmt->execute();

        if ($updateStmt->affected_rows > 0) {
            // Запис в лог
            $logStmt = $conn->prepare("INSERT INTO logs (user_id, action, created_at) VALUES (?, ?, NOW())");
            $adminId = $_SESSION['user_id'];
            $action = "Змінено пароль для користувача з ID: {$userId}";
            $logStmt->bind_param("is", $adminId, $action);
            $logStmt->execute();

            $successMessage = "Пароль успішно змінено";
            header("Location: admin_dashboard.php?section=users&success=" . urlencode($successMessage));
            exit();
        } else {
            throw new Exception("Помилка при зміні пароля або пароль не було змінено");
        }
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
    }
}

// Повідомлення про успіх або помилку
$successMessage = isset($_GET['success']) ? $_GET['success'] : '';
?>

<!-- Контент секції користувачів -->
<h2 class="section-title mb-4">
    <i class="bi bi-people-fill text-primary"></i> Керування користувачами
</h2>

<!-- Повідомлення про успіх або помилку -->
<?php if (!empty($errorMessage)): ?>
    <div class="alert alert-danger">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div><?php echo $errorMessage; ?></div>
    </div>
<?php endif; ?>

<?php if (!empty($successMessage)): ?>
    <div class="alert alert-success">
        <i class="bi bi-check-circle-fill"></i>
        <div><?php echo $successMessage; ?></div>
    </div>
<?php endif; ?>

<!-- Форма пошуку користувачів -->
<form method="GET" class="filters">
    <input type="hidden" name="section" value="users">

    <div class="search-group">
        <label for="user-search">Пошук за логіном</label>
        <div class="position-relative w-100">
            <i class="bi bi-search search-icon"></i>
            <input type="text" id="user-search" class="search-input" name="user_search"
                   placeholder="Введіть логін користувача..."
                   value="<?= htmlspecialchars($userSearch) ?>">
        </div>
    </div>

    <div class="search-group">
        <label for="user-id-search">Пошук за ID</label>
        <div class="position-relative w-100">
            <i class="bi bi-key search-icon"></i>
            <input type="number" id="user-id-search" class="search-input" name="user_id_search"
                   placeholder="Введіть ID користувача..."
                   value="<?= htmlspecialchars($userIdSearch) ?>">
        </div>
    </div>

    <div class="filter-group d-flex align-items-end">
        <button type="submit" class="btn btn-primary btn-with-icon">
            <i class="bi bi-search"></i> Пошук
        </button>
    </div>

    <?php if (!empty($userSearch) || !empty($userIdSearch)): ?>
        <div class="filter-actions">
            <a href="?section=users" class="btn btn-sm btn-with-icon">
                <i class="bi bi-x-circle"></i> Очистити пошук
            </a>
        </div>
    <?php endif; ?>
</form>

<!-- Кнопка додавання нового користувача -->
<div class="d-flex justify-content-end mb-4">
    <button type="button" id="addUserBtn" class="btn btn-success btn-with-icon">
        <i class="bi bi-person-plus"></i> Додати користувача
    </button>
</div>

<!-- Список користувачів -->
<?php if (!empty($users)): ?>
    <div class="grid">
        <?php foreach ($users as $user): ?>
            <div class="card fade-in">
                <div class="card-header">
                    <div class="d-flex align-items-center gap-3">
                        <div class="comment-avatar">
                            <?php echo strtoupper(substr($user['username'] ?? 'U', 0, 1)); ?>
                        </div>
                        <div>
                            <h3 class="card-title"><?= htmlspecialchars($user['username']) ?></h3>
                            <small class="text-secondary">ID: <?= $user['id'] ?></small>
                        </div>
                    </div>
                    <div>
                        <?php if ($user['role'] === 'admin'): ?>
                            <span class="badge status-completed">Адміністратор</span>
                        <?php elseif ($user['role'] === 'junior_admin'): ?>
                            <span class="badge status-in-progress">Молодший адмін</span>
                        <?php else: ?>
                            <span class="badge status-new">Користувач</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <form method="POST" action="update_user.php" class="d-flex flex-column gap-3">
                        <input type="hidden" name="section" value="users">
                        <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                        <div class="form-group">
                            <label for="username-<?= $user['id'] ?>">Логін</label>
                            <input type="text" id="username-<?= $user['id'] ?>" name="username"
                                   value="<?= htmlspecialchars($user['username']) ?>" class="form-control" required>
                        </div>

                        <div class="form-group">
                            <label for="email-<?= $user['id'] ?>">Email</label>
                            <input type="email" id="email-<?= $user['id'] ?>" name="email"
                                   value="<?= htmlspecialchars($user['email'] ?? '') ?>" class="form-control">
                        </div>

                        <div class="form-group">
                            <label for="role-<?= $user['id'] ?>">Роль</label>
                            <select id="role-<?= $user['id'] ?>" name="role" class="form-control">
                                <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Адміністратор</option>
                                <option value="junior_admin" <?= $user['role'] === 'junior_admin' ? 'selected' : '' ?>>Молодший адмін</option>
                                <option value="user" <?= $user['role'] === 'user' ? 'selected' : '' ?>>Користувач</option>
                            </select>
                        </div>

                        <div>
                            <button type="submit" class="btn btn-primary btn-with-icon">
                                <i class="bi bi-check-circle"></i> Оновити
                            </button>
                            <button type="button" class="btn btn-info btn-with-icon"
                                    onclick="showChangePasswordModal(<?= $user['id'] ?>, '<?= htmlspecialchars($user['username']) ?>')">
                                <i class="bi bi-key"></i> Змінити пароль
                            </button>
                        </div>
                    </form>

                    <?php if (isset($user['blocked_until']) && strtotime($user['blocked_until']) > time()): ?>
                        <div class="alert alert-danger mt-3">
                            <div class="d-flex flex-column">
                                <div><strong>Заблоковано до:</strong> <?= htmlspecialchars($user['blocked_until']) ?></div>
                                <div><strong>Причина:</strong> <?= htmlspecialchars($user['block_reason']) ?></div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-success btn-with-icon mt-2" onclick="unblockUser(<?= $user['id'] ?>)">
                            <i class="bi bi-unlock"></i> Розблокувати
                        </button>
                    <?php else: ?>
                        <div class="mt-3">
                            <button type="button" class="btn btn-warning btn-with-icon" onclick="toggleBlockForm(<?= $user['id'] ?>)">
                                <i class="bi bi-lock"></i> Заблокувати
                            </button>
                        </div>
                        <div id="block-form-<?= $user['id'] ?>" style="display:none; margin-top: 15px;">
                            <form method="POST" action="block_user.php" class="d-flex flex-column gap-3">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <input type="hidden" name="user_id" value="<?= $user['id'] ?>">

                                <div class="form-group">
                                    <label for="block-reason-<?= $user['id'] ?>">Причина блокування</label>
                                    <input type="text" id="block-reason-<?= $user['id'] ?>" name="block_reason" class="form-control" required>
                                </div>

                                <div class="form-group">
                                    <label for="blocked-until-<?= $user['id'] ?>">Час блокування (до)</label>
                                    <input type="datetime-local" id="blocked-until-<?= $user['id'] ?>" name="blocked_until" class="form-control" required>
                                </div>

                                <div>
                                    <button type="submit" class="btn btn-danger btn-with-icon">
                                        <i class="bi bi-lock-fill"></i> Заблокувати
                                    </button>
                                    <button type="button" class="btn btn-secondary btn-with-icon ml-2" onclick="toggleBlockForm(<?= $user['id'] ?>)">
                                        <i class="bi bi-x"></i> Скасувати
                                    </button>
                                </div>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="card-footer">
                    <button type="button" class="btn btn-danger btn-with-icon" onclick="confirmDelete(<?= $user['id'] ?>)">
                        <i class="bi bi-trash"></i> Видалити
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="empty-state">
        <div class="empty-state-icon">
            <i class="bi bi-people"></i>
        </div>
        <div class="empty-state-text">Користувачів не знайдено</div>
    </div>
<?php endif; ?>

<!-- Модальне вікно додавання користувача -->
<div class="modal" id="addUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Додати нового користувача</h5>
                <button type="button" class="modal-close" data-dismiss="modal" aria-label="Close">×</button>
            </div>
            <div class="modal-body">
                <form id="addUserForm" method="POST" action="admin_dashboard.php?section=users">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="add_user" value="1">

                    <div class="form-group">
                        <label for="new-username">Логін <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-person"></i></span>
                            <input type="text" id="new-username" name="username" class="form-control" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="new-email">Email</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                            <input type="email" id="new-email" name="email" class="form-control">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="new-password">Пароль <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-key"></i></span>
                            <input type="password" id="new-password" name="password" class="form-control" required>
                            <button type="button" class="btn btn-outline-secondary password-toggle" onclick="togglePasswordVisibility('new-password')">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <div class="password-strength-meter mt-2 d-none" id="password-strength-meter">
                            <div class="progress">
                                <div class="progress-bar" role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
                            </div>
                            <small class="text-muted mt-1 d-block">Надійність пароля: <span id="password-strength-text">дуже слабкий</span></small>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="confirm-password">Підтвердження пароля <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-key-fill"></i></span>
                            <input type="password" id="confirm-password" name="confirm_password" class="form-control" required>
                            <button type="button" class="btn btn-outline-secondary password-toggle" onclick="togglePasswordVisibility('confirm-password')">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <div id="password-match-message" class="mt-1"></div>
                    </div>

                    <div class="form-group">
                        <label for="new-role">Роль <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-person-badge"></i></span>
                            <select id="new-role" name="role" class="form-control" required>
                                <option value="user">Користувач</option>
                                <option value="junior_admin">Молодший адмін</option>
                                <option value="admin">Адміністратор</option>
                            </select>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Скасувати</button>
                <button type="submit" form="addUserForm" class="btn btn-primary">Додати</button>
            </div>
        </div>
    </div>
</div>

<!-- Модальне вікно для зміни пароля -->
<div class="modal" id="changePasswordModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Змінити пароль користувача: <span id="change-password-username"></span></h5>
                <button type="button" class="modal-close" data-dismiss="modal" aria-label="Close">×</button>
            </div>
            <div class="modal-body">
                <form id="changePasswordForm" method="POST" action="admin_dashboard.php?section=users">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="change_password" value="1">
                    <input type="hidden" name="user_id" id="change-password-user-id" value="">

                    <div class="form-group">
                        <label for="change-new-password">Новий пароль <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-key"></i></span>
                            <input type="password" id="change-new-password" name="new_password" class="form-control" required>
                            <button type="button" class="btn btn-outline-secondary password-toggle" onclick="togglePasswordVisibility('change-new-password')">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <div class="password-strength-meter mt-2 d-none" id="change-password-strength-meter">
                            <div class="progress">
                                <div class="progress-bar" role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
                            </div>
                            <small class="text-muted mt-1 d-block">Надійність пароля: <span id="change-password-strength-text">дуже слабкий</span></small>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="change-confirm-password">Підтвердження пароля <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-key-fill"></i></span>
                            <input type="password" id="change-confirm-password" name="confirm_password" class="form-control" required>
                            <button type="button" class="btn btn-outline-secondary password-toggle" onclick="togglePasswordVisibility('change-confirm-password')">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <div id="change-password-match-message" class="mt-1"></div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Скасувати</button>
                <button type="submit" form="changePasswordForm" class="btn btn-primary">Змінити пароль</button>
            </div>
        </div>
    </div>
</div>

<style>
    /* Стилі для модальних вікон користувачів */
    .modal-backdrop {
        background-color: rgba(0,0,0,0.5);
    }

    .password-strength-meter .progress {
        height: 5px;
    }

    .password-strength-meter .progress-bar.very-weak {
        background-color: #d9534f;
        width: 20%;
    }

    .password-strength-meter .progress-bar.weak {
        background-color: #f0ad4e;
        width: 40%;
    }

    .password-strength-meter .progress-bar.medium {
        background-color: #5bc0de;
        width: 60%;
    }

    .password-strength-meter .progress-bar.strong {
        background-color: #5cb85c;
        width: 80%;
    }

    .password-strength-meter .progress-bar.very-strong {
        background-color: #28a745;
        width: 100%;
    }

    .input-group-text {
        background-color: var(--primary-color-light);
        color: var(--primary-color);
        border-color: var(--border-color);
    }

    .password-match-success {
        color: var(--success-color);
    }

    .password-match-error {
        color: var(--danger-color);
    }

    /* Покращення для поля пошуку по ID */
    #user-id-search {
        font-family: monospace;
    }

    /* Покращення для відображення ID користувача */
    .card-header small {
        font-family: monospace;
        font-size: 0.8rem;
    }
</style>

<script>
    // Ідентифікатор для запобігання конфліктам з іншими скриптами
    var userManagementLoaded = false;

    // Функція для безпечної ініціалізації скрипта
    function initUserManagement() {
        // Перевіряємо, чи скрипт вже був ініціалізований
        if (userManagementLoaded) return;
        userManagementLoaded = true;

        // Функції для роботи з модальними вікнами
        window.addModalBackdrop = function() {
            if (!document.querySelector('.modal-backdrop')) {
                const backdrop = document.createElement('div');
                backdrop.className = 'modal-backdrop';
                backdrop.style.position = 'fixed';
                backdrop.style.top = '0';
                backdrop.style.left = '0';
                backdrop.style.width = '100%';
                backdrop.style.height = '100%';
                backdrop.style.backgroundColor = 'rgba(0,0,0,0.5)';
                backdrop.style.zIndex = '999';
                document.body.appendChild(backdrop);
            }
        };

        window.closeModals = function() {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                modal.style.display = 'none';
                modal.classList.remove('show');
            });

            document.body.classList.remove('modal-open');

            const backdrop = document.querySelector('.modal-backdrop');
            if (backdrop) {
                backdrop.remove();
            }
        };

        // Функція для показу модального вікна додавання користувача
        var setupAddUserModal = function() {
            const addUserBtn = document.getElementById('addUserBtn');
            const addUserModal = document.getElementById('addUserModal');

            if (addUserBtn && addUserModal) {
                addUserBtn.onclick = function(e) {
                    e.preventDefault();
                    addUserModal.style.display = 'block';
                    addUserModal.classList.add('show');
                    document.body.classList.add('modal-open');
                    addModalBackdrop();
                    return false;
                };
            }
        };

        // Закриття модальних вікон
        var setupCloseModalButtons = function() {
            document.querySelectorAll('[data-dismiss="modal"]').forEach(closeBtn => {
                closeBtn.onclick = function(e) {
                    e.preventDefault();
                    closeModals();
                    return false;
                };
            });

            // Закриття при кліку поза модальним вікном
            window.addEventListener('click', function(event) {
                const modals = document.querySelectorAll('.modal.show');
                modals.forEach(modal => {
                    if (event.target === modal) {
                        closeModals();
                    }
                });
            });
        };

        // Перевірка співпадіння паролів
        var setupPasswordValidation = function() {
            // Для форми додавання користувача
            const passwordInput = document.getElementById('new-password');
            const confirmPasswordInput = document.getElementById('confirm-password');
            const passwordMatchMessage = document.getElementById('password-match-message');

            if (passwordInput && confirmPasswordInput && passwordMatchMessage) {
                var checkPasswordMatch = function() {
                    if (confirmPasswordInput.value === '') {
                        passwordMatchMessage.innerHTML = '';
                        return;
                    }

                    if (passwordInput.value === confirmPasswordInput.value) {
                        passwordMatchMessage.innerHTML = '<span class="password-match-success"><i class="bi bi-check-circle"></i> Паролі співпадають</span>';
                    } else {
                        passwordMatchMessage.innerHTML = '<span class="password-match-error"><i class="bi bi-exclamation-triangle"></i> Паролі не співпадають</span>';
                    }
                };

                passwordInput.oninput = checkPasswordMatch;
                confirmPasswordInput.oninput = checkPasswordMatch;

                // Перевірка надійності пароля
                const passwordStrengthMeter = document.getElementById('password-strength-meter');
                const passwordStrengthText = document.getElementById('password-strength-text');
                const passwordStrengthBar = passwordStrengthMeter ? passwordStrengthMeter.querySelector('.progress-bar') : null;

                if (passwordStrengthMeter && passwordStrengthText && passwordStrengthBar) {
                    passwordInput.oninput = function() {
                        if (this.value.length > 0) {
                            passwordStrengthMeter.classList.remove('d-none');

                            const strength = checkPasswordStrength(this.value);
                            passwordStrengthBar.className = 'progress-bar ' + strength.class;
                            passwordStrengthText.textContent = strength.text;
                        } else {
                            passwordStrengthMeter.classList.add('d-none');
                        }

                        // Також перевіряємо співпадіння паролів
                        checkPasswordMatch();
                    };
                }
            }

            // Для форми зміни пароля
            const changePasswordInput = document.getElementById('change-new-password');
            const changeConfirmPasswordInput = document.getElementById('change-confirm-password');
            const changePasswordMatchMessage = document.getElementById('change-password-match-message');

            if (changePasswordInput && changeConfirmPasswordInput && changePasswordMatchMessage) {
                var checkChangePasswordMatch = function() {
                    if (changeConfirmPasswordInput.value === '') {
                        changePasswordMatchMessage.innerHTML = '';
                        return;
                    }

                    if (changePasswordInput.value === changeConfirmPasswordInput.value) {
                        changePasswordMatchMessage.innerHTML = '<span class="password-match-success"><i class="bi bi-check-circle"></i> Паролі співпадають</span>';
                    } else {
                        changePasswordMatchMessage.innerHTML = '<span class="password-match-error"><i class="bi bi-exclamation-triangle"></i> Паролі не співпадають</span>';
                    }
                };

                changePasswordInput.oninput = checkChangePasswordMatch;
                changeConfirmPasswordInput.oninput = checkChangePasswordMatch;

                // Перевірка надійності пароля
                const changePasswordStrengthMeter = document.getElementById('change-password-strength-meter');
                const changePasswordStrengthText = document.getElementById('change-password-strength-text');
                const changePasswordStrengthBar = changePasswordStrengthMeter ? changePasswordStrengthMeter.querySelector('.progress-bar') : null;

                if (changePasswordStrengthMeter && changePasswordStrengthText && changePasswordStrengthBar) {
                    changePasswordInput.oninput = function() {
                        if (this.value.length > 0) {
                            changePasswordStrengthMeter.classList.remove('d-none');

                            const strength = checkPasswordStrength(this.value);
                            changePasswordStrengthBar.className = 'progress-bar ' + strength.class;
                            changePasswordStrengthText.textContent = strength.text;
                        } else {
                            changePasswordStrengthMeter.classList.add('d-none');
                        }

                        // Також перевіряємо співпадіння паролів
                        checkChangePasswordMatch();
                    };
                }
            }
        };

        // Функції для керування користувачами
        // Ці функції доступні глобально, щоб їх можна було викликати з HTML
        window.togglePasswordVisibility = function(inputId) {
            const input = document.getElementById(inputId);
            const icon = input.parentElement.querySelector('.password-toggle i');

            if (input.type === 'password') {
                input.type = 'text';
                icon.className = 'bi bi-eye-slash';
            } else {
                input.type = 'password';
                icon.className = 'bi bi-eye';
            }
        };

        window.toggleBlockForm = function(userId) {
            const form = document.getElementById('block-form-' + userId);
            if (form) {
                form.style.display = form.style.display === 'none' ? 'block' : 'none';
            }
        };

        window.unblockUser = function(userId) {
            if (confirm('Розблокувати користувача?')) {
                const csrfToken = document.querySelector('input[name="csrf_token"]').value;
                window.location.href = '/admin_panel/unblock_user.php?id=' + userId + '&csrf_token=' + encodeURIComponent(csrfToken);
            }
        };

        window.confirmDelete = function(userId) {
            if (confirm('Видалити користувача? Ця дія безповоротна.')) {
                const csrfToken = document.querySelector('input[name="csrf_token"]').value;
                window.location.href = '/admin_panel/delete_user.php?id=' + userId + '&csrf_token=' + encodeURIComponent(csrfToken);
            }
        };

        window.showChangePasswordModal = function(userId, username) {
            const modal = document.getElementById('changePasswordModal');
            if (modal) {
                const userIdField = document.getElementById('change-password-user-id');
                const usernameField = document.getElementById('change-password-username');

                if (userIdField && usernameField) {
                    userIdField.value = userId;
                    usernameField.textContent = username;

                    modal.style.display = 'block';
                    modal.classList.add('show');
                    document.body.classList.add('modal-open');
                    addModalBackdrop();
                }
            }
        };

        // Функції для оцінки надійності пароля
        window.checkPasswordStrength = function(password) {
            let strength = 0;

            // Довжина пароля
            if (password.length >= 8) strength += 1;
            if (password.length >= 12) strength += 1;

            // Великі та малі літери
            if (password.match(/[a-z]+/)) strength += 1;
            if (password.match(/[A-Z]+/)) strength += 1;

            // Цифри та спеціальні символи
            if (password.match(/[0-9]+/)) strength += 1;
            if (password.match(/[^a-zA-Z0-9]+/)) strength += 1;

            return {
                score: strength,
                text: getStrengthText(strength),
                class: getStrengthClass(strength)
            };
        };

        window.getStrengthText = function(strength) {
            switch(strength) {
                case 0: return 'дуже слабкий';
                case 1: return 'слабкий';
                case 2: return 'середній';
                case 3:
                case 4: return 'надійний';
                case 5:
                case 6: return 'дуже надійний';
                default: return 'невідомо';
            }
        };

        window.getStrengthClass = function(strength) {
            switch(strength) {
                case 0: return 'very-weak';
                case 1: return 'weak';
                case 2: return 'medium';
                case 3:
                case 4: return 'strong';
                case 5:
                case 6: return 'very-strong';
                default: return '';
            }
        };

        // Ініціалізація всіх компонентів
        setupAddUserModal();
        setupCloseModalButtons();
        setupPasswordValidation();
    }

    // Ініціалізація при завантаженні сторінки
    if (document.readyState === 'interactive' || document.readyState === 'complete') {
        initUserManagement();
    } else {
        document.addEventListener('DOMContentLoaded', initUserManagement);
    }
</script>