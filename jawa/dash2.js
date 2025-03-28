// Firebase конфігурація
const firebaseConfig = {
    apiKey: "AIzaSyDX9x5R-8EaVP4uFbJOVzxbFc-A2JQzBWI",
    authDomain: "lagodi-notifications.firebaseapp.com",
    projectId: "lagodi-notifications",
    messagingSenderId: "781209015230",
    appId: "1:781209015230:web:f7c43ebfc0f8d5e7682e7c"
};

// Ініціалізація Firebase
let messaging;
try {
    firebase.initializeApp(firebaseConfig);
    messaging = firebase.messaging();
} catch (error) {
    console.log('Firebase не ініціалізовано:', error);
}

// Оновлення локального часу
function updateLocalTimes() {
    document.getElementById('current-time').textContent = new Date().toLocaleString('uk-UA');

    const utcTimeElements = document.querySelectorAll('.local-time');
    utcTimeElements.forEach(element => {
        const utcTime = element.getAttribute('data-utc');
        if (utcTime) {
            const localTime = new Date(utcTime).toLocaleString('uk-UA');
            element.textContent = localTime;
        }
    });
}

// Функція для форматування розміру файлу
function formatFileSize(bytes) {
    if (bytes < 1024) return bytes + ' байт';
    else if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' КБ';
    else return (bytes / 1048576).toFixed(1) + ' МБ';
}

// Ініціалізація сповіщень
function initNotifications() {
    const notificationsToggle = document.getElementById('notifications-toggle');
    const notificationsContainer = document.getElementById('notifications-container');
    const mobileNotificationsBtn = document.getElementById('mobile-notifications-btn');
    const markAllRead = document.getElementById('mark-all-read');
    const markAllNotificationsBtn = document.getElementById('mark-all-notifications');

    // Відкриття/закриття панелі сповіщень
    function toggleNotifications() {
        if (notificationsContainer.style.display === 'block') {
            notificationsContainer.style.display = 'none';
        } else {
            notificationsContainer.style.display = 'block';

            // Перевіряємо наявність нових сповіщень при відкритті
            fetchLatestNotifications();
        }
    }

    // Додавання обробників подій
    if (notificationsToggle) {
        notificationsToggle.addEventListener('click', toggleNotifications);
    }

    if (mobileNotificationsBtn) {
        mobileNotificationsBtn.addEventListener('click', toggleNotifications);
    }

    // Закриття панелі при кліці поза нею
    document.addEventListener('click', function(event) {
        const isClickInside = notificationsContainer.contains(event.target) ||
            notificationsToggle.contains(event.target) ||
            (mobileNotificationsBtn && mobileNotificationsBtn.contains(event.target));

        if (!isClickInside && notificationsContainer.style.display === 'block') {
            notificationsContainer.style.display = 'none';
        }
    });

    // Обробка кліків по сповіщеннях
    document.querySelectorAll('.notification-item').forEach(item => {
        item.addEventListener('click', function() {
            const orderId = this.getAttribute('data-order-id');
            if (orderId) {
                // Перехід до замовлення та позначення сповіщень як прочитаних
                markOrderNotificationsAsRead(orderId);

                // Активуємо вкладку "Мої замовлення"
                const ordersTab = document.querySelector('.sidebar-menu a[data-tab="orders"]');
                if (ordersTab) ordersTab.click();

                // Скролимо до замовлення
                setTimeout(() => {
                    const orderCard = document.querySelector(`.order-card[data-order-id="${orderId}"]`);
                    if (orderCard) {
                        orderCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        orderCard.classList.add('highlight-animation');
                        setTimeout(() => {
                            orderCard.classList.remove('highlight-animation');
                        }, 2000);
                    }
                }, 300);

                // Закриваємо панель сповіщень, якщо вона була відкрита
                notificationsContainer.style.display = 'none';
            }
        });
    });

    // Позначення всіх сповіщень як прочитаних
    if (markAllRead) {
        markAllRead.addEventListener('click', function(e) {
            e.stopPropagation();
            markAllNotificationsAsRead();
        });
    }

    // Кнопка "Позначити всі як прочитані" на вкладці сповіщень
    if (markAllNotificationsBtn) {
        markAllNotificationsBtn.addEventListener('click', function() {
            markAllNotificationsAsRead();
        });
    }

    // Обробка клікання на картці замовлення з сповіщеннями
    document.querySelectorAll('.order-card.has-notifications').forEach(card => {
        card.addEventListener('click', function(e) {
            // Перевіряємо, що клік не був по кнопках дій
            if (!e.target.closest('.order-actions') && !e.target.closest('.view-more-btn') &&
                !e.target.closest('.delete-comment') && !e.target.closest('.view-file')) {
                const orderId = this.getAttribute('data-order-id');
                if (orderId) {
                    markOrderNotificationsAsRead(orderId);
                }
            }
        });
    });

    // Періодичне оновлення списку сповіщень
    setInterval(fetchLatestNotifications, 60000); // Кожну хвилину
}

// Функція для отримання останніх сповіщень
function fetchLatestNotifications() {
    fetch('dashboard.php?update_notifications=1')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Оновлюємо лічильник сповіщень
                updateNotificationCounters(data.total_count);

                // Оновлюємо список сповіщень, якщо контейнер відкритий
                const notificationsContainer = document.getElementById('notifications-container');
                if (notificationsContainer.style.display === 'block') {
                    updateNotificationsContent(data.notifications);
                }
            }
        })
        .catch(error => {
            console.error('Error fetching notifications:', error);
        });
}

// Функція для оновлення вмісту контейнера сповіщень
function updateNotificationsContent(notifications) {
    const notificationsContent = document.querySelector('.notifications-content');
    if (!notificationsContent) return;

    if (notifications.length === 0) {
        notificationsContent.innerHTML = `
            <div class="empty-notifications">
                <i class="fas fa-check-circle" style="font-size: 2rem; color: var(--success-color); margin-bottom: 10px;"></i>
                <p>Немає нових сповіщень</p>
            </div>
        `;
        return;
    }

    let html = '';
    notifications.forEach(notification => {
        html += `
            <div class="notification-item unread" data-order-id="${notification.order_id}" data-notification-id="${notification.id}">
                <div class="notification-title">${escapeHtml(notification.title)}</div>
                <div class="notification-message">${escapeHtml(notification.description)}</div>
                <div class="notification-service">${escapeHtml(notification.service)}</div>
                <div class="notification-time">
                    ${new Date(notification.created_at).toLocaleString('uk-UA')}
                </div>
            </div>
        `;
    });

    notificationsContent.innerHTML = html;

    // Додаємо обробники подій для нових елементів
    document.querySelectorAll('.notification-item').forEach(item => {
        item.addEventListener('click', function() {
            const orderId = this.getAttribute('data-order-id');
            if (orderId) {
                markOrderNotificationsAsRead(orderId);

                // Активуємо вкладку "Мої замовлення"
                const ordersTab = document.querySelector('.sidebar-menu a[data-tab="orders"]');
                if (ordersTab) ordersTab.click();

                // Скролимо до замовлення
                setTimeout(() => {
                    const orderCard = document.querySelector(`.order-card[data-order-id="${orderId}"]`);
                    if (orderCard) {
                        orderCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        orderCard.classList.add('highlight-animation');
                        setTimeout(() => {
                            orderCard.classList.remove('highlight-animation');
                        }, 2000);
                    }
                }, 300);

                // Закриваємо панель сповіщень
                document.getElementById('notifications-container').style.display = 'none';
            }
        });
    });
}

// Функція для екранування HTML
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Функція для позначення сповіщень замовлення як прочитаних
function markOrderNotificationsAsRead(orderId) {
    const formData = new FormData();
    formData.append('mark_notifications_read', '1');
    formData.append('order_id', orderId);
    formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);

    fetch('dashboard.php', {
        method: 'POST',
        body: formData
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Оновлюємо візуальні індикатори сповіщень
                const orderCards = document.querySelectorAll(`.order-card[data-order-id="${orderId}"]`);
                orderCards.forEach(orderCard => {
                    orderCard.classList.remove('has-notifications');
                    const indicator = orderCard.querySelector('.notification-indicator');
                    if (indicator) indicator.remove();

                    const statusUpdate = orderCard.querySelector('.status-update');
                    if (statusUpdate) statusUpdate.style.display = 'none';
                });

                // Оновлюємо лічильники сповіщень
                fetchLatestNotifications();
            }
        })
        .catch(error => {
            console.error('Error marking notifications as read:', error);
        });
}

// Функція для позначення всіх сповіщень як прочитаних
function markAllNotificationsAsRead() {
    const orderIds = Array.from(document.querySelectorAll('.order-card.has-notifications'))
        .map(card => card.getAttribute('data-order-id'));

    if (orderIds.length === 0) return;

    // Створюємо масив промісів для всіх запитів
    const promises = orderIds.map(orderId => {
        const formData = new FormData();
        formData.append('mark_notifications_read', '1');
        formData.append('order_id', orderId);
        formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);

        return fetch('dashboard.php', {
            method: 'POST',
            body: formData
        }).then(response => response.json());
    });

    // Очікуємо завершення всіх запитів
    Promise.all(promises)
        .then(() => {
            // Оновлюємо всі візуальні індикатори
            document.querySelectorAll('.order-card.has-notifications').forEach(card => {
                card.classList.remove('has-notifications');
                const indicator = card.querySelector('.notification-indicator');
                if (indicator) indicator.remove();

                const statusUpdate = card.querySelector('.status-update');
                if (statusUpdate) statusUpdate.style.display = 'none';
            });

            // Оновлюємо лічильники сповіщень
            updateNotificationCounters(0);

            // Оновлюємо вигляд панелі сповіщень
            const notificationsContent = document.querySelector('.notifications-content');
            if (notificationsContent) {
                notificationsContent.innerHTML = '<div class="empty-notifications"><i class="fas fa-check-circle" style="font-size: 2rem; color: var(--success-color); margin-bottom: 10px;"></i><p>Немає нових сповіщень</p></div>';
            }

            // Оновлюємо вкладку сповіщень
            const notificationsTab = document.querySelector('#notifications-content .notifications-list');
            if (notificationsTab) {
                window.location.reload(); // Перезавантажуємо сторінку для оновлення вкладки сповіщень
            } else {
                // Приховуємо кнопку "Позначити всі як прочитані"
                const markAllReadBtn = document.getElementById('mark-all-read');
                if (markAllReadBtn) markAllReadBtn.style.display = 'none';

                const markAllNotificationsBtn = document.getElementById('mark-all-notifications');
                if (markAllNotificationsBtn) markAllNotificationsBtn.style.display = 'none';
            }

            // Приховуємо панель сповіщень
            const notificationsContainer = document.getElementById('notifications-container');
            if (notificationsContainer) {
                setTimeout(() => {
                    notificationsContainer.style.display = 'none';
                }, 1000);
            }

            // Показуємо повідомлення про успіх
            showTempMessage('Всі сповіщення позначено прочитаними');
        })
        .catch(error => {
            console.error('Error marking all notifications as read:', error);
            showTempMessage('Помилка при обробці сповіщень', false);
        });
}

// Функція для оновлення лічильників сповіщень
function updateNotificationCounters(notificationsCount) {
    // Оновлюємо лічильник на іконці сповіщень
    const countBadges = document.querySelectorAll('.notifications-count');
    countBadges.forEach(badge => {
        if (notificationsCount > 0) {
            badge.textContent = notificationsCount > 9 ? '9+' : notificationsCount;
            badge.style.display = 'flex';
        } else {
            badge.style.display = 'none';
        }
    });

    // Оновлюємо індикатор у меню
    const menuBadges = document.querySelectorAll('.sidebar-menu .notification-badge');
    menuBadges.forEach(badge => {
        if (notificationsCount > 0) {
            badge.textContent = notificationsCount > 9 ? '9+' : notificationsCount;
            badge.style.display = 'flex';
        } else {
            badge.style.display = 'none';
        }
    });

    // Оновлюємо статистику на головній
    const notificationsStats = document.querySelector('.order-stats p:nth-child(3)');
    if (notificationsStats) {
        if (notificationsCount > 0) {
            notificationsStats.textContent = `Непрочитаних сповіщень: ${notificationsCount}`;
            notificationsStats.style.display = 'block';
        } else {
            notificationsStats.style.display = 'none';
        }
    }

    // Оновлюємо кнопку "Позначити всі як прочитані"
    const markAllReadBtn = document.getElementById('mark-all-read');
    if (markAllReadBtn) {
        markAllReadBtn.style.display = notificationsCount > 0 ? 'block' : 'none';
    }

    const markAllNotificationsBtn = document.getElementById('mark-all-notifications');
    if (markAllNotificationsBtn) {
        markAllNotificationsBtn.style.display = notificationsCount > 0 ? 'block' : 'none';
    }
}

// Ініціалізація WebSockets
function initWebSockets() {
    const wsStatus = document.getElementById('websocket-status');
    const wsStatusText = document.getElementById('websocket-status-text');

    // WebSocket-підключення для отримання сповіщень в реальному часі
    const wsProtocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
    const wsHost = window.location.hostname;
    const wsPort = window.location.protocol === 'https:' ? '8443' : '8080';
    const userId = document.querySelector('meta[name="user-id"]')?.getAttribute('content');

    if (!userId) {
        console.error('User ID не знайдено');
        return;
    }

    const ws = new WebSocket(`${wsProtocol}//${wsHost}:${wsPort}/notifications?user_id=${userId}`);

    ws.onopen = function() {
        wsStatus.classList.add('connected');
        wsStatus.classList.remove('disconnected');
        wsStatusText.textContent = 'Підключено';
        wsStatus.style.visibility = 'visible';

        console.log('WebSocket підключення встановлено');
    };

    ws.onclose = function() {
        wsStatus.classList.remove('connected');
        wsStatus.classList.add('disconnected');
        wsStatusText.textContent = 'Відключено';
        wsStatus.style.visibility = 'visible';

        console.log('WebSocket підключення закрито');

        // Спроба перепідключення через 5 секунд
        setTimeout(function() {
            initWebSockets();
        }, 5000);
    };

    ws.onerror = function(error) {
        console.error('WebSocket помилка:', error);
        wsStatus.classList.remove('connected');
        wsStatus.classList.add('disconnected');
        wsStatusText.textContent = 'Помилка підключення';
        wsStatus.style.visibility = 'visible';
    };

    ws.onmessage = function(event) {
        try {
            const data = JSON.parse(event.data);
            console.log('Отримано WebSocket повідомлення:', data);

            if (data.type === 'notification') {
                // Оновлюємо лічильник сповіщень
                fetchLatestNotifications();

                // Показуємо системне сповіщення в браузері
                if (Notification.permission === 'granted' && data.title) {
                    const notification = new Notification(data.title, {
                        body: data.message,
                        icon: '/assets/images/logo.png'
                    });

                    notification.onclick = function() {
                        window.focus();
                        if (data.order_id) {
                            const ordersTab = document.querySelector('.sidebar-menu a[data-tab="orders"]');
                            if (ordersTab) ordersTab.click();

                            setTimeout(() => {
                                const orderCard = document.querySelector(`.order-card[data-order-id="${data.order_id}"]`);
                                if (orderCard) {
                                    orderCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                }
                            }, 300);
                        }
                        notification.close();
                    };
                }

                // Показуємо спливаюче повідомлення на сторінці
                showTempMessage(`${data.title}: ${data.message}`);
            }
        } catch (error) {
            console.error('Помилка обробки WebSocket повідомлення:', error);
        }
    };
}

// Ініціалізація Push-сповіщень
function initPushNotifications() {
    if (!messaging) return;

    const pushToggle = document.getElementById('push-notifications-toggle');
    const permissionStatus = document.getElementById('push-permission-status');
    const permissionNotice = document.getElementById('push-permission-notice');
    const permissionPrompt = document.getElementById('push-permission-prompt');
    const allowBtn = document.getElementById('allow-notifications');
    const denyBtn = document.getElementById('deny-notifications');

    // Перевірка статусу дозволу на сповіщення
    function checkNotificationPermission() {
        if (!('Notification' in window)) {
            if (permissionStatus) {
                permissionStatus.textContent = 'Ваш браузер не підтримує сповіщення';
                permissionStatus.style.color = 'var(--error-color)';
            }
            if (pushToggle) pushToggle.disabled = true;
            return;
        }

        if (Notification.permission === 'granted') {
            if (permissionStatus) {
                permissionStatus.textContent = 'Дозвіл на сповіщення надано';
                permissionStatus.style.color = 'var(--success-color)';
            }
            if (permissionNotice) permissionNotice.style.display = 'none';
            if (permissionPrompt) permissionPrompt.style.display = 'none';

            // Запитуємо FCM токен
            getFirebaseToken();
        } else if (Notification.permission === 'denied') {
            if (permissionStatus) {
                permissionStatus.textContent = 'Ви заборонили сповіщення. Змініть налаштування браузера щоб дозволити їх.';
                permissionStatus.style.color = 'var(--error-color)';
            }
            if (permissionNotice) permissionNotice.style.display = 'block';
            if (pushToggle) pushToggle.checked = false;
        } else {
            if (permissionStatus) {
                permissionStatus.textContent = 'Дозвіл на сповіщення не запитано';
                permissionStatus.style.color = '';
            }
            if (permissionNotice) permissionNotice.style.display = 'block';

            // Показуємо запит на дозвіл сповіщень при першому відвідуванні
            if (permissionPrompt && localStorage.getItem('notification_prompt_shown') !== 'true') {
                permissionPrompt.style.display = 'block';
                localStorage.setItem('notification_prompt_shown', 'true');
            }
        }
    }

    // Запит дозволу на сповіщення
    function requestNotificationPermission() {
        Notification.requestPermission().then(function(permission) {
            if (permission === 'granted') {
                if (permissionStatus) {
                    permissionStatus.textContent = 'Дозвіл на сповіщення надано';
                    permissionStatus.style.color = 'var(--success-color)';
                }
                if (permissionNotice) permissionNotice.style.display = 'none';
                if (permissionPrompt) permissionPrompt.style.display = 'none';

                // Запитуємо FCM токен
                getFirebaseToken();

                // Зберігаємо налаштування
                updatePushNotificationSettings(true);
            } else {
                if (permissionStatus) {
                    permissionStatus.textContent = permission === 'denied'
                        ? 'Ви заборонили сповіщення. Змініть налаштування браузера щоб дозволити їх.'
                        : 'Дозвіл на сповіщення не надано';
                    permissionStatus.style.color = 'var(--error-color)';
                }
                if (pushToggle) pushToggle.checked = false;

                // Зберігаємо налаштування
                updatePushNotificationSettings(false);
            }
        });
    }

    // Отримання Firebase токена
    function getFirebaseToken() {
        messaging.getToken()
            .then(function(token) {
                if (token) {
                    // Зберігаємо токен на сервері
                    registerPushToken(token);
                } else {
                    console.log('Неможливо отримати токен');
                }
            })
            .catch(function(err) {
                console.log('Помилка отримання токена', err);
            });
    }

    // Реєстрація токена на сервері
    function registerPushToken(token) {
        const formData = new FormData();
        formData.append('register_push_token', '1');
        formData.append('token', token);
        formData.append('device_info', navigator.userAgent);
        formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);

        fetch('dashboard.php', {
            method: 'POST',
            body: formData
        })
            .then(response => response.json())
            .then(data => {
                console.log('Токен успішно зареєстровано', data);
            })
            .catch(error => {
                console.error('Помилка реєстрації токена', error);
            });
    }

    // Оновлення налаштувань push-сповіщень
    function updatePushNotificationSettings(enabled) {
        const formData = new FormData();
        formData.append('update_webpush_settings', '1');
        formData.append('enabled', enabled);
        formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);

        fetch('dashboard.php', {
            method: 'POST',
            body: formData
        })
            .then(response => response.json())
            .then(data => {
                console.log('Налаштування push-сповіщень оновлено', data);
            })
            .catch(error => {
                console.error('Помилка оновлення налаштувань push-сповіщень', error);
            });
    }

    // Обробники подій
    if (pushToggle) {
        pushToggle.addEventListener('change', function() {
            if (this.checked) {
                if (Notification.permission !== 'granted') {
                    requestNotificationPermission();
                } else {
                    updatePushNotificationSettings(true);
                }
            } else {
                updatePushNotificationSettings(false);
            }
        });
    }

    if (allowBtn) {
        allowBtn.addEventListener('click', function() {
            requestNotificationPermission();
            permissionPrompt.style.display = 'none';
        });
    }

    if (denyBtn) {
        denyBtn.addEventListener('click', function() {
            permissionPrompt.style.display = 'none';
        });
    }

    // Обробник зміни токена
    messaging.onTokenRefresh(function() {
        messaging.getToken()
            .then(function(refreshedToken) {
                console.log('Токен оновлено');
                registerPushToken(refreshedToken);
            })
            .catch(function(err) {
                console.log('Неможливо отримати оновлений токен ', err);
            });
    });

    // Обробник вхідних повідомлень
    messaging.onMessage(function(payload) {
        console.log('Отримано повідомлення', payload);

        // Показуємо повідомлення у браузері
        if (Notification.permission === 'granted') {
            const notificationTitle = payload.notification.title;
            const notificationOptions = {
                body: payload.notification.body,
                icon: payload.notification.icon || '/assets/images/logo.png'
            };

            const notification = new Notification(notificationTitle, notificationOptions);

            notification.onclick = function() {
                window.focus();
                notification.close();
            };
        }

        // Показуємо спливаюче повідомлення у вікні
        showTempMessage(`${payload.notification.title}: ${payload.notification.body}`);

        // Оновлюємо лічильник сповіщень
        fetchLatestNotifications();
    });

    // Перевіряємо статус дозволу при завантаженні
    checkNotificationPermission();
}

// Обробка перегляду деталей замовлення
function initViewMoreButtons() {
    const viewMoreButtons = document.querySelectorAll('.view-more-btn');
    viewMoreButtons.forEach(button => {
        button.addEventListener('click', function() {
            const orderBody = this.closest('.order-body');
            orderBody.classList.toggle('expanded');
            this.innerHTML = orderBody.classList.contains('expanded')
                ? 'Згорнути <i class="fas fa-chevron-up"></i>'
                : 'Переглянути повну інформацію <i class="fas fa-chevron-down"></i>';
        });
    });
}

// Функція для ініціалізації Drag & Drop
function initDragAndDrop(dropZoneId, inputId, previewContainerId, droppedFilesInputId) {
    const dropZone = document.getElementById(dropZoneId);
    const fileInput = document.getElementById(inputId);
    const previewContainer = document.getElementById(previewContainerId);
    const droppedFilesInput = document.getElementById(droppedFilesInputId);

    if (!dropZone || !fileInput) return;

    let droppedFilesData = [];

    // Обробка вибору файлів через клік
    dropZone.addEventListener('click', () => fileInput.click());

    // Обробка вибору файлів
    fileInput.addEventListener('change', function() {
        updateFilePreview(this.files);
    });

    // Обробка Drag & Drop
    dropZone.addEventListener('dragover', (e) => {
        e.preventDefault();
        dropZone.classList.add('active');
    });

    dropZone.addEventListener('dragleave', () => {
        dropZone.classList.remove('active');
    });

    dropZone.addEventListener('drop', (e) => {
        e.preventDefault();
        dropZone.classList.remove('active');

        if (e.dataTransfer.files.length > 0) {
            handleDroppedFiles(e.dataTransfer.files);
        }
    });

    // Обробка перетягнутих файлів
    function handleDroppedFiles(files) {
        for (const file of files) {
            const reader = new FileReader();
            reader.readAsDataURL(file);
            reader.onload = function() {
                const fileData = {
                    name: file.name,
                    data: reader.result
                };
                droppedFilesData.push(fileData);
                droppedFilesInput.value = JSON.stringify(droppedFilesData);

                // Додавання превью файлу
                const previewItem = document.createElement('div');
                previewItem.className = 'drop-zone-thumb';

                let iconClass = 'fa-file';
                const ext = file.name.split('.').pop().toLowerCase();
                if (['jpg', 'jpeg', 'png', 'gif'].includes(ext)) {
                    iconClass = 'fa-file-image';
                } else if (['mp4', 'avi', 'mov'].includes(ext)) {
                    iconClass = 'fa-file-video';
                } else if (ext === 'pdf') {
                    iconClass = 'fa-file-pdf';
                } else if (['doc', 'docx'].includes(ext)) {
                    iconClass = 'fa-file-word';
                } else if (ext === 'txt') {
                    iconClass = 'fa-file-alt';
                }

                previewItem.innerHTML = `
                <i class="fas ${iconClass}" style="margin-right: 5px;"></i>
                <span>${file.name}</span> (${formatFileSize(file.size)})
                <button type="button" class="btn-text remove-file" data-index="${droppedFilesData.length - 1}">&times;</button>
            `;
                previewContainer.appendChild(previewItem);
            };
        }
    }

    // Оновлення превью файлів
    function updateFilePreview(files) {
        for (const file of files) {
            // Додавання превью файлу
            const previewItem = document.createElement('div');
            previewItem.className = 'drop-zone-thumb';

            let iconClass = 'fa-file';
            const ext = file.name.split('.').pop().toLowerCase();
            if (['jpg', 'jpeg', 'png', 'gif'].includes(ext)) {
                iconClass = 'fa-file-image';
            } else if (['mp4', 'avi', 'mov'].includes(ext)) {
                iconClass = 'fa-file-video';
            } else if (ext === 'pdf') {
                iconClass = 'fa-file-pdf';
            } else if (['doc', 'docx'].includes(ext)) {
                iconClass = 'fa-file-word';
            } else if (ext === 'txt') {
                iconClass = 'fa-file-alt';
            }

            previewItem.innerHTML = `
            <i class="fas ${iconClass}" style="margin-right: 5px;"></i>
            <span>${file.name}</span> (${formatFileSize(file.size)})
        `;
            previewContainer.appendChild(previewItem);
        }
    }

    // Обробка видалення файлів
    previewContainer.addEventListener('click', function(e) {
        if (e.target.classList.contains('remove-file')) {
            const index = parseInt(e.target.getAttribute('data-index'));
            droppedFilesData.splice(index, 1);
            droppedFilesInput.value = JSON.stringify(droppedFilesData);
            e.target.closest('.drop-zone-thumb').remove();

            // Оновлення індексів для інших кнопок видалення
            const removeButtons = previewContainer.querySelectorAll('.remove-file');
            removeButtons.forEach((button, i) => {
                button.setAttribute('data-index', i);
            });
        }
    });
}

// Функція для створення тимчасового повідомлення
function showTempMessage(message, isSuccess = true) {
    const messageEl = document.createElement('div');
    messageEl.className = 'temp-message';
    messageEl.textContent = message;

    if (!isSuccess) {
        messageEl.style.backgroundColor = 'var(--error-color)';
    }

    document.body.appendChild(messageEl);

    setTimeout(() => {
        messageEl.remove();
    }, 4000); // Видаляємо через 4 секунди
}

// Функція для зміни теми
function initThemeSelector() {
    const themeOptions = document.querySelectorAll('.theme-option');
    const htmlElement = document.documentElement;

    // Перевірка збереженої теми
    const savedTheme = localStorage.getItem('theme') || 'light';
    htmlElement.setAttribute('data-theme', savedTheme);

    // Позначення активної теми
    themeOptions.forEach(option => {
        if (option.getAttribute('data-theme') === savedTheme) {
            option.classList.add('active');
        } else {
            option.classList.remove('active');
        }
    });

    // Обробка вибору теми
    themeOptions.forEach(option => {
        option.addEventListener('click', function() {
            const theme = this.getAttribute('data-theme');
            htmlElement.setAttribute('data-theme', theme);
            localStorage.setItem('theme', theme);

            themeOptions.forEach(op => op.classList.remove('active'));
            this.classList.add('active');
        });
    });
}

// Функція для ініціалізації видалення коментарів користувача
function initDeleteComments() {
    const deleteButtons = document.querySelectorAll('.delete-comment');
    deleteButtons.forEach(button => {
        button.addEventListener('click', function() {
            if (confirm('Ви впевнені, що хочете видалити коментар?')) {
                const orderId = this.getAttribute('data-id');
                const csrfToken = document.querySelector('input[name="csrf_token"]').value;

                const formData = new FormData();
                formData.append('delete_comment', '1');
                formData.append('order_id', orderId);
                formData.append('csrf_token', csrfToken);

                fetch('dashboard.php', {
                    method: 'POST',
                    body: formData
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Видаляємо коментар з DOM
                            const commentSection = this.closest('.user-comment-section');
                            commentSection.innerHTML = '';
                            showTempMessage(data.message);
                        } else {
                            showTempMessage(data.message, false);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showTempMessage('Помилка при видаленні коментаря', false);
                    });
            }
        });
    });
}

// Функція для ініціалізації перегляду файлів
function initFileViewer() {
    const viewButtons = document.querySelectorAll('.view-file');
    const modal = document.getElementById('file-view-modal');
    const fileNameTitle = document.getElementById('file-name-title');
    const fileContentContainer = document.getElementById('file-content-container');
    const closeButton = modal.querySelector('.close-modal');

    viewButtons.forEach(button => {
        button.addEventListener('click', function() {
            const filePath = this.getAttribute('data-path');
            const fileName = this.getAttribute('data-filename');
            fileNameTitle.textContent = fileName;

            // Очищення контейнера
            fileContentContainer.innerHTML = '';

            // Визначення типу файлу
            const ext = fileName.split('.').pop().toLowerCase();

            if (['jpg', 'jpeg', 'png', 'gif'].includes(ext)) {
                // Зображення
                const img = document.createElement('img');
                img.src = filePath;
                img.alt = fileName;
                fileContentContainer.appendChild(img);
            } else if (['mp4', 'avi', 'mov'].includes(ext)) {
                // Відео
                const video = document.createElement('video');
                video.src = filePath;
                video.controls = true;
                fileContentContainer.appendChild(video);
            } else if (ext === 'pdf') {
                // PDF
                const embed = document.createElement('embed');
                embed.src = filePath;
                embed.type = 'application/pdf';
                embed.style.width = '100%';
                embed.style.height = '500px';
                fileContentContainer.appendChild(embed);
            } else if (['doc', 'docx', 'txt'].includes(ext)) {
                // Текстові файли або MS Word
                const iframe = document.createElement('iframe');
                iframe.src = filePath;
                iframe.style.width = '100%';
                iframe.style.height = '500px';
                fileContentContainer.appendChild(iframe);

                // Альтернативне посилання для скачування
                const downloadLink = document.createElement('a');
                downloadLink.href = filePath;
                downloadLink.download = fileName;
                downloadLink.textContent = 'Завантажити файл';
                downloadLink.className = 'btn';
                downloadLink.style.marginTop = '10px';
                fileContentContainer.appendChild(downloadLink);
            } else {
                // Невідомий тип файлу
                const downloadLink = document.createElement('a');
                downloadLink.href = filePath;
                downloadLink.download = fileName;
                downloadLink.textContent = 'Завантажити файл';
                downloadLink.className = 'btn';
                fileContentContainer.appendChild(downloadLink);
            }

            modal.style.display = 'block';
            document.body.classList.add('modal-open'); // Додаємо клас для заборони прокрутки
        });
    });

    // Закриття модального вікна
    closeButton.addEventListener('click', function() {
        modal.style.display = 'none';
        document.body.classList.remove('modal-open'); // Видаляємо клас блокування прокрутки
    });

    window.addEventListener('click', function(event) {
        if (event.target === modal) {
            modal.style.display = 'none';
            document.body.classList.remove('modal-open'); // Видаляємо клас блокування прокрутки
        }
    });
}

// Функція для ініціалізації модальних вікон
function initModals() {
    // Загальна функція для всіх модальних вікон
    function setupModal(modalId, openButtons, dataAttribute) {
        const modal = document.getElementById(modalId);
        if (!modal) return console.error(`Modal with id ${modalId} not found`);

        const closeButton = modal.querySelector('.close-modal');

        // Відкриття модального вікна
        openButtons.forEach(button => {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                const id = this.getAttribute(dataAttribute);
                console.log(`Opening ${modalId} with id ${id}`);

                if (modalId === 'edit-order-modal') {
                    document.getElementById('edit-order-id').textContent = '#' + id;
                    document.getElementById('modal-order-id').value = id;

                    // Заповнення форми даними замовлення
                    const orderCard = this.closest('.order-card');
                    if (!orderCard) return console.error('Order card not found');

                    const service = orderCard.querySelector('.order-detail:nth-child(1) div:nth-child(2)')?.textContent.trim();
                    const deviceType = orderCard.querySelector('.order-detail:nth-child(2) div:nth-child(2)')?.textContent.trim();
                    const details = orderCard.querySelector('.order-detail:nth-child(3) div:nth-child(2)')?.textContent.trim();
                    const phone = orderCard.querySelector('.order-detail:nth-child(4) div:nth-child(2)')?.textContent.trim();

                    let address = '';
                    const addressEl = orderCard.querySelector('.order-detail:nth-child(5) div:nth-child(2)');
                    if (addressEl) {
                        address = addressEl.textContent.trim();
                    }

                    let deliveryMethod = '';
                    const deliveryEl = orderCard.querySelector('.order-detail:nth-child(6) div:nth-child(2)');
                    if (deliveryEl) {
                        deliveryMethod = deliveryEl.textContent.trim();
                    }

                    let userComment = '';
                    const userCommentSection = orderCard.querySelector('.user-comment-section .comment');
                    if (userCommentSection) {
                        userComment = userCommentSection.textContent.trim();
                    }

                    document.getElementById('edit-service').value = service || '';
                    document.getElementById('edit-device_type').value = deviceType || '';
                    document.getElementById('edit-details').value = details || '';
                    document.getElementById('edit-phone').value = phone || '';
                    document.getElementById('edit-address').value = address || '';
                    document.getElementById('edit-delivery_method').value = deliveryMethod || '';
                    document.getElementById('edit-user_comment').value = userComment || '';
                } else if (modalId === 'add-comment-modal') {
                    document.getElementById('comment-order-id').textContent = '#' + id;
                    document.getElementById('comment-order-id-input').value = id;
                }

                // Відкриваємо модальне вікно (встановлюємо display: block)
                modal.style.display = 'block';

                // Додаємо клас до body для заборони прокрутки основного вмісту
                document.body.classList.add('modal-open');
            });
        });

        // Закриття модального вікна
        if (closeButton) {
            closeButton.addEventListener('click', function() {
                modal.style.display = 'none';
                document.body.classList.remove('modal-open');
            });
        }

        // Закриття модального вікна при кліку поза його межами
        window.addEventListener('click', function(event) {
            if (event.target === modal) {
                modal.style.display = 'none';
                document.body.classList.remove('modal-open');
            }
        });
    }

    // Модальне вікно редагування замовлення
    const editButtons = document.querySelectorAll('.edit-order');
    if (editButtons.length) {
        setupModal('edit-order-modal', editButtons, 'data-id');
        // Ініціалізація Drag & Drop для редагування замовлення
        initDragAndDrop('edit-drop-zone', 'edit-drop-zone-input', 'edit-file-preview-container', 'edit-dropped_files_data');
    }

    // Модальне вікно додавання коментаря
    const addCommentButtons = document.querySelectorAll('.add-comment');
    if (addCommentButtons.length) {
        setupModal('add-comment-modal', addCommentButtons, 'data-id');
    }

    // Модальне вікно перегляду файлу
    const viewFileButtons = document.querySelectorAll('.view-file');
    if (viewFileButtons.length) {
        setupModal('file-view-modal', viewFileButtons, 'data-path');
    }
}

// AJAX відправка форми додавання коментаря
function initAjaxCommentForm() {
    const form = document.getElementById('add-comment-form');
    if (!form) return;

    form.addEventListener('submit', function(e) {
        e.preventDefault();

        const formData = new FormData(form);

        fetch('dashboard.php', {
            method: 'POST',
            body: formData
        })
            .then(response => {
                document.getElementById('add-comment-modal').style.display = 'none';
                document.body.classList.remove('modal-open');  // Видаляємо клас блокування прокрутки
                showTempMessage('Коментар успішно додано!');
                setTimeout(() => {
                    window.location.reload();
                }, 1500);
            })
            .catch(error => {
                console.error('Error:', error);
                showTempMessage('Помилка при додаванні коментаря', false);
            });
    });
}

// Перемикання вкладок
function initTabs() {
    // Переключення вкладок навігації
    const menuTabs = document.querySelectorAll('.sidebar-menu a[data-tab]');
    const contentTabs = document.querySelectorAll('.main-content > .tab-content');

    menuTabs.forEach(tab => {
        tab.addEventListener('click', function(e) {
            e.preventDefault();

            const targetTabId = this.getAttribute('data-tab');

            // Активний пункт меню
            menuTabs.forEach(t => t.classList.remove('active'));
            this.classList.add('active');

            // Активна вкладка контенту
            contentTabs.forEach(content => {
                content.classList.remove('active');
                if (content.id === targetTabId + '-content') {
                    content.classList.add('active');
                }
            });

            // Оновлення URL з хешем
            window.location.hash = targetTabId;
        });
    });

    // Переключення підвкладок в налаштуваннях
    const settingsTabs = document.querySelectorAll('.tabs .tab');
    const settingsContents = document.querySelectorAll('#settings-content .tab-content');

    settingsTabs.forEach(tab => {
        tab.addEventListener('click', function() {
            const targetTabId = this.getAttribute('data-target');

            // Активна вкладка
            settingsTabs.forEach(t => t.classList.remove('active'));
            this.classList.add('active');

            // Активний контент
            settingsContents.forEach(content => {
                content.classList.remove('active');
                if (content.id === targetTabId + '-content') {
                    content.classList.add('active');
                }
            });
        });
    });

    // Перевірка хешу для активації вкладок
    if (window.location.hash) {
        const hash = window.location.hash.substr(1);
        const targetTab = document.querySelector(`.sidebar-menu a[data-tab="${hash}"]`);
        if (targetTab) {
            targetTab.click();
        }
    }

    // Обробка кнопки "Переглянути всі"
    const viewAllButtons = document.querySelectorAll('.view-all[data-tab]');
    viewAllButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            const targetTabId = this.getAttribute('data-tab');
            const targetTab = document.querySelector(`.sidebar-menu a[data-tab="${targetTabId}"]`);
            if (targetTab) {
                targetTab.click();
            }
        });
    });
}

// Ініціалізація згортання сайдбару
function initSidebarToggle() {
    const toggleButton = document.getElementById('toggle-sidebar');
    const mobileToggleButton = document.getElementById('mobile-toggle-sidebar');
    const sidebar = document.getElementById('sidebar');

    if (toggleButton) {
        toggleButton.addEventListener('click', function() {
            if (window.innerWidth <= 768) {
                sidebar.classList.toggle('expanded');
            } else {
                sidebar.classList.toggle('collapsed');
            }
        });
    }

    if (mobileToggleButton) {
        mobileToggleButton.addEventListener('click', function() {
            sidebar.classList.toggle('expanded');
        });
    }

    // Автоматичне згортання на мобільних пристроях
    if (window.innerWidth <= 768) {
        sidebar.classList.remove('expanded');
    }

    window.addEventListener('resize', function() {
        if (window.innerWidth <= 768) {
            sidebar.classList.remove('collapsed');
            if (!sidebar.classList.contains('expanded')) {
                sidebar.classList.remove('expanded');
            }
        } else {
            sidebar.classList.remove('expanded');
        }
    });

    // Закриття сайдбару при кліку поза ним на мобільних
    document.addEventListener('click', function(event) {
        const isClickInside = sidebar.contains(event.target) ||
            (mobileToggleButton && mobileToggleButton.contains(event.target));

        if (window.innerWidth <= 768 && !isClickInside && sidebar.classList.contains('expanded')) {
            sidebar.classList.remove('expanded');
        }
    });
}

// Ініціалізація форми профілю для фото
function initProfilePicForm() {
    const profileDropZone = document.getElementById('profile-drop-zone');
    const profilePicInput = document.getElementById('profile_pic');

    if (!profileDropZone || !profilePicInput) return;

    // Обробка вибору фото через клік
    profileDropZone.addEventListener('click', () => profilePicInput.click());

    // Обробка вибору фото
    profilePicInput.addEventListener('change', function() {
        if (this.files && this.files[0]) {
            const file = this.files[0];
            const reader = new FileReader();

            reader.onload = function(e) {
                const img = document.querySelector('.profile-preview');
                if (img) {
                    img.src = e.target.result;
                }
            };

            reader.readAsDataURL(file);

            // Автоматичне відправлення форми
            this.closest('form').submit();
        }
    });
}

// Функція для обробки фільтрації замовлень
function initFilters() {
    const filterForm = document.getElementById('filter-form');
    if (!filterForm) return;

    const statusSelect = filterForm.querySelector('[name="status"]');
    const serviceSelect = filterForm.querySelector('[name="service"]');
    const searchInput = filterForm.querySelector('[name="search"]');

    // Автоматичне відправлення форми при зміні селектів
    if (statusSelect) statusSelect.addEventListener('change', () => filterForm.submit());
    if (serviceSelect) serviceSelect.addEventListener('change', () => filterForm.submit());

    // Відправлення форми при натисненні Enter у полі пошуку
    if (searchInput) {
        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                filterForm.submit();
            }
        });
    }
}

// Ініціалізація всіх компонентів при завантаженні сторінки
document.addEventListener("DOMContentLoaded", function() {
    // Оновлення часу
    updateLocalTimes();
    setInterval(updateLocalTimes, 60000); // Оновлення кожну хвилину

    // Ініціалізація сповіщень
    initNotifications();

    // Ініціалізація WebSockets
    initWebSockets();

    // Ініціалізація Push-сповіщень
    if (messaging) {
        initPushNotifications();
    }

    // Ініціалізація інтерфейсу
    initViewMoreButtons();
    initTabs();
    initSidebarToggle();
    initThemeSelector();
    initDeleteComments();
    initFileViewer();
    initModals();
    initAjaxCommentForm();
    initFilters();

    // Ініціалізація форм
    initProfilePicForm();
    initDragAndDrop('drop-zone', 'drop-zone-input', 'file-preview-container', 'dropped_files_data');

    // Додаємо метатег з ID користувача для WebSockets
    const userIdMeta = document.createElement('meta');
    userIdMeta.name = 'user-id';
    userIdMeta.content = document.body.dataset.userId || '';
    document.head.appendChild(userIdMeta);
});