<div class="page-header">
    <h1>Налаштування</h1>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Налаштування інтерфейсу</h2>
            </div>
            <div class="card-body">
                <div class="setting-group">
                    <h3 class="setting-title">Тема оформлення</h3>
                    <div class="setting-value">
                        <?php
                        $themeNames = [
                            'light' => 'Світла',
                            'dark' => 'Темна',
                            'blue' => 'Блакитна',
                            'grey' => 'Сіра'
                        ];
                        ?>
                        <span><?= $themeNames[$theme] ?? 'Світла' ?></span>
                        <button id="change-theme-btn" class="btn btn-primary btn-sm">Змінити</button>
                    </div>
                </div>

                <div class="setting-group">
                    <h3 class="setting-title">Кількість записів на сторінку</h3>
                    <div class="setting-value">
                        <select id="items-per-page" name="items_per_page" class="form-control">
                            <?php
                            $perPageOptions = [5, 10, 15, 20, 25, 30, 50];
                            $currentPerPage = (int)($userSettings['setting_value'] ?? 10);

                            foreach ($perPageOptions as $option):
                                ?>
                                <option value="<?= $option ?>" <?= $currentPerPage === $option ? 'selected' : '' ?>><?= $option ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button id="save-per-page-btn" class="btn btn-primary btn-sm">Зберегти</button>
                    </div>
                </div>

                <div class="setting-group">
                    <h3 class="setting-title">Сповіщення на email</h3>
                    <div class="setting-value">
                        <div class="form-check">
                            <?php
                            $emailNotifications = $db->query(
                                "SELECT setting_value FROM user_settings WHERE user_id = ? AND setting_key = 'email_notifications'",
                                [$userId]
                            )->find();

                            $emailNotificationsEnabled = $emailNotifications ? (bool)$emailNotifications['setting_value'] : true;
                            ?>
                            <input type="checkbox" id="email-notifications" class="form-check-input" <?= $emailNotificationsEnabled ? 'checked' : '' ?>>
                            <label for="email-notifications" class="form-check-label">
                                Отримувати сповіщення про замовлення на email
                            </label>
                        </div>
                    </div>
                </div>

                <div class="setting-group">
                    <h3 class="setting-title">Час автоматичного виходу</h3>
                    <div class="setting-value">
                        <span>30 хвилин</span>
                        <div class="form-hint">
                            <i class="fas fa-info-circle"></i>
                            Система автоматично завершує сеанс після 30 хвилин неактивності
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Безпека</h2>
            </div>
            <div class="card-body">
                <div class="setting-group">
                    <h3 class="setting-title">Пароль</h3>
                    <div class="setting-value">
                        <span>••••••••</span>
                        <button id="change-password-btn" class="btn btn-primary btn-sm">Змінити</button>
                    </div>
                </div>

                <div class="setting-group">
                    <h3 class="setting-title">Email</h3>
                    <div class="setting-value">
                        <span><?= htmlspecialchars($userData['email']) ?></span>
                        <button id="change-email-btn" class="btn btn-primary btn-sm">Змінити</button>
                    </div>
                </div>

                <div class="setting-group">
                    <h3 class="setting-title">Двофакторна автентифікація</h3>
                    <div class="setting-value">
                        <span>Вимкнено</span>
                        <button id="enable-2fa-btn" class="btn btn-primary btn-sm" disabled>Увімкнути</button>
                        <div class="form-hint">
                            <i class="fas fa-info-circle"></i>
                            Функція незабаром буде доступна
                        </div>
                    </div>
                </div>

                <div class="setting-group">
                    <h3 class="setting-title">Активні сеанси</h3>
                    <div class="setting-value">
                        <?php
                        // Отримання активних сеансів
                        $sessions = $db->query(
                            "SELECT * FROM user_sessions WHERE user_id = ? ORDER BY last_activity DESC",
                            [$userId]
                        )->findAll();

                        $sessionCount = count($sessions);
                        ?>
                        <span><?= $sessionCount ?> <?= numWord($sessionCount, ['активний сеанс', 'активні сеанси', 'активних сеансів']) ?></span>
                        <button id="show-sessions-btn" class="btn btn-secondary btn-sm">Переглянути</button>
                    </div>
                </div>

                <div class="setting-group">
                    <h3 class="setting-title">Журнал активності</h3>
                    <div class="setting-value">
                        <?php
                        // Отримання кількості записів з журналу
                        $activityCount = $db->query(
                            "SELECT COUNT(*) FROM user_activity_logs WHERE user_id = ?",
                            [$userId]
                        )->findColumn();
                        ?>
                        <span><?= $activityCount ?> <?= numWord($activityCount, ['запис', 'записи', 'записів']) ?></span>
                        <button id="show-activity-btn" class="btn btn-secondary btn-sm">Переглянути</button>
                    </div>
                </div>
            </div>
        </div>
        <div class="card mt-4">
            <div class="card-header">
                <h2 class="card-title">Обмеження замовлень</h2>
            </div>
            <div class="card-body">
                <?php
                // Отримання інформації про обмеження замовлень
                $orderLimits = $db->query(
                    "SELECT * FROM order_limits WHERE user_id = ? ORDER BY limit_type",
                    [$userId]
                )->findAll();

                $limitTypes = [
                    'daily' => 'Денний ліміт',
                    'weekly' => 'Тижневий ліміт',
                    'monthly' => 'Місячний ліміт',
                    'total' => 'Загальний ліміт'
                ];

                if (!empty($orderLimits)):
                    ?>
                    <div class="limits-table">
                        <table class="table">
                            <thead>
                            <tr>
                                <th>Тип ліміту</th>
                                <th>Використано</th>
                                <th>Максимум</th>
                                <th>Скидання</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($orderLimits as $limit): ?>
                                <tr>
                                    <td><?= $limitTypes[$limit['limit_type']] ?? $limit['limit_type'] ?></td>
                                    <td><?= $limit['current_count'] ?></td>
                                    <td><?= $limit['max_orders'] ?></td>
                                    <td>
                                        <?php
                                        if ($limit['limit_type'] === 'total') {
                                            echo 'Ніколи';
                                        } elseif ($limit['reset_date']) {
                                            $resetDate = strtotime($limit['reset_date']);

                                            switch ($limit['limit_type']) {
                                                case 'daily':
                                                    $nextReset = $resetDate + 86400;
                                                    break;
                                                case 'weekly':
                                                    $nextReset = $resetDate + 604800;
                                                    break;
                                                case 'monthly':
                                                    $nextReset = $resetDate + 2592000;
                                                    break;
                                            }

                                            echo date('d.m.Y H:i', $nextReset);
                                        } else {
                                            echo 'При наступному замовленні';
                                        }
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="form-hint">
                        <i class="fas fa-info-circle"></i>
                        Ліміти замовлень встановлюються адміністрацією та можуть бути змінені в залежності від вашої активності
                    </div>
                <?php else: ?>
                    <div class="empty-state-mini">
                        <p>Інформація про обмеження недоступна</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Зміна теми оформлення
        const changeThemeBtn = document.getElementById('change-theme-btn');
        if (changeThemeBtn) {
            changeThemeBtn.addEventListener('click', function() {
                openModal('change-theme-modal');
            });
        }

        // Ініціалізація обробників теми
        initThemeHandlers();

        // Зміна кількості записів на сторінку
        const savePerPageBtn = document.getElementById('save-per-page-btn');
        const itemsPerPageSelect = document.getElementById('items-per-page');

        if (savePerPageBtn && itemsPerPageSelect) {
            savePerPageBtn.addEventListener('click', function() {
                const perPage = itemsPerPageSelect.value;

                // Зберігаємо налаштування
                fetch('dashboard.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: new URLSearchParams({
                        'save_setting': '1',
                        'setting_key': 'items_per_page',
                        'setting_value': perPage,
                        'csrf_token': config.csrfToken
                    })
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showNotification('success', 'Налаштування успішно збережені');
                        } else {
                            showNotification('error', data.message || 'Помилка при збереженні налаштувань');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showNotification('error', 'Помилка при збереженні налаштувань');
                    });
            });
        }

        // Увімкнення/вимкнення сповіщень на email
        const emailNotificationsCheckbox = document.getElementById('email-notifications');

        if (emailNotificationsCheckbox) {
            emailNotificationsCheckbox.addEventListener('change', function() {
                const enabled = this.checked ? 1 : 0;

                // Зберігаємо налаштування
                fetch('dashboard.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: new URLSearchParams({
                        'save_setting': '1',
                        'setting_key': 'email_notifications',
                        'setting_value': enabled,
                        'csrf_token': config.csrfToken
                    })
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showNotification('success', 'Налаштування успішно збережені');
                        } else {
                            // Відновлюємо попередній стан, якщо помилка
                            this.checked = !this.checked;
                            showNotification('error', data.message || 'Помилка при збереженні налаштувань');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        // Відновлюємо попередній стан, якщо помилка
                        this.checked = !this.checked;
                        showNotification('error', 'Помилка при збереженні налаштувань');
                    });
            });
        }

        // Обробка кнопок зміни пароля та email
        const changePasswordBtn = document.getElementById('change-password-btn');
        if (changePasswordBtn) {
            changePasswordBtn.addEventListener('click', function() {
                openModal('change-password-modal');
            });
        }

        const changeEmailBtn = document.getElementById('change-email-btn');
        if (changeEmailBtn) {
            changeEmailBtn.addEventListener('click', function() {
                openModal('change-email-modal');
            });
        }

        // Перегляд активних сеансів
        const showSessionsBtn = document.getElementById('show-sessions-btn');
        if (showSessionsBtn) {
            showSessionsBtn.addEventListener('click', function() {
                // Тут можемо реалізувати відкриття модального вікна з активними сеансами
                // або перенаправлення на окрему сторінку з активними сеансами
                showNotification('info', 'Функціональність перегляду активних сеансів буде доступна незабаром');
            });
        }

        // Перегляд журналу активності
        const showActivityBtn = document.getElementById('show-activity-btn');
        if (showActivityBtn) {
            showActivityBtn.addEventListener('click', function() {
                // Тут можемо реалізувати відкриття модального вікна з журналом активності
                // або перенаправлення на окрему сторінку з журналом активності
                showNotification('info', 'Функціональність перегляду журналу активності буде доступна незабаром');
            });
        }

        // Ініціалізація обробників для теми
        function initThemeHandlers() {
            // Обробка кліків на елементах вибору теми
            document.querySelectorAll('.theme-item').forEach(function(item) {
                item.addEventListener('click', function() {
                    const theme = this.getAttribute('data-theme');
                    changeTheme(theme);

                    // Позначаємо активну тему
                    document.querySelectorAll('.theme-item').forEach(el => el.classList.remove('active'));
                    this.classList.add('active');
                });
            });
        }
    });
</script>