<div class="tab-content" id="new-order-content">
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Створення нового замовлення</h2>
        </div>

        <form method="POST" action="dashboard.php" enctype="multipart/form-data" id="create-order-form">
            <input type="hidden" name="create_order" value="1">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="dropped_files" id="dropped_files_data" value="">

            <div class="form-group">
                <label for="service" class="required-field">Послуга</label>
                <select name="service" id="service" class="form-control" required>
                    <option value="">Виберіть послугу</option>
                    <option value="Ремонт телефону">Ремонт телефону</option>
                    <option value="Ремонт планшету">Ремонт планшету</option>
                    <option value="Ремонт ноутбука">Ремонт ноутбука</option>
                    <option value="Ремонт комп'ютера">Ремонт комп'ютера</option>
                    <option value="Налаштування ПЗ">Налаштування ПЗ</option>
                    <option value="Інше">Інше</option>
                </select>
            </div>

            <div class="form-group">
                <label for="device_type" class="required-field">Тип пристрою</label>
                <input type="text" name="device_type" id="device_type" class="form-control" placeholder="Наприклад: iPhone 13, Samsung Galaxy S21, Lenovo ThinkPad..." required>
            </div>

            <div class="form-group">
                <label for="details" class="required-field">Опис проблеми</label>
                <textarea name="details" id="details" class="form-control" rows="5" placeholder="Будь ласка, опишіть детально проблему з вашим пристроєм..." required></textarea>
            </div>

            <div class="form-group">
                <label for="drop-zone" class="file-upload-label">Прикріпити файли (опціонально)</label>
                <div id="drop-zone" class="drop-zone">
                    <span class="drop-zone-prompt">Перетягніть файли сюди або натисніть для вибору</span>
                    <input type="file" name="order_files[]" id="drop-zone-input" class="drop-zone-input" multiple style="display: none;">
                </div>
                <div id="file-preview-container"></div>
                <div class="file-types-info" style="font-size: 0.8rem; color: #777; margin-top: 5px;">
                    Допустимі формати: jpg, jpeg, png, gif, mp4, avi, mov, pdf, doc, docx, txt. Максимальний розмір: 10 МБ.
                </div>
            </div>

            <div class="form-group">
                <label for="phone" class="required-field">Номер телефону</label>
                <input type="tel" name="phone" id="phone" class="form-control" placeholder="+380..." value="<?= htmlspecialchars($user_data['phone'] ?? '') ?>" required>
            </div>

            <div class="form-group">
                <label for="address" class="required-field">Адреса</label>
                <input type="text" name="address" id="address" class="form-control" placeholder="Вкажіть адресу для доставки..." value="<?= htmlspecialchars($user_data['address'] ?? '') ?>" required>
            </div>

            <div class="form-group">
                <label for="delivery_method" class="required-field">Спосіб доставки</label>
                <select name="delivery_method" id="delivery_method" class="form-control" required>
                    <option value="">Виберіть спосіб доставки</option>
                    <option value="Самовивіз" <?= ($user_data['delivery_method'] ?? '') === 'Самовивіз' ? 'selected' : '' ?>>Самовивіз</option>
                    <option value="Нова пошта" <?= ($user_data['delivery_method'] ?? '') === 'Нова пошта' ? 'selected' : '' ?>>Нова пошта</option>
                    <option value="Кур'єр" <?= ($user_data['delivery_method'] ?? '') === 'Кур\'єр' ? 'selected' : '' ?>>Кур'єр</option>
                    <option value="Укрпошта" <?= ($user_data['delivery_method'] ?? '') === 'Укрпошта' ? 'selected' : '' ?>>Укрпошта</option>
                </select>
            </div>

            <div class="form-group">
                <label for="first_name">Ім'я</label>
                <input type="text" name="first_name" id="first_name" class="form-control" value="<?= htmlspecialchars($user_data['first_name'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label for="last_name">Прізвище</label>
                <input type="text" name="last_name" id="last_name" class="form-control" value="<?= htmlspecialchars($user_data['last_name'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label for="middle_name">По-батькові</label>
                <input type="text" name="middle_name" id="middle_name" class="form-control" value="<?= htmlspecialchars($user_data['middle_name'] ?? '') ?>">
            </div>

            <div class="notification-settings">
                <h3>Отримувати сповіщення для цього замовлення</h3>
                <p class="notification-description">Виберіть, як ви хочете отримувати сповіщення про статус замовлення та коментарі адміністраторів</p>

                <div class="notification-option">
                    <label>
                        <span class="switch">
                            <input type="checkbox" name="order_email_notifications" checked>
                            <span class="slider"></span>
                        </span>
                        Email-сповіщення
                    </label>
                    <div class="notification-description">Отримувати повідомлення на вашу електронну пошту</div>
                </div>

                <div class="notification-option">
                    <label>
                        <span class="switch">
                            <input type="checkbox" name="order_push_notifications" checked>
                            <span class="slider"></span>
                        </span>
                        Push-сповіщення у браузері
                    </label>
                    <div class="notification-description">Отримувати миттєві сповіщення у вашому браузері</div>
                </div>
            </div>

            <button type="submit" class="btn">
                <i class="fas fa-plus"></i> Створити замовлення
            </button>
        </form>
    </div>
</div>