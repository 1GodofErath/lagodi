/**
 * Модуль для роботи зі сповіщеннями
 */
document.addEventListener('DOMContentLoaded', function() {
    initNotifications();
});

/**
 * Ініціалізація функцій сповіщень
 */
function initNotifications() {
    const notificationsItems = document.querySelectorAll('.notification-item');
    const markAllReadButton = document.getElementById('mark-all-read');

    // Оновлення сповіщень кожні 60 секунд
    const notificationUpdateInterval = setInterval(fetchNotifications, 60000);

    // Обробка кліку по окремому сповіщенню
    notificationsItems.forEach(function(item) {
        item.addEventListener('click', function() {
            const notificationId = this.getAttribute('data-id');
            const orderId = this.getAttribute('data-order-id');

            if (notificationId) {
                markNotificationAsRead(notificationId);
            }

            if (orderId) {
                navigateToOrder(orderId);
            }
        });
    });

    // Обробка кнопки "Позначити всі як прочитані"
    if (markAllReadButton) {
        markAllReadButton.addEventListener('click', function() {
            markAllNotificationsAsRead();
        });
    }

    // Обробники кнопок "Позначити як прочитане" в списку сповіщень
    document.querySelectorAll('.mark-read-btn').forEach(function(button) {
        button.addEventListener('click', function(event) {
            event.stopPropagation();

            const notificationItem = this.closest('.notification-card');
            const notificationId = notificationItem.getAttribute('data-id');

            if (notificationId) {
                markNotificationAsRead(notificationId, function() {
                    notificationItem.classList.remove('unread');
                    button.style.display = 'none';
                });
            }
        });
    });

    // Обробники кнопок "Перейти до замовлення" в списку сповіщень
    document.querySelectorAll('.view-order-btn').forEach(function(button) {
        button.addEventListener('click', function(event) {
            event.stopPropagation();

            const notificationItem = this.closest('.notification-card');
            const orderId = notificationItem.getAttribute('data-order-id');

            if (orderId) {
                navigateToOrder(orderId);
            }
        });
    });
}

/**
 * Отримання сповіщень з сервера
 */
function fetchNotifications() {
    fetch('dashboard.php?update_notifications=1&_=' + Date.now())
        .then(response => {
            if (!response.ok) {
                throw new Error('Помилка з\'єднання');
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                updateNotificationCount(data.total_count);
                updateNotificationsList(data.notifications);
            }
        })
        .catch(error => {
            console.error('Помилка отримання сповіщень:', error);
        });
}

/**
 * Оновлення лічильника непрочитаних сповіщень
 * @param {number} count - Кількість непрочитаних сповіщень
 */
function updateNotificationCount(count) {
    const badges = document.querySelectorAll('.badge');

    badges.forEach(function(badge) {
        if (count > 0) {
            badge.textContent = count;
            badge.style.display = 'flex';
        } else {
            badge.style.display = 'none';
        }
    });

    // Оновлення іконки в шапці
    const iconBadge = document.querySelector('.notifications-toggle .icon-badge');
    if (iconBadge) {
        if (count > 0) {
            iconBadge.textContent = count > 9 ? '9+' : count;
            iconBadge.style.display = 'flex';
        } else {
            iconBadge.style.display = 'none';
        }
    }
}

/**
 * Оновлення списку сповіщень
 * @param {Array} notifications - Масив сповіщень
 */
function updateNotificationsList(notifications) {
    const notificationsDropdown = document.getElementById('notifications-list');
    const allNotificationsTab = document.getElementById('notifications-content');

    if (notificationsDropdown) {
        if (notifications.length > 0) {
            let dropdownHtml = '';

            notifications.slice(0, 5).forEach(function(notification) {
                dropdownHtml += createNotificationItemHtml(notification);
            });

            notificationsDropdown.innerHTML = dropdownHtml;
        } else {
            notificationsDropdown.innerHTML = `
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <p>Немає нових сповіщень</p>
                </div>
            `;
        }
    }

    if (allNotificationsTab) {
        const notificationsWrapper = allNotificationsTab.querySelector('.notifications-wrapper');

        if (notificationsWrapper) {
            if (notifications.length > 0) {
                let tabHtml = '';

                notifications.forEach(function(notification) {
                    tabHtml += createNotificationCardHtml(notification);
                });

                notificationsWrapper.innerHTML = tabHtml;
            } else {
                notificationsWrapper.innerHTML = `
                    <div class="empty-state-container">
                        <div class="empty-state-icon">
                            <i class="fas fa-bell-slash"></i>
                        </div>
                        <h3>Немає нових сповіщень</h3>
                        <p>У вас немає непрочитаних сповіщень. Нові сповіщення з'являться тут, коли будуть доступні.</p>
                    </div>
                `;
            }
        }
    }
}

/**
 * Створення HTML для елемента сповіщення у випадаючому меню
 * @param {Object} notification - Об'єкт сповіщення
 * @returns {string} HTML-код елемента сповіщення
 */
function createNotificationItemHtml(notification) {
    const icon = getNotificationIcon(notification.type);
    const title = escapeHtml(notification.title);
    const message = escapeHtml(notification.description || notification.content || '');
    const time = formatTimeAgo(notification.created_at);
    const service = notification.service ? escapeHtml(notification.service) : '';

    return `
        <div class="notification-item" data-id="${notification.id}" data-order-id="${notification.order_id}">
            <div class="notification-icon">
                <i class="${icon}"></i>
            </div>
            <div class="notification-content">
                <div class="notification-title">${title}</div>
                <div class="notification-message">${message}</div>
                <div class="notification-meta">
                    <span class="notification-time">${time}</span>
                    ${service ? `<span class="notification-service">${service}</span>` : ''}
                </div>
            </div>
        </div>
    `;
}

/**
 * Створення HTML для картки сповіщення на вкладці Сповіщення
 * @param {Object} notification - Об'єкт сповіщення
 * @returns {string} HTML-код картки сповіщення
 */
function createNotificationCardHtml(notification) {
    const icon = getNotificationIcon(notification.type);
    const title = escapeHtml(notification.title);
    const message = escapeHtml(notification.description || notification.content || '');
    const time = formatTimeAgo(notification.created_at);
    const service = notification.service ? escapeHtml(notification.service) : '';

    return `
        <div class="notification-card unread" data-id="${notification.id}" data-order-id="${notification.order_id}">
            <div class="notification-card-icon">
                <div class="notification-card-icon-inner">
                    <i class="${icon}"></i>
                </div>
            </div>
            <div class="notification-card-content">
                <div class="notification-card-header">
                    <h3 class="notification-card-title">${title}</h3>
                    <span class="notification-card-time">${time}</span>
                </div>
                <div class="notification-card-body">${message}</div>
                <div class="notification-card-meta">
                    ${service ? `<span class="notification-card-service">${service}</span>` : ''}
                    <div class="notification-card-actions">
                        <button class="mark-read-btn">
                            <i class="fas fa-check"></i> Позначити як прочитане
                        </button>
                        ${notification.order_id ? `
                            <a href="#orders" class="view-order-btn" data-order-id="${notification.order_id}">
                                <i class="fas fa-eye"></i> Перейти до замовлення
                            </a>
                        ` : ''}
                    </div>
                </div>
            </div>
        </div>
    `;
}

/**
 * Отримання CSS класу іконки для типу сповіщення
 * @param {string} type - Тип сповіщення
 * @returns {string} CSS клас для іконки
 */
function getNotificationIcon(type) {
    switch (type) {
        case 'status_update':
            return 'fas fa-sync';
        case 'comment':
            return 'fas fa-comment-alt';
        case 'admin_message':
            return 'fas fa-envelope';
        case 'system':
            return 'fas fa-cog';
        default:
            return 'fas fa-bell';
    }
}

/**
 * Позначення сповіщення як прочитане
 * @param {number} notificationId - ID сповіщення
 * @param {function} callback - Функція зворотного виклику після успішної операції
 */
function markNotificationAsRead(notificationId, callback) {
    const formData = new FormData();
    formData.append('mark_notification_read', '1');
    formData.append('notification_id', notificationId);
    formData.append('csrf_token', getCsrfToken());

    fetch('dashboard.php', {
        method: 'POST',
        body: formData
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Оновлення лічильника
                updateNotificationCount(data.total_count);

                if (typeof callback === 'function') {
                    callback();
                }
            }
        })
        .catch(error => {
            console.error('Помилка при позначенні сповіщення як прочитане:', error);
            showToast('Не вдалося позначити сповіщення як прочитане', 'error');
        });
}

/**
 * Позначення всіх сповіщень як прочитані
 */
function markAllNotificationsAsRead() {
    const formData = new FormData();
    formData.append('mark_all_notifications_read', '1');
    formData.append('csrf_token', getCsrfToken());

    fetch('dashboard.php', {
        method: 'POST',
        body: formData
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Оновлення лічильника
                updateNotificationCount(0);

                // Оновлення списків сповіщень
                const notificationsDropdown = document.getElementById('notifications-list');
                if (notificationsDropdown) {
                    notificationsDropdown.innerHTML = `
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <p>Немає нових сповіщень</p>
                    </div>
                `;
                }

                const notificationsTab = document.getElementById('notifications-content');
                if (notificationsTab) {
                    const notificationsWrapper = notificationsTab.querySelector('.notifications-wrapper');
                    if (notificationsWrapper) {
                        notificationsWrapper.innerHTML = `
                        <div class="empty-state-container">
                            <div class="empty-state-icon">
                                <i class="fas fa-bell-slash"></i>
                            </div>
                            <h3>Немає нових сповіщень</h3>
                            <p>У вас немає непрочитаних сповіщень. Нові сповіщення з'являться тут, коли будуть доступні.</p>
                        </div>
                    `;
                    }
                }

                // Приховування кнопки "Позначити всі прочитаними"
                const markAllReadButton = document.getElementById('mark-all-read');
                if (markAllReadButton) {
                    markAllReadButton.style.display = 'none';
                }

                showToast('Всі сповіщення позначено як прочитані', 'success');
            }
        })
        .catch(error => {
            console.error('Помилка при позначенні всіх сповіщень як прочитані:', error);
            showToast('Не вдалося позначити сповіщення як прочитані', 'error');
        });
}

/**
 * Перехід до замовлення за ID
 * @param {number} orderId - ID замовлення
 */
function navigateToOrder(orderId) {
    // Спочатку переходимо на вкладку замовлень
    window.location.hash = 'orders';

    // Потім через невелику затримку скролимо до потрібного замовлення
    setTimeout(() => {
        const orderCard = document.querySelector(`.order-card[data-order-id="${orderId}"]`);
        if (orderCard) {
            orderCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
            orderCard.classList.add('highlight');

            setTimeout(() => {
                orderCard.classList.remove('highlight');
            }, 2000);
        } else {
            showToast('Замовлення не знайдено', 'warning');
        }
    }, 300);
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
