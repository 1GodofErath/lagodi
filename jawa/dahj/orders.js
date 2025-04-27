/**
 * Модуль для роботи з замовленнями
 */
document.addEventListener('DOMContentLoaded', function() {
    initOrdersModule();
});

/**
 * Ініціалізація функцій для безпечного додавання обробників подій щоб уникнути дублювання
 * @param {string} selector - CSS-селектор для елементів
 * @param {string} eventType - Тип події (click, change тощо)
 * @param {Function} handler - Функція-обробник події
 */
function safeAddEventListener(selector, eventType, handler) {
    document.querySelectorAll(selector).forEach(function(element) {
        // Перевіряємо, чи вже є обробник цього типу на елементі
        const dataAttr = 'data-has-' + eventType;
        if (!element.hasAttribute(dataAttr)) {
            element.addEventListener(eventType, handler);
            // Позначаємо елемент як такий, що має обробник події
            element.setAttribute(dataAttr, 'true');
        }
    });
}

/**
 * Ініціалізація модуля замовлень
 */
function initOrdersModule() {
    // Фільтрація замовлень
    initOrderFilters();

    // Перегляд деталей замовлення
    initViewDetailsButtons();

    // Система коментарів до замовлень
    initOrderComments();

    // Прикріплення та перегляд файлів
    initFileUploads();

    // Скасування замовлень
    initCancelOrders();

    // Редагування замовлень
    initEditOrders();
}

/**
 * Ініціалізація фільтрів замовлень
 */
function initOrderFilters() {
    const filterForm = document.getElementById('filter-form');
    const statusFilter = document.getElementById('status');
    const serviceFilter = document.getElementById('service');
    const searchFilter = document.getElementById('search');
    const clearFiltersBtn = document.querySelector('.filters-form .btn-outline');

    if (filterForm) {
        // Автоматичне застосування фільтрів при зміні
        if (statusFilter) {
            statusFilter.addEventListener('change', function() {
                filterForm.submit();
            });
        }

        if (serviceFilter) {
            serviceFilter.addEventListener('change', function() {
                filterForm.submit();
            });
        }

        // Очистка фільтрів
        if (clearFiltersBtn) {
            clearFiltersBtn.addEventListener('click', function(event) {
                event.preventDefault();
                if (statusFilter) statusFilter.value = '';
                if (serviceFilter) serviceFilter.value = '';
                if (searchFilter) searchFilter.value = '';
                filterForm.submit();
            });
        }
    }
}

/**
 * Ініціалізація перегляду деталей замовлення
 */
function initViewDetailsButtons() {
    safeAddEventListener('.view-order-details', 'click', function() {
        const orderId = this.getAttribute('data-id');
        if (orderId) {
            viewOrderDetails(orderId);
        }
    });
}

/**
 * Функція для отримання та відображення деталей замовлення
 * @param {string} orderId - ID замовлення
 */
function viewOrderDetails(orderId) {
    // Показати модальне вікно з індикатором завантаження
    const orderDetailsModal = document.getElementById('order-details-modal');
    const orderIdElement = document.getElementById('detail-order-id');
    const orderInfoContainer = document.getElementById('order-info-container');
    const filesContainer = document.getElementById('detail-files-container');
    const commentsContainer = document.getElementById('detail-comments-container');
    const editOrderBtn = document.getElementById('detail-edit-order-btn');

    if (!orderDetailsModal) return;

    // Скидаємо попередній вміст
    if (orderIdElement) orderIdElement.textContent = orderId;
    if (orderInfoContainer) orderInfoContainer.innerHTML = '<div class="loading-spinner"></div>';
    if (filesContainer) filesContainer.innerHTML = '<div class="loading-spinner"></div>';
    if (commentsContainer) commentsContainer.innerHTML = '<div class="loading-spinner"></div>';

    // Відкриваємо модальне вікно
    openModal('order-details-modal');

    // Додаємо мітку часу для уникнення кешування
    const timestamp = new Date().getTime();

    // Завантаження даних замовлення
    fetch(`dashboard.php?get_order_details=${orderId}&_=${timestamp}`, {
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
        .then(response => {
            if (!response.ok) {
                throw new Error('Помилка завантаження деталей замовлення');
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                const order = data.order;

                // Оновлюємо кнопку редагування
                if (editOrderBtn) {
                    if (isOrderEditable(order.status)) {
                        editOrderBtn.style.display = 'inline-flex';
                        editOrderBtn.setAttribute('data-id', orderId);

                        // Видаляємо попередній обробник, якщо є
                        const newBtn = editOrderBtn.cloneNode(true);
                        editOrderBtn.parentNode.replaceChild(newBtn, editOrderBtn);

                        // Додаємо новий обробник
                        newBtn.addEventListener('click', function() {
                            closeModal('order-details-modal');
                            editOrder(orderId);
                        });
                    } else {
                        editOrderBtn.style.display = 'none';
                    }
                }

                // Заповнюємо інформацію про замовлення
                if (orderInfoContainer) {
                    orderInfoContainer.innerHTML = generateOrderInfoHTML(order);
                }

                // Заповнюємо файли
                if (filesContainer) {
                    if (order.files && order.files.length > 0) {
                        filesContainer.innerHTML = generateFilesHTML(order.files);
                        initFileViewers();
                    } else {
                        filesContainer.innerHTML = `
                        <div class="empty-state-mini">
                            <i class="fas fa-file-alt"></i>
                            <p>Немає прикріплених файлів</p>
                        </div>
                    `;
                    }
                }

                // Заповнюємо коментарі
                if (commentsContainer) {
                    if (order.comments && order.comments.length > 0) {
                        commentsContainer.innerHTML = generateCommentsHTML(order.comments);
                    } else {
                        commentsContainer.innerHTML = `
                        <div class="empty-state-mini">
                            <i class="far fa-comments"></i>
                            <p>Немає коментарів</p>
                        </div>
                    `;
                    }

                    // Додаємо форму для коментаря, якщо замовлення не скасоване
                    if (order.status.toLowerCase() !== 'скасовано' && order.status.toLowerCase() !== 'відмінено' &&
                        order.status.toLowerCase() !== 'завершено') {
                        const commentForm = document.createElement('div');
                        commentForm.className = 'comment-form';
                        commentForm.innerHTML = `
                        <textarea class="comment-input" placeholder="Напишіть коментар..." data-order-id="${orderId}"></textarea>
                        <button class="send-comment" data-order-id="${orderId}">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    `;
                        commentsContainer.appendChild(commentForm);

                        // Ініціалізація обробника для нової форми коментаря
                        const textarea = commentForm.querySelector('.comment-input');
                        const sendBtn = commentForm.querySelector('.send-comment');

                        if (textarea && sendBtn) {
                            sendBtn.addEventListener('click', function() {
                                const comment = textarea.value.trim();
                                addComment(orderId, comment);
                            });

                            // Обробник Ctrl+Enter для відправки
                            textarea.addEventListener('keydown', function(e) {
                                if (e.ctrlKey && e.keyCode === 13) {
                                    const comment = this.value.trim();
                                    addComment(orderId, comment);
                                }
                            });
                        }
                    }
                }

                // Оновлюємо лічильник непрочитаних сповіщень, якщо вони були
                updateUnreadBadge(order.id);
            }
        })
        .catch(error => {
            console.error('Помилка:', error);
            if (orderInfoContainer) {
                orderInfoContainer.innerHTML = `
                <div class="empty-state-mini">
                    <i class="fas fa-exclamation-circle"></i>
                    <p>Помилка завантаження даних замовлення</p>
                </div>
            `;
            }
        });
}

/**
 * Оновлення індикаторів непрочитаних повідомлень для замовлення
 * @param {string} orderId - ID замовлення
 */
function updateUnreadBadge(orderId) {
    // Шукаємо картку з цим замовленням
    const orderCard = document.querySelector(`.order-card[data-order-id="${orderId}"]`);
    if (!orderCard) return;

    // Видаляємо клас unread і бейдж, якщо вони є
    orderCard.classList.remove('has-unread');
    const badge = orderCard.querySelector('.badge.badge-danger');
    if (badge) {
        badge.remove();
    }
}

/**
 * Функція для додавання коментаря до замовлення
 * @param {string} orderId - ID замовлення
 * @param {string} comment - Текст коментаря
 * @param {function} callback - Функція зворотного виклику після успішного додавання
 */
function addComment(orderId, comment, callback) {
    // Перевірка вхідних даних
    if (!orderId || !comment.trim()) {
        showToast('Будь ласка, введіть текст коментаря', 'warning');
        return;
    }

    // Отримання CSRF токена
    const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';

    // Підготовка даних для відправки
    const formData = new FormData();
    formData.append('ajax_add_comment', '1');
    formData.append('order_id', orderId);
    formData.append('comment', comment);
    formData.append('csrf_token', csrfToken);

    // Показуємо індикатор завантаження
    const commentButton = document.querySelector(`.send-comment[data-order-id="${orderId}"]`);
    if (commentButton) {
        commentButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        commentButton.disabled = true;
    }

    fetch('dashboard.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
        .then(response => {
            if (!response.ok) {
                throw new Error('Помилка сервера при додаванні коментаря');
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                showToast('Коментар успішно додано', 'success');

                // Очищення поля введення
                const commentInput = document.querySelector(`.comment-input[data-order-id="${orderId}"]`);
                if (commentInput) {
                    commentInput.value = '';
                }

                // Виклик callback функції
                if (typeof callback === 'function') {
                    callback(data);
                }

                // Якщо це модальне вікно деталей замовлення, оновлюємо коментарі
                const detailsModal = document.getElementById('order-details-modal');
                if (detailsModal && detailsModal.classList.contains('active')) {
                    // Перезавантажуємо деталі замовлення
                    viewOrderDetails(orderId);
                } else {
                    // Інакше додаємо коментар до списку на сторінці
                    addCommentToList(data.comment, orderId);
                }
            } else {
                showToast(data.message || 'Помилка додавання коментаря', 'error');
            }
        })
        .catch(error => {
            console.error('Помилка:', error);
            showToast('Помилка додавання коментаря', 'error');
        })
        .finally(() => {
            // Відновлюємо кнопку
            if (commentButton) {
                commentButton.innerHTML = '<i class="fas fa-paper-plane"></i>';
                commentButton.disabled = false;
            }
        });
}

/**
 * Додавання коментаря до списку на сторінці
 * @param {Object} comment - Об'єкт з даними коментаря
 * @param {string} orderId - ID замовлення
 */
function addCommentToList(comment, orderId) {
    // Шукаємо контейнер з коментарями для цього замовлення
    const orderCard = document.querySelector(`.order-card[data-order-id="${orderId}"]`);
    if (!orderCard) return;

    const commentsContainer = orderCard.querySelector('.comments-list');
    const emptyState = orderCard.querySelector('.empty-state-mini');

    // Якщо є повідомлення про відсутність коментарів, видаляємо його
    if (emptyState) {
        emptyState.remove();
    }

    // Створюємо контейнер для коментарів, якщо його немає
    if (!commentsContainer) {
        const orderCommentsBlock = orderCard.querySelector('.order-comments');
        if (orderCommentsBlock) {
            // Спочатку створюємо заголовок
            const heading = document.createElement('h4');
            heading.className = 'comments-heading';
            heading.innerHTML = `
                Останні коментарі
                <button class="btn btn-sm add-comment" data-id="${orderId}">
                    <i class="fas fa-plus"></i> Додати
                </button>
            `;
            orderCommentsBlock.insertBefore(heading, orderCommentsBlock.firstChild);

            // Потім додаємо контейнер для коментарів
            const newCommentsContainer = document.createElement('div');
            newCommentsContainer.className = 'comments-list';
            orderCommentsBlock.insertBefore(newCommentsContainer, orderCommentsBlock.querySelector('.comment-form'));

            // Додаємо обробник для кнопки додавання коментаря
            const addButton = heading.querySelector('.add-comment');
            if (addButton) {
                addButton.addEventListener('click', function() {
                    const commentModal = document.getElementById('add-comment-modal');
                    const orderIdElement = document.getElementById('comment-order-id');
                    const orderIdInput = document.getElementById('comment-order-id-input');
                    const commentTextarea = document.getElementById('comment');

                    if (commentModal && orderIdElement && orderIdInput && commentTextarea) {
                        orderIdElement.textContent = orderId;
                        orderIdInput.value = orderId;
                        commentTextarea.value = '';

                        openModal('add-comment-modal');
                    }
                });
            }
        }
    }

    // Отримуємо оновлений контейнер для коментарів
    const updatedCommentsContainer = orderCard.querySelector('.comments-list');
    if (!updatedCommentsContainer) return;

    // Створюємо елемент коментаря
    const commentElement = document.createElement('div');
    commentElement.className = 'comment';
    commentElement.innerHTML = `
        <div class="comment-header">
            <span class="comment-author">${escapeHtml(comment.author || comment.admin_name || 'Користувач')}</span>
            <span class="comment-date">${formatDateTime(comment.created_at)}</span>
        </div>
        <div class="comment-text">${nl2br(escapeHtml(comment.comment || comment.content))}</div>
    `;

    // Додаємо коментар на початок списку
    if (updatedCommentsContainer.firstChild) {
        updatedCommentsContainer.insertBefore(commentElement, updatedCommentsContainer.firstChild);

        // Обмежуємо кількість видимих коментарів до 2
        const comments = updatedCommentsContainer.querySelectorAll('.comment');
        if (comments.length > 2) {
            // Приховуємо всі коментарі, крім перших двох
            let hiddenCount = 0;
            for (let i = 2; i < comments.length; i++) {
                comments[i].style.display = 'none';
                hiddenCount++;
            }

            // Оновлюємо або додаємо блок "Показати всі коментарі"
            let moreComments = orderCard.querySelector('.more-comments');
            if (!moreComments) {
                moreComments = document.createElement('div');
                moreComments.className = 'more-comments';
                updatedCommentsContainer.parentNode.insertBefore(moreComments, updatedCommentsContainer.nextSibling);
            }

            moreComments.innerHTML = `
                <button class="btn btn-sm btn-link view-order-details" data-id="${orderId}">
                    <i class="fas fa-comments"></i> Показати всі коментарі (${comments.length})
                </button>
            `;

            // Додаємо обробник для кнопки перегляду всіх коментарів
            const viewButton = moreComments.querySelector('.view-order-details');
            if (viewButton) {
                viewButton.addEventListener('click', function() {
                    viewOrderDetails(orderId);
                });
            }
        }
    } else {
        updatedCommentsContainer.appendChild(commentElement);
    }
}

/**
 * Генерація HTML для інформації про замовлення
 * @param {Object} order - Об'єкт замовлення
 * @returns {string} HTML-код з інформацією про замовлення
 */
function generateOrderInfoHTML(order) {
    return `
        <div class="order-info">
            <div class="order-detail">
                <div class="order-detail-label">Послуга</div>
                <div class="order-detail-value">${escapeHtml(order.service)}</div>
            </div>
            
            <div class="order-detail">
                <div class="order-detail-label">Статус</div>
                <div class="order-detail-value">
                    <span class="status-badge ${getStatusClass(order.status)}">${escapeHtml(order.status)}</span>
                </div>
            </div>
            
            <div class="order-detail">
                <div class="order-detail-label">Тип пристрою</div>
                <div class="order-detail-value">${escapeHtml(order.device_type)}</div>
            </div>
            
            <div class="order-detail">
                <div class="order-detail-label">Дата створення</div>
                <div class="order-detail-value">${formatDate(order.created_at)}</div>
            </div>
            
            <div class="order-detail">
                <div class="order-detail-label">Контактний телефон</div>
                <div class="order-detail-value">${escapeHtml(order.phone)}</div>
            </div>
            
            ${order.address ? `
            <div class="order-detail">
                <div class="order-detail-label">Адреса</div>
                <div class="order-detail-value">${escapeHtml(order.address)}</div>
            </div>
            ` : ''}
            
            ${order.delivery_method ? `
            <div class="order-detail">
                <div class="order-detail-label">Спосіб доставки</div>
                <div class="order-detail-value">${escapeHtml(order.delivery_method)}</div>
            </div>
            ` : ''}
        </div>
        
        <div class="order-detail" style="grid-column: 1 / -1;">
            <div class="order-detail-label">Опис проблеми</div>
            <div class="order-detail-value">${nl2br(escapeHtml(order.details))}</div>
        </div>
        
        ${order.user_comment ? `
        <div class="order-detail" style="grid-column: 1 / -1;">
            <div class="order-detail-label">Коментар користувача</div>
            <div class="order-detail-value">${nl2br(escapeHtml(order.user_comment))}</div>
        </div>
        ` : ''}
        
        ${order.estimated_completion_date ? `
        <div class="order-detail">
            <div class="order-detail-label">Очікувана дата завершення</div>
            <div class="order-detail-value">${formatDate(order.estimated_completion_date)}</div>
        </div>
        ` : ''}
    `;
}

/**
 * Генерація HTML для файлів замовлення
 * @param {Array} files - Масив файлів
 * @returns {string} HTML-код для файлів
 */
function generateFilesHTML(files) {
    let html = '<div class="files-grid">';

    files.forEach(function(file) {
        const fileIcon = getFileIcon(file.ext || getFileExtension(file.name));
        const isImage = ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes((file.ext || getFileExtension(file.name))?.toLowerCase());

        html += `
            <div class="file-item" data-path="${escapeHtml(file.path)}" data-name="${escapeHtml(file.name)}">
                <div class="file-thumbnail">
                    ${isImage ? `<img src="${escapeHtml(file.path)}" alt="${escapeHtml(file.name)}">` : `<i class="file-icon ${fileIcon}"></i>`}
                </div>
                <div class="file-name">${escapeHtml(file.name)}</div>
                <div class="file-overlay">
                    <div class="file-actions">
                        <button class="file-action view-file" title="Переглянути">
                            <i class="fas fa-eye"></i>
                        </button>
                        <a href="${escapeHtml(file.path)}" download="${escapeHtml(file.name)}" class="file-action" title="Завантажити">
                            <i class="fas fa-download"></i>
                        </a>
                    </div>
                </div>
            </div>
        `;
    });

    html += '</div>';
    return html;
}

/**
 * Генерація HTML для коментарів до замовлення
 * @param {Array} comments - Масив коментарів
 * @returns {string} HTML-код для коментарів
 */
function generateCommentsHTML(comments) {
    let html = '<div class="comments-list">';

    comments.forEach(function(comment) {
        html += `
            <div class="comment">
                <div class="comment-header">
                    <span class="comment-author">${escapeHtml(comment.author || comment.admin_name || 'Адміністратор')}</span>
                    <span class="comment-date">${formatDateTime(comment.created_at)}</span>
                </div>
                <div class="comment-text">${nl2br(escapeHtml(comment.comment || comment.content))}</div>
            </div>
        `;
    });

    html += '</div>';
    return html;
}

/**
 * Ініціалізація системи коментарів
 */
function initOrderComments() {
    // Додавання коментаря через кнопку "Додати коментар"
    safeAddEventListener('.add-comment', 'click', function() {
        const orderId = this.getAttribute('data-id');

        // Заповнюємо та показуємо модальне вікно для додавання коментаря
        const commentModal = document.getElementById('add-comment-modal');
        const orderIdElement = document.getElementById('comment-order-id');
        const orderIdInput = document.getElementById('comment-order-id-input');
        const commentTextarea = document.getElementById('comment');

        if (commentModal && orderIdElement && orderIdInput && commentTextarea) {
            orderIdElement.textContent = orderId;
            orderIdInput.value = orderId;
            commentTextarea.value = '';

            openModal('add-comment-modal');
        }
    });

    // Відправлення коментаря з модального вікна
    const submitCommentBtn = document.getElementById('submit-comment');
    if (submitCommentBtn) {
        submitCommentBtn.addEventListener('click', function() {
            const orderId = document.getElementById('comment-order-id-input').value;
            const comment = document.getElementById('comment').value.trim();

            if (!comment) {
                showToast('Введіть текст коментаря', 'warning');
                return;
            }

            // Відправка коментаря через основну функцію
            addComment(orderId, comment, function() {
                closeModal('add-comment-modal');
            });
        });
    }

    // Ініціалізація форм коментарів у списку замовлень
    safeAddEventListener('.send-comment', 'click', function() {
        const orderId = this.getAttribute('data-order-id');
        const textarea = document.querySelector(`.comment-input[data-order-id="${orderId}"]`);

        if (textarea) {
            const comment = textarea.value.trim();
            addComment(orderId, comment);
        }
    });

    // Додавання обробників для клавіатурних скорочень
    safeAddEventListener('.comment-input', 'keydown', function(e) {
        // Ctrl+Enter для відправки коментаря
        if (e.ctrlKey && e.keyCode === 13) {
            const orderId = this.getAttribute('data-order-id');
            const comment = this.value.trim();

            if (orderId && comment) {
                addComment(orderId, comment);
            }
        }
    });
}

/**
 * Ініціалізація завантаження файлів
 */
function initFileUploads() {
    const fileDropArea = document.querySelector('.file-drop-area');
    const fileInput = document.querySelector('.file-input');
    const selectedFilesContainer = document.querySelector('.selected-files');

    if (fileDropArea && fileInput) {
        // Обробка події drag & drop
        fileDropArea.addEventListener('dragover', function(e) {
            e.preventDefault();
            this.classList.add('drag-over');
        });

        fileDropArea.addEventListener('dragleave', function() {
            this.classList.remove('drag-over');
        });

        fileDropArea.addEventListener('drop', function(e) {
            e.preventDefault();
            this.classList.remove('drag-over');

            if (e.dataTransfer.files.length > 0) {
                fileInput.files = e.dataTransfer.files;
                showSelectedFiles(fileInput.files, selectedFilesContainer);
            }
        });

        // Обробка вибору файлів через діалог
        fileDropArea.addEventListener('click', function() {
            fileInput.click();
        });

        fileInput.addEventListener('change', function() {
            showSelectedFiles(this.files, selectedFilesContainer);
        });
    }

    // Ініціалізація перегляду файлів в списку замовлень
    initFileViewers();
}

/**
 * Відображення вибраних файлів
 * @param {FileList} files - Список файлів
 * @param {HTMLElement} container - Контейнер для відображення
 */
function showSelectedFiles(files, container) {
    if (!container) return;

    container.innerHTML = '';

    if (files.length === 0) {
        container.innerHTML = '<p>Файли не вибрано</p>';
        return;
    }

    for (let i = 0; i < files.length; i++) {
        const file = files[i];
        const fileType = file.type.split('/')[0];
        const isImage = fileType === 'image';

        const fileItem = document.createElement('div');
        fileItem.className = 'selected-file-item';

        let thumbnailContent = '';
        if (isImage) {
            const imgUrl = URL.createObjectURL(file);
            thumbnailContent = `<img src="${imgUrl}" alt="${file.name}">`;
        } else {
            thumbnailContent = `<i class="selected-file-icon ${getFileIconByMimeType(file.type)}"></i>`;
        }

        fileItem.innerHTML = `
            <div class="selected-file-thumbnail">
                ${thumbnailContent}
            </div>
            <div class="selected-file-name">${file.name}</div>
            <div class="selected-file-size">${formatFileSize(file.size)}</div>
            <button type="button" class="selected-file-remove" data-index="${i}">
                <i class="fas fa-times"></i>
            </button>
        `;

        container.appendChild(fileItem);
    }

    // Додавання обробників для кнопок видалення
    container.querySelectorAll('.selected-file-remove').forEach(button => {
        button.addEventListener('click', function() {
            const index = parseInt(this.getAttribute('data-index'));
            removeFileFromInput(fileInput, index);
            showSelectedFiles(fileInput.files, container);
        });
    });
}

/**
 * Видалення файлу з інпута за індексом
 * @param {HTMLInputElement} input - Елемент інпута
 * @param {number} index - Індекс файлу для видалення
 */
function removeFileFromInput(input, index) {
    if (!input || !input.files || index < 0 || index >= input.files.length) return;

    // Створюємо новий об'єкт для збереження файлів
    const dt = new DataTransfer();

    // Додаємо всі файли крім того, який потрібно видалити
    for (let i = 0; i < input.files.length; i++) {
        if (i !== index) {
            dt.items.add(input.files[i]);
        }
    }

    // Оновлюємо інпут
    input.files = dt.files;
}

/**
 * Ініціалізація перегляду файлів
 */
function initFileViewers() {
    safeAddEventListener('.view-file', 'click', function() {
        const fileItem = this.closest('.file-item');
        if (!fileItem) return;

        const filePath = fileItem.getAttribute('data-path');
        const fileName = fileItem.getAttribute('data-name');

        if (filePath && fileName) {
            // Виклик модуля перегляду файлів
            if (typeof MediaViewer !== 'undefined' && MediaViewer.open) {
                MediaViewer.open(filePath, fileName);
            } else {
                // Запасний варіант - відкриття в новій вкладці
                window.open(filePath, '_blank');
            }
        }
    });
}

/**
 * Ініціалізація скасування замовлень
 */
function initCancelOrders() {
    safeAddEventListener('.cancel-order', 'click', function() {
        const orderId = this.getAttribute('data-id');

        // Налаштування модального вікна підтвердження
        const cancelModal = document.getElementById('cancel-order-modal');
        const orderIdElement = document.getElementById('cancel-order-id');
        const confirmBtn = document.getElementById('confirm-cancel-order');

        if (cancelModal && orderIdElement && confirmBtn) {
            orderIdElement.textContent = orderId;

            // Оновлюємо обробник для кнопки підтвердження
            const newConfirmBtn = confirmBtn.cloneNode(true);
            confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);

            newConfirmBtn.addEventListener('click', function() {
                cancelOrder(orderId);
            });

            openModal('cancel-order-modal');
        }
    });
}

/**
 * Скасування замовлення
 * @param {string} orderId - ID замовлення
 */
function cancelOrder(orderId) {
    // Отримання CSRF токена
    const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';

    const formData = new FormData();
    formData.append('cancel_order', '1');
    formData.append('order_id', orderId);
    formData.append('cancel_reason', 'Скасовано користувачем');
    formData.append('csrf_token', csrfToken);

    // Показуємо індикатор завантаження
    const confirmBtn = document.getElementById('confirm-cancel-order');
    if (confirmBtn) {
        confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Скасування...';
        confirmBtn.disabled = true;
    }

    fetch('dashboard.php', {
        method: 'POST',
        body: formData
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast('Замовлення успішно скасовано', 'success');
                closeModal('cancel-order-modal');

                // Оновлення сторінки
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            } else {
                showToast(data.message || 'Помилка скасування замовлення', 'error');

                // Відновлюємо кнопку
                if (confirmBtn) {
                    confirmBtn.innerHTML = 'Так, скасувати';
                    confirmBtn.disabled = false;
                }
            }
        })
        .catch(error => {
            console.error('Помилка:', error);
            showToast('Помилка скасування замовлення', 'error');

            // Відновлюємо кнопку
            if (confirmBtn) {
                confirmBtn.innerHTML = 'Так, скасувати';
                confirmBtn.disabled = false;
            }
        });
}

/**
 * Ініціалізація редагування замовлень
 */
function initEditOrders() {
    safeAddEventListener('.edit-order', 'click', function() {
        const orderId = this.getAttribute('data-id');
        if (orderId) {
            editOrder(orderId);
        }
    });
}

/**
 * Редагування замовлення
 * @param {string} orderId - ID замовлення
 */
function editOrder(orderId) {
    const editModal = document.getElementById('edit-order-modal');
    const orderIdElement = document.getElementById('edit-order-id');
    const orderIdInput = document.getElementById('modal-order-id');

    if (!editModal || !orderIdElement || !orderIdInput) return;

    // Встановлюємо ID замовлення
    orderIdElement.textContent = orderId;
    orderIdInput.value = orderId;

    // Показуємо індикатори завантаження
    const formContent = document.querySelectorAll('.order-form-content');
    formContent.forEach(content => {
        content.innerHTML = '<div class="loading-spinner"></div>';
    });

    // Відкриваємо модальне вікно
    openModal('edit-order-modal');

    // Додаємо мітку часу для уникнення кешування
    const timestamp = new Date().getTime();

    // Завантаження даних замовлення
    fetch(`dashboard.php?get_order_details=${orderId}&_=${timestamp}`, {
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
        .then(response => {
            if (!response.ok) {
                throw new Error('Помилка завантаження деталей замовлення');
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                const order = data.order;

                // Заповнюємо форму даними
                fillEditForm(order);

                // Ініціалізуємо переключення вкладок
                initEditOrderTabs();
            }
        })
        .catch(error => {
            console.error('Помилка:', error);
            showToast('Помилка завантаження даних замовлення', 'error');
            closeModal('edit-order-modal');
        });
}

/**
 * Заповнення форми редагування даними замовлення
 * @param {Object} order - Об'єкт замовлення
 */
function fillEditForm(order) {
    // Заповнюємо вкладку деталей
    const detailsContent = document.getElementById('edit-details-content');
    if (detailsContent) {
        detailsContent.innerHTML = `
            <div class="form-row">
                <div class="form-col">
                    <div class="form-group">
                        <label for="edit-service" class="form-label">Послуга*</label>
                        <select id="edit-service" name="service" class="form-control" required>
                            <option value="">Виберіть послугу</option>
                            <option value="Ремонт смартфона" ${order.service === 'Ремонт смартфона' ? 'selected' : ''}>Ремонт смартфона</option>
                            <option value="Ремонт ноутбука" ${order.service === 'Ремонт ноутбука' ? 'selected' : ''}>Ремонт ноутбука</option>
                            <option value="Ремонт планшета" ${order.service === 'Ремонт планшета' ? 'selected' : ''}>Ремонт планшета</option>
                            <option value="Ремонт комп'ютера" ${order.service === 'Ремонт комп\'ютера' ? 'selected' : ''}>Ремонт комп'ютера</option>
                            <option value="Інше" ${order.service !== 'Ремонт смартфона' && order.service !== 'Ремонт ноутбука' && order.service !== 'Ремонт планшета' && order.service !== 'Ремонт комп\'ютера' ? 'selected' : ''}>Інше</option>
                        </select>
                    </div>
                </div>
                <div class="form-col">
                    <div class="form-group">
                        <label for="edit-device_type" class="form-label">Тип пристрою*</label>
                        <input type="text" id="edit-device_type" name="device_type" class="form-control" value="${escapeHtml(order.device_type)}" required>
                    </div>
                </div>
            </div>
            
            <div class="form-group">
                <label for="edit-details" class="form-label">Опис проблеми*</label>
                <textarea id="edit-details" name="details" class="form-control" rows="4" required>${escapeHtml(order.details)}</textarea>
            </div>
            
            <div class="form-row">
                <div class="form-col">
                    <div class="form-group">
                        <label for="edit-phone" class="form-label">Контактний телефон*</label>
                        <input type="tel" id="edit-phone" name="phone" class="form-control" value="${escapeHtml(order.phone)}" required>
                    </div>
                </div>
                <div class="form-col">
                    <div class="form-group">
                        <label for="edit-delivery_method" class="form-label">Спосіб доставки</label>
                        <select id="edit-delivery_method" name="delivery_method" class="form-control">
                            <option value="">Виберіть спосіб доставки</option>
                            <option value="Самовивіз" ${order.delivery_method === 'Самовивіз' ? 'selected' : ''}>Самовивіз</option>
                            <option value="Доставка кур'єром" ${order.delivery_method === 'Доставка кур\'єром' ? 'selected' : ''}>Доставка кур'єром</option>
                            <option value="Нова Пошта" ${order.delivery_method === 'Нова Пошта' ? 'selected' : ''}>Нова Пошта</option>
                        </select>
                    </div>
                </div>
            </div>
            
            <div class="form-group">
                <label for="edit-address" class="form-label">Адреса</label>
                <input type="text" id="edit-address" name="address" class="form-control" value="${escapeHtml(order.address || '')}">
                <div class="form-text">Вкажіть адресу, якщо потрібна доставка. Для Нової Пошти вкажіть номер відділення.</div>
            </div>
            
            <div class="form-group">
                <label for="edit-user_comment" class="form-label">Коментар до замовлення</label>
                <textarea id="edit-user_comment" name="user_comment" class="form-control" rows="3">${escapeHtml(order.user_comment || '')}</textarea>
            </div>
        `;
    }

    // Заповнюємо вкладку файлів
    const filesContent = document.getElementById('edit-files-content');
    if (filesContent) {
        // Підготовка блоку для поточних файлів
        let currentFilesHtml = '';
        if (order.files && order.files.length > 0) {
            order.files.forEach(function(file, index) {
                const fileIcon = getFileIcon(file.ext || getFileExtension(file.name));
                const isImage = ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(file.ext?.toLowerCase());

                currentFilesHtml += `
                    <div class="file-preview-item" data-file-id="${file.id}">
                        <div class="file-preview-thumbnail">
                            ${isImage ? `<img src="${escapeHtml(file.path)}" alt="${escapeHtml(file.name)}">` : `<i class="${fileIcon}"></i>`}
                        </div>
                        <div class="file-preview-name">${escapeHtml(file.name)}</div>
                        <button type="button" class="file-preview-remove" data-file-id="${file.id}">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                `;
            });
        } else {
            currentFilesHtml = '<p>Немає прикріплених файлів</p>';
        }

        filesContent.innerHTML = `
            <div class="form-group">
                <label class="form-label">Поточні файли</label>
                <div class="file-list-preview" id="edit-current-files">
                    ${currentFilesHtml}
                </div>
                <input type="hidden" name="removed_files" id="edit-removed-files" value="">
            </div>
            
            <div class="form-group">
                <label class="form-label">Додати нові файли</label>
                <div class="file-drop-area">
                    <div class="file-drop-icon">
                        <i class="fas fa-cloud-upload-alt"></i>
                    </div>
                    <div class="file-drop-message">Перетягніть файли сюди або клікніть для вибору</div>
                    <div class="file-drop-hint">Підтримуються файли JPG, PNG, PDF, до 10 МБ кожен</div>
                    <input type="file" class="file-input" name="files[]" multiple accept="image/*,application/pdf,text/plain">
                </div>
                <div class="selected-files" id="edit-new-files"></div>
            </div>
        `;

        // Ініціалізуємо обробники для нових файлів
        const fileDropArea = filesContent.querySelector('.file-drop-area');
        const fileInput = filesContent.querySelector('.file-input');
        const selectedFilesContainer = filesContent.querySelector('#edit-new-files');

        initFileDropArea(fileDropArea, fileInput, selectedFilesContainer);

        // Ініціалізуємо обробники для видалення поточних файлів
        const removeBtns = filesContent.querySelectorAll('.file-preview-remove');
        const removedFilesInput = document.getElementById('edit-removed-files');

        const removedFiles = [];

        removeBtns.forEach(function(btn) {
            btn.addEventListener('click', function() {
                const fileId = this.getAttribute('data-file-id');
                const fileItem = this.closest('.file-preview-item');

                if (fileId && fileItem) {
                    removedFiles.push(fileId);
                    removedFilesInput.value = JSON.stringify(removedFiles);
                    fileItem.remove();

                    // Показуємо повідомлення, якщо всі файли видалені
                    const currentFiles = document.getElementById('edit-current-files');
                    if (currentFiles && currentFiles.querySelectorAll('.file-preview-item').length === 0) {
                        currentFiles.innerHTML = '<p>Немає прикріплених файлів</p>';
                    }
                }
            });
        });
    }

    // Ініціалізація кнопки збереження
    const saveBtn = document.getElementById('submit-edit-order');
    if (saveBtn) {
        // Оновлюємо обробник для кнопки збереження
        const newSaveBtn = saveBtn.cloneNode(true);
        saveBtn.parentNode.replaceChild(newSaveBtn, saveBtn);

        newSaveBtn.addEventListener('click', function() {
            const form = document.getElementById('edit-order-form');
            if (form) {
                submitEditForm(form);
            }
        });
    }
}

/**
 * Ініціалізація вкладок в модальному вікні редагування замовлення
 */
function initEditOrderTabs() {
    const tabLinks = document.querySelectorAll('.order-form-tab');
    const tabContents = document.querySelectorAll('.order-form-content');

    tabLinks.forEach(function(link) {
        link.addEventListener('click', function() {
            const tabId = this.getAttribute('data-tab');

            // Деактивація всіх вкладок
            tabLinks.forEach(function(tab) {
                tab.classList.remove('active');
            });

            tabContents.forEach(function(content) {
                content.classList.remove('active');
            });

            // Активація обраної вкладки
            this.classList.add('active');

            const activeContent = document.getElementById(`${tabId}-content`);
            if (activeContent) {
                activeContent.classList.add('active');
            }
        });
    });
}

/**
 * Ініціалізація області для перетягування файлів
 * @param {HTMLElement} dropArea - Область для перетягування
 * @param {HTMLInputElement} input - Елемент інпуту файлів
 * @param {HTMLElement} container - Контейнер для відображення вибраних файлів
 */
function initFileDropArea(dropArea, input, container) {
    if (!dropArea || !input || !container) return;

    // Обробка події перетягування файлів
    dropArea.addEventListener('dragover', function(e) {
        e.preventDefault();
        this.classList.add('drag-over');
    });

    dropArea.addEventListener('dragleave', function() {
        this.classList.remove('drag-over');
    });

    dropArea.addEventListener('drop', function(e) {
        e.preventDefault();
        this.classList.remove('drag-over');

        if (e.dataTransfer.files.length > 0) {
            input.files = e.dataTransfer.files;
            showSelectedFiles(input.files, container);
        }
    });

    // Обробка кліку для вибору файлів через діалог
    dropArea.addEventListener('click', function() {
        input.click();
    });

    // Обробка зміни вибраних файлів
    input.addEventListener('change', function() {
        showSelectedFiles(this.files, container);
    });
}

/**
 * Подання форми редагування замовлення
 * @param {HTMLFormElement} form - Форма для відправки
 */
function submitEditForm(form) {
    // Валідація форми
    const requiredFields = form.querySelectorAll('[required]');
    let isValid = true;

    requiredFields.forEach(function(field) {
        if (!field.value.trim()) {
            field.classList.add('is-invalid');
            isValid = false;
        } else {
            field.classList.remove('is-invalid');
        }
    });

    if (!isValid) {
        showToast('Заповніть всі обов\'язкові поля', 'warning');
        return;
    }

    // Показуємо індикатор завантаження
    const submitButton = document.getElementById('submit-edit-order');
    if (submitButton) {
        submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Збереження...';
        submitButton.disabled = true;
    }

    // Відправка форми
    const formData = new FormData(form);

    fetch('dashboard.php', {
        method: 'POST',
        body: formData
    })
        .then(response => {
            if (!response.ok) {
                throw new Error('Помилка відправки форми');
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                showToast('Замовлення успішно оновлено', 'success');
                closeModal('edit-order-modal');

                // Оновлення сторінки
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            } else {
                showToast(data.message || 'Помилка оновлення замовлення', 'error');

                // Відновлюємо кнопку
                if (submitButton) {
                    submitButton.innerHTML = 'Зберегти зміни';
                    submitButton.disabled = false;
                }
            }
        })
        .catch(error => {
            console.error('Помилка:', error);
            showToast('Помилка оновлення замовлення', 'error');

            // Відновлюємо кнопку
            if (submitButton) {
                submitButton.innerHTML = 'Зберегти зміни';
                submitButton.disabled = false;
            }
        });
}

/**
 * Перевірка можливості редагування замовлення за статусом
 * @param {string} status - Статус замовлення
 * @returns {boolean} Може бути відредаговане чи ні
 */
function isOrderEditable(status) {
    if (!status) return false;

    const lowerStatus = status.toLowerCase();
    const editableStatuses = [
        'новий', 'новое', 'нове',
        'очікує', 'ожидает', 'в очікуванні',
        'очікується поставки товару', 'ожидается поставка товара'
    ];

    return editableStatuses.some(s => lowerStatus.includes(s));
}

/**
 * Отримання CSS класу для статусу замовлення
 * @param {string} status - Статус замовлення
 * @returns {string} CSS клас
 */
function getStatusClass(status) {
    if (!status) return '';

    const lowerStatus = status.toLowerCase();

    if (lowerStatus.includes('нов')) {
        return 'new';
    } else if (lowerStatus.includes('в робот') || lowerStatus.includes('в работ') || lowerStatus.includes('прийнят')) {
        return 'in-progress';
    } else if (lowerStatus.includes('заверш') || lowerStatus.includes('готов')) {
        return 'completed';
    } else if (lowerStatus.includes('скасова') || lowerStatus.includes('отмен')) {
        return 'cancelled';
    } else if (lowerStatus.includes('очіку') || lowerStatus.includes('ожида')) {
        return 'pending';
    }

    return '';
}

/**
 * Отримання іконки для файлу залежно від MIME-типу
 * @param {string} mimeType - MIME-тип файлу
 * @returns {string} CSS клас іконки
 */
function getFileIconByMimeType(mimeType) {
    if (!mimeType) return 'fas fa-file';

    const type = mimeType.split('/')[0];
    const subtype = mimeType.split('/')[1];

    if (type === 'image') {
        return 'fas fa-file-image';
    } else if (type === 'video') {
        return 'fas fa-file-video';
    } else if (type === 'audio') {
        return 'fas fa-file-audio';
    } else if (mimeType === 'application/pdf') {
        return 'fas fa-file-pdf';
    } else if (mimeType === 'application/msword' || mimeType === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
        return 'fas fa-file-word';
    } else if (mimeType === 'application/vnd.ms-excel' || mimeType === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') {
        return 'fas fa-file-excel';
    } else if (mimeType === 'application/vnd.ms-powerpoint' || mimeType === 'application/vnd.openxmlformats-officedocument.presentationml.presentation') {
        return 'fas fa-file-powerpoint';
    } else if (mimeType.includes('archive') || mimeType.includes('zip') || mimeType.includes('compress')) {
        return 'fas fa-file-archive';
    } else if (type === 'text') {
        return 'fas fa-file-alt';
    } else if (mimeType.includes('code') || mimeType.includes('script')) {
        return 'fas fa-file-code';
    }

    return 'fas fa-file';
}

/**
 * Отримання розширення файлу з імені
 * @param {string} filename - Ім'я файлу
 * @returns {string} Розширення файлу
 */
function getFileExtension(filename) {
    if (!filename) return '';

    return filename.split('.').pop().toLowerCase();
}

/**
 * Форматування розміру файлу у читабельний формат (Кб, Мб)
 * @param {number} bytes - Розмір у байтах
 * @returns {string} Відформатований розмір
 */
function formatFileSize(bytes) {
    if (bytes === 0) return '0 Байт';

    const k = 1024;
    const sizes = ['Байт', 'Кб', 'Мб', 'Гб', 'Тб'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));

    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

/**
 * Форматування дати
 * @param {string} dateStr - Дата у вигляді строки
 * @returns {string} Відформатована дата
 */
function formatDate(dateStr) {
    if (!dateStr) return '';

    const date = new Date(dateStr);
    return date.toLocaleDateString('uk-UA');
}

/**
 * Форматування дати і часу
 * @param {string} dateStr - Дата у вигляді строки
 * @returns {string} Відформатована дата і час
 */
function formatDateTime(dateStr) {
    if (!dateStr) return '';

    const date = new Date(dateStr);
    return date.toLocaleDateString('uk-UA') + ' ' + date.toLocaleTimeString('uk-UA');
}

/**
 * Перетворення символів нового рядка в HTML-теги <br>
 * @param {string} text - Вхідний текст
 * @returns {string} Текст з HTML-тегами <br> замість символів нового рядка
 */
function nl2br(text) {
    if (!text) return '';
    return text.replace(/\n/g, '<br>');
}

/**
 * Екранування HTML-символів
 * @param {string} text - Вхідний текст
 * @returns {string} Екранований текст
 */
function escapeHtml(text) {
    if (!text) return '';

    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/**
 * Отримання CSRF токену з форми
 * @returns {string} CSRF токен
 */
function getCsrfToken() {
    return document.querySelector('input[name="csrf_token"]')?.value || '';
}

/**
 * Показ спливаючого повідомлення
 * @param {string} message - Текст повідомлення
 * @param {string} type - Тип повідомлення (success, error, warning, info)
 */
function showToast(message, type = 'info') {
    if (typeof window.showToast === 'function') {
        window.showToast(message, type);
    } else {
        // Запасний варіант, якщо функція глобально не доступна
        const toastContainer = document.getElementById('toast-container');
        if (!toastContainer) return;

        const toast = document.createElement('div');
        toast.className = `toast ${type}`;

        let icon = '';
        switch (type) {
            case 'success': icon = '<i class="fas fa-check-circle"></i>'; break;
            case 'error': icon = '<i class="fas fa-exclamation-circle"></i>'; break;
            case 'warning': icon = '<i class="fas fa-exclamation-triangle"></i>'; break;
            default: icon = '<i class="fas fa-info-circle"></i>';
        }

        toast.innerHTML = `
            <div class="toast-content">
                ${icon}
                <div class="toast-message">${message}</div>
            </div>
            <button class="toast-close"><i class="fas fa-times"></i></button>
        `;

        toastContainer.appendChild(toast);

        // Анімація появи
        setTimeout(() => {
            toast.classList.add('show');
        }, 10);

        // Автоматичне закриття через 4 секунди
        const autoCloseTimeout = setTimeout(() => {
            closeToast(toast);
        }, 4000);

        // Обробник закриття
        const closeBtn = toast.querySelector('.toast-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', () => {
                clearTimeout(autoCloseTimeout);
                closeToast(toast);
            });
        }
    }
}

/**
 * Закриття спливаючого повідомлення
 * @param {HTMLElement} toast - Елемент повідомлення
 */
function closeToast(toast) {
    toast.classList.remove('show');

    // Видалення з DOM після завершення анімації
    setTimeout(() => {
        if (toast.parentNode) {
            toast.parentNode.removeChild(toast);
        }
    }, 300);
}