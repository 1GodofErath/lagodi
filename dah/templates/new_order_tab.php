<div class="page-header">
    <h1>Нове замовлення</h1>
</div>

<div class="card">
    <div class="card-body">
        <form id="new-order-form" method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="service_id" id="service_id">

            <div class="form-row">
                <div class="form-group">
                    <label for="category">Категорія <span class="required">*</span></label>
                    <select id="category" name="category" class="form-control" required>
                        <option value="">Виберіть категорію</option>
                        <?php foreach ($repairCategories as $category): ?>
                            <option value="<?= $category['id'] ?>"><?= htmlspecialchars($category['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="service">Послуга <span class="required">*</span></label>
                    <select id="service" name="service" class="form-control" disabled required>
                        <option value="">Виберіть послугу</option>
                    </select>
                </div>
            </div>

            <div class="form-group service-info" style="display: none;">
                <div class="service-price">
                    Вартість: <span id="service-price">0.00 грн</span>
                </div>
                <div class="service-time">
                    Приблизний час виконання: <span id="service-time">1-3 дні</span>
                </div>
                <div class="service-description" id="service-description"></div>
            </div>

            <div class="form-group">
                <label for="device-type">Тип пристрою <span class="required">*</span></label>
                <input type="text" id="device-type" name="device_type" class="form-control" required placeholder="Наприклад: Samsung Galaxy S21, iPhone 13, Lenovo ThinkPad і т.д.">
            </div>

            <div class="form-group">
                <label for="details">Опис проблеми <span class="required">*</span></label>
                <textarea id="details" name="details" class="form-control" rows="5" required placeholder="Детально опишіть проблему, з якою ви звернулися"></textarea>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="phone">Контактний телефон <span class="required">*</span></label>
                    <input type="tel" id="phone" name="phone" class="form-control" required value="<?= htmlspecialchars($userData['phone'] ?? '') ?>" placeholder="+380501234567">
                </div>

                <div class="form-group">
                    <label for="delivery">Спосіб доставки</label>
                    <select id="delivery" name="delivery_method" class="form-control">
                        <option value="">Виберіть спосіб доставки</option>
                        <option value="self">Самовивіз</option>
                        <option value="courier">Кур'єр</option>
                        <option value="nova-poshta">Нова Пошта</option>
                        <option value="ukrposhta">Укрпошта</option>
                    </select>
                </div>
            </div>

            <div class="form-group delivery-address" style="display: none;">
                <label for="address">Адреса</label>
                <input type="text" id="address" name="address" class="form-control" placeholder="Вкажіть адресу для доставки кур'єром">
            </div>

            <div class="form-group">
                <label for="comment">Додатковий коментар</label>
                <textarea id="comment" name="comment" class="form-control" rows="3" placeholder="Додаткова інформація для сервісного центру"></textarea>
            </div>

            <div class="form-group">
                <label for="files">Прикріпити файли</label>
                <div class="file-upload">
                    <input type="file" id="files" name="files[]" multiple class="file-input" accept="image/*, video/*, .pdf, .doc, .docx, .txt, .log">
                    <label for="files" class="file-label">
                        <i class="fas fa-cloud-upload-alt"></i>
                        <span>Виберіть файли або перетягніть їх сюди</span>
                    </label>
                </div>
                <div id="files-preview" class="file-preview"></div>
                <div class="form-hint">
                    Допустимі формати: зображення (JPG, PNG, GIF), відео (MP4, AVI), документи (PDF, DOC, DOCX), текст (TXT, LOG)<br>
                    Максимальний розмір файлу: 10 МБ
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-lg">Створити замовлення</button>
        </form>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Обробка вибору категорії та завантаження послуг
        const categorySelect = document.getElementById('category');
        const serviceSelect = document.getElementById('service');
        const serviceInfoBlock = document.querySelector('.service-info');
        const servicePrice = document.getElementById('service-price');
        const serviceTime = document.getElementById('service-time');
        const serviceDescription = document.getElementById('service-description');
        const serviceIdInput = document.getElementById('service_id');

        categorySelect.addEventListener('change', function() {
            const categoryId = this.value;

            // Скидаємо вибір послуги
            serviceSelect.innerHTML = '<option value="">Виберіть послугу</option>';
            serviceSelect.disabled = true;
            serviceInfoBlock.style.display = 'none';
            serviceIdInput.value = '';

            if (categoryId) {
                // Показуємо індикатор завантаження
                serviceSelect.innerHTML = '<option value="">Завантаження...</option>';

                // Завантажуємо послуги для обраної категорії
                fetch(`dashboard.php?get_services_by_category=${categoryId}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.services && data.services.length > 0) {
                            let options = '<option value="">Виберіть послугу</option>';

                            data.services.forEach(service => {
                                options += `<option value="${service.name}" data-id="${service.id}"
                                               data-price="${service.price}"
                                               data-time="${service.estimated_time}"
                                               data-description="${service.description || ''}">${service.name} - ${formatCurrency(service.price)}</option>`;
                            });

                            serviceSelect.innerHTML = options;
                            serviceSelect.disabled = false;
                        } else {
                            serviceSelect.innerHTML = '<option value="">Немає доступних послуг</option>';
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        serviceSelect.innerHTML = '<option value="">Помилка завантаження</option>';
                    });
            }
        });

        // Обробка вибору послуги і відображення інформації
        serviceSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];

            if (selectedOption && selectedOption.value) {
                const serviceId = selectedOption.getAttribute('data-id');
                const price = selectedOption.getAttribute('data-price');
                const time = selectedOption.getAttribute('data-time');
                const description = selectedOption.getAttribute('data-description');

                serviceIdInput.value = serviceId;
                servicePrice.textContent = formatCurrency(price);
                serviceTime.textContent = time || '1-3 дні';

                if (description) {
                    serviceDescription.textContent = description;
                    serviceDescription.style.display = 'block';
                } else {
                    serviceDescription.style.display = 'none';
                }

                serviceInfoBlock.style.display = 'block';
            } else {
                serviceInfoBlock.style.display = 'none';
                serviceIdInput.value = '';
            }
        });

        // Обробка вибору способу доставки
        const deliverySelect = document.getElementById('delivery');
        const addressBlock = document.querySelector('.delivery-address');

        deliverySelect.addEventListener('change', function() {
            if (this.value === 'courier' || this.value === 'nova-poshta' || this.value === 'ukrposhta') {
                addressBlock.style.display = 'block';
            } else {
                addressBlock.style.display = 'none';
            }
        });

        // Форматування валюти
        function formatCurrency(value) {
            return new Intl.NumberFormat('uk-UA', {
                style: 'currency',
                currency: 'UAH',
                minimumFractionDigits: 2
            }).format(value);
        }

        // Обробка відправки форми
        const form = document.getElementById('new-order-form');

        form.addEventListener('submit', function(e) {
            e.preventDefault();

            // Відправка форми через AJAX
            const formData = new FormData(this);
            formData.append('create_order', '1');

            // Показуємо індикатор завантаження
            const submitButton = this.querySelector('button[type="submit"]');
            const originalText = submitButton.innerHTML;
            submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Відправка...';
            submitButton.disabled = true;

            fetch('dashboard.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification('success', data.message || 'Замовлення успішно створено');

                        // Перенаправлення через 1.5 секунди
                        setTimeout(() => {
                            window.location.href = data.redirect || '?tab=orders';
                        }, 1500);
                    } else {
                        showNotification('error', data.message || 'Помилка при створенні замовлення');

                        // Відновлюємо стан кнопки
                        submitButton.innerHTML = originalText;
                        submitButton.disabled = false;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('error', 'Помилка при створенні замовлення');

                    // Відновлюємо стан кнопки
                    submitButton.innerHTML = originalText;
                    submitButton.disabled = false;
                });
        });
    });
</script>