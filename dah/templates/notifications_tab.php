<div class="tab-content" id="notifications-content">
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Всі сповіщення</h2>
            <?php if (!empty($unread_notifications)): ?>
                <button class="btn" id="mark-all-notifications">
                    <i class="fas fa-check-double"></i> Позначити всі як прочитані
                </button>
            <?php endif; ?>
        </div>

        <div class="notifications-list">
            <?php if (!empty($unread_notifications)): ?>
                <h3>Нові сповіщення</h3>
                <?php foreach ($unread_notifications as $notification): ?>
                    <div class="notification-item unread" data-order-id="<?= $notification['order_id'] ?>" data-notification-id="<?= $notification['id'] ?>">
                        <div class="notification-title"><?= htmlspecialchars($notification['title']) ?></div>
                        <div class="notification-message"><?= htmlspecialchars($notification['description']) ?></div>
                        <div class="notification-service"><?= htmlspecialchars($notification['service']) ?></div>
                        <div class="notification-time">
                            <?= date('d.m.Y H:i', strtotime($notification['created_at'])) ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <!-- Додаємо розділювач, якщо є також прочитані сповіщення -->
                <hr style="margin: 20px 0;">
            <?php endif; ?>

            <?php
            // Отримання прочитаних сповіщень (обмежено до 20)
            $stmt = $conn->prepare("
                SELECT n.*, o.service 
                FROM notifications n 
                JOIN orders o ON n.order_id = o.id 
                WHERE n.user_id = ? AND n.is_read = 1 
                ORDER BY n.created_at DESC 
                LIMIT 20
            ");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $read_notifications = [];

            while ($row = $result->fetch_assoc()) {
                // Формуємо зрозумілий опис сповіщення в залежності від типу
                $description = '';
                $title = '';

                switch($row['type']) {
                    case 'status':
                        $title = "Статус замовлення #{$row['order_id']} змінено";
                        $description = "Новий статус: {$row['new_status']}";
                        break;

                    case 'comment':
                        // Отримуємо інформацію про коментар
                        $comment_stmt = $conn->prepare("
                            SELECT c.content, u.username 
                            FROM comments c 
                            JOIN users u ON c.user_id = u.id 
                            WHERE c.id = ?
                        ");
                        $comment_id = $row['content'];
                        $comment_stmt->bind_param("i", $comment_id);
                        $comment_stmt->execute();
                        $comment_result = $comment_stmt->get_result();
                        $comment_data = $comment_result->fetch_assoc();

                        $title = "Коментар до замовлення #{$row['order_id']}";
                        $description = "Адміністратор {$comment_data['username']} додав коментар";
                        break;

                    case 'system':
                        $title = "Системне сповіщення для замовлення #{$row['order_id']}";
                        $description = $row['content'];
                        break;
                }

                $read_notifications[] = [
                    'id' => $row['id'],
                    'order_id' => $row['order_id'],
                    'type' => $row['type'],
                    'title' => $title,
                    'description' => $description,
                    'created_at' => $row['created_at'],
                    'service' => $row['service']
                ];
            }
            ?>

            <?php if (!empty($read_notifications)): ?>
                <h3>Прочитані сповіщення</h3>
                <?php foreach ($read_notifications as $notification): ?>
                    <div class="notification-item" data-order-id="<?= $notification['order_id'] ?>">
                        <div class="notification-title"><?= htmlspecialchars($notification['title']) ?></div>
                        <div class="notification-message"><?= htmlspecialchars($notification['description']) ?></div>
                        <div class="notification-service"><?= htmlspecialchars($notification['service']) ?></div>
                        <div class="notification-time">
                            <?= date('d.m.Y H:i', strtotime($notification['created_at'])) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if (empty($unread_notifications) && empty($read_notifications)): ?>
                <div class="empty-notifications">
                    <i class="fas fa-bell-slash" style="font-size: 3rem; color: #ccc; margin-bottom: 15px;"></i>
                    <p>У вас немає сповіщень</p>
                    <p style="font-size: 0.9rem; margin-top: 10px;">Тут будуть відображатися сповіщення про зміни статусу ваших замовлень та нові коментарі</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>