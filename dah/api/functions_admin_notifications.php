<?php
// Функції для роботи з адміністраторськими повідомленнями

// Функція для отримання адміністраторських повідомлень
function getAdminNotifications($limit = 10, $offset = 0, $only_unread = false) {
    $database = new Database();
    $db = $database->getConnection();

    $whereClause = "";

    if ($only_unread) {
        $whereClause = "WHERE is_read = 0";
    }

    $query = "SELECT an.*, o.service, u.username
              FROM admin_notifications an
              LEFT JOIN orders o ON an.order_id = o.id
              LEFT JOIN users u ON o.user_id = u.id
              $whereClause
              ORDER BY an.created_at DESC 
              LIMIT :limit OFFSET :offset";
    
    $stmt = $db->prepare($query);
    $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Функція для підрахунку кількості непрочитаних адміністраторських повідомлень
function getUnreadAdminNotificationsCount() {
    $database = new Database();
    $db = $database->getConnection();

    $query = "SELECT COUNT(*) as unread_count 
              FROM admin_notifications 
              WHERE is_read = 0";
    
    $stmt = $db->prepare($query);
    $stmt->execute();

    $result = $stmt->fetch();
    return isset($result['unread_count']) ? intval($result['unread_count']) : 0;
}

// Функція для позначення адміністраторського повідомлення як прочитаного
function markAdminNotificationAsRead($notification_id) {
    $database = new Database();
    $db = $database->getConnection();

    $query = "UPDATE admin_notifications SET is_read = 1 WHERE id = :notification_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':notification_id', $notification_id);

    return $stmt->execute();
}

// Функція для позначення всіх адміністраторських повідомлень як прочитаних
function markAllAdminNotificationsAsRead() {
    $database = new Database();
    $db = $database->getConnection();

    $query = "UPDATE admin_notifications SET is_read = 1 WHERE is_read = 0";
    $stmt = $db->prepare($query);

    return $stmt->execute();
}

// Функція для додавання адміністраторського повідомлення
function addAdminNotification($order_id, $type, $content) {
    $database = new Database();
    $db = $database->getConnection();

    $query = "INSERT INTO admin_notifications (order_id, type, content, created_at) 
              VALUES (:order_id, :type, :content, NOW())";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':order_id', $order_id);
    $stmt->bindParam(':type', $type);
    $stmt->bindParam(':content', $content);

    return $stmt->execute();
}

// Функція для видалення адміністраторського повідомлення
function deleteAdminNotification($notification_id) {
    $database = new Database();
    $db = $database->getConnection();

    $query = "DELETE FROM admin_notifications WHERE id = :notification_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':notification_id', $notification_id);

    return $stmt->execute();
}

// Функція для оновлення замовлення та додавання повідомлень
function updateOrderStatus($order_id, $new_status, $admin_id = null) {
    $database = new Database();
    $db = $database->getConnection();
    
    // Починаємо транзакцію
    $db->beginTransaction();
    
    try {
        // Отримуємо поточний статус замовлення
        $query = "SELECT status, user_id FROM orders WHERE id = :order_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':order_id', $order_id);
        $stmt->execute();
        
        $order = $stmt->fetch();
        
        if (!$order) {
            return ['success' => false, 'message' => 'Замовлення не знайдено'];
        }
        
        $previous_status = $order['status'];
        $user_id = $order['user_id'];
        
        // Оновлюємо статус замовлення
        $query = "UPDATE orders SET status = :new_status, updated_at = NOW() WHERE id = :order_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':new_status', $new_status);
        $stmt->bindParam(':order_id', $order_id);
        $stmt->execute();
        
        // Додаємо запис в історію замовлення
        $query = "INSERT INTO order_history (order_id, user_id, previous_status, new_status, changed_at) 
                  VALUES (:order_id, :user_id, :previous_status, :new_status, NOW())";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':order_id', $order_id);
        $stmt->bindParam(':user_id', $admin_id);
        $stmt->bindParam(':previous_status', $previous_status);
        $stmt->bindParam(':new_status', $new_status);
        $stmt->execute();
        
        // Додаємо повідомлення для користувача
        $query = "INSERT INTO notifications (user_id, order_id, type, title, content, old_status, new_status, created_at) 
                  VALUES (:user_id, :order_id, 'status_update', 'Статус замовлення змінено', 
                  :content, :old_status, :new_status, NOW())";
        $content = "Статус вашого замовлення #$order_id змінено з \"$previous_status\" на \"$new_status\"";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':order_id', $order_id);
        $stmt->bindParam(':content', $content);
        $stmt->bindParam(':old_status', $previous_status);
        $stmt->bindParam(':new_status', $new_status);
        $stmt->execute();
        
        // Встановлюємо флаг наявності непрочитаних повідомлень
        $query = "UPDATE orders SET has_notifications = 1, unread_count = unread_count + 1 WHERE id = :order_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':order_id', $order_id);
        $stmt->execute();
        
        // Завершуємо транзакцію
        $db->commit();
        
        return ['success' => true, 'message' => 'Статус замовлення успішно оновлено'];
    } catch (Exception $e) {
        // Відкочуємо транзакцію у випадку помилки
        $db->rollBack();
        
        return ['success' => false, 'message' => 'Помилка при оновленні статусу: ' . $e->getMessage()];
    }
}
?>