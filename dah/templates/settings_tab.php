<div class="tab-content" id="settings-content">
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Налаштування профілю</h2>
        </div>

        <div class="tabs">
            <div class="tab active" data-target="profile">Особисті дані</div>
            <div class="tab" data-target="account">Обліковий запис</div>
            <div class="tab" data-target="notifications">Налаштування сповіщень</div>
            <div class="tab" data-target="theme">Тема оформлення</div>
        </div>

        <div class="tab-content active" id="profile-content">
            <div class="user-avatar-section" style="text-align: center; margin-bottom: 20px;">
                <h3>Фотографія профілю</h3>
                <div class="user-avatar" style="width: 120px; height: 120px; margin: 10px auto;">
                    <?php if (!empty($user_data['profile_pic']) && file_exists($user_data['profile_pic'])): ?>
                        <img src="<?= htmlspecialchars($user_data['profile_pic']) ?>" alt="Фото профілю" class="profile-preview">
                    <?php else: ?>
                        <img src="assets/images/default_avatar.png" alt="Фото профілю за замовчуванням" class="profile-preview">
                    <?php endif; ?>
                </div>
                <form method="POST" action="../dashboard.php" enctype="multipart/form-data" id="profile-pic-form">
                    <input type="hidden" name="update_profile_pic" value="1">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <div id="profile-drop-zone" class="drop-zone" style="width: 200px; height: auto; padding: 10px; margin: 10px auto;">
                        <span class="drop-zone-prompt">Натисніть щоб змінити фото</span>
                        <input type="file" name="profile_pic" id="profile_pic" accept="image/*" style="display: none;">
                    </div>
                </form>
            </div>

            <form method="POST" action="../dashboard.php">
                <input type="hidden" name="update_profile" value="1">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                <div class="form-group">
                    <label for="settings_first_name">Ім'я</label>
                    <input type="text" name="first_name" id="settings_first_name" class="form-control" value="<?= htmlspecialchars($user_data['first_name'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label for="settings_last_name">Прізвище</label>
                    <input type="text" name="last_name" id="settings_last_name" class="form-control" value="<?= htmlspecialchars($user_data['last_name'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label for="settings_middle_name">По-батькові</label>
                    <input type="text" name="middle_name" id="settings_middle_name" class="form-control" value="<?= htmlspecialchars($user_data['middle_name'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label for="settings_phone" class="required-field">Номер телефону</label>
                    <input type="tel" name="phone" id="settings_phone" class="form-control" value="<?= htmlspecialchars($user_data['phone'] ?? '') ?>" required>
                </div>

                <div class="form-group">
                    <label for="settings_address" class="required-field">Адреса</label>
                    <input type="text" name="address" id="settings_address" class="form-control" value="<?= htmlspecialchars($user_data['address'] ?? '') ?>" required>
                </div>

                <div class="form-group">
                    <label for="settings_delivery_method" class="required-field">Спосіб доставки за замовчуванням</label>
                    <select name="delivery_method" id="settings_delivery_method" class="form-control" required>
                        <option value="">Виберіть спосіб доставки</option>
                        <option value="Самовивіз" <?= ($user_data['delivery_method'] ?? '') === 'Самовивіз' ? 'selected' : '' ?>>Самовивіз</option>
                        <option value="Нова пошта" <?= ($user_data['delivery_method'] ?? '') === 'Нова пошта' ? 'selected' : '' ?>>Нова пошта</option>
                        <option value="Кур'єр" <?= ($user_data['delivery_method'] ?? '') === 'Кур\'єр' ? 'selected' : '' ?>>Кур'єр</option>
                        <option value="Укрпошта" <?= ($user_data['delivery_method'] ?? '') === 'Укрпошта' ? 'selected' : '' ?>>Укрпошта</option>
                    </select>
                </div>

                <button type="submit" class="btn">
                    <i class="fas fa-save"></i> Зберегти зміни
                </button>
            </form>
        </div>

        <div class="tab-content" id="account-content">
            <h3>Зміна Email</h3>
            <form method="POST" action="dashboard.php">
                <input type="hidden" name="update_email" value="1">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                <div class="form-group">
                    <label for="current_email">Поточний Email</label>
                    <input type="email" id="current_email" class="form-control" value="<?= htmlspecialchars($user_data['email']) ?>" disabled>
                </div>

                <div class="form-group">
                    <label for="new_email" class="required-field">Новий Email</label>
                    <input type="email" name="new_email" id="new_email" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="email_password" class="required-field">Пароль для підтвердження</label>
                    <input type="password" name="password" id="email_password" class="form-control" required>
                </div>

                <button type="submit" class="btn">
                    <i class="fas fa-envelope"></i> Змінити Email
                </button>
            </form>

            <hr style="margin: 30px 0;">

            <h3>Зміна логіна</h3>
            <form method="POST" action="dashboard.php">
                <input type="hidden" name="update_username" value="1">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                <div class="form-group">
                    <label for="current_username">Поточний логін</label>
                    <input type="text" id="current_username" class="form-control" value="<?= htmlspecialchars($username) ?>" disabled>
                </div>

                <div class="form-group">
                    <label for="new_username" class="required-field">Новий логін</label>
                    <input type="text" name="new_username" id="new_username" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="username_password" class="required-field">Пароль для підтвердження</label>
                    <input type="password" name="password" id="username_password" class="form-control" required>
                </div>

                <button type="submit" class="btn">
                    <i class="fas fa-user-edit"></i> Змінити логін
                </button>
            </form>

            <hr style="margin: 30px 0;">

            <h3>Зміна пароля</h3>
            <form method="POST" action="dashboard.php">
                <input type="hidden" name="update_password" value="1">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                <div class="form-group">
                    <label for="current_password" class="required-field">Поточний пароль</label>
                    <input type="password" name="current_password" id="current_password" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="new_password" class="required-field">Новий пароль</label>
                    <input type="password" name="new_password" id="new_password" class="form-control" required>
                    <div class="password-requirements" style="font-size: 0.8rem; color: #777; margin-top: 5px;">
                        Пароль повинен містити мінімум 8 символів
                    </div>
                </div>

                <div class="form-group">
                    <label for="confirm_password" class="required-field">Підтвердження пароля</label>
                    <input type="password" name="confirm_password" id="confirm_password" class="form-control" required>
                </div>

                <button type="submit" class="btn">
                    <i class="fas fa-lock"></i> Змінити пароль
                </button>
            </form>
        </div>

        <div class="tab-content" id="notifications-content">
            <h3>Налаштування сповіщень</h3>
            <p>Налаштуйте, як ви хочете отримувати сповіщення про статус ваших замовлень та нові коментарі адміністраторів</p>

            <form method="POST" action="dashboard.php">
                <input type="hidden" name="update_notification_settings" value="1">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                <div class="notification-settings">
                    <div class="notification-option">
                        <label>
                            <span class="switch">
                                <input type="checkbox" name="email_notifications" <?= $email_notifications_enabled ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </span>
                            Email-сповіщення
                        </label>
                        <div class="notification-description">
                            Отримувати email-сповіщення про зміну статусу замовлень та нові коментарі адміністратора.
                            Будуть надсилатися на адресу: <?= htmlspecialchars($user_data['email']) ?>
                        </div>
                    </div>

                    <div class="notification-option">
                        <label>
                            <span class="switch">
                                <input type="checkbox" name="push_notifications" id="push-notifications-toggle" <?= $push_notifications_enabled ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </span>
                            Push-сповіщення
                        </label>
                        <div class="notification-description">
                            Отримувати миттєві сповіщення у вашому браузері, навіть коли сайт не відкритий.
                            <span id="push-permission-status" style="display: block; margin-top: 5px; font-weight: 500;"></span>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn">
                    <i class="fas fa-save"></i> Зберегти налаштування
                </button>
            </form>

            <div id="push-permission-notice" style="margin-top: 20px; display: none;">
                <div class="alert alert-info">
                    <p><i class="fas fa-info-circle"></i> Для отримання push-сповіщень потрібно надати дозвіл на їх відображення у вашому браузері</p>
                    <button id="request-permission-btn" class="btn" style="margin-top: 10px;">
                        <i class="fas fa-bell"></i> Надати дозвіл на сповіщення
                    </button>
                </div>
            </div>
        </div>

        <div class="tab-content" id="theme-content">
            <h3>Налаштування теми оформлення</h3>
            <p>Виберіть зручну для вас тему оформлення інтерфейсу</p>

            <div class="theme-options">
                <div class="theme-option-card" data-theme="light">
                    <div class="theme-preview theme-preview-light"></div>
                    <div class="theme-name">Світла тема</div>
                    <div class="theme-description">Стандартна світла тема з білим фоном</div>
                </div>
                <div class="theme-option-card" data-theme="dark">
                    <div class="theme-preview theme-preview-dark"></div>
                    <div class="theme-name">Темна тема</div>
                    <div class="theme-description">Темна тема для комфортної роботи вночі</div>
                </div>
                <div class="theme-option-card" data-theme="blue">
                    <div class="theme-preview theme-preview-blue"></div>
                    <div class="theme-name">Синя тема</div>
                    <div class="theme-description">Синя тема для зниження навантаження на очі</div>
                </div>
            </div>
        </div>
    </div>
</div>