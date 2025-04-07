<?php
// Отримання параметрів для сторінки
$notificationPage = isset($_GET['npage']) ? max(1, (int)$_GET['npage']) : 1;
$notificationPerPage = 20;

// Отримання сповіщень з пагінацією
$notificationsData = $comment->getUserNotifications($userId, $notificationPage, $notificationPerPage);
$notifications = $notificationsData['notifications'];
$notificationPagination = $notificationsData['pagination'];

// Отримання кількості непрочитаних
$unreadCount = $comment->getUnreadNotificationsCount($userId);
?>

<div class="page-header">
    <h1>Сповіщення</h1>

    <?php if ($unreadCount > 0): ?>
        <div class="page-actions">
            <button id="mark-all-notifications-read" class="btn btn-primary">
                <i class="fas fa-check-double"></i> Позначити всі як прочитані (<?= $unreadCount ?>)
            </button>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-body">
        <?php if (empty($notifications)): ?>
            <div class="empty-state">
                <i class="fas fa-bell-slash"></i>
                <h3>Сповіщення відсутні</h3>
                <p>У вас немає сповіщень.</p>
            </div>
        <?php else: ?>
            <div class="notifications-list-full">
                <?php foreach ($notifications as $notification): ?>
                    <div class="notification-item <?= $notification['is_read'] ? 'read' : '' ?>" data-id="<?= $notification['id'] ?>">
                        <div class="notification-icon">
                            <?php
                            $iconClass = 'fas fa-bell';
                            switch ($notification['type']) {
                                case 'comment': $iconClass = 'fas fa-comment-alt'; break;
                                case 'status_update': $iconClass = 'fas fa-sync-alt'; break;
                                case 'admin_message': $iconClass = 'fas fa-envelope'; break;
                                case 'new_order': $iconClass = 'fas fa-clipboard-check'; break;
                                case 'order_cancelled': $iconClass = 'fas fa-times-circle'; break;
                                case 'order_updated': $iconClass = 'fas fa-edit'; break;
                            }
                            ?>
                            <i class="<?= $iconClass ?>"></i>
                        </div>
                        <div class="notification-content">
                            <div class="notification-header">
                                <div class="notification-title"><?= htmlspecialchars($notification['title']) ?></div>
                                <div class="notification-time"><?= formatDateTime($notification['created_at']) ?></div>
                            </div>
                            <div class="notification-text"><?= htmlspecialchars($notification['content']) ?></div>

                            <?php if ($notification['order_id']): ?>
                                <div class="notification-actions">
                                    <button type="button" class="btn btn-primary btn-sm view-order-btn" data-id="<?= $notification['order_id'] ?>">
                                        Переглянути замовлення
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php if (!$notification['is_read']): ?>
                            <div class="notification-status">
                                <button class="mark-read-btn" title="Позначити як прочитане">
                                    <i class="fas fa-check"></i>
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Пагінація -->
            <?php if ($notificationPagination['pages'] > 1): ?>
                <div class="pagination">
                    <ul class="pagination-list">
                        <?php if ($notificationPagination['current'] > 1): ?>
                            <li class="pagination-item">
                                <a href="?tab=notifications&npage=<?= $notificationPagination['current'] - 1 ?>" class="pagination-link">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            </li>
                        <?php endif; ?>

                        <?php
                        // Визначаємо діапазон сторінок для відображення
                        $startPage = max(1, $notificationPagination['current'] - 2);
                        $endPage = min($notificationPagination['pages'], $notificationPagination['current'] + 2);

                        // Якщо поточна сторінка близько до початку, показуємо більше сторінок після
                        if ($startPage <= 3) {
                            $endPage = min($notificationPagination['pages'], 5);
                        }

                        // Якщо поточна сторінка близько до кінця, показуємо більше сторінок до
                        if ($endPage >= $notificationPagination['pages'] - 2) {
                            $startPage = max(1, $notificationPagination['pages'] - 4);
                        }

                        // Показуємо першу сторінку та "..."
                        if ($startPage > 1) {
                            echo '<li class="pagination-item"><a href="?tab=notifications&npage=1" class="pagination-link">1</a></li>';

                            if ($startPage > 2) {
                                echo '<li class="pagination-item"><span class="pagination-link disabled">...</span></li>';
                            }
                        }

                        // Показуємо діапазон сторінок
                        for ($i = $startPage; $i <= $endPage; $i++):
                            ?>
                            <li class="pagination-item">
                                <a href="?tab=notifications&npage=<?= $i ?>" class="pagination-link <?= $i === $notificationPagination['current'] ? 'active' : '' ?>">
                                    <?= $i ?>
                                </a>
                            </li>
                        <?php endfor; ?>

                        <?php
                        // Показуємо "..." та останню сторінку
                        if ($endPage < $notificationPagination['pages']) {
                            if ($endPage < $notificationPagination['pages'] - 1) {
                                echo '<li class="pagination-item"><span class="pagination-link disabled">...</span></li>';
                            }

                            echo '<li class="pagination-item"><a href="?tab=notifications&npage=' . $notificationPagination['pages'] . '" class="pagination-link">' . $notificationPagination['pages'] . '</a></li>';
                        }
                        ?>

                        <?php if ($notificationPagination['current'] < $notificationPagination['pages']): ?>
                            <li class="pagination-item">
                                <a href="?tab=notifications&npage=<?= $notificationPagination['current'] + 1 ?>" class="pagination-link">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
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
        const markAllReadBtn = document.getElementById('mark-all-notifications-read');
        if (markAllReadBtn) {
            markAllReadBtn.addEventListener('click', function() {
                markAllNotificationsAsRead();
            });
        }

        // Перегляд замовлення при кліку на сповіщення
        document.querySelectorAll('.notification-item').forEach(function(item) {
            item.addEventListener('click', function() {
                // Перевіряємо, чи є кнопка перегляду замовлення
                const viewOrderBtn = this.querySelector('.view-order-btn');
                if (viewOrderBtn) {
                    const orderId = viewOrderBtn.getAttribute('data-id');
                    viewOrder(orderId);
                }

                // Позначаємо сповіщення як прочитане, якщо воно не прочитане
                if (!this.classList.contains('read')) {
                    const notificationId = this.getAttribute('data-id');
                    markNotificationAsRead(notificationId, this);
                }
            });
        });

        // Функція позначення сповіщення як прочитаного
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
                        // Позначаємо сповіщення як прочитане у інтерфейсі
                        if (element) {
                            element.classList.add('read');
                            const statusElement = element.querySelector('.notification-status');
                            if (statusElement) {
                                statusElement.remove();
                            }
                        }

                        // Оновлюємо лічильник непрочитаних сповіщень
                        updateNotificationCounter(data.unreadCount);

                        // Перевіряємо, чи залишилися непрочитані сповіщення
                        if (data.unreadCount === 0) {
                            const markAllBtn = document.getElementById('mark-all-notifications-read');
                            if (markAllBtn) {
                                markAllBtn.style.display = 'none';
                            }
                        }
                    }
                })
                .catch(error => console.error('Error:', error));
        }

        // Функція позначення всіх сповіщень як прочитаних
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
                        document.querySelectorAll('.notification-item:not(.read)').forEach(item => {
                            item.classList.add('read');
                            const statusElement = item.querySelector('.notification-status');
                            if (statusElement) {
                                statusElement.remove();
                            }
                        });

                        // Приховуємо кнопку "Позначити всі як прочитані"
                        const markAllBtn = document.getElementById('mark-all-notifications-read');
                        if (markAllBtn) {
                            markAllBtn.style.display = 'none';
                        }

                        // Оновлюємо лічильник непрочитаних сповіщень
                        updateNotificationCounter(0);

                        showNotification('success', 'Всі сповіщення позначені як прочитані');
                    }
                })
                .catch(error => console.error('Error:', error));
        }

        // Оновлення лічильника непрочитаних сповіщень
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
    });
</script>