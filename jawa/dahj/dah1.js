/**
 * Скрипт для роботи з дашбордом користувача
 */

// Функція для оновлення лічильника непрочитаних повідомлень
function updateNotificationCounter() {
    fetch('/api/notifications/count.php')
        .then(response => response.json())
        .then(data => {
            const notificationBadge = document.querySelector('#notificationsDropdown .badge');

            if (data.count > 0) {
                // Якщо є непрочитані повідомлення
                if (notificationBadge) {
                    // Оновлюємо існуючий бейдж
                    notificationBadge.textContent = data.count;
                } else {
                    // Створюємо новий бейдж
                    const badge = document.createElement('span');
                    badge.className = 'badge bg-danger';
                    badge.textContent = data.count;
                    document.querySelector('#notificationsDropdown').appendChild(badge);
                }
            } else if (notificationBadge) {
                // Якщо немає непрочитаних повідомлень, видаляємо бейдж
                notificationBadge.remove();
            }
        })
        .catch(error => console.error('Помилка при оновленні лічильника повідомлень:', error));
}

// Функція для форматування дати у зручний формат
function formatDate(dateString) {
    const options = {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    };
    return new Date(dateString).toLocaleDateString('uk-UA', options);
}

// Функція для зміни теми сайту
function changeTheme(theme) {
    fetch('/api/user/change-theme.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({ theme: theme })
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Оновлюємо сторінку для застосування нової теми
                location.reload();
            } else {
                showNotification(data.message || 'Помилка при зміні теми');
            }
        })
        .catch(error => {
            console.error('Помилка при зміні теми:', error);
            showNotification('Помилка при з\'єднанні з сервером');
        });
}

// Функція для відображення повідомлення в модальному вікні
function showNotification(message, title = 'Повідомлення') {
    const modal = new bootstrap.Modal(document.getElementById('notificationModal'));
    document.getElementById('notificationMessage').textContent = message;
    document.querySelector('#notificationModal .modal-title').textContent = title;
    modal.show();
}

// Функція для відображення підтверджувального діалогу
function showConfirmDialog(message, callback, title = 'Підтвердження') {
    const confirmModal = document.getElementById('confirmModal');

    if (!confirmModal) {
        // Створюємо модальне вікно, якщо воно не існує
        const modalHtml = `
        <div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">${title}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p id="confirmMessage">${message}</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Скасувати</button>
                        <button type="button" class="btn btn-primary" id="confirmButton">Підтвердити</button>
                    </div>
                </div>
            </div>
        </div>
        `;

        document.body.insertAdjacentHTML('beforeend', modalHtml);

        // Додаємо обробник для кнопки підтвердження
        document.getElementById('confirmButton').addEventListener('click', function() {
            const modal = bootstrap.Modal.getInstance(document.getElementById('confirmModal'));
            modal.hide();
            if (typeof callback === 'function') {
                callback();
            }
        });
    } else {
        // Оновлюємо текст повідомлення
        document.getElementById('confirmMessage').textContent = message;
        document.querySelector('#confirmModal .modal-title').textContent = title;
    }

    // Показуємо модальне вікно
    const modal = new bootstrap.Modal(document.getElementById('confirmModal'));
    modal.show();
}

// Функція для виходу з системи
function logout() {
    showConfirmDialog('Ви дійсно бажаєте вийти з системи?', function() {
        // Перенаправляємо на сторінку виходу
        window.location.href = '/logout.php';
    });
}

// Ініціалізація сторінки
document.addEventListener('DOMContentLoaded', function() {
    // Періодичне оновлення лічильника повідомлень
    updateNotificationCounter();
    setInterval(updateNotificationCounter, 60000); // Оновлення кожну хвилину

    // Додаємо обробник для кнопки виходу
    const logoutButtons = document.querySelectorAll('a[href="/logout.php"]');
    logoutButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            logout();
        });
    });

    // Функціональність для кнопки зміни теми
    const themeButtons = document.querySelectorAll('.theme-switch');
    themeButtons.forEach(button => {
        button.addEventListener('click', function() {
            const theme = this.dataset.theme;
            changeTheme(theme);
        });
    });

    // Ініціалізація тултіпів
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});

// Обробник відправки повідомлення
function submitComment(formElement) {
    const formData = new FormData(formElement);

    fetch('/api/comments/add.php', {
        method: 'POST',
        body: formData
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Очищаємо форму
                formElement.reset();

                // Оновлюємо список коментарів
                if (typeof loadComments === 'function') {
                    const orderId = formElement.querySelector('input[name="order_id"]').value;
                    loadComments(orderId);
                } else {
                    // Перезавантажуємо сторінку, якщо функція оновлення недоступна
                    location.reload();
                }
            } else {
                showNotification(data.message || 'Помилка при додаванні коментаря');
            }
        })
        .catch(error => {
            console.error('Помилка при відправці коментаря:', error);
            showNotification('Помилка при з\'єднанні з сервером');
        });

    // Запобігаємо стандартній відправці форми
    return false;
}

// Функція для завантаження коментарів
function loadComments(orderId) {
    fetch(`/api/comments/get.php?order_id=${orderId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const commentsContainer = document.getElementById('commentsContainer');

                if (commentsContainer) {
                    // Очищаємо контейнер
                    commentsContainer.innerHTML = '';

                    if (data.comments.length === 0) {
                        commentsContainer.innerHTML = '<div class="text-center my-4">Немає коментарів</div>';
                        return;
                    }

                    // Додаємо коментарі
                    data.comments.forEach(comment => {
                        const isAdmin = comment.role === 'admin' || comment.role === 'junior_admin';

                        const commentHtml = `
                        <div class="comment mb-3 ${isAdmin ? 'admin-comment' : ''}">
                            <div class="comment-header d-flex align-items-center">
                                <div class="comment-avatar">
                                    ${comment.profile_pic
                            ? `<img src="${comment.profile_pic}" alt="Аватар" class="rounded-circle">`
                            : `<div class="avatar-placeholder rounded-circle">${comment.username.charAt(0).toUpperCase()}</div>`
                        }
                                </div>
                                <div class="ms-2">
                                    <div class="comment-author">
                                        ${isAdmin ? '<span class="badge bg-primary me-1">Адмін</span>' : ''}
                                        ${comment.first_name ? `${comment.first_name} ${comment.last_name}` : comment.username}
                                    </div>
                                    <div class="comment-time">${formatDate(comment.created_at)}</div>
                                </div>
                            </div>
                            <div class="comment-body mt-2">
                                ${comment.content.replace(/\n/g, '<br>')}
                            </div>
                            ${comment.file_attachment
                            ? `<div class="comment-attachment mt-2">
                                    <a href="${comment.file_attachment}" target="_blank" class="btn btn-sm btn-outline-secondary">
                                        <i class="bi bi-paperclip"></i> Вкладений файл
                                    </a>
                                   </div>`
                            : ''
                        }
                        </div>
                        `;

                        commentsContainer.insertAdjacentHTML('beforeend', commentHtml);
                    });
                }
            } else {
                showNotification(data.message || 'Помилка при завантаженні коментарів');
            }
        })
        .catch(error => {
            console.error('Помилка при завантаженні коментарів:', error);
            showNotification('Помилка при з\'єднанні з сервером');
        });
}