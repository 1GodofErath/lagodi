/**
 * Глобальні налаштування і конфігурація
 */
document.addEventListener('DOMContentLoaded', function() {
    // Ініціалізація випадаючих меню
    initDropdowns();

    // Ініціалізація боковій панелі
    initSidebar();

    // Ініціалізація модальних вікон
    initModals();

    // Ініціалізація вкладок
    initTabs();

    // Ініціалізація сповіщень
    initNotifications();

    // Ініціалізація файлового завантаження
    initFileUpload();

    // Ініціалізація обробників для профілю
    initProfileHandlers();

    // Ініціалізація індикатора складності пароля
    initPasswordStrengthMeter();

    // Обробка кнопок перегляду/редагування/скасування замовлення
    initOrderButtons();

    // Ініціалізація сеансу користувача
    initSessionMonitor();

    // Load theme from localStorage if it exists
    const savedTheme = localStorage.getItem('user-theme');
    if (savedTheme && savedTheme !== config.theme) {
        document.documentElement.setAttribute('data-theme', savedTheme);
        // Update the config theme value
        config.theme = savedTheme;
    }

    // Обробник скидання фільтрів
    const resetFiltersBtn = document.getElementById('reset-filters');
    if (resetFiltersBtn) {
        resetFiltersBtn.addEventListener('click', function() {
            window.location.href = '?tab=orders';
        });
    }

    // Initialize tab-specific functionality
    initTabSpecificFunctionality();
});

// Create a function to init buttons on tab changes
function initTabSpecificFunctionality() {
    const currentTab = window.location.search.match(/tab=([^&]*)/);
    if (!currentTab || currentTab[1] === 'orders') {
        // Initialize order-specific functionality
        initOrderButtons();
    }
}

/**
 * Ініціалізація випадаючих меню
 */
function initDropdowns() {
    // Функція для закриття всіх відкритих випадаючих меню
    function closeAllDropdowns() {
        document.querySelectorAll('.dropdown-menu').forEach(menu => {
            menu.classList.remove('active');
        });
    }

    // Відкриття/закриття випадаючого меню користувача
    const userToggle = document.getElementById('user-toggle');
    const userDropdown = document.getElementById('user-dropdown');

    if (userToggle && userDropdown) {
        userToggle.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();

            if (userDropdown.classList.contains('active')) {
                userDropdown.classList.remove('active');
            } else {
                closeAllDropdowns();
                userDropdown.classList.add('active');
            }
        });
    }

    // Відкриття/закриття випадаючого меню сповіщень
    const notificationsToggle = document.getElementById('notifications-toggle');
    const notificationsDropdown = document.getElementById('notifications-dropdown');

    if (notificationsToggle && notificationsDropdown) {
        notificationsToggle.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();

            if (notificationsDropdown.classList.contains('active')) {
                notificationsDropdown.classList.remove('active');
            } else {
                closeAllDropdowns();
                notificationsDropdown.classList.add('active');
            }
        });
    }

    // Закриття випадаючих меню при кліку поза ними
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.dropdown-menu') && !e.target.closest('#user-toggle') && !e.target.closest('#notifications-toggle')) {
            closeAllDropdowns();
        }
    });
}

/**
 * Ініціалізація бічній панелі (сайдбару)
 */
function initSidebar() {
    const menuToggle = document.getElementById('menu-toggle');
    const sidebar = document.getElementById('sidebar');
    const body = document.body;

    if (menuToggle && sidebar) {
        menuToggle.addEventListener('click', function() {
            body.classList.toggle('sidebar-open');

            // Запам'ятовуємо стан сайдбара
            if (body.classList.contains('sidebar-open')) {
                localStorage.setItem('sidebar-open', 'true');
            } else {
                localStorage.setItem('sidebar-open', 'false');
            }
        });

        // Відновлюємо стан сайдбара
        if (localStorage.getItem('sidebar-open') === 'true') {
            body.classList.add('sidebar-open');
        }
    }

    // Закриття сайдбара при кліку за його межами на мобільних пристроях
    document.addEventListener('click', function(e) {
        if (window.innerWidth <= 991 && body.classList.contains('sidebar-open') && !e.target.closest('#sidebar') && !e.target.closest('#menu-toggle')) {
            body.classList.remove('sidebar-open');
            localStorage.setItem('sidebar-open', 'false');
        }
    });
}

/**
 * Ініціалізація модальних вікон
 */
function initModals() {
    // Функція відкриття модального вікна
    window.openModal = function(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
    };

    // Функція закриття модального вікна
    window.closeModal = function(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.remove('active');
            document.body.style.overflow = '';
        }
    };

    // Обробка кнопок закриття модальних вікон
    document.querySelectorAll('.modal-close, [data-dismiss="modal"]').forEach(button => {
        button.addEventListener('click', function() {
            const modal = this.closest('.modal');
            if (modal) {
                closeModal(modal.id);
            }
        });
    });

    // Закриття модального вікна при кліку поза ним
    document.querySelectorAll('.modal').forEach(modal => {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal(this.id);
            }
        });
    });

    // Закриття модального вікна при натисканні Esc
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const activeModal = document.querySelector('.modal.active');
            if (activeModal) {
                closeModal(activeModal.id);
            }
        }
    });
}

/**
 * Ініціалізація вкладок
 */
function initTabs() {
    document.querySelectorAll('.tabs').forEach(tabGroup => {
        const tabButtons = tabGroup.querySelectorAll('.tab-btn');
        const tabPanes = tabGroup.querySelectorAll('.tab-pane');

        tabButtons.forEach(button => {
            button.addEventListener('click', function() {
                // Видаляємо активний клас з усіх кнопок і вкладок
                tabButtons.forEach(btn => btn.classList.remove('active'));
                tabPanes.forEach(pane => pane.classList.remove('active'));

                // Додаємо активний клас обраним кнопці і вкладці
                this.classList.add('active');

                const tabId = this.getAttribute('data-tab');
                const tabPane = tabGroup.querySelector(`#tab-${tabId}`);

                if (tabPane) {
                    tabPane.classList.add('active');
                }
            });
        });
    });
}

/**
 * Ініціалізація кнопок перегляду/редагування/скасування замовлення
 */
function initOrderButtons() {
    console.log('Initializing order buttons');

    // Обробка кнопок перегляду замовлення
    document.querySelectorAll('.view-order-btn').forEach(button => {
        button.addEventListener('click', function() {
            const orderId = this.getAttribute('data-id');
            console.log('View order clicked:', orderId);
            viewOrder(orderId);
        });
    });

    // Обробка кнопок редагування замовлення
    document.querySelectorAll('.edit-order-btn').forEach(button => {
        button.addEventListener('click', function() {
            const orderId = this.getAttribute('data-id');
            editOrder(orderId);
        });
    });

    // Обробка кнопок скасування замовлення
    document.querySelectorAll('.cancel-order-btn').forEach(button => {
        button.addEventListener('click', function() {
            const orderId = this.getAttribute('data-id');
            showCancelOrderModal(orderId);
        });
    });

    // Обробка подібних кнопок всередині модальних вікон
    const editOrderBtnInModal = document.getElementById('edit-order-btn');
    if (editOrderBtnInModal) {
        editOrderBtnInModal.addEventListener('click', function() {
            const orderId = document.getElementById('view-order-id').textContent;
            closeModal('view-order-modal');
            editOrder(orderId);
        });
    }

    const cancelOrderBtnInModal = document.getElementById('cancel-order-btn');
    if (cancelOrderBtnInModal) {
        cancelOrderBtnInModal.addEventListener('click', function() {
            const orderId = document.getElementById('view-order-id').textContent;
            closeModal('view-order-modal');
            showCancelOrderModal(orderId);
        });
    }

    // Обробка кнопки підтвердження скасування замовлення
    const confirmCancelBtn = document.getElementById('confirm-cancel-btn');
    if (confirmCancelBtn) {
        confirmCancelBtn.addEventListener('click', function() {
            cancelOrder();
        });
    }

    // Обробка кнопки відправки коментаря
    const sendCommentBtn = document.getElementById('send-comment');
    if (sendCommentBtn) {
        sendCommentBtn.addEventListener('click', function() {
            sendComment();
        });
    }

    // Обробка кнопки збереження змін замовлення
    const saveOrderBtn = document.getElementById('save-order-btn');
    if (saveOrderBtn) {
        saveOrderBtn.addEventListener('click', function() {
            saveOrderChanges();
        });
    }
}

/**
 * Відображення замовлення
 */
function viewOrder(orderId) {
    // Встановлюємо ID замовлення в модальному вікні
    document.getElementById('view-order-id').textContent = orderId;

    // Відображаємо індикатори завантаження
    document.querySelectorAll('.tab-pane .loading-spinner').forEach(spinner => {
        spinner.style.display = 'flex';
    });

    // Очищаємо попередній вміст
    document.getElementById('order-info-content').innerHTML = '';
    document.getElementById('order-files-content').innerHTML = '';
    document.getElementById('order-comments-content').innerHTML = '';
    document.getElementById('order-history-content').innerHTML = '';

    // Встановлюємо атрибут для кнопки відправки коментаря
    document.getElementById('send-comment').setAttribute('data-order-id', orderId);

    // Відкриваємо модальне вікно
    openModal('view-order-modal');

    // Завантажуємо дані замовлення
    fetch(`dashboard.php?get_order_details=${orderId}`, {
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
        .then(response => response.json())
        .then(data => {
            // Приховуємо індикатори завантаження
            document.querySelectorAll('.tab-pane .loading-spinner').forEach(spinner => {
                spinner.style.display = 'none';
            });

            if (data.success && data.order) {
                const order = data.order;

                // Оновлюємо вміст вкладок
                document.getElementById('order-info-content').innerHTML = generateOrderInfoHTML(order);
                document.getElementById('order-files-content').innerHTML = generateOrderFilesHTML(order.files);
                document.getElementById('order-comments-content').innerHTML = generateOrderCommentsHTML(order.comments);
                document.getElementById('order-history-content').innerHTML = generateOrderHistoryHTML(order.status_history);

                // Показуємо або ховаємо кнопки "Редагувати" та "Скасувати"
                const editOrderBtn = document.getElementById('edit-order-btn');
                const cancelOrderBtn = document.getElementById('cancel-order-btn');

                if (isOrderEditable(order.status)) {
                    editOrderBtn.style.display = 'inline-block';
                } else {
                    editOrderBtn.style.display = 'none';
                }

                if (isOrderCancellable(order.status)) {
                    cancelOrderBtn.style.display = 'inline-block';
                } else {
                    cancelOrderBtn.style.display = 'none';
                }
            } else {
                showNotification('error', data.message || 'Помилка при завантаженні замовлення');
                closeModal('view-order-modal');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('error', 'Помилка при завантаженні замовлення');
            closeModal('view-order-modal');
        });
}

/**
 * Редагування замовлення
 */
function editOrder(orderId) {
    // Встановлюємо ID замовлення
    document.getElementById('edit-order-id').textContent = orderId;
    document.getElementById('edit-order-id-input').value = orderId;

    // Очищаємо форму
    document.getElementById('edit-order-form').reset();
    document.getElementById('edit-files-list').innerHTML = '';
    document.getElementById('edit-files-preview').innerHTML = '';

    // Відкриваємо модальне вікно
    openModal('edit-order-modal');

    // Завантажуємо дані замовлення
    fetch(`dashboard.php?get_order_details=${orderId}`, {
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.order) {
                const order = data.order;

                // Заповнюємо форму даними
                document.getElementById('edit-device-type').value = order.device_type || '';
                document.getElementById('edit-details').value = order.details || '';
                document.getElementById('edit-phone').value = order.phone || '';
                document.getElementById('edit-address').value = order.address || '';
                document.getElementById('edit-delivery').value = order.delivery_method || '';
                document.getElementById('edit-comment').value = order.user_comment || '';

                // Відображаємо файли
                if (order.files && order.files.length > 0) {
                    const filesListContainer = document.getElementById('edit-files-list');
                    filesListContainer.innerHTML = '';

                    order.files.forEach(file => {
                        const fileItem = document.createElement('div');
                        fileItem.className = 'file-item';
                        fileItem.setAttribute('data-id', file.id);

                        const isImage = file.file_type === 'image' || (file.file_mime && file.file_mime.startsWith('image/'));

                        fileItem.innerHTML = `
                        <div class="file-thumbnail">
                            ${isImage ?
                            `<img src="${file.file_path}" alt="${file.original_name}">` :
                            `<i class="${getFileIconClass(file.file_mime || '', file.original_name)}"></i>`
                        }
                        </div>
                        <div class="file-name">${file.original_name}</div>
                        <div class="file-actions">
                            <button type="button" class="file-action remove-file" title="Видалити файл">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    `;

                        filesListContainer.appendChild(fileItem);

                        // Додаємо обробник для кнопки видалення файлу
                        const removeBtn = fileItem.querySelector('.remove-file');
                        if (removeBtn) {
                            removeBtn.addEventListener('click', function() {
                                const fileId = fileItem.getAttribute('data-id');
                                // Додаємо приховане поле для відправки ID файлу для видалення
                                const removeFileInput = document.createElement('input');
                                removeFileInput.type = 'hidden';
                                removeFileInput.name = 'remove_files[]';
                                removeFileInput.value = fileId;
                                document.getElementById('edit-order-form').appendChild(removeFileInput);

                                fileItem.remove();
                            });
                        }
                    });
                }
            } else {
                showNotification('error', data.message || 'Помилка при завантаженні замовлення');
                closeModal('edit-order-modal');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('error', 'Помилка при завантаженні замовлення');
            closeModal('edit-order-modal');
        });
}

/**
 * Збереження змін замовлення
 */
function saveOrderChanges() {
    const form = document.getElementById('edit-order-form');

    // Перевіряємо валідність форми
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    // Збираємо дані форми
    const formData = new FormData(form);
    formData.append('update_order', '1');

    // Показуємо індикатор завантаження
    const submitButton = document.getElementById('save-order-btn');
    const originalText = submitButton.textContent;
    submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Збереження...';
    submitButton.disabled = true;

    // Відправляємо запит
    fetch('dashboard.php', {
        method: 'POST',
        body: formData
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification('success', data.message || 'Замовлення успішно оновлено');

                // Закриваємо модальне вікно і оновлюємо сторінку
                closeModal('edit-order-modal');

                // Перезавантажуємо сторінку через 1.5 секунди
                setTimeout(() => {
                    window.location.reload();
                }, 1500);
            } else {
                showNotification('error', data.message || 'Помилка при оновленні замовлення');
                submitButton.textContent = originalText;
                submitButton.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('error', 'Помилка при оновленні замовлення');
            submitButton.textContent = originalText;
            submitButton.disabled = false;
        });
}

/**
 * Показує модальне вікно для скасування замовлення
 */
function showCancelOrderModal(orderId) {
    // Встановлюємо ID замовлення
    document.getElementById('cancel-order-id').textContent = orderId;

    // Очищаємо поле причини
    document.getElementById('cancel-reason').value = '';

    // Відкриваємо модальне вікно
    openModal('cancel-order-modal');
}

/**
 * Скасування замовлення
 */
function cancelOrder() {
    // Отримуємо ID замовлення і причину
    const orderId = document.getElementById('cancel-order-id').textContent;
    const reason = document.getElementById('cancel-reason').value;

    // Показуємо індикатор завантаження
    const submitButton = document.getElementById('confirm-cancel-btn');
    const originalText = submitButton.textContent;
    submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Скасування...';
    submitButton.disabled = true;

    // Відправляємо запит
    fetch('dashboard.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
            'cancel_order': '1',
            'order_id': orderId,
            'reason': reason,
            'csrf_token': config.csrfToken
        })
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification('success', data.message || 'Замовлення успішно скасовано');

                // Закриваємо модальне вікно і оновлюємо сторінку
                closeModal('cancel-order-modal');

                // Перезавантажуємо сторінку через 1.5 секунди
                setTimeout(() => {
                    window.location.reload();
                }, 1500);
            } else {
                showNotification('error', data.message || 'Помилка при скасуванні замовлення');
                submitButton.textContent = originalText;
                submitButton.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('error', 'Помилка при скасуванні замовлення');
            submitButton.textContent = originalText;
            submitButton.disabled = false;
        });
}

/**
 * Відправка коментаря
 */
function sendComment() {
    // Отримуємо дані
    const commentText = document.getElementById('comment-text');
    const orderId = document.getElementById('send-comment').getAttribute('data-order-id');

    if (!commentText || !orderId || commentText.value.trim() === '') {
        showNotification('error', 'Коментар не може бути порожнім');
        return;
    }

    // Показуємо індикатор завантаження
    const submitButton = document.getElementById('send-comment');
    const originalText = submitButton.innerHTML;
    submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    submitButton.disabled = true;

    // Відправляємо запит
    fetch('dashboard.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
            'add_comment': '1',
            'order_id': orderId,
            'comment': commentText.value.trim(),
            'csrf_token': config.csrfToken
        })
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Очищаємо поле введення
                commentText.value = '';

                // Додаємо новий коментар до списку
                if (data.comment) {
                    const commentsContainer = document.getElementById('order-comments-content');
                    const isAdmin = data.comment.is_admin == 1 || data.comment.author_role === 'admin';

                    const commentEl = document.createElement('div');
                    commentEl.className = `comment-item ${isAdmin ? 'admin-comment' : ''}`;

                    // Аватар користувача
                    let avatarHtml = '<i class="fas fa-user"></i>';
                    if (data.comment.avatar) {
                        avatarHtml = `<img src="/uploads/avatars/${data.comment.avatar}" alt="${data.comment.author_display_name || data.comment.author_name || 'Користувач'}">`;
                    }

                    commentEl.innerHTML = `
                    <div class="comment-avatar">
                        ${avatarHtml}
                    </div>
                    <div class="comment-content">
                        <div class="comment-header">
                            <div class="comment-author">
                                ${data.comment.author_display_name || data.comment.author_name || 'Користувач'}
                                ${isAdmin ? '<span class="admin-badge">Адміністратор</span>' : ''}
                            </div>
                            <div class="comment-date">щойно</div>
                        </div>
                        <div class="comment-text">${data.comment.comment}</div>
                    </div>
                `;

                    // Додаємо коментар на початок списку
                    if (commentsContainer.firstChild) {
                        commentsContainer.insertBefore(commentEl, commentsContainer.firstChild);
                    } else {
                        commentsContainer.appendChild(commentEl);
                    }
                }

                showNotification('success', 'Коментар успішно додано');
            } else {
                showNotification('error', data.message || 'Помилка при додаванні коментаря');
            }

            submitButton.innerHTML = originalText;
            submitButton.disabled = false;
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('error', 'Помилка при додаванні коментаря');
            submitButton.innerHTML = originalText;
            submitButton.disabled = false;
        });
}

/**
 * Ініціалізація обробників для сповіщень
 */
function initNotifications() {
    // Позначення сповіщення як прочитаного
    document.querySelectorAll('.mark-read-btn').forEach(function(button) {
        button.addEventListener('click', function(e) {
            e.stopPropagation();
            const notificationItem = this.closest('.notification-item');
            const notificationId = notificationItem.getAttribute('data-id');
            markNotificationAsRead(notificationId, notificationItem);
        });
    });

    // Позначення всіх сповіщень як прочитаних
    const markAllReadBtn = document.getElementById('mark-all-read');
    if (markAllReadBtn) {
        markAllReadBtn.addEventListener('click', function() {
            markAllNotificationsAsRead();
        });
    }

    // Перехід до замовлення при кліку на сповіщення
    document.querySelectorAll('.notification-item').forEach(function(item) {
        item.addEventListener('click', function() {
            // Якщо в контенті є посилання на ID замовлення, відкриваємо його
            const orderId = this.querySelector('.view-order-btn')?.getAttribute('data-id');

            if (orderId) {
                viewOrder(orderId);

                // Позначаємо сповіщення як прочитане
                const notificationId = this.getAttribute('data-id');
                markNotificationAsRead(notificationId, this);
            }
        });
    });
}

/**
 * Позначення сповіщення як прочитаного
 */
function markNotificationAsRead(notificationId, element) {
    fetch('dashboard.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
            'mark_notification_read': '1',
            'notification_id': notificationId,
            'csrf_token': config.csrfToken
        })
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Видаляємо елемент з інтерфейсу
                if (element) {
                    element.classList.add('read');

                    // Видаляємо кнопку позначення
                    const markReadBtn = element.querySelector('.mark-read-btn');
                    if (markReadBtn) {
                        markReadBtn.remove();
                    }

                    // Перевіряємо, чи всі сповіщення прочитані
                    const unreadNotifications = document.querySelectorAll('.notification-item:not(.read)');
                    if (unreadNotifications.length === 0) {
                        const emptyState = document.createElement('div');
                        emptyState.className = 'empty-state';
                        emptyState.innerHTML = `
                        <i class="fas fa-bell-slash"></i>
                        <p>Немає нових сповіщень</p>
                    `;

                        // Очищаємо контейнер сповіщень і додаємо повідомлення
                        const notificationsContainer = document.querySelector('.notifications-list');
                        if (notificationsContainer) {
                            notificationsContainer.innerHTML = '';
                            notificationsContainer.appendChild(emptyState);
                        }

                        // Приховуємо кнопку "Позначити всі як прочитані"
                        const markAllReadBtn = document.getElementById('mark-all-read');
                        if (markAllReadBtn) markAllReadBtn.style.display = 'none';
                    }
                }

                // Оновлюємо лічильник непрочитаних сповіщень
                updateNotificationCounter(data.unreadCount);
            }
        })
        .catch(error => console.error('Error:', error));
}

/**
 * Позначення всіх сповіщень як прочитаних
 */
function markAllNotificationsAsRead() {
    fetch('dashboard.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
            'mark_all_notifications_read': '1',
            'csrf_token': config.csrfToken
        })
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Позначаємо всі сповіщення як прочитані в інтерфейсі
                document.querySelectorAll('.notification-item').forEach(element => {
                    element.classList.add('read');

                    // Видаляємо кнопку позначення
                    const markReadBtn = element.querySelector('.mark-read-btn');
                    if (markReadBtn) {
                        markReadBtn.remove();
                    }
                });

                // Додаємо повідомлення про відсутність сповіщень
                const notificationsContainer = document.querySelector('.notifications-list');
                if (notificationsContainer) {
                    notificationsContainer.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-bell-slash"></i>
                        <p>Немає нових сповіщень</p>
                    </div>
                `;
                }

                // Приховуємо кнопку "Позначити всі як прочитані"
                const markAllReadBtn = document.getElementById('mark-all-read');
                if (markAllReadBtn) markAllReadBtn.style.display = 'none';

                // Оновлюємо лічильник непрочитаних сповіщень
                updateNotificationCounter(0);

                showNotification('success', 'Всі сповіщення позначені як прочитані');
            }
        })
        .catch(error => console.error('Error:', error));
}

/**
 * Оновлення лічильника непрочитаних сповіщень
 */
function updateNotificationCounter(count) {
    // Оновлюємо лічильник на кнопці в шапці
    const badge = document.querySelector('#notifications-toggle .badge');
    if (count > 0) {
        if (badge) {
            badge.textContent = count;
        } else {
            const newBadge = document.createElement('span');
            newBadge.className = 'badge';
            newBadge.textContent = count;
            document.getElementById('notifications-toggle').appendChild(newBadge);
        }
    } else if (badge) {
        badge.remove();
    }

    // Оновлюємо лічильник у боковому меню
    const menuBadge = document.querySelector('.nav-item a[href="?tab=notifications"] .badge');
    if (count > 0) {
        if (menuBadge) {
            menuBadge.textContent = count;
        } else {
            const newBadge = document.createElement('span');
            newBadge.className = 'badge';
            newBadge.textContent = count;
            document.querySelector('.nav-item a[href="?tab=notifications"]').appendChild(newBadge);
        }
    } else if (menuBadge) {
        menuBadge.remove();
    }
}

/**
 * Ініціалізація обробників для профілю
 */
function initProfileHandlers() {
    // Завантаження аватара
    const avatarInput = document.getElementById('avatar-upload');
    if (avatarInput) {
        avatarInput.addEventListener('change', function() {
            uploadAvatar(this.files[0]);
        });
    }

    // Оновлення профілю
    const updateProfileForm = document.getElementById('profile-form');
    if (updateProfileForm) {
        updateProfileForm.addEventListener('submit', function(e) {
            e.preventDefault();
            updateProfile();
        });
    }

    // Зміна пароля
    const savePasswordBtn = document.getElementById('save-password-btn');
    if (savePasswordBtn) {
        savePasswordBtn.addEventListener('click', function() {
            changePassword();
        });
    }

    // Зміна email
    const saveEmailBtn = document.getElementById('save-email-btn');
    if (saveEmailBtn) {
        saveEmailBtn.addEventListener('click', function() {
            changeEmail();
        });
    }

    // Обробка кнопок відкриття модальних вікон
    const changePasswordBtn = document.getElementById('change-password-btn');
    if (changePasswordBtn) {
        changePasswordBtn.addEventListener('click', function(e) {
            e.preventDefault();
            openModal('change-password-modal');
        });
    }

    const changeEmailBtn = document.getElementById('change-email-btn');
    if (changeEmailBtn) {
        changeEmailBtn.addEventListener('click', function(e) {
            e.preventDefault();
            openModal('change-email-modal');
        });
    }
}

/**
 * Завантаження аватара
 */
function uploadAvatar(file) {
    if (!file) return;

    // Перевіряємо тип файлу
    if (!['image/jpeg', 'image/png', 'image/gif', 'image/webp'].includes(file.type)) {
        showNotification('error', 'Дозволені лише зображення форматів JPEG, PNG, GIF та WEBP');
        return;
    }

    // Перевіряємо розмір файлу
    if (file.size > 2 * 1024 * 1024) { // 2 MB
        showNotification('error', 'Розмір файлу не повинен перевищувати 2 МБ');
        return;
    }

    // Показуємо індикатор завантаження
    const avatar = document.getElementById('user-avatar');
    if (avatar) {
        avatar.style.opacity = '0.5';

        // Створюємо і показуємо спіннер
        const spinner = document.createElement('div');
        spinner.className = 'spinner';
        spinner.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        avatar.parentNode.appendChild(spinner);
    }

    // Створюємо форму для відправки файлу
    const formData = new FormData();
    formData.append('avatar', file);
    formData.append('csrf_token', config.csrfToken);

    // Відправляємо запит
    fetch('dashboard.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification('success', data.message || 'Аватар успішно оновлено');

                // Оновлюємо аватар на сторінці
                if (avatar) {
                    const img = new Image();
                    img.onload = function() {
                        avatar.style.backgroundImage = `url(${data.avatarUrl}?${new Date().getTime()})`;
                        avatar.style.opacity = '1';

                        // Видаляємо спіннер
                        const spinner = avatar.parentNode.querySelector('.spinner');
                        if (spinner) spinner.remove();
                    };
                    img.src = data.avatarUrl + '?' + new Date().getTime();

                    // Оновлюємо аватар в шапці
                    const headerAvatar = document.querySelector('.user-btn .user-avatar');
                    if (headerAvatar) {
                        headerAvatar.innerHTML = `<img src="${data.avatarUrl}?${new Date().getTime()}" alt="Аватар користувача">`;
                    }
                }
            } else {
                showNotification('error', data.message || 'Помилка при завантаженні аватара');

                // Відновлюємо аватар
                if (avatar) {
                    avatar.style.opacity = '1';

                    // Видаляємо спіннер
                    const spinner = avatar.parentNode.querySelector('.spinner');
                    if (spinner) spinner.remove();
                }
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('error', 'Помилка при завантаженні аватара');

            // Відновлюємо аватар
            if (avatar) {
                avatar.style.opacity = '1';

                // Видаляємо спіннер
                const spinner = avatar.parentNode.querySelector('.spinner');
                if (spinner) spinner.remove();
            }
        });
}

/**
 * Оновлення профілю
 */
function updateProfile() {
    // Отримуємо форму
    const form = document.getElementById('profile-form');
    if (!form) return;

    // Перевіряємо валідність форми
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    // Збираємо дані форми
    const formData = new FormData(form);
    formData.append('update_profile', '1');

    // Показуємо індикатор завантаження
    const submitButton = form.querySelector('button[type="submit"]');
    const originalText = submitButton.innerHTML;
    submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Збереження...';
    submitButton.disabled = true;

    // Відправляємо запит
    fetch('dashboard.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification('success', data.message || 'Профіль успішно оновлено');

                // Оновлюємо відображуване ім'я користувача
                const displayNameInput = document.getElementById('display-name');
                if (displayNameInput) {
                    const userNameDisplay = document.querySelector('.user-name');
                    if (userNameDisplay) {
                        userNameDisplay.textContent = displayNameInput.value;
                    }

                    // Оновлюємо ім'я в профілі
                    const profileName = document.querySelector('.profile-name');
                    if (profileName) {
                        profileName.textContent = displayNameInput.value;
                    }
                }

                // Оновлюємо email в профілі
                const emailInput = document.getElementById('email');
                if (emailInput) {
                    const profileEmail = document.querySelector('.profile-email');
                    if (profileEmail) {
                        profileEmail.childNodes[0].nodeValue = emailInput.value;
                    }
                }
            } else {
                showNotification('error', data.message || 'Помилка при оновленні профілю');
            }

            submitButton.innerHTML = originalText;
            submitButton.disabled = false;
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('error', 'Помилка при оновленні профілю');
            submitButton.innerHTML = originalText;
            submitButton.disabled = false;
        });
}

/**
 * Зміна пароля
 */
function changePassword() {
    // Отримуємо форму
    const form = document.getElementById('change-password-form');
    if (!form) return;

    // Перевіряємо валідність форми
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    // Перевіряємо, чи співпадають паролі
    const newPassword = document.getElementById('new-password').value;
    const confirmPassword = document.getElementById('confirm-password').value;

    if (newPassword !== confirmPassword) {
        showNotification('error', 'Паролі не співпадають');
        return;
    }

    // Перевіряємо мінімальну довжину пароля
    if (newPassword.length < 8) {
        showNotification('error', 'Пароль повинен містити не менше 8 символів');
        return;
    }

    // Fix: Create proper FormData object and add change_password parameter
    const formData = new FormData(form);
    formData.append('change_password', '1');

    // Показуємо індикатор завантаження
    const submitButton = document.getElementById('save-password-btn');
    const originalText = submitButton.innerHTML;
    submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Збереження...';
    submitButton.disabled = true;

    // Відправляємо запит
    fetch('dashboard.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification('success', data.message || 'Пароль успішно змінено');

                // Закриваємо модальне вікно і очищаємо форму
                closeModal('change-password-modal');
                form.reset();

                // Скидаємо індикатори складності пароля
                const passwordStrength = document.getElementById('password-strength');
                const passwordMatch = document.getElementById('password-match');

                if (passwordStrength) passwordStrength.textContent = '';
                if (passwordMatch) passwordMatch.textContent = '';
            } else {
                showNotification('error', data.message || 'Помилка при зміні пароля');
            }

            submitButton.innerHTML = originalText;
            submitButton.disabled = false;
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('error', 'Помилка при зміні пароля');
            submitButton.innerHTML = originalText;
            submitButton.disabled = false;
        });
}

/**
 * Зміна email
 */
function changeEmail() {
    // Отримуємо форму
    const form = document.getElementById('change-email-form');
    if (!form) return;

    // Перевіряємо валідність форми
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    // Fix: Create proper FormData object and add change_email parameter
    const formData = new FormData(form);
    formData.append('change_email', '1'); // Add this missing parameter

    // Показуємо індикатор завантаження
    const submitButton = document.getElementById('save-email-btn');
    const originalText = submitButton.innerHTML;
    submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Збереження...';
    submitButton.disabled = true;

    // Відправляємо запит
    fetch('dashboard.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification('success', data.message || 'Email успішно змінено');

                // Закриваємо модальне вікно і очищаємо форму
                closeModal('change-email-modal');
                form.reset();

                // Оновлюємо email на сторінці
                const newEmail = document.getElementById('new-email').value;
                const emailDisplay = document.getElementById('current-email');

                if (emailDisplay) {
                    emailDisplay.value = newEmail;
                }

                // Оновлюємо email в профілі і додаємо значок "не підтверджено"
                const profileEmail = document.querySelector('.profile-email');
                if (profileEmail) {
                    profileEmail.childNodes[0].nodeValue = newEmail;

                    // Додаємо значок "не підтверджено", якщо його ще немає
                    if (!profileEmail.querySelector('.email-not-verified')) {
                        const badge = document.createElement('span');
                        badge.className = 'badge email-not-verified';
                        badge.setAttribute('title', 'Email не підтверджено');
                        badge.textContent = 'Не підтверджено';
                        profileEmail.appendChild(badge);
                    }
                }
            } else {
                showNotification('error', data.message || 'Помилка при зміні email');
            }

            submitButton.innerHTML = originalText;
            submitButton.disabled = false;
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('error', 'Помилка при зміні email');
            submitButton.innerHTML = originalText;
            submitButton.disabled = false;
        });
}

/**
 * Ініціалізація обробників для зміни теми
 */
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

    // Обробка кнопки відкриття модального вікна вибору теми
    const changeThemeBtn = document.getElementById('change-theme-btn');
    if (changeThemeBtn) {
        changeThemeBtn.addEventListener('click', function(e) {
            e.preventDefault();
            openModal('change-theme-modal');
        });
    }
}

/**
 * Зміна теми оформлення
 */
function changeTheme(theme) {
    // Змінюємо тему на сторінці
    document.documentElement.setAttribute('data-theme', theme);

    // Зберігаємо тему в localStorage для локального збереження між сторінками
    localStorage.setItem('user-theme', theme);

    // Відправляємо запит на збереження теми
    fetch('dashboard.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
            'change_theme': '1',
            'theme': theme,
            'csrf_token': config.csrfToken
        })
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                config.theme = theme;
                showNotification('success', 'Тему успішно змінено');
            }
        })
        .catch(error => console.error('Error:', error));
}

/**
 * Ініціалізація моніторингу сесії
 */
function initSessionMonitor() {
    // Отримуємо дані про сесію
    const sessionLifetime = config.sessionLifetime;
    let lastActivity = config.lastActivity;

    // Функція для перевірки активності сесії
    function checkSession() {
        const currentTime = Math.floor(Date.now() / 1000);
        const elapsedTime = currentTime - lastActivity;
        const remainingTime = sessionLifetime - elapsedTime;

        // Якщо час сесії скоро закінчиться, показуємо попередження
        if (remainingTime <= 300 && remainingTime > 0) { // За 5 хвилин до закінчення
            showSessionWarning(remainingTime);
        }

        // Якщо час сесії закінчився, перенаправляємо на сторінку входу
        if (remainingTime <= 0) {
            window.location.href = '/?timeout=1';
            return;
        }
    }

    // Запускаємо перевірку кожні 60 секунд
    setInterval(checkSession, 60000);

    // Оновлюємо час останньої активності при взаємодії користувача зі сторінкою
    const events = ['mousemove', 'keypress', 'click', 'touchstart', 'scroll'];

    events.forEach(event => {
        document.addEventListener(event, function() {
            const currentTime = Math.floor(Date.now() / 1000);

            // Оновлюємо час останньої активності не частіше ніж раз на хвилину
            if (currentTime - lastActivity > 60) {
                lastActivity = currentTime;

                // Приховуємо попередження, якщо воно відображається
                hideSessionWarning();

                // Відправляємо запит на оновлення сесії
                fetch('dashboard.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: new URLSearchParams({
                        'check_session': '1',
                        'csrf_token': config.csrfToken
                    })
                });
            }
        });
    });
}

/**
 * Показує попередження про закінчення сесії
 */
function showSessionWarning(remainingTime) {
    // Перевіряємо, чи вже є попередження
    let warningElement = document.getElementById('session-warning');

    if (!warningElement) {
        // Створюємо елемент попередження
        warningElement = document.createElement('div');
        warningElement.id = 'session-warning';
        warningElement.className = 'session-warning';

        // Додаємо кнопки
        warningElement.innerHTML = `
            <div class="session-warning-content">
                <i class="fas fa-exclamation-triangle"></i>
                <p>Ваша сесія скоро закінчиться. <span id="session-timer"></span></p>
                <div class="session-warning-actions">
                    <button id="extend-session" class="btn btn-primary btn-sm">Продовжити сесію</button>
                    <button id="logout-now" class="btn btn-outline btn-sm">Вийти</button>
                </div>
            </div>
        `;

        // Додаємо на сторінку
        document.body.appendChild(warningElement);

        // Показуємо попередження
        setTimeout(() => {
            warningElement.classList.add('active');
        }, 100);

        // Ініціалізуємо таймер
        updateSessionTimer(remainingTime);

        // Додаємо обробники для кнопок
        document.getElementById('extend-session').addEventListener('click', function() {
            // Відправляємо запит на оновлення сесії
            fetch('dashboard.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: new URLSearchParams({
                    'check_session': '1',
                    'csrf_token': config.csrfToken
                })
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Оновлюємо час останньої активності
                        config.lastActivity = Math.floor(Date.now() / 1000);

                        // Приховуємо попередження
                        hideSessionWarning();
                    }
                });
        });

        document.getElementById('logout-now').addEventListener('click', function() {
            window.location.href = '?logout=1';
        });
    } else {
        // Оновлюємо таймер
        updateSessionTimer(remainingTime);
    }
}

/**
 * Приховує попередження про закінчення сесії
 */
function hideSessionWarning() {
    const warningElement = document.getElementById('session-warning');
    if (warningElement) {
        warningElement.classList.remove('active');

        // Видаляємо елемент після завершення анімації
        setTimeout(() => {
            if (warningElement.parentNode) {
                warningElement.parentNode.removeChild(warningElement);
            }
        }, 300);
    }
}

/**
 * Оновлює таймер у попередженні про закінчення сесії
 */
function updateSessionTimer(remainingTime) {
    const timerElement = document.getElementById('session-timer');
    if (timerElement) {
        // Форматуємо час
        const minutes = Math.floor(remainingTime / 60);
        const seconds = remainingTime % 60;

        timerElement.textContent = `(${minutes}:${seconds < 10 ? '0' : ''}${seconds})`;

        // Оновлюємо кожну секунду
        if (remainingTime > 0) {
            setTimeout(() => updateSessionTimer(remainingTime - 1), 1000);
        }
    }
}

/**
 * Ініціалізація файлового завантаження
 */
function initFileUpload() {
    // Обробка перетягування файлів для форми нового замовлення
    const fileInput = document.getElementById('files');
    const filePreview = document.getElementById('files-preview');
    const fileLabel = fileInput ? fileInput.nextElementSibling : null;

    if (fileInput && fileLabel) {
        // Додаємо обробник для вибору файлів
        fileInput.addEventListener('change', function() {
            if (this.files && this.files.length > 0) {
                previewFiles(this.files, filePreview);
            }
        });

        // Додаємо обробники для перетягування файлів
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            fileLabel.addEventListener(eventName, preventDefaults, false);
            document.body.addEventListener(eventName, preventDefaults, false);
        });

        // Додаємо класи при перетягуванні
        ['dragenter', 'dragover'].forEach(eventName => {
            fileLabel.addEventListener(eventName, () => {
                fileLabel.classList.add('highlight');
            }, false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            fileLabel.addEventListener(eventName, () => {
                fileLabel.classList.remove('highlight');
            }, false);
        });

        // Обробка відпускання файлів
        fileLabel.addEventListener('drop', function(e) {
            if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                fileInput.files = e.dataTransfer.files;
                previewFiles(e.dataTransfer.files, filePreview);
            }
        }, false);
    }

    // Обробка перетягування файлів для форми редагування замовлення
    const editFileInput = document.getElementById('edit-new-files');
    const editFilePreview = document.getElementById('edit-files-preview');
    const editFileLabel = editFileInput ? editFileInput.nextElementSibling : null;

    if (editFileInput && editFileLabel) {
        // Додаємо обробник для вибору файлів
        editFileInput.addEventListener('change', function() {
            if (this.files && this.files.length > 0) {
                previewFiles(this.files, editFilePreview);
            }
        });

        // Додаємо обробники для перетягування файлів
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            editFileLabel.addEventListener(eventName, preventDefaults, false);
            document.body.addEventListener(eventName, preventDefaults, false);
        });

        // Додаємо класи при перетягуванні
        ['dragenter', 'dragover'].forEach(eventName => {
            editFileLabel.addEventListener(eventName, () => {
                editFileLabel.classList.add('highlight');
            }, false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            editFileLabel.addEventListener(eventName, () => {
                editFileLabel.classList.remove('highlight');
            }, false);
        });

        // Обробка відпускання файлів
        editFileLabel.addEventListener('drop', function(e) {
            if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                editFileInput.files = e.dataTransfer.files;
                previewFiles(e.dataTransfer.files, editFilePreview);
            }
        }, false);
    }
}

/**
 * Запобігає стандартній поведінці браузера
 */
function preventDefaults(e) {
    e.preventDefault();
    e.stopPropagation();
}

/**
 * Попередній перегляд обраних файлів
 */
function previewFiles(files, previewContainer) {
    if (!previewContainer) return;

    // Очищаємо контейнер
    previewContainer.innerHTML = '';

    // Обмеження на кількість файлів
    const maxFiles = 10;
    const filesToProcess = Array.from(files).slice(0, maxFiles);

    // Додаємо попередній перегляд для кожного файлу
    filesToProcess.forEach((file, index) => {
        const previewItem = document.createElement('div');
        previewItem.className = 'file-preview-item';
        previewItem.setAttribute('data-index', index);

        // Визначаємо тип файлу
        const isImage = file.type.startsWith('image/');
        const isVideo = file.type.startsWith('video/');

        // Створюємо вміст для попереднього перегляду
        if (isImage) {
            const reader = new FileReader();
            reader.onload = function(e) {
                previewItem.innerHTML = `
                    <img src="${e.target.result}" alt="${file.name}">
                    <div class="file-info">
                        <div class="file-name">${file.name}</div>
                        <div class="file-size">${formatFileSize(file.size)}</div>
                    </div>
                    <button type="button" class="remove-file-btn" title="Видалити файл">×</button>
                `;
            };
            reader.readAsDataURL(file);
        } else {
            // Для не-зображень показуємо іконку
            previewItem.innerHTML = `
                <i class="${getFileIconClass(file.type, file.name)}"></i>
                <div class="file-info">
                    <div class="file-name">${file.name}</div>
                    <div class="file-size">${formatFileSize(file.size)}</div>
                </div>
                <button type="button" class="remove-file-btn" title="Видалити файл">×</button>
            `;
        }

        previewContainer.appendChild(previewItem);

        // Додаємо обробник для кнопки видалення
        previewItem.querySelector('.remove-file-btn').addEventListener('click', function() {
            previewItem.remove();
        });
    });

    // Показуємо повідомлення, якщо перевищено ліміт файлів
    if (files.length > maxFiles) {
        const message = document.createElement('div');
        message.className = 'files-limit-message';
        message.textContent = `Показано ${maxFiles} з ${files.length} файлів. Максимальна кількість файлів: ${maxFiles}.`;
        previewContainer.appendChild(message);
    }
}

/**
 * Отримує клас іконки для файлу залежно від його типу
 */
function getFileIconClass(mimeType, fileName) {
    if (!mimeType && !fileName) return 'fas fa-file';

    // Перевірка за розширенням, якщо MIME-тип не визначено
    if (!mimeType && fileName) {
        const extension = fileName.split('.').pop().toLowerCase();

        switch (extension) {
            case 'pdf': return 'fas fa-file-pdf';
            case 'doc':
            case 'docx': return 'fas fa-file-word';
            case 'xls':
            case 'xlsx': return 'fas fa-file-excel';
            case 'ppt':
            case 'pptx': return 'fas fa-file-powerpoint';
            case 'zip':
            case 'rar':
            case '7z': return 'fas fa-file-archive';
            case 'jpg':
            case 'jpeg':
            case 'png':
            case 'gif':
            case 'bmp':
            case 'webp': return 'fas fa-image';
            case 'mp4':
            case 'avi':
            case 'mov':
            case 'wmv':
            case 'mkv': return 'fas fa-file-video';
            case 'mp3':
            case 'wav':
            case 'ogg': return 'fas fa-file-audio';
            case 'txt':
            case 'log':
            case 'md': return 'fas fa-file-alt';
            default: return 'fas fa-file';
        }
    }

    // Перевірка за MIME-типом
    if (mimeType.startsWith('image/')) {
        return 'fas fa-image';
    } else if (mimeType.startsWith('video/')) {
        return 'fas fa-file-video';
    } else if (mimeType.startsWith('audio/')) {
        return 'fas fa-file-audio';
    } else if (mimeType === 'application/pdf') {
        return 'fas fa-file-pdf';
    } else if (mimeType === 'application/msword' || mimeType === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
        return 'fas fa-file-word';
    } else if (mimeType === 'application/vnd.ms-excel' || mimeType === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') {
        return 'fas fa-file-excel';
    } else if (mimeType === 'application/vnd.ms-powerpoint' || mimeType === 'application/vnd.openxmlformats-officedocument.presentationml.presentation') {
        return 'fas fa-file-powerpoint';
    } else if (mimeType === 'application/zip' || mimeType === 'application/x-rar-compressed' || mimeType === 'application/x-7z-compressed') {
        return 'fas fa-file-archive';
    } else if (mimeType === 'text/plain' || mimeType === 'text/markdown') {
        return 'fas fa-file-alt';
    }

    return 'fas fa-file';
}

/**
 * Форматує розмір файлу в читабельний формат
 */
function formatFileSize(bytes) {
    if (!bytes || bytes === 0) return '0 Б';

    const k = 1024;
    const sizes = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));

    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

/**
 * Ініціалізація індикатора складності пароля
 */
function initPasswordStrengthMeter() {
    const newPassword = document.getElementById('new-password');
    const confirmPassword = document.getElementById('confirm-password');
    const passwordStrength = document.getElementById('password-strength');
    const passwordMatch = document.getElementById('password-match');

    if (newPassword && passwordStrength) {
        newPassword.addEventListener('input', function() {
            updatePasswordStrengthIndicator(this.value, passwordStrength);
        });
    }

    if (newPassword && confirmPassword && passwordMatch) {
        confirmPassword.addEventListener('input', function() {
            updatePasswordMatchIndicator(newPassword.value, this.value, passwordMatch);
        });
    }
}

/**
 * Оновлює індикатор складності пароля
 */
function updatePasswordStrengthIndicator(password, indicator) {
    const strength = calculatePasswordStrength(password);

    // Очищаємо всі класи
    indicator.className = 'password-strength';

    // Додаємо відповідний клас
    if (strength < 30) {
        indicator.classList.add('weak');
        indicator.textContent = 'Слабкий пароль';
    } else if (strength < 70) {
        indicator.classList.add('medium');
        indicator.textContent = 'Середній пароль';
    } else {
        indicator.classList.add('strong');
        indicator.textContent = 'Надійний пароль';
    }
}

/**
 * Оновлює індикатор співпадіння паролів
 */
function updatePasswordMatchIndicator(password, confirmation, indicator) {
    // Очищаємо всі класи
    indicator.className = 'form-hint';

    if (confirmation === '') {
        indicator.textContent = '';
    } else if (password === confirmation) {
        indicator.classList.add('text-success');
        indicator.textContent = 'Паролі співпадають';
    } else {
        indicator.classList.add('text-danger');
        indicator.textContent = 'Паролі не співпадають';
    }
}

/**
 * Обчислює складність пароля
 */
function calculatePasswordStrength(password) {
    let strength = 0;

    if (!password) return strength;

    // Базова оцінка за довжину
    if (password.length >= 8) {
        strength += 20;

        // Додаткові бали за довшій пароль
        strength += Math.min(20, (password.length - 8) * 2);
    }

    // Бали за використання різних типів символів
    const patterns = [
        /[a-z]/, // маленькі літери
        /[A-Z]/, // великі літери
        /[0-9]/, // цифри
        /[^a-zA-Z0-9]/ // спеціальні символи
    ];

    // Додаємо бали за різні типи символів
    patterns.forEach(pattern => {
        if (pattern.test(password)) {
            strength += 15;
        }
    });

    // Бали за змішування різних типів символів у різних частинах
    const parts = [
        password.substring(0, password.length / 2),
        password.substring(password.length / 2)
    ];

    let mixedTypes = 0;
    parts.forEach(part => {
        patterns.forEach(pattern => {
            if (pattern.test(part)) mixedTypes++;
        });
    });

    strength += Math.min(10, mixedTypes * 2);

    // Обмежуємо оцінку до 100
    return Math.min(100, strength);
}

/**
 * Показує сповіщення користувачу
 */
window.showNotification = function(type, message, duration = 3000) {
    // Створюємо контейнер для сповіщень, якщо його ще немає
    let container = document.getElementById('notification-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'notification-container';
        document.body.appendChild(container);
    }

    // Створюємо нове сповіщення
    const notification = document.createElement('div');
    notification.className = `notification ${type}`;

    // Визначаємо іконку для типу сповіщення
    let iconClass = 'fas fa-info-circle';
    if (type === 'success') {
        iconClass = 'fas fa-check-circle';
    } else if (type === 'error') {
        iconClass = 'fas fa-exclamation-circle';
    } else if (type === 'warning') {
        iconClass = 'fas fa-exclamation-triangle';
    }

    // Додаємо вміст сповіщення
    notification.innerHTML = `
        <div class="notification-icon"><i class="${iconClass}"></i></div>
        <div class="notification-content">${message}</div>
        <button class="notification-close" title="Закрити">&times;</button>
    `;

    // Додаємо сповіщення в контейнер
    container.appendChild(notification);

    // Показуємо сповіщення з анімацією
    setTimeout(() => {
        notification.classList.add('active');

        // Додаємо обробник для закриття
        const closeBtn = notification.querySelector('.notification-close');
        closeBtn.addEventListener('click', () => {
            closeNotification(notification);
        });

        // Автоматичне закриття через вказаний час
        if (duration > 0) {
            setTimeout(() => {
                closeNotification(notification);
            }, duration);
        }
    }, 10);
};

/**
 * Закриває сповіщення
 */
function closeNotification(notification) {
    notification.classList.remove('active');

    // Видаляємо елемент після завершення анімації
    setTimeout(() => {
        if (notification.parentNode) {
            notification.parentNode.removeChild(notification);
        }
    }, 300);
}

/**
 * Перевіряє, чи можна редагувати замовлення з вказаним статусом
 */
function isOrderEditable(status) {
    if (!status) return false;

    status = status.toLowerCase();

    // Статуси, при яких замовлення можна редагувати
    const editableStatuses = ['новий', 'очікується', 'в роботі', 'прийнято'];

    for (const editableStatus of editableStatuses) {
        if (status.includes(editableStatus)) {
            return true;
        }
    }

    return false;
}

/**
 * Перевіряє, чи можна скасувати замовлення з вказаним статусом
 */
function isOrderCancellable(status) {
    if (!status) return false;

    status = status.toLowerCase();

    // Статуси, при яких замовлення не можна скасувати
    const nonCancellableStatuses = ['виконано', 'завершено', 'скасовано', 'видано'];

    for (const nonCancellableStatus of nonCancellableStatuses) {
        if (status.includes(nonCancellableStatus)) {
            return false;
        }
    }

    // За замовчуванням дозволяємо скасування, якщо статус не входить до списку нескасовуваних
    return true;
}

/**
 * Генерує HTML для відображення інформації про замовлення
 */
function generateOrderInfoHTML(order) {
    // Перевірка необхідних полів
    if (!order) {
        return '<div class="empty-state-mini">Дані замовлення відсутні</div>';
    }

    const createdAt = formatDateTime(order.created_at || '');
    const updatedAt = formatDateTime(order.updated_at || '');
    const estimatedDate = formatDate(order.estimated_completion_date || '');

    let statusClass = 'status-default';
    if (order.status) {
        statusClass = getStatusClass(order.status);
    }

    // Форматування ціни з безпечною перевіркою
    const price = (order.price || order.price === 0) ? formatCurrency(order.price) : 'Не вказана';

    // Безпечне отримання значень з перевіркою
    const service = order.service || 'Не вказано';
    const deviceType = order.device_type || 'Не вказано';
    const status = order.status || 'Невідомо';
    const details = order.details || 'Не вказано';
    const phone = order.phone || 'Не вказано';
    const deliveryMethod = getDeliveryMethodName(order.delivery_method) || 'Не вказано';
    const address = order.address || '';
    const userComment = order.user_comment || '';

    return `
        <div class="order-details-full">
            <div class="order-detail-row">
                <div class="order-detail-label">Послуга</div>
                <div class="order-detail-value">${service}</div>
            </div>
            
            <div class="order-detail-row">
                <div class="order-detail-label">Пристрій</div>
                <div class="order-detail-value">${deviceType}</div>
            </div>
            
            <div class="order-detail-row">
                <div class="order-detail-label">Статус</div>
                <div class="order-detail-value">
                    <span class="status-badge ${statusClass}">${status}</span>
                </div>
            </div>
            
            <div class="order-detail-row">
                <div class="order-detail-label">Опис проблеми</div>
                <div class="order-detail-value">${details}</div>
            </div>
            
            <div class="order-detail-row">
                <div class="order-detail-label">Контактний телефон</div>
                <div class="order-detail-value">${phone}</div>
            </div>
            
            <div class="order-detail-row">
                <div class="order-detail-label">Спосіб доставки</div>
                <div class="order-detail-value">${deliveryMethod}</div>
            </div>
            
            ${address ? `
            <div class="order-detail-row">
                <div class="order-detail-label">Адреса</div>
                <div class="order-detail-value">${address}</div>
            </div>
            ` : ''}
            
            ${userComment ? `
            <div class="order-detail-row">
                <div class="order-detail-label">Коментар клієнта</div>
                <div class="order-detail-value">${userComment}</div>
            </div>
            ` : ''}
            
            <div class="order-detail-row">
                <div class="order-detail-label">Вартість</div>
                <div class="order-detail-value">${price}</div>
            </div>
            
            ${estimatedDate ? `
            <div class="order-detail-row">
                <div class="order-detail-label">Очікувана дата завершення</div>
                <div class="order-detail-value">${estimatedDate}</div>
            </div>
            ` : ''}
            
            <div class="order-detail-row">
                <div class="order-detail-label">Дата створення</div>
                <div class="order-detail-value">${createdAt}</div>
            </div>
            
            <div class="order-detail-row">
                <div class="order-detail-label">Останнє оновлення</div>
                <div class="order-detail-value">${updatedAt}</div>
            </div>
        </div>
    `;
}

/**
 * Генерує HTML для відображення файлів замовлення
 */
function generateOrderFilesHTML(files) {
    if (!files || !Array.isArray(files) || files.length === 0) {
        return `
            <div class="empty-state-mini">
                <i class="fas fa-file"></i>
                <p>До цього замовлення не прикріплено файлів</p>
            </div>
        `;
    }

    let html = '<div class="order-files-grid">';

    files.forEach(file => {
        if (!file) return;

        const isImage = file.file_type === 'image' || (file.file_mime && file.file_mime.startsWith('image/'));
        const filePath = file.file_path || '#';
        const originalName = file.original_name || 'Файл';
        const fileSize = file.file_size ? formatFileSize(file.file_size) : '';

        html += `
            <div class="file-card">
                <div class="file-thumbnail">
                    ${isImage ?
            `<img src="${filePath}" alt="${originalName}">` :
            `<i class="${getFileIconClass(file.file_mime || '', originalName)}"></i>`
        }
                </div>
                <div class="file-info">
                    <div class="file-name" title="${originalName}">${originalName}</div>
                    ${fileSize ? `<div class="file-size">${fileSize}</div>` : ''}
                </div>
                <div class="file-actions">
                    <a href="${filePath}" target="_blank" class="file-action" title="Відкрити">
                        <i class="fas fa-external-link-alt"></i>
                    </a>
                    <a href="${filePath}" download="${originalName}" class="file-action" title="Завантажити">
                        <i class="fas fa-download"></i>
                    </a>
                </div>
            </div>
        `;
    });

    html += '</div>';

    return html;
}

/**
 * Генерує HTML для відображення коментарів замовлення
 */
function generateOrderCommentsHTML(comments) {
    if (!comments || !Array.isArray(comments) || comments.length === 0) {
        return `
            <div class="empty-state-mini">
                <i class="fas fa-comments"></i>
                <p>Поки що немає коментарів</p>
            </div>
        `;
    }

    let html = '';

    comments.forEach(comment => {
        if (!comment) return;

        const isAdmin = comment.is_admin == 1 || comment.author_role === 'admin';
        const date = formatDateTime(comment.created_at || '');
        const commentText = comment.comment || '';
        const authorName = comment.author_display_name || comment.author_name || 'Користувач';

        // Аватар користувача
        let avatarHtml = '<i class="fas fa-user"></i>';
        if (comment.author_avatar) {
            avatarHtml = `<img src="/uploads/avatars/${comment.author_avatar}" alt="${authorName}">`;
        }

        html += `
            <div class="comment-item ${isAdmin ? 'admin-comment' : ''}">
                <div class="comment-avatar">
                    ${avatarHtml}
                </div>
                <div class="comment-content">
                    <div class="comment-header">
                        <div class="comment-author">
                            ${authorName}
                            ${isAdmin ? '<span class="admin-badge">Адміністратор</span>' : ''}
                        </div>
                        <div class="comment-date">${date}</div>
                    </div>
                    <div class="comment-text">${commentText}</div>
                </div>
            </div>
        `;
    });

    return html;
}

/**
 * Генерує HTML для відображення історії замовлення
 */
function generateOrderHistoryHTML(history) {
    if (!history || !Array.isArray(history) || history.length === 0) {
        return `
            <div class="empty-state-mini">
                <i class="fas fa-history"></i>
                <p>Історія недоступна</p>
            </div>
        `;
    }

    let html = '<div class="order-history-list">';

    history.forEach(item => {
        if (!item) return;

        const date = formatDateTime(item.changed_at || '');
        const authorName = item.changed_by_display_name || item.changed_by_name || 'Система';
        const comment = item.comment || '';

        const previousStatus = item.previous_status || '';
        const newStatus = item.new_status || 'Новий';

        const changeText = previousStatus ?
            `Статус змінено з <span class="status-old">${previousStatus}</span> на <span class="status-new">${newStatus}</span>` :
            `Замовлення створено зі статусом <span class="status-new">${newStatus}</span>`;

        html += `
            <div class="history-item">
                <div class="history-icon">
                    <i class="fas fa-sync-alt"></i>
                </div>
                <div class="history-content">
                    <div class="history-header">
                        <div class="history-title">${changeText}</div>
                        <div class="history-date">${date}</div>
                    </div>
                    <div class="history-author">
                        ${authorName}
                    </div>
                    ${comment ? `<div class="history-comment">${comment}</div>` : ''}
                </div>
            </div>
        `;
    });

    html += '</div>';

    return html;
}

/**
 * Конвертує дату в рядок формату ДД.ММ.РРРР
 */
function formatDate(dateString) {
    if (!dateString) return '';

    try {
        const date = new Date(dateString);
        if (isNaN(date.getTime())) return ''; // Перевірка на валідність дати

        const day = String(date.getDate()).padStart(2, '0');
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const year = date.getFullYear();

        return `${day}.${month}.${year}`;
    } catch (e) {
        console.error('Помилка форматування дати:', e);
        return '';
    }
}

/**
 * Конвертує дату в рядок формату ДД.ММ.РРРР ГГ:ХХ
 */
function formatDateTime(dateString) {
    if (!dateString) return '';

    try {
        const date = new Date(dateString);
        if (isNaN(date.getTime())) return ''; // Перевірка на валідність дати

        const day = String(date.getDate()).padStart(2, '0');
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const year = date.getFullYear();
        const hours = String(date.getHours()).padStart(2, '0');
        const minutes = String(date.getMinutes()).padStart(2, '0');

        return `${day}.${month}.${year} ${hours}:${minutes}`;
    } catch (e) {
        console.error('Помилка форматування дати і часу:', e);
        return '';
    }
}

/**
 * Форматує ціну у вигляді валюти
 */
function formatCurrency(value) {
    if (value === null || value === undefined) return 'Не вказана';

    try {
        return new Intl.NumberFormat('uk-UA', {
            style: 'currency',
            currency: 'UAH',
            minimumFractionDigits: 2
        }).format(value);
    } catch (e) {
        console.error('Помилка форматування валюти:', e);
        return value + ' грн';
    }
}

/**
 * Повертає назву способу доставки
 */
function getDeliveryMethodName(method) {
    if (!method) return '';

    switch (method) {
        case 'self': return 'Самовивіз';
        case 'courier': return 'Кур\'єр';
        case 'nova-poshta': return 'Нова Пошта';
        case 'ukrposhta': return 'Укрпошта';
        default: return method;
    }
}

/**
 * Повертає клас для статусу замовлення
 */
function getStatusClass(status) {
    if (!status) return 'status-default';

    const statusLower = status.toLowerCase();

    if (statusLower.includes('нов')) {
        return 'status-new';
    } else if (statusLower.includes('робот') || statusLower.includes('в роботі')) {
        return 'status-in-progress';
    } else if (statusLower.includes('очіку')) {
        return 'status-pending';
    } else if (statusLower.includes('заверш') || statusLower.includes('готов') || statusLower.includes('викон')) {
        return 'status-completed';
    } else if (statusLower.includes('скасова') || statusLower.includes('відмін')) {
        return 'status-cancelled';
    }

    return 'status-default';
}
/**
 * Show user activity log in a modal window
 */
function showActivityLog() {
    fetch('dashboard.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
            'get_activity_log': '1',
            'csrf_token': config.csrfToken
        })
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Create modal content
                let tableRows = '';

                data.logs.forEach(log => {
                    const createdAt = new Date(log.created_at);
                    const formattedDate = createdAt.toLocaleDateString() + ' ' + createdAt.toLocaleTimeString();

                    // Format action for display
                    let action = log.action.replace(/_/g, ' ');
                    action = action.charAt(0).toUpperCase() + action.slice(1);

                    // Format details if available
                    let details = '';
                    if (log.details) {
                        try {
                            const detailsObj = JSON.parse(log.details);
                            details = Object.entries(detailsObj)
                                .map(([key, value]) => `<strong>${key}:</strong> ${value}`)
                                .join('<br>');
                        } catch (e) {
                            details = log.details;
                        }
                    }

                    tableRows += `
                <tr>
                    <td>${formattedDate}</td>
                    <td>${action}</td>
                    <td>${log.entity_type || ''} ${log.entity_id ? `#${log.entity_id}` : ''}</td>
                    <td>${details}</td>
                    <td>${log.ip_address || ''}</td>
                </tr>`;
                });

                const modalContent = `
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Дата і час</th>
                                <th>Дія</th>
                                <th>Об'єкт</th>
                                <th>Деталі</th>
                                <th>IP-адреса</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${tableRows}
                        </tbody>
                    </table>
                </div>
                ${data.has_more ? '<div class="text-center mt-3"><button id="load-more-activity" class="btn btn-primary">Завантажити більше</button></div>' : ''}
            `;

                // Show modal with activity log
                showModal('Журнал активності', modalContent, 'lg');

                // Add event listener for loading more activities
                const loadMoreBtn = document.getElementById('load-more-activity');
                if (loadMoreBtn && data.has_more) {
                    loadMoreBtn.addEventListener('click', function() {
                        loadMoreActivityLog(data.page + 1, this);
                    });
                }
            } else {
                showNotification('error', data.message || 'Не вдалося отримати журнал активності');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('error', 'Помилка при отриманні журналу активності');
        });
}

/**
 * Load more activity log entries
 * @param {number} page - Page number to load
 * @param {HTMLElement} button - Load more button element
 */
function loadMoreActivityLog(page, button) {
    // Show loading state
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Завантаження...';
    button.disabled = true;

    fetch('dashboard.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
            'get_activity_log': '1',
            'page': page,
            'csrf_token': config.csrfToken
        })
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Create rows for new log entries
                let tableRows = '';

                data.logs.forEach(log => {
                    const createdAt = new Date(log.created_at);
                    const formattedDate = createdAt.toLocaleDateString() + ' ' + createdAt.toLocaleTimeString();

                    // Format action for display
                    let action = log.action.replace(/_/g, ' ');
                    action = action.charAt(0).toUpperCase() + action.slice(1);

                    // Format details if available
                    let details = '';
                    if (log.details) {
                        try {
                            const detailsObj = JSON.parse(log.details);
                            details = Object.entries(detailsObj)
                                .map(([key, value]) => `<strong>${key}:</strong> ${value}`)
                                .join('<br>');
                        } catch (e) {
                            details = log.details;
                        }
                    }

                    tableRows += `
                <tr>
                    <td>${formattedDate}</td>
                    <td>${action}</td>
                    <td>${log.entity_type || ''} ${log.entity_id ? `#${log.entity_id}` : ''}</td>
                    <td>${details}</td>
                    <td>${log.ip_address || ''}</td>
                </tr>`;
                });

                // Add new rows to the table
                const tbody = document.querySelector('.modal-body table tbody');
                tbody.innerHTML += tableRows;

                // Update or remove the load more button
                if (data.has_more) {
                    button.innerHTML = 'Завантажити більше';
                    button.disabled = false;
                    button.setAttribute('data-page', data.page + 1);
                } else {
                    button.parentNode.remove();
                }
            } else {
                showNotification('error', data.message || 'Не вдалося отримати додаткові записи журналу');
                button.innerHTML = 'Завантажити більше';
                button.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('error', 'Помилка при отриманні додаткових записів журналу');
            button.innerHTML = 'Завантажити більше';
            button.disabled = false;
        });
}
/**
 * Show active sessions in a modal window
 */
function showActiveSessions() {
    // Request active sessions data
    fetch('dashboard.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
            'get_sessions': '1',
            'csrf_token': config.csrfToken
        })
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Create modal content
                let tableRows = '';

                data.sessions.forEach(session => {
                    const isCurrentSession = session.is_current ? '<span class="badge bg-primary">Поточний</span>' : '';
                    const lastActivity = new Date(session.last_activity);
                    const formattedDate = lastActivity.toLocaleDateString() + ' ' + lastActivity.toLocaleTimeString();

                    tableRows += `
                <tr>
                    <td>${session.browser} ${isCurrentSession}</td>
                    <td>${session.ip_address}</td>
                    <td>${formattedDate}</td>
                    <td>
                        ${!session.is_current ? `<button class="btn btn-danger btn-sm terminate-session" data-token="${session.session_token}">Завершити</button>` : ''}
                    </td>
                </tr>`;
                });

                const modalContent = `
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Пристрій</th>
                                <th>IP-адреса</th>
                                <th>Остання активність</th>
                                <th>Дії</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${tableRows}
                        </tbody>
                    </table>
                </div>
                <div class="modal-footer">
                    <button id="terminate-all-sessions" class="btn btn-danger">Завершити всі інші сеанси</button>
                </div>
            `;

                // Show modal with sessions
                showModal('Активні сеанси', modalContent);

                // Add event listeners for session termination
                document.querySelectorAll('.terminate-session').forEach(btn => {
                    btn.addEventListener('click', function() {
                        terminateSession(this.getAttribute('data-token'));
                    });
                });

                // Add event listener for terminating all sessions
                const terminateAllBtn = document.getElementById('terminate-all-sessions');
                if (terminateAllBtn) {
                    terminateAllBtn.addEventListener('click', terminateAllSessions);
                }
            } else {
                showNotification('error', data.message || 'Не вдалося отримати дані про активні сеанси');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('error', 'Помилка при отриманні даних про активні сеанси');
        });
}

/**
 * Terminate a specific session
 * @param {string} sessionToken - Token of the session to terminate
 */
function terminateSession(sessionToken) {
    if (!confirm('Ви впевнені, що хочете завершити цей сеанс?')) return;

    fetch('dashboard.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
            'terminate_session': '1',
            'session_token': sessionToken,
            'csrf_token': config.csrfToken
        })
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification('success', 'Сеанс успішно завершено');
                showActiveSessions(); // Refresh the sessions list
            } else {
                showNotification('error', data.message || 'Помилка при завершенні сеансу');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('error', 'Помилка при завершенні сеансу');
        });
}

/**
 * Terminate all other sessions except the current one
 */
function terminateAllSessions() {
    if (!confirm('Ви впевнені, що хочете завершити всі інші сеанси?')) return;

    fetch('dashboard.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
            'terminate_all_sessions': '1',
            'csrf_token': config.csrfToken
        })
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification('success', 'Усі інші сеанси успішно завершено');
                showActiveSessions(); // Refresh the sessions list
            } else {
                showNotification('error', data.message || 'Помилка при завершенні сеансів');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('error', 'Помилка при завершенні сеансів');
        });
}
/**
 * Показати активні сеанси у модальному вікні
 */
function showActiveSessions() {
    // Запит активних сеансів
    fetch('dashboard.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
            'get_sessions': '1',
            'csrf_token': config.csrfToken
        })
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Створення вмісту модального вікна
                let tableRows = '';

                data.sessions.forEach(session => {
                    const isCurrentSession = session.is_current ? '<span class="badge bg-primary">Поточний</span>' : '';
                    const lastActivity = new Date(session.last_activity);
                    const formattedDate = lastActivity.toLocaleDateString() + ' ' + lastActivity.toLocaleTimeString();

                    tableRows += `
                <tr>
                    <td>${session.browser} ${isCurrentSession}</td>
                    <td>${session.ip_address}</td>
                    <td>${formattedDate}</td>
                    <td>
                        ${!session.is_current ? `<button class="btn btn-danger btn-sm terminate-session" data-token="${session.session_token}">Завершити</button>` : ''}
                    </td>
                </tr>`;
                });

                const modalContent = `
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Пристрій</th>
                                <th>IP-адреса</th>
                                <th>Остання активність</th>
                                <th>Дії</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${tableRows}
                        </tbody>
                    </table>
                </div>
                <div class="modal-footer">
                    <button id="terminate-all-sessions" class="btn btn-danger">Завершити всі інші сеанси</button>
                </div>
            `;

                // Показати модальне вікно із сеансами
                showModal('Активні сеанси', modalContent);

                // Додати обробники подій для кнопок завершення сеансів
                document.querySelectorAll('.terminate-session').forEach(btn => {
                    btn.addEventListener('click', function() {
                        terminateSession(this.getAttribute('data-token'));
                    });
                });

                // Додати обробник події для кнопки завершення всіх сеансів
                const terminateAllBtn = document.getElementById('terminate-all-sessions');
                if (terminateAllBtn) {
                    terminateAllBtn.addEventListener('click', terminateAllSessions);
                }
            } else {
                showNotification('error', data.message || 'Не вдалося отримати дані про активні сеанси');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('error', 'Помилка при отриманні даних про активні сеанси');
        });
}

/**
 * Завершення певного сеансу
 * @param {string} sessionToken - Токен сеансу, який потрібно завершити
 */
function terminateSession(sessionToken) {
    if (!confirm('Ви впевнені, що хочете завершити цей сеанс?')) return;

    fetch('dashboard.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
            'terminate_session': '1',
            'session_token': sessionToken,
            'csrf_token': config.csrfToken
        })
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification('success', 'Сеанс успішно завершено');
                showActiveSessions(); // Оновити список сеансів
            } else {
                showNotification('error', data.message || 'Помилка при завершенні сеансу');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('error', 'Помилка при завершенні сеансу');
        });
}

/**
 * Завершення всіх інших сеансів, крім поточного
 */
function terminateAllSessions() {
    if (!confirm('Ви впевнені, що хочете завершити всі інші сеанси?')) return;

    fetch('dashboard.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
            'terminate_all_sessions': '1',
            'csrf_token': config.csrfToken
        })
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification('success', 'Усі інші сеанси успішно завершено');
                showActiveSessions(); // Оновити список сеансів
            } else {
                showNotification('error', data.message || 'Помилка при завершенні сеансів');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('error', 'Помилка при завершенні сеансів');
        });
}

/**
 * Показати журнал активності користувача
 */
function showActivityLog() {
    fetch('dashboard.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
            'get_activity_log': '1',
            'csrf_token': config.csrfToken
        })
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Створити вміст модального вікна
                let tableRows = '';

                data.logs.forEach(log => {
                    const createdAt = new Date(log.created_at);
                    const formattedDate = createdAt.toLocaleDateString() + ' ' + createdAt.toLocaleTimeString();

                    // Форматувати дію для відображення
                    let action = log.action.replace(/_/g, ' ');
                    action = action.charAt(0).toUpperCase() + action.slice(1);

                    // Форматувати деталі, якщо вони є
                    let details = '';
                    if (log.details) {
                        try {
                            const detailsObj = JSON.parse(log.details);
                            details = Object.entries(detailsObj)
                                .map(([key, value]) => `<strong>${key}:</strong> ${value}`)
                                .join('<br>');
                        } catch (e) {
                            details = log.details;
                        }
                    }

                    tableRows += `
                <tr>
                    <td>${formattedDate}</td>
                    <td>${action}</td>
                    <td>${log.entity_type || ''} ${log.entity_id ? `#${log.entity_id}` : ''}</td>
                    <td>${details}</td>
                    <td>${log.ip_address || ''}</td>
                </tr>`;
                });

                const modalContent = `
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Дата і час</th>
                                <th>Дія</th>
                                <th>Об'єкт</th>
                                <th>Деталі</th>
                                <th>IP-адреса</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${tableRows}
                        </tbody>
                    </table>
                </div>
                ${data.has_more ? '<div class="text-center mt-3"><button id="load-more-activity" class="btn btn-primary" data-page="' + (data.page + 1) + '">Завантажити більше</button></div>' : ''}
            `;

                // Показати модальне вікно з журналом активності
                showModal('Журнал активності', modalContent, 'lg');

                // Додати обробник події для кнопки завантаження додаткових записів
                const loadMoreBtn = document.getElementById('load-more-activity');
                if (loadMoreBtn && data.has_more) {
                    loadMoreBtn.addEventListener('click', function() {
                        loadMoreActivityLog(parseInt(this.getAttribute('data-page')), this);
                    });
                }
            } else {
                showNotification('error', data.message || 'Не вдалося отримати журнал активності');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('error', 'Помилка при отриманні журналу активності');
        });
}

/**
 * Завантажити більше записів журналу активності
 * @param {number} page - Номер сторінки для завантаження
 * @param {HTMLElement} button - Елемент кнопки "Завантажити більше"
 */
function loadMoreActivityLog(page, button) {
    // Показати стан завантаження
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Завантаження...';
    button.disabled = true;

    fetch('dashboard.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
            'get_activity_log': '1',
            'page': page,
            'csrf_token': config.csrfToken
        })
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Створити рядки для нових записів журналу
                let tableRows = '';

                data.logs.forEach(log => {
                    const createdAt = new Date(log.created_at);
                    const formattedDate = createdAt.toLocaleDateString() + ' ' + createdAt.toLocaleTimeString();

                    // Форматувати дію для відображення
                    let action = log.action.replace(/_/g, ' ');
                    action = action.charAt(0).toUpperCase() + action.slice(1);

                    // Форматувати деталі, якщо вони є
                    let details = '';
                    if (log.details) {
                        try {
                            const detailsObj = JSON.parse(log.details);
                            details = Object.entries(detailsObj)
                                .map(([key, value]) => `<strong>${key}:</strong> ${value}`)
                                .join('<br>');
                        } catch (e) {
                            details = log.details;
                        }
                    }

                    tableRows += `
                <tr>
                    <td>${formattedDate}</td>
                    <td>${action}</td>
                    <td>${log.entity_type || ''} ${log.entity_id ? `#${log.entity_id}` : ''}</td>
                    <td>${details}</td>
                    <td>${log.ip_address || ''}</td>
                </tr>`;
                });

                // Додати нові рядки до таблиці
                const tbody = document.querySelector('.modal-body table tbody');
                tbody.innerHTML += tableRows;

                // Оновити або видалити кнопку "Завантажити більше"
                if (data.has_more) {
                    button.innerHTML = 'Завантажити більше';
                    button.disabled = false;
                    button.setAttribute('data-page', data.page + 1);
                } else {
                    button.parentNode.remove();
                }
            } else {
                showNotification('error', data.message || 'Не вдалося отримати додаткові записи журналу');
                button.innerHTML = 'Завантажити більше';
                button.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('error', 'Помилка при отриманні додаткових записів журналу');
            button.innerHTML = 'Завантажити більше';
            button.disabled = false;
        });
}

/**
 * Функція для зміни теми сайту
 * @param {string} theme - Назва теми (light, dark, blue, grey)
 */
function changeTheme(theme) {
    // Відправити запит на зміну теми
    fetch('dashboard.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
            'change_theme': '1',
            'theme': theme,
            'csrf_token': config.csrfToken
        })
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Оновити атрибут data-theme на html елементі
                document.documentElement.setAttribute('data-theme', theme);

                // Оновити значення в конфігурації
                config.theme = theme;

                // Закрити модальне вікно вибору теми
                closeModal('change-theme-modal');

                showNotification('success', 'Тему оформлення успішно змінено');
            } else {
                showNotification('error', data.message || 'Помилка при зміні теми');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('error', 'Помилка при зміні теми');
        });
}

/**
 * Функція для відображення модального вікна
 * @param {string} title - Заголовок модального вікна
 * @param {string} content - HTML-вміст модального вікна
 * @param {string} size - Розмір модального вікна (sm, md, lg)
 */
function showModal(title, content, size = 'md') {
    // Створити елементи модального вікна
    const modal = document.createElement('div');
    modal.className = 'modal active';
    modal.id = 'dynamic-modal';

    const sizeClass = size === 'lg' ? 'modal-lg' : (size === 'sm' ? 'modal-sm' : '');

    modal.innerHTML = `
        <div class="modal-dialog ${sizeClass}">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">${title}</h3>
                    <button type="button" class="modal-close" onclick="closeModal('dynamic-modal')">&times;</button>
                </div>
                <div class="modal-body">
                    ${content}
                </div>
            </div>
        </div>
    `;

    // Додати модальне вікно до body
    document.body.appendChild(modal);

    // Додати обробник події для закриття модального вікна по кліку поза ним
    modal.addEventListener('click', function(event) {
        if (event.target === modal) {
            closeModal('dynamic-modal');
        }
    });
}

/**
 * Функція для закриття модального вікна
 * @param {string} modalId - ID модального вікна
 */
function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        if (modalId === 'dynamic-modal') {
            modal.remove();
        } else {
            modal.classList.remove('active');
        }
    }
}

/**
 * Функція для відображення повідомлень користувачу
 * @param {string} type - Тип повідомлення (success, error, info, warning)
 * @param {string} message - Текст повідомлення
 * @param {number} duration - Тривалість відображення в мілісекундах
 */
function showNotification(type, message, duration = 3000) {
    const container = document.getElementById('notification-container') || createNotificationContainer();

    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;

    let icon = '';
    switch (type) {
        case 'success':
            icon = '<i class="fas fa-check-circle"></i>';
            break;
        case 'error':
            icon = '<i class="fas fa-exclamation-circle"></i>';
            break;
        case 'info':
            icon = '<i class="fas fa-info-circle"></i>';
            break;
        case 'warning':
            icon = '<i class="fas fa-exclamation-triangle"></i>';
            break;
    }

    notification.innerHTML = `
        ${icon}
        <span class="notification-message">${message}</span>
        <button class="notification-close">&times;</button>
    `;

    container.appendChild(notification);

    // Додати клас для анімації появи
    setTimeout(() => {
        notification.classList.add('show');
    }, 10);

    // Додати обробник події для кнопки закриття
    const closeBtn = notification.querySelector('.notification-close');
    closeBtn.addEventListener('click', () => {
        closeNotification(notification);
    });

    // Автоматичне закриття після вказаного часу
    setTimeout(() => {
        closeNotification(notification);
    }, duration);
}

/**
 * Створити контейнер для повідомлень, якщо він відсутній
 * @returns {HTMLElement} Контейнер для повідомлень
 */
function createNotificationContainer() {
    const container = document.createElement('div');
    container.id = 'notification-container';
    document.body.appendChild(container);
    return container;
}

/**
 * Закрити повідомлення з анімацією
 * @param {HTMLElement} notification - Елемент повідомлення
 */
function closeNotification(notification) {
    notification.classList.remove('show');
    notification.classList.add('hide');

    setTimeout(() => {
        notification.remove();
    }, 300); // час анімації зникнення
}
// Додайте цей код у ваш JavaScript файл, який обробляє отримання даних замовлення

function loadOrderDetails(orderId) {
    // Показуємо спіннер завантаження
    $('.loading-spinner').show();

    fetch(`dashboard.php?get_order_details=${orderId}`, {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const order = data.order;

                // Заповнюємо інформацію про замовлення
                $('#view-order-id').text(order.id);

                // Заповнюємо вкладку з файлами
                let filesContent = '';
                if (order.files && order.files.length > 0) {
                    filesContent = '<div class="files-grid">';
                    order.files.forEach(file => {
                        let filePreview = '';
                        if (file.file_type === 'image' || file.file_mime.startsWith('image/')) {
                            filePreview = `<img src="${file.file_path}" alt="${file.original_name}">`;
                        } else {
                            filePreview = `<i class="fas fa-file"></i>`;
                        }

                        filesContent += `
                        <div class="file-item" data-id="${file.id}">
                            <div class="file-thumbnail">
                                ${filePreview}
                            </div>
                            <div class="file-info">
                                <div class="file-name">${file.original_name}</div>
                                <div class="file-size">${formatFileSize(file.file_size)}</div>
                            </div>
                            <div class="file-actions">
                                <a href="${file.file_path}" download="${file.original_name}" class="btn btn-sm btn-primary">
                                    <i class="fas fa-download"></i> Завантажити
                                </a>
                            </div>
                        </div>
                    `;
                    });
                    filesContent += '</div>';
                } else {
                    filesContent = `
                    <div class="empty-state">
                        <i class="fas fa-file-upload"></i>
                        <p>Немає прикріплених файлів</p>
                    </div>
                `;
                }
                $('#order-files-content').html(filesContent);

                // Коли редагуємо замовлення, додаємо файли в форму редагування
                if (order.files && order.files.length > 0) {
                    let editFilesContent = '';

                    order.files.forEach(file => {
                        let filePreview = '';
                        if (file.file_type === 'image' || file.file_mime.startsWith('image/')) {
                            filePreview = `<img src="${file.file_path}" alt="${file.original_name}">`;
                        } else {
                            filePreview = `<i class="fas fa-file"></i>`;
                        }

                        editFilesContent += `
                        <div class="file-item" data-id="${file.id}">
                            <div class="file-thumbnail">
                                ${filePreview}
                            </div>
                            <div class="file-info">
                                <div class="file-name">${file.original_name}</div>
                                <div class="file-size">${formatFileSize(file.file_size)}</div>
                            </div>
                            <div class="file-actions">
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" id="remove-file-${file.id}" name="remove_files[]" value="${file.id}">
                                    <label class="form-check-label" for="remove-file-${file.id}">Видалити</label>
                                </div>
                            </div>
                        </div>
                    `;
                    });

                    $('#edit-files-list').html(editFilesContent);
                } else {
                    $('#edit-files-list').html(`
                    <p>Немає прикріплених файлів</p>
                `);
                }

                // Приховуємо спіннер завантаження
                $('.loading-spinner').hide();
            }
        })
        .catch(error => {
            console.error('Error:', error);
            $('.loading-spinner').hide();
        });
}

function formatFileSize(bytes) {
    if (bytes === 0 || !bytes) return '0 Б';
    const k = 1024;
    const sizes = ['Б', 'КБ', 'МБ', 'ГБ'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}
// Додайте цей код для відображення коментарів та історії замовлення

function loadOrderComments(orderId) {
    // Отримуємо коментарі та історію з даних замовлення
    fetch(`dashboard.php?get_order_details=${orderId}`, {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const order = data.order;

                // Заповнюємо вкладку з коментарями
                let commentsContent = '';
                if (order.comments && order.comments.length > 0) {
                    commentsContent = '<div class="comments-list">';
                    order.comments.forEach(comment => {
                        const isAdmin = comment.is_admin == 1 || comment.author_role === 'admin';
                        const commentDate = new Date(comment.created_at);
                        const formattedDate = commentDate.toLocaleDateString() + ' ' + commentDate.toLocaleTimeString();

                        commentsContent += `
                        <div class="comment${isAdmin ? ' admin-comment' : ''}" data-id="${comment.id}">
                            <div class="comment-header">
                                <div class="comment-author">${comment.author_display_name || comment.author_name || 'Користувач'}</div>
                                <div class="comment-date">${formattedDate}</div>
                            </div>
                            <div class="comment-text">${comment.comment}</div>
                            ${!isAdmin && order.status !== 'Завершено' && order.status !== 'Скасовано' ?
                            `<div class="comment-actions">
                                    <button class="btn btn-danger btn-sm delete-comment-btn" data-id="${comment.id}">
                                        <i class="fas fa-trash"></i> Видалити
                                    </button>
                                </div>` : ''
                        }
                        </div>
                    `;
                    });
                    commentsContent += '</div>';
                } else {
                    commentsContent = `
                    <div class="empty-state">
                        <i class="fas fa-comments"></i>
                        <p>Немає коментарів</p>
                    </div>
                `;
                }
                $('#order-comments-content').html(commentsContent);

                // Заповнюємо вкладку з історією змін
                let historyContent = '';
                if (order.status_history && order.status_history.length > 0) {
                    historyContent = '<div class="history-timeline">';
                    order.status_history.forEach(item => {
                        const historyDate = new Date(item.changed_at);
                        const formattedDate = historyDate.toLocaleDateString() + ' ' + historyDate.toLocaleTimeString();

                        historyContent += `
                        <div class="history-item">
                            <div class="history-date">${formattedDate}</div>
                            <div class="history-content">
                                <div class="history-title">Змінено статус замовлення</div>
                                <div class="history-details">
                                    <span class="status-badge ${getStatusClass(item.previous_status)}">${item.previous_status}</span>
                                    <i class="fas fa-long-arrow-alt-right"></i>
                                    <span class="status-badge ${getStatusClass(item.new_status)}">${item.new_status}</span>
                                </div>
                                ${item.comment ? `<div class="history-comment">${item.comment}</div>` : ''}
                                <div class="history-author">
                                    <i class="fas fa-user"></i> ${item.user_name || 'Система'}
                                </div>
                            </div>
                        </div>
                    `;
                    });
                    historyContent += '</div>';
                } else {
                    historyContent = `
                    <div class="empty-state">
                        <i class="fas fa-history"></i>
                        <p>Історія змін відсутня</p>
                    </div>
                `;
                }
                $('#order-history-content').html(historyContent);

                // Оновлюємо статус кнопок відповідно до статусу замовлення
                updateOrderActionButtons(order);

                // Приховуємо спіннер завантаження
                $('.loading-spinner').hide();
            }
        })
        .catch(error => {
            console.error('Error:', error);
            $('.loading-spinner').hide();
        });
}

// Функція для оновлення кнопок дій відповідно до статусу замовлення
function updateOrderActionButtons(order) {
    const $editBtn = $('#edit-order-btn');
    const $cancelBtn = $('#cancel-order-btn');
    const $commentForm = $('.comment-form');

    // Приховуємо всі кнопки спочатку
    $editBtn.hide();
    $cancelBtn.hide();

    // Перевіряємо, чи можна редагувати замовлення
    if (['Новий', 'Очікування', 'Підтверджено'].includes(order.status)) {
        $editBtn.show();
    }

    // Перевіряємо, чи можна скасувати замовлення
    if (['Новий', 'Очікування', 'Підтверджено', 'В роботі'].includes(order.status)) {
        $cancelBtn.show();
    }

    // Перевіряємо, чи можна додавати коментарі
    if (['Завершено', 'Скасовано'].includes(order.status)) {
        $commentForm.hide();
    } else {
        $commentForm.show();
    }
}

// Функція для отримання класу статусу
function getStatusClass(status) {
    if (!status) return 'status-default';

    status = status.toLowerCase();

    if (status.includes('нов')) {
        return 'status-new';
    } else if (status.includes('робот') || status.includes('в роботі')) {
        return 'status-in-progress';
    } else if (status.includes('очіку')) {
        return 'status-pending';
    } else if (status.includes('заверш') || status.includes('готов') || status.includes('викон')) {
        return 'status-completed';
    } else if (status.includes('скасова') || status.includes('відмін')) {
        return 'status-cancelled';
    }

    return 'status-default';
}
// Додаємо глобальні слухачі подій, коли DOM повністю завантажений
$(document).ready(function() {
    // Обробка кліку по кнопці перегляду замовлення
    $(document).on('click', '.view-order-btn', function() {
        const orderId = $(this).data('id');
        openViewOrderModal(orderId);
    });

    // Обробка кліку по кнопці редагування замовлення
    $(document).on('click', '.edit-order-btn, #edit-order-btn', function() {
        const orderId = $(this).data('id') || $('#view-order-id').text();
        openEditOrderModal(orderId);
    });

    // Обробка кліку по кнопці скасування замовлення
    $(document).on('click', '.cancel-order-btn, #cancel-order-btn', function() {
        const orderId = $(this).data('id') || $('#view-order-id').text();
        openCancelOrderModal(orderId);
    });

    // Обробка кліку по вкладках модального вікна замовлення
    $('#order-tabs .tab-btn').click(function() {
        const tabId = $(this).data('tab');

        // Переключаємо активну вкладку
        $('#order-tabs .tab-btn').removeClass('active');
        $(this).addClass('active');

        // Показуємо відповідний контент
        $('#order-tabs .tab-pane').removeClass('active');
        $(`#tab-${tabId}`).addClass('active');
    });

    // Обробка відправлення коментаря
    $('#send-comment').click(function() {
        const orderId = $('#view-order-id').text();
        const commentText = $('#comment-text').val().trim();

        if (!commentText) {
            showNotification('error', 'Коментар не може бути порожнім');
            return;
        }

        sendComment(orderId, commentText);
    });

    // Обробка видалення коментаря
    $(document).on('click', '.delete-comment-btn', function() {
        const commentId = $(this).data('id');
        if (confirm('Ви впевнені, що хочете видалити цей коментар?')) {
            deleteComment(commentId);
        }
    });

    // Обробка збереження змін замовлення
    $('#save-order-btn').click(function() {
        saveOrder();
    });

    // Обробка підтвердження скасування замовлення
    $('#confirm-cancel-btn').click(function() {
        confirmCancelOrder();
    });

    // Закриття модального вікна за кнопкою "Закрити" або "Скасувати"
    $(document).on('click', '[data-dismiss="modal"]', function() {
        const modalId = $(this).closest('.modal').attr('id');
        closeModal(modalId);
    });
});

// Функція відкриття модального вікна перегляду замовлення
function openViewOrderModal(orderId) {
    // Показуємо модальне вікно
    $('#view-order-modal').addClass('active');
    $('#view-order-id').text(orderId);

    // Скидаємо вміст вкладок
    $('#order-info-content, #order-files-content, #order-comments-content, #order-history-content').empty();
    $('.loading-spinner').show();

    // Переходимо на першу вкладку
    $('#order-tabs .tab-btn').removeClass('active');
    $('#order-tabs .tab-btn[data-tab="info"]').addClass('active');
    $('#order-tabs .tab-pane').removeClass('active');
    $('#tab-info').addClass('active');

    // Завантажуємо дані замовлення
    loadOrderDetails(orderId);
    loadOrderComments(orderId);

    // Очищаємо поле коментаря
    $('#comment-text').val('');
}

// Функція відкриття модального вікна редагування замовлення
function openEditOrderModal(orderId) {
    // Показуємо модальне вікно
    $('#edit-order-modal').addClass('active');
    $('#edit-order-id').text(orderId);
    $('#edit-order-id-input').val(orderId);

    // Завантажуємо дані замовлення для редагування
    loadOrderForEditing(orderId);

    // Закриваємо вікно перегляду, якщо воно відкрите
    closeModal('view-order-modal');
}

// Функція відкриття модального вікна скасування замовлення
function openCancelOrderModal(orderId) {
    // Показуємо модальне вікно
    $('#cancel-order-modal').addClass('active');
    $('#cancel-order-id').text(orderId);

    // Очищаємо поле причини скасування
    $('#cancel-reason').val('');

    // Закриваємо вікно перегляду, якщо воно відкрите
    closeModal('view-order-modal');
}

// Функція закриття модального вікна
function closeModal(modalId) {
    $(`#${modalId}`).removeClass('active');
}

// Функція завантаження даних замовлення
function loadOrderDetails(orderId) {
    // Показуємо спіннер завантаження
    $('.loading-spinner').show();

    fetch(`dashboard.php?get_order_details=${orderId}`, {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const order = data.order;

                // Заповнюємо інформацію про замовлення
                let infoContent = `
                <div class="order-info">
                    <div class="order-header">
                        <div class="order-date">Створено: ${formatDateTime(order.created_at)}</div>
                        <div class="order-status">
                            <span class="status-badge ${getStatusClass(order.status)}">${order.status}</span>
                        </div>
                    </div>
                    
                    <div class="order-details-grid">
                        <div class="detail-group">
                            <span class="detail-label">Тип пристрою</span>
                            <span class="detail-value">${order.device_type}</span>
                        </div>
                        
                        <div class="detail-group">
                            <span class="detail-label">Послуга</span>
                            <span class="detail-value">${order.service}</span>
                        </div>
                        
                        <div class="detail-group full-width">
                            <span class="detail-label">Опис проблеми</span>
                            <span class="detail-value">${order.details}</span>
                        </div>
                        
                        <div class="detail-group">
                            <span class="detail-label">Телефон</span>
                            <span class="detail-value">${order.phone}</span>
                        </div>
                        
                        ${order.address ? `
                        <div class="detail-group">
                            <span class="detail-label">Адреса</span>
                            <span class="detail-value">${order.address}</span>
                        </div>
                        ` : ''}
                        
                        ${order.delivery_method ? `
                        <div class="detail-group">
                            <span class="detail-label">Спосіб доставки</span>
                            <span class="detail-value">${getDeliveryMethodName(order.delivery_method)}</span>
                        </div>
                        ` : ''}
                        
                        ${order.estimated_completion_date ? `
                        <div class="detail-group">
                            <span class="detail-label">Очікувана дата завершення</span>
                            <span class="detail-value">${formatDate(order.estimated_completion_date)}</span>
                        </div>
                        ` : ''}
                        
                        ${order.price ? `
                        <div class="detail-group">
                            <span class="detail-label">Вартість</span>
                            <span class="detail-value">${formatPrice(order.price)}</span>
                        </div>
                        ` : ''}
                        
                        ${order.user_comment ? `
                        <div class="detail-group full-width">
                            <span class="detail-label">Коментар клієнта</span>
                            <span class="detail-value">${order.user_comment}</span>
                        </div>
                        ` : ''}
                    </div>
                </div>
            `;

                $('#order-info-content').html(infoContent);

                // Заповнюємо вкладку з файлами
                let filesContent = '';
                if (order.files && order.files.length > 0) {
                    filesContent = '<div class="files-grid">';
                    order.files.forEach(file => {
                        let filePreview = '';
                        if (file.file_type === 'image' || (file.file_mime && file.file_mime.startsWith('image/'))) {
                            filePreview = `<img src="${file.file_path}" alt="${file.original_name}">`;
                        } else {
                            filePreview = `<i class="fas fa-file"></i>`;
                        }

                        filesContent += `
                        <div class="file-item" data-id="${file.id}">
                            <div class="file-thumbnail">
                                ${filePreview}
                            </div>
                            <div class="file-info">
                                <div class="file-name">${file.original_name}</div>
                                <div class="file-size">${formatFileSize(file.file_size)}</div>
                            </div>
                            <div class="file-actions">
                                <a href="${file.file_path}" download="${file.original_name}" class="btn btn-sm btn-primary">
                                    <i class="fas fa-download"></i> Завантажити
                                </a>
                            </div>
                        </div>
                    `;
                    });
                    filesContent += '</div>';
                } else {
                    filesContent = `
                    <div class="empty-state">
                        <i class="fas fa-file-upload"></i>
                        <p>Немає прикріплених файлів</p>
                    </div>
                `;
                }
                $('#order-files-content').html(filesContent);

                // Оновлюємо статус кнопок відповідно до статусу замовлення
                updateOrderActionButtons(order);

                // Приховуємо спіннер завантаження
                $('.loading-spinner').hide();
            }
        })
        .catch(error => {
            console.error('Error:', error);
            $('.loading-spinner').hide();
            $('#order-info-content').html('<div class="alert alert-danger">Помилка завантаження даних замовлення</div>');
        });
}

// Функція для завантаження коментарів та історії замовлення
function loadOrderComments(orderId) {
    // Показуємо спіннер завантаження
    $('.loading-spinner').show();

    fetch(`dashboard.php?get_order_details=${orderId}`, {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const order = data.order;

                // Заповнюємо вкладку з коментарями
                let commentsContent = '';
                if (order.comments && order.comments.length > 0) {
                    commentsContent = '<div class="comments-list">';
                    order.comments.forEach(comment => {
                        const isAdmin = comment.is_admin == 1 || comment.author_role === 'admin';
                        const commentDate = new Date(comment.created_at);
                        const formattedDate = commentDate.toLocaleDateString() + ' ' + commentDate.toLocaleTimeString();

                        commentsContent += `
                        <div class="comment${isAdmin ? ' admin-comment' : ''}" data-id="${comment.id}">
                            <div class="comment-header">
                                <div class="comment-author">${comment.author_display_name || comment.author_name || 'Користувач'}</div>
                                <div class="comment-date">${formattedDate}</div>
                            </div>
                            <div class="comment-text">${comment.comment}</div>
                            ${!isAdmin && !['Завершено', 'Скасовано'].includes(order.status) ?
                            `<div class="comment-actions">
                                    <button class="btn btn-danger btn-sm delete-comment-btn" data-id="${comment.id}">
                                        <i class="fas fa-trash"></i> Видалити
                                    </button>
                                </div>` : ''
                        }
                        </div>
                    `;
                    });
                    commentsContent += '</div>';
                } else {
                    commentsContent = `
                    <div class="empty-state">
                        <i class="fas fa-comments"></i>
                        <p>Немає коментарів</p>
                    </div>
                `;
                }
                $('#order-comments-content').html(commentsContent);

                // Заповнюємо вкладку з історією змін
                let historyContent = '';
                if (order.status_history && order.status_history.length > 0) {
                    historyContent = '<div class="history-timeline">';
                    order.status_history.forEach(item => {
                        const historyDate = new Date(item.changed_at);
                        const formattedDate = historyDate.toLocaleDateString() + ' ' + historyDate.toLocaleTimeString();

                        historyContent += `
                        <div class="history-item">
                            <div class="history-date">${formattedDate}</div>
                            <div class="history-content">
                                <div class="history-title">Змінено статус замовлення</div>
                                <div class="history-details">
                                    <span class="status-badge ${getStatusClass(item.previous_status)}">${item.previous_status}</span>
                                    <i class="fas fa-long-arrow-alt-right"></i>
                                    <span class="status-badge ${getStatusClass(item.new_status)}">${item.new_status}</span>
                                </div>
                                ${item.comment ? `<div class="history-comment">${item.comment}</div>` : ''}
                                <div class="history-author">
                                    <i class="fas fa-user"></i> ${item.user_name || 'Система'}
                                </div>
                            </div>
                        </div>
                    `;
                    });
                    historyContent += '</div>';
                } else {
                    historyContent = `
                    <div class="empty-state">
                        <i class="fas fa-history"></i>
                        <p>Історія змін відсутня</p>
                    </div>
                `;
                }
                $('#order-history-content').html(historyContent);

                // Якщо замовлення завершене або скасоване, приховуємо форму коментарів
                if (['Завершено', 'Скасовано'].includes(order.status)) {
                    $('.comment-form').hide();
                } else {
                    $('.comment-form').show();
                }

                // Приховуємо спіннер завантаження
                $('.loading-spinner').hide();
            }
        })
        .catch(error => {
            console.error('Error:', error);
            $('.loading-spinner').hide();
            $('#order-comments-content').html('<div class="alert alert-danger">Помилка завантаження коментарів</div>');
            $('#order-history-content').html('<div class="alert alert-danger">Помилка завантаження історії</div>');
        });
}

// Функція для оновлення кнопок дій відповідно до статусу замовлення
function updateOrderActionButtons(order) {
    const $editBtn = $('#edit-order-btn');
    const $cancelBtn = $('#cancel-order-btn');

    // Приховуємо всі кнопки спочатку
    $editBtn.hide();
    $cancelBtn.hide();

    // Перевіряємо, чи можна редагувати замовлення
    const editableStatuses = ['Новий', 'Очікування', 'Підтверджено'];
    if (editableStatuses.includes(order.status)) {
        $editBtn.show();
    }

    // Перевіряємо, чи можна скасувати замовлення
    const cancellableStatuses = ['Новий', 'Очікування', 'Підтверджено', 'В роботі'];
    if (cancellableStatuses.includes(order.status)) {
        $cancelBtn.show();
    }
}

// Функція для завантаження даних замовлення для редагування
function loadOrderForEditing(orderId) {
    fetch(`dashboard.php?get_order_details=${orderId}`, {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const order = data.order;

                // Заповнюємо форму даними
                $('#edit-device-type').val(order.device_type);
                $('#edit-details').val(order.details);
                $('#edit-phone').val(order.phone);
                $('#edit-address').val(order.address || '');
                $('#edit-delivery').val(order.delivery_method || '');
                $('#edit-comment').val(order.user_comment || '');

                // Заповнюємо список файлів
                let filesListContent = '';
                if (order.files && order.files.length > 0) {
                    filesListContent = '<div class="files-grid edit-files-grid">';
                    order.files.forEach(file => {
                        let filePreview = '';
                        if (file.file_type === 'image' || (file.file_mime && file.file_mime.startsWith('image/'))) {
                            filePreview = `<img src="${file.file_path}" alt="${file.original_name}">`;
                        } else {
                            filePreview = `<i class="fas fa-file"></i>`;
                        }

                        filesListContent += `
                        <div class="file-item" data-id="${file.id}">
                            <div class="file-thumbnail">
                                ${filePreview}
                            </div>
                            <div class="file-info">
                                <div class="file-name">${file.original_name}</div>
                                <div class="file-size">${formatFileSize(file.file_size)}</div>
                            </div>
                            <div class="file-actions">
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" id="remove-file-${file.id}" name="remove_files[]" value="${file.id}">
                                    <label class="form-check-label" for="remove-file-${file.id}">Видалити</label>
                                </div>
                            </div>
                        </div>
                    `;
                    });
                    filesListContent += '</div>';
                } else {
                    filesListContent = `
                    <p>Немає прикріплених файлів</p>
                `;
                }
                $('#edit-files-list').html(filesListContent);

                // Очищаємо попереднє перегляд нових файлів
                $('#edit-files-preview').empty();
                $('#edit-new-files').val('');
            } else {
                showNotification('error', data.message || 'Помилка завантаження даних замовлення');
                closeModal('edit-order-modal');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('error', 'Помилка завантаження даних замовлення');
            closeModal('edit-order-modal');
        });
}

// Функція для відправлення коментаря
function sendComment(orderId, commentText) {
    fetch('dashboard.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
            'add_comment': '1',
            'order_id': orderId,
            'comment': commentText,
            'csrf_token': config.csrfToken
        })
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification('success', 'Коментар успішно додано');
                $('#comment-text').val('');

                // Оновлюємо список коментарів
                loadOrderComments(orderId);
            } else {
                showNotification('error', data.message || 'Помилка при додаванні коментаря');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('error', 'Помилка при додаванні коментаря');
        });
}

// Функція для видалення коментаря
function deleteComment(commentId) {
    fetch('dashboard.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
            'delete_comment': '1',
            'comment_id': commentId,
            'csrf_token': config.csrfToken
        })
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification('success', 'Коментар успішно видалено');

                // Оновлюємо список коментарів
                const orderId = $('#view-order-id').text();
                loadOrderComments(orderId);
            } else {
                showNotification('error', data.message || 'Помилка при видаленні коментаря');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('error', 'Помилка при видаленні коментаря');
        });
}

// Функція для збереження змін замовлення
function saveOrder() {
    const form = document.getElementById('edit-order-form');
    const formData = new FormData(form);
    formData.append('update_order', '1');

    fetch('dashboard.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification('success', 'Замовлення успішно оновлено');
                closeModal('edit-order-modal');

                // Оновлюємо дані в модальному вікні перегляду, якщо воно відкрите
                const orderId = formData.get('order_id');
                if ($('#view-order-modal').hasClass('active')) {
                    loadOrderDetails(orderId);
                    loadOrderComments(orderId);
                } else {
                    // Якщо модальне вікно перегляду не відкрите, перезавантажуємо сторінку
                    setTimeout(() => {
                        location.reload();
                    }, 1000);
                }
            } else {
                showNotification('error', data.message || 'Помилка при оновленні замовлення');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('error', 'Помилка при оновленні замовлення');
        });
}

// Функція для підтвердження скасування замовлення
function confirmCancelOrder() {
    const orderId = $('#cancel-order-id').text();
    const reason = $('#cancel-reason').val();

    fetch('dashboard.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
            'cancel_order': '1',
            'order_id': orderId,
            'reason': reason,
            'csrf_token': config.csrfToken
        })
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification('success', 'Замовлення успішно скасовано');
                closeModal('cancel-order-modal');

                // Оновлюємо дані в модальному вікні перегляду, якщо воно відкрите
                if ($('#view-order-modal').hasClass('active')) {
                    loadOrderDetails(orderId);
                    loadOrderComments(orderId);
                } else {
                    // Якщо модальне вікно перегляду не відкрите, перезавантажуємо сторінку
                    setTimeout(() => {
                        location.reload();
                    }, 1000);
                }
            } else {
                showNotification('error', data.message || 'Помилка при скасуванні замовлення');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('error', 'Помилка при скасуванні замовлення');
        });
}

// Допоміжні функції форматування
function formatDateTime(dateTimeStr) {
    if (!dateTimeStr) return '';
    const date = new Date(dateTimeStr);
    return date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
}

function formatDate(dateStr) {
    if (!dateStr) return '';
    const date = new Date(dateStr);
    return date.toLocaleDateString();
}

function formatFileSize(bytes) {
    if (!bytes || bytes === 0) return '0 Б';
    const k = 1024;
    const sizes = ['Б', 'КБ', 'МБ', 'ГБ'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

function formatPrice(price) {
    if (!price) return '0,00 грн';
    return new Intl.NumberFormat('uk-UA', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }).format(price) + ' грн';
}

function getDeliveryMethodName(method) {
    if (!method) return '';

    switch (method) {
        case 'self': return 'Самовивіз';
        case 'courier': return 'Кур\'єр';
        case 'nova-poshta': return 'Нова Пошта';
        case 'ukrposhta': return 'Укрпошта';
        default: return method;
    }
}

function getStatusClass(status) {
    if (!status) return 'status-default';

    const statusLower = status.toLowerCase();

    if (statusLower.includes('нов')) {
        return 'status-new';
    } else if (statusLower.includes('робот') || statusLower.includes('в роботі')) {
        return 'status-in-progress';
    } else if (statusLower.includes('очіку')) {
        return 'status-pending';
    } else if (statusLower.includes('заверш') || statusLower.includes('готов') || statusLower.includes('викон')) {
        return 'status-completed';
    } else if (statusLower.includes('скасова') || statusLower.includes('відмін')) {
        return 'status-cancelled';
    }

    return 'status-default';
}

// Функція для відображення сповіщень
function showNotification(type, message, duration = 3000) {
    const container = document.getElementById('notification-container') || createNotificationContainer();

    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;

    let icon = '';
    switch (type) {
        case 'success':
            icon = '<i class="fas fa-check-circle"></i>';
            break;
        case 'error':
            icon = '<i class="fas fa-exclamation-circle"></i>';
            break;
        case 'info':
            icon = '<i class="fas fa-info-circle"></i>';
            break;
        case 'warning':
            icon = '<i class="fas fa-exclamation-triangle"></i>';
            break;
    }

    notification.innerHTML = `
        ${icon}
        <span class="notification-message">${message}</span>
        <button class="notification-close">&times;</button>
    `;

    container.appendChild(notification);

    // Додати клас для анімації появи
    setTimeout(() => {
        notification.classList.add('show');
    }, 10);

    // Додати обробник події для кнопки закриття
    const closeBtn = notification.querySelector('.notification-close');
    closeBtn.addEventListener('click', () => {
        closeNotification(notification);
    });

    // Автоматичне закриття після вказаного часу
    setTimeout(() => {
        closeNotification(notification);
    }, duration);
}

// Створити контейнер для сповіщень, якщо він відсутній
function createNotificationContainer() {
    const container = document.createElement('div');
    container.id = 'notification-container';
    document.body.appendChild(container);
    return container;
}

// Закрити сповіщення з анімацією
function closeNotification(notification) {
    notification.classList.remove('show');
    notification.classList.add('hide');

    setTimeout(() => {
        notification.remove();
    }, 300); // час анімації зникнення
}