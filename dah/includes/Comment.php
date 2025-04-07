<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
// Запобігання прямому доступу до файлу
if (!defined('SECURITY_CHECK')) {
    die('Прямий доступ до цього файлу заборонений');
}

/**
 * Клас для роботи з коментарями та сповіщеннями
 */
class Comment {
    private $db;
    private $user;
    
    /**
     * Конструктор класу
     */
    public function __construct() {
        $this->db = Database::getInstance();
        $this->user = new User();
    }
    
    /**
     * Отримання всіх коментарів для замовлення
     * @param int $orderId ID замовлення
     * @param int $limit Обмеження кількості коментарів
     * @return array Масив коментарів
     */
    public function getOrderComments($orderId, $limit = 0) {
        try {
            $sql = "SELECT c.*, 
                    u.username as author_name, 
                    u.display_name as author_display_name, 
                    u.role as author_role,
                    u.avatar as author_avatar
                    FROM order_comments c 
                    LEFT JOIN users u ON c.user_id = u.id 
                    WHERE c.order_id = ? 
                    ORDER BY c.created_at DESC";
            
            if ($limit > 0) {
                $sql .= " LIMIT ?";
                return $this->db->query($sql, [$orderId, $limit])->findAll();
            } else {
                return $this->db->query($sql, [$orderId])->findAll();
            }
        } catch (Exception $e) {
            logError("Error getting order comments: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Отримання коментаря за ID
     * @param int $commentId ID коментаря
     * @return array|false Коментар або false, якщо не знайдено
     */
    public function getCommentById($commentId) {
        try {
            return $this->db->query(
                "SELECT c.*, 
                u.username as author_name, 
                u.display_name as author_display_name, 
                u.role as author_role,
                u.avatar as author_avatar
                FROM order_comments c 
                LEFT JOIN users u ON c.user_id = u.id 
                WHERE c.id = ?",
                [$commentId]
            )->find();
        } catch (Exception $e) {
            logError("Error getting comment by ID: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Додавання коментаря до замовлення
     * @param int $orderId ID замовлення
     * @param int $userId ID користувача
     * @param string $comment Текст коментаря
     * @param bool $isAdmin Чи є користувач адміністратором
     * @return array Результат додавання коментаря
     */
    public function addComment($orderId, $userId, $comment, $isAdmin = false) {
        try {
            $this->db->beginTransaction();
            
            // Перевіряємо, чи існує замовлення
            $order = $this->db->query(
                "SELECT * FROM orders WHERE id = ?",
                [$orderId]
            )->find();
            
            if (!$order) {
                throw new Exception("Замовлення не знайдено");
            }
            
            // Перевіряємо, чи користувач має право додавати коментар
            if (!$isAdmin && $order['user_id'] != $userId) {
                throw new Exception("У вас немає прав для додавання коментаря до цього замовлення");
            }
            
            // Додаємо коментар
            $this->db->query(
                "INSERT INTO order_comments (order_id, user_id, comment, is_admin, created_at)
                VALUES (?, ?, ?, ?, NOW())",
                [$orderId, $userId, $comment, $isAdmin ? 1 : 0]
            );
            
            $commentId = $this->db->lastInsertId();
            
            // Додаємо сповіщення
            if ($isAdmin) {
                // Якщо коментар від адміністратора, сповіщаємо користувача
                $this->db->query(
                    "INSERT INTO notifications (user_id, order_id, type, title, content, created_at)
                    VALUES (?, ?, 'comment', 'Новий коментар до замовлення', ?, NOW())",
                    [
                        $order['user_id'],
                        $orderId,
                        "Адміністратор додав новий коментар до вашого замовлення #{$orderId}"
                    ]
                );
            } else {
                // Якщо коментар від користувача, сповіщаємо адміністраторів
                $admins = $this->db->query("SELECT id FROM users WHERE role = 'admin'")->findAll();
                foreach ($admins as $admin) {
                    $this->db->query(
                        "INSERT INTO notifications (user_id, order_id, type, title, content, created_at)
                        VALUES (?, ?, 'comment', 'Новий коментар до замовлення', ?, NOW())",
                        [
                            $admin['id'],
                            $orderId,
                            "Користувач додав новий коментар до замовлення #{$orderId}"
                        ]
                    );
                }
            }
            
            // Записуємо дію в лог
            $this->user->logUserActivity($userId, 'comment_added', 'order_comments', $commentId, [
                'order_id' => $orderId,
                'is_admin' => $isAdmin
            ]);
            
            $this->db->commit();
            
            // Отримуємо доданий коментар
            $newComment = $this->getCommentById($commentId);
            
            return [
                'success' => true,
                'comment' => $newComment
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            logError("Error adding comment: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Видалення коментаря
     * @param int $commentId ID коментаря
     * @param int $userId ID користувача
     * @param bool $isAdmin Чи є користувач адміністратором
     * @return array Результат видалення коментаря
     */
    public function deleteComment($commentId, $userId, $isAdmin = false) {
        try {
            $this->db->beginTransaction();
            
            // Отримуємо коментар
            $comment = $this->getCommentById($commentId);
            
            if (!$comment) {
                throw new Exception("Коментар не знайдено");
            }
            
            // Перевіряємо, чи користувач має право видаляти коментар
            if (!$isAdmin && $comment['user_id'] != $userId) {
                throw new Exception("У вас немає прав для видалення цього коментаря");
            }
            
            // Видаляємо коментар
            $this->db->query(
                "DELETE FROM order_comments WHERE id = ?",
                [$commentId]
            );
            
            // Записуємо дію в лог
            $this->user->logUserActivity($userId, 'comment_deleted', 'order_comments', $commentId, [
                'order_id' => $comment['order_id'],
                'is_admin' => $isAdmin
            ]);
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => 'Коментар успішно видалено'
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            logError("Error deleting comment: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Отримання всіх сповіщень для користувача
     * @param int $userId ID користувача
     * @param int $page Номер сторінки
     * @param int $perPage Кількість записів на сторінку
     * @param bool|null $isRead Фільтрація за статусом прочитання
     * @return array Сповіщення та параметри пагінації
     */
    public function getUserNotifications($userId, $page = 1, $perPage = 20, $isRead = null) {
        try {
            $offset = ($page - 1) * $perPage;
            
            $params = ['user_id' => $userId];
            $whereClauses = ["user_id = :user_id"];
            
            if ($isRead !== null) {
                $whereClauses[] = "is_read = :is_read";
                $params['is_read'] = (int)$isRead;
            }
            
            // Складання WHERE частини запиту
            $whereClause = implode(" AND ", $whereClauses);
            
            // Підрахунок загальної кількості записів
            $countSql = "SELECT COUNT(*) FROM notifications WHERE $whereClause";
            $totalCount = $this->db->query($countSql, $params)->findColumn();
            
            // Запит на отримання даних з пагінацією
            $sql = "SELECT * FROM notifications WHERE $whereClause ORDER BY created_at DESC LIMIT :offset, :perPage";
            $params['offset'] = $offset;
            $params['perPage'] = $perPage;
            
            $notifications = $this->db->query($sql, $params)->findAll();
            
            // Обчислення кількості сторінок
            $totalPages = ceil($totalCount / $perPage);
            if ($totalPages < 1) $totalPages = 1;
            
            return [
                'notifications' => $notifications,
                'pagination' => [
                    'total' => (int)$totalCount,
                    'pages' => $totalPages,
                    'current' => $page,
                    'perPage' => $perPage
                ]
            ];
        } catch (Exception $e) {
            logError("Error getting user notifications: " . $e->getMessage());
            return [
                'notifications' => [],
                'pagination' => [
                    'total' => 0,
                    'pages' => 1,
                    'current' => $page,
                    'perPage' => $perPage
                ]
            ];
        }
    }
    
    /**
     * Позначення сповіщення як прочитаного
     * @param int $notificationId ID сповіщення
     * @param int $userId ID користувача
     * @return bool Результат оновлення
     */
    public function markNotificationAsRead($notificationId, $userId) {
        try {
            $this->db->query(
                "UPDATE notifications SET is_read = 1, updated_at = NOW() WHERE id = ? AND user_id = ?",
                [$notificationId, $userId]
            );
            
            return $this->db->rowCount() > 0;
        } catch (Exception $e) {
            logError("Error marking notification as read: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Позначення всіх сповіщень як прочитаних
     * @param int $userId ID користувача
     * @return bool Результат оновлення
     */
    public function markAllNotificationsAsRead($userId) {
        try {
            $this->db->query(
                "UPDATE notifications SET is_read = 1, updated_at = NOW() WHERE user_id = ? AND is_read = 0",
                [$userId]
            );
            
            return $this->db->rowCount() > 0;
        } catch (Exception $e) {
            logError("Error marking all notifications as read: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Отримання кількості непрочитаних сповіщень
     * @param int $userId ID користувача
     * @return int Кількість непрочитаних сповіщень
     */
    public function getUnreadNotificationsCount($userId) {
        try {
            return (int)$this->db->query(
                "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0",
                [$userId]
            )->findColumn();
        } catch (Exception $e) {
            logError("Error getting unread notifications count: " . $e->getMessage());
            return 0;
        }
    }
}