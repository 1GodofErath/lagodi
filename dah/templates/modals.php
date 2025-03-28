<!-- Модальні вікна -->
<div class="modal" id="edit-order-modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Редагування замовлення <span id="edit-order-id"></span></h3>
            <button class="close-modal">&times;</button>
        </div>
        <form method="POST" action="dashboard.php" enctype="multipart/form-data" id="edit-order-form">
            <input type="hidden" name="edit_order" value="1">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="order_id" id="modal-order-id">
            <input type="hidden" name="dropped_files" id="edit-dropped_files_data" value="">

            <div class="form-group">
                <label for="edit-service" class="required-field">Послуга</label>
                <select name="service" id="edit-service" class="form-control" required>
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
                <label for="edit-device_type" class="required-field">Тип пристрою</label>
                <input type="text" name="device_type" id="edit-device_type" class="form-control" required>
            </div>

            <div class="form-group">
                <label for="edit-details" class="required-field">Опис проблеми</label>
                <textarea name="details" id="edit-details" class="form-control" rows="5" required></textarea>
            </div>

            <div class="form-group">
                <label for="edit-drop-zone" class="file-upload-label">Прикріпити додаткові файли (опціонально)</label>
                <div id="edit-drop-zone" class="drop-zone">
                    <span class="drop-zone-prompt">Перетягніть файли сюди або натисніть для вибору</span>
                    <input type="file" name="order_files[]" id="edit-drop-zone-input" class="drop-zone-input" multiple style="display: none;">
                </div>
                <div id="edit-file-preview-container"></div>
                <div class="file-types-info" style="font-size: 0.8rem; color: #777; margin-top: 5px;">
                    Допустимі формати: jpg, jpeg, png, gif, mp4, avi, mov, pdf, doc, docx, txt. Максимальний розмір: 10 МБ.
                </div>
            </div>

            <div class="form-group">
                <label for="edit-phone" class="required-field">Номер телефону</label>
                <input type="tel" name="phone" id="edit-phone" class="form-control" required>
            </div>

            <div class="form-group">
                <label for="edit-address" class="required-field">Адреса</label>
                <input type="text" name="address" id="edit-address" class="form-control" required>
            </div>

            <div class="form-group">
                <label for="edit-delivery_method" class="required-field">Спосіб доставки</label>
                <select name="delivery_method" id="edit-delivery_method" class="form-control" required>
                    <option value="">Виберіть спосіб доставки</option>
                    <option value="Самовивіз">Самовивіз</option>
                    <option value="Нова пошта">Нова пошта</option>
                    <option value="Кур'єр">Кур'єр</option>
                    <option value="Укрпошта">Укрпошта</option>
                </select>
            </div>

            <div class="form-group">
                <label for="edit-user_comment">Коментар до замовлення</label>
                <textarea name="user_comment" id="edit-user_comment" class="form-control" rows="3"></textarea>
            </div>

            <button type="submit" class="btn">
                <i class="fas fa-save"></i> Зберегти зміни
            </button>
        </form>
    </div>
</div>

<div class="modal" id="add-comment-modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Додати коментар до замовлення <span id="comment-order-id"></span></h3>
            <button class="close-modal">&times;</button>
        </div>
        <form method="POST" action="dashboard.php" id="add-comment-form">
            <input type="hidden" name="add_comment" value="1">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="order_id" id="comment-order-id-input">

            <div class="form-group">
                <label for="comment" class="required-field">Ваш коментар</label>
                <textarea name="comment" id="comment" class="form-control" rows="5" required></textarea>
            </div>

            <button type="submit" class="btn">
                <i class="fas fa-comment"></i> Додати коментар
            </button>
        </form>
    </div>
</div>

<div class="modal" id="file-view-modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Перегляд файлу: <span id="file-name-title"></span></h3>
            <button class="close-modal">&times;</button>
        </div>
        <div id="file-content-container" class="media-viewer">
            <!-- Тут буде відображено вміст файлу -->
        </div>
    </div>
</div>