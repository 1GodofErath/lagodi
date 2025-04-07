<!-- Модальне вікно деталей замовлення -->
<div id="order-details-modal" class="modal">
    <div class="modal-dialog modal-lg">
        <div class="modal-header">
            <h3 class="modal-title">Деталі замовлення #<span id="detail-order-id"></span></h3>
            <button type="button" class="close-modal" onclick="closeModal('order-details-modal')">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body">
            <div id="order-info-container">
                <div class="loading-spinner"></div>
            </div>

            <h4 class="section-title">Файли</h4>
            <div id="detail-files-container">
                <div class="loading-spinner"></div>
            </div>

            <h4 class="section-title">Коментарі</h4>
            <div id="detail-comments-container">
                <div class="loading-spinner"></div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModal('order-details-modal')">Закрити</button>
            <button type="button" class="btn btn-primary" id="detail-edit-order-btn">Редагувати</button>
        </div>
    </div>
</div>

<!-- Модальне вікно додавання коментаря -->
<div id="add-comment-modal" class="modal">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3 class="modal-title">Додати коментар до замовлення #<span id="comment-order-id"></span></h3>
            <button type="button" class="close-modal" onclick="closeModal('add-comment-modal')">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body">
            <form id="comment-form">
                <input type="hidden" name="add_comment" value="1">
                <input type="hidden" name="order_id" id="comment-order-id-input">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                <div class="form-group">
                    <label for="comment" class="form-label">Текст коментаря*</label>
                    <textarea id="comment" name="comment" class="form-control" rows="5" required></textarea>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModal('add-comment-modal')">Скасувати</button>
            <button type="button" class="btn btn-primary" id="submit-comment">Відправити</button>
        </div>
    </div>
</div>

<!-- Модальне вікно редагування замовлення -->
<div id="edit-order-modal" class="modal">
    <div class="modal-dialog modal-lg">
        <div class="modal-header">
            <h3 class="modal-title">Редагування замовлення #<span id="edit-order-id"></span></h3>
            <button type="button" class="close-modal" onclick="closeModal('edit-order-modal')">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body">
            <div class="order-form-tabs">
                <div class="order-form-tab active" data-tab="edit-details">Деталі замовлення</div>
                <div class="order-form-tab" data-tab="edit-files">Файли</div>
            </div>

            <form id="edit-order-form" enctype="multipart/form-data">
                <input type="hidden" name="edit_order" value="1">
                <input type="hidden" name="order_id" id="modal-order-id">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                <!-- Вміст вкладок буде заповнено динамічно при відкритті модального вікна -->
                <div id="edit-details-content" class="order-form-content active"></div>
                <div id="edit-files-content" class="order-form-content"></div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModal('edit-order-modal')">Скасувати</button>
            <button type="button" class="btn btn-primary" id="submit-edit-order">Зберегти зміни</button>
        </div>
    </div>
</div>

<!-- Модальне вікно підтвердження скасування замовлення -->
<div id="cancel-order-modal" class="modal modal-confirm">
    <div class="modal-dialog modal-sm">
        <div class="modal-header">
            <h3 class="modal-title">Скасування замовлення</h3>
            <button type="button" class="close-modal" onclick="closeModal('cancel-order-modal')">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body">
            <div class="confirm-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <h4 class="confirm-title">Ви впевнені?</h4>
            <p class="confirm-text">Ви дійсно бажаєте скасувати замовлення #<span id="cancel-order-id"></span>? Цю дію неможливо скасувати.</p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModal('cancel-order-modal')">Скасувати</button>
            <button type="button" class="btn btn-danger" id="confirm-cancel-order">Так, скасувати</button>
        </div>
    </div>
</div>

<!-- Модальне вікно повідомлення -->
<div id="message-modal" class="modal">
    <div class="modal-dialog modal-sm">
        <div class="modal-header">
            <h3 class="modal-title" id="message-title">Повідомлення</h3>
            <button type="button" class="close-modal" onclick="closeModal('message-modal')">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body">
            <p id="message-text"></p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-primary" onclick="closeModal('message-modal')">Зрозуміло</button>
        </div>
    </div>
</div>

<!-- Скрипти для роботи з модальними вікнами -->
<script>
    // Функція для відкриття модального вікна
    function openModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
    }

    // Функція для закриття модального вікна
    function closeModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.remove('active');
            document.body.style.overflow = '';
        }
    }

    // Закриття модальних вікон при кліку за межами вмісту
    document.querySelectorAll('.modal').forEach(modal => {
        modal.addEventListener('click', function(event) {
            if (event.target === this) {
                closeModal(this.id);
            }
        });
    });
</script>