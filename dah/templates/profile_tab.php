<?php
// Перевірка, що файл був включений через require, а не прямий доступ
if (!defined('SECURITY_CHECK')) {
    die('Прямий доступ до цього файлу заборонений');
}

// Захисна функція для отримання значень з масиву з перевіркою наявності ключа
function safeArrayGet($array, $key, $default = '') {
    return isset($array[$key]) ? $array[$key] : $default;
}

/**
 * Функція повертає відповідний CSS-клас для статусу замовлення
 * @param string $status Статус замовлення
 * @return string CSS-клас для кольору
 */
function getStatusColor($status) {
    switch ($status) {
        case 'Новий':
            return 'primary';
        case 'В роботі':
            return 'info';
        case 'Очікується поставки товару':
            return 'warning';
        case 'Вирішено':
            return 'success';
        case 'Скасовано':
            return 'danger';
        case 'Завершено':
            return 'success';
        case 'Очікує на оплату':
            return 'warning';
        default:
            return 'secondary';
    }
}
?>

<!-- Вміст вкладки з замовленнями -->
<div class="tab-pane" id="orders" role="tabpanel">
    <div class="card">
        <div class="card-header">
            <h5 class="card-title">Мої замовлення</h5>
            <button class="btn btn-primary btn-sm float-end" data-bs-toggle="modal" data-bs-target="#newOrderModal">
                <i class="fas fa-plus"></i> Нове замовлення
            </button>
        </div>
        <div class="card-body">
            <?php if (empty($orders)): ?>
                <div class="alert alert-info">
                    У вас ще немає замовлень. Ви можете створити нове замовлення, натиснувши кнопку "Нове замовлення".
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead>
                        <tr>
                            <th>№</th>
                            <th>Дата</th>
                            <th>Послуга</th>
                            <th>Пристрій</th>
                            <th>Статус</th>
                            <th>Дії</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($orders as $order): ?>
                            <tr class="<?php echo safeArrayGet($order, 'has_notifications', 0) ? 'has-notification' : ''; ?>">
                                <td><?php echo safeArrayGet($order, 'id', 'N/A'); ?></td>
                                <td><?php echo date('d.m.Y', strtotime(safeArrayGet($order, 'created_at', 'now'))); ?></td>
                                <td><?php echo htmlspecialchars(safeArrayGet($order, 'service', 'Не вказано')); ?></td>
                                <td><?php echo htmlspecialchars(safeArrayGet($order, 'device_type', 'Не вказано')); ?></td>
                                <td>
                                        <span class="badge bg-<?php echo getStatusColor(safeArrayGet($order, 'status', 'Новий')); ?>">
                                            <?php echo htmlspecialchars(safeArrayGet($order, 'status', 'Новий')); ?>
                                        </span>
                                </td>
                                <td>
                                    <button class="btn btn-info btn-sm view-order" data-order-id="<?php echo safeArrayGet($order, 'id', '0'); ?>">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Модальне вікно для створення нового замовлення -->
<div class="modal fade" id="newOrderModal" tabindex="-1" aria-labelledby="newOrderModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="newOrderModalLabel">Створення нового замовлення</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="newOrderForm" method="post" action="actions/create_order.php" enctype="multipart/form-data">
                    <?php foreach ($formFields as $field): ?>
                        <div class="mb-3">
                            <label for="<?php echo safeArrayGet($field, 'field_key', 'field'); ?>" class="form-label">
                                <?php echo htmlspecialchars(safeArrayGet($field, 'field_label', 'Поле')); ?>
                                <?php if (safeArrayGet($field, 'is_required', 0)): ?><span class="text-danger">*</span><?php endif; ?>
                            </label>

                            <?php if (safeArrayGet($field, 'field_type') == 'select'): ?>
                                <?php $options = json_decode(safeArrayGet($field, 'field_options', '{"options":[]}'), true); ?>
                                <select class="form-select" id="<?php echo safeArrayGet($field, 'field_key', 'field'); ?>" name="<?php echo safeArrayGet($field, 'field_key', 'field'); ?>" <?php echo safeArrayGet($field, 'is_required', 0) ? 'required' : ''; ?>>
                                    <option value="">Виберіть...</option>
                                    <?php foreach (safeArrayGet($options, 'options', []) as $option): ?>
                                        <option value="<?php echo htmlspecialchars($option); ?>">
                                            <?php echo htmlspecialchars($option); ?>
                                            <?php if (isset($servicesPrices) && is_array($servicesPrices) && isset($servicesPrices[$option])): ?>
                                                - <?php echo number_format(safeArrayGet(safeArrayGet($servicesPrices, $option, []), 'price', 0), 2, ',', ' '); ?> грн
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                            <?php elseif (safeArrayGet($field, 'field_type') == 'textarea'): ?>
                                <textarea class="form-control" id="<?php echo safeArrayGet($field, 'field_key', 'field'); ?>" name="<?php echo safeArrayGet($field, 'field_key', 'field'); ?>" rows="3" <?php echo safeArrayGet($field, 'is_required', 0) ? 'required' : ''; ?>></textarea>

                            <?php elseif (safeArrayGet($field, 'field_type') == 'radio'): ?>
                                <?php $options = json_decode(safeArrayGet($field, 'field_options', '{"options":[]}'), true); ?>
                                <div>
                                    <?php foreach (safeArrayGet($options, 'options', []) as $index => $option): ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="<?php echo safeArrayGet($field, 'field_key', 'field'); ?>" id="<?php echo safeArrayGet($field, 'field_key', 'field') . $index; ?>" value="<?php echo htmlspecialchars($option); ?>" <?php echo $index === 0 && safeArrayGet($field, 'is_required', 0) ? 'required' : ''; ?>>
                                            <label class="form-check-label" for="<?php echo safeArrayGet($field, 'field_key', 'field') . $index; ?>">
                                                <?php echo htmlspecialchars($option); ?>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                            <?php else: ?>
                                <input type="<?php echo safeArrayGet($field, 'field_type', 'text'); ?>" class="form-control" id="<?php echo safeArrayGet($field, 'field_key', 'field'); ?>" name="<?php echo safeArrayGet($field, 'field_key', 'field'); ?>" <?php echo safeArrayGet($field, 'is_required', 0) ? 'required' : ''; ?>>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                    <!-- Додавання файлів -->
                    <div class="mb-3">
                        <label for="order_files" class="form-label">Прикріпити файли (фото, документи)</label>
                        <input class="form-control" type="file" id="order_files" name="order_files[]" multiple>
                        <div class="form-text">Максимальний розмір файлу: 10 МБ. Підтримувані формати: JPG, PNG, PDF, DOCX.</div>
                    </div>

                    <input type="hidden" name="create_order" value="1">
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Скасувати</button>
                <button type="submit" form="newOrderForm" class="btn btn-primary">Створити замовлення</button>
            </div>
        </div>
    </div>
</div>

<!-- Модальне вікно для перегляду замовлення -->
<div class="modal fade" id="viewOrderModal" tabindex="-1" aria-labelledby="viewOrderModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="viewOrderModalLabel">Деталі замовлення</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="orderDetailsContainer">
                <div class="text-center">
                    <div class="spinner-border" role="status">
                        <span class="visually-hidden">Завантаження...</span>
                    </div>
                    <p>Завантаження інформації...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрити</button>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Обробник кліку на кнопку перегляду замовлення
        document.querySelectorAll('.view-order').forEach(function(button) {
            button.addEventListener('click', function() {
                const orderId = this.getAttribute('data-order-id');

                // Показуємо модальне вікно
                const viewOrderModal = new bootstrap.Modal(document.getElementById('viewOrderModal'));
                viewOrderModal.show();

                // Завантажуємо дані замовлення
                fetch('actions/get_order_details.php?order_id=' + orderId)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            document.getElementById('orderDetailsContainer').innerHTML = data.html;
                            // Ініціалізуємо функціональність для коментарів
                            initCommentFunctionality();
                        } else {
                            document.getElementById('orderDetailsContainer').innerHTML = '<div class="alert alert-danger">' + data.error + '</div>';
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        document.getElementById('orderDetailsContainer').innerHTML = '<div class="alert alert-danger">Сталася помилка при завантаженні деталей замовлення.</div>';
                    });
            });
        });

        // Функція для ініціалізації функціональності коментарів
        function initCommentFunctionality() {
            const commentForm = document.getElementById('commentForm');
            if (commentForm) {
                commentForm.addEventListener('submit', function(e) {
                    e.preventDefault();

                    const formData = new FormData(this);

                    fetch('actions/add_comment.php', {
                        method: 'POST',
                        body: formData
                    })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                // Додаємо новий коментар до списку
                                const commentsContainer = document.getElementById('orderComments');
                                commentsContainer.innerHTML = data.html + commentsContainer.innerHTML;

                                // Очищаємо форму
                                document.getElementById('comment_text').value = '';
                                document.getElementById('comment_file').value = '';
                            } else {
                                alert(data.error);
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            alert('Сталася помилка при додаванні коментаря.');
                        });
                });
            }
        }
    });
</script>