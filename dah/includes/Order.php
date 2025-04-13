<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
// Запобігання прямому доступу до файлу
if (!defined('SECURITY_CHECK')) {
    die('Прямий доступ до цього файлу заборонений');
}

/**
 * Клас для роботи з замовленнями
 */
class Order {
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
     * Отримання замовлення за ID
     * @param int $orderId ID замовлення
     * @return array|false Дані замовлення або false, якщо не знайдено
     */
    public function getById($orderId) {
        $result = $this->db->query("SELECT * FROM orders WHERE id = ?", [$orderId])->find();
        return $result ? $result : false;
    }

    /**
     * Створення нового замовлення
     * @param int $userId ID користувача
     * @param array $data Дані замовлення
     * @return int|bool ID нового замовлення або false при помилці
     * @throws Exception При помилці створення
     */
    public function create($userId, $data) {
        try {
            $this->db->beginTransaction();

            // Перевірка обмежень на створення замовлень
            $limitCheck = $this->checkOrderLimits($userId);
            if (!$limitCheck['allowed']) {
                throw new Exception($limitCheck['message']);
            }

            // Перевірка вибраної послуги
            if (!empty($data['service_id'])) {
                $service = $this->db->query("SELECT * FROM repair_services WHERE id = ? AND is_active = 1", [$data['service_id']])->find();
                if (!$service) {
                    throw new Exception("Вибрана послуга недоступна або не існує");
                }
                $serviceName = $service['name'];
                $price = $service['price'];
                $estimatedCompletionDays = explode('-', $service['estimated_time'])[1] ?? '3';
                $estimatedCompletionDate = date('Y-m-d', strtotime("+$estimatedCompletionDays days"));
            } else {
                $serviceName = $data['service'];
                $price = 0;
                $estimatedCompletionDate = null;
            }

            // Підготовка даних для вставки
            $params = [
                'user_id' => $userId,
                'service' => $serviceName,
                'service_id' => $data['service_id'] ?? null,
                'device_type' => $data['device_type'],
                'details' => $data['details'],
                'phone' => $data['phone'],
                'address' => $data['address'] ?? null,
                'delivery_method' => $data['delivery_method'] ?? null,
                'status' => 'Новий',
                'user_comment' => $data['user_comment'] ?? null,
                'has_media' => !empty($_FILES['files']['name'][0]) ? 1 : 0,
                'price' => $price,
                'estimated_completion_date' => $estimatedCompletionDate
            ];

            $sql = "INSERT INTO orders (
                user_id, 
                service, 
                service_id, 
                device_type, 
                details, 
                phone, 
                address, 
                delivery_method, 
                status, 
                user_comment, 
                has_media,
                price,
                estimated_completion_date,
                created_at
            ) VALUES (
                :user_id, 
                :service, 
                :service_id, 
                :device_type, 
                :details, 
                :phone, 
                :address, 
                :delivery_method, 
                :status, 
                :user_comment, 
                :has_media,
                :price,
                :estimated_completion_date,
                NOW()
            )";

            $this->db->query($sql, $params);
            $orderId = $this->db->lastInsertId();

            // Збереження файлів, якщо вони є
            if (!empty($_FILES['files']['name'][0])) {
                $this->saveOrderFiles($orderId, $_FILES['files'], $userId);
            }

            // Додаємо запис в історію статусів
            $this->db->query(
                "INSERT INTO order_history (order_id, previous_status, new_status, changed_by, changed_at)
                VALUES (?, NULL, ?, ?, NOW())",
                [$orderId, 'Новий', $userId]
            );

            // Оновлення лімітів замовлень
            $this->updateOrderLimits($userId);

            // Запис в лог
            $this->user->logUserActivity($userId, 'order_created', 'orders', $orderId);

            // Додаємо сповіщення для адміністраторів
            $this->notifyAdminsAboutNewOrder($orderId, $userId);

            $this->db->commit();
            return $orderId;
        } catch (Exception $e) {
            $this->db->rollBack();
            logError("Error creating order: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Оновлення замовлення
     * @param int $orderId ID замовлення
     * @param int $userId ID користувача
     * @param array $data Дані для оновлення
     * @return bool Результат оновлення
     * @throws Exception При помилці оновлення
     */
    public function update($orderId, $userId, $data) {
        try {
            $this->db->beginTransaction();

            // Перевірка, чи існує замовлення і чи належить воно користувачу
            $order = $this->getById($orderId);
            if (!$order || $order['user_id'] != $userId) {
                throw new Exception("Замовлення не знайдено або у вас немає прав для його редагування");
            }

            // Перевірка, чи можна редагувати замовлення
            if (!$this->isOrderEditable($order['status'])) {
                throw new Exception("Замовлення з поточним статусом не можна редагувати");
            }

            // Підготовка даних для оновлення
            $params = [
                'device_type' => $data['device_type'],
                'details' => $data['details'],
                'phone' => $data['phone'],
                'address' => $data['address'] ?? null,
                'delivery_method' => $data['delivery_method'] ?? null,
                'user_comment' => $data['user_comment'] ?? null,
                'orderId' => $orderId
            ];

            $sql = "UPDATE orders SET 
                    device_type = :device_type, 
                    details = :details, 
                    phone = :phone, 
                    address = :address, 
                    delivery_method = :delivery_method, 
                    user_comment = :user_comment, 
                    updated_at = NOW() 
                    WHERE id = :orderId";

            $this->db->query($sql, $params);

            // Обробка нових файлів
            if (!empty($_FILES['files']['name'][0])) {
                $this->saveOrderFiles($orderId, $_FILES['files'], $userId);
                $this->db->query("UPDATE orders SET has_media = 1 WHERE id = ?", [$orderId]);
            }

            // Видалення файлів, якщо потрібно
            if (!empty($data['remove_files']) && is_array($data['remove_files'])) {
                foreach ($data['remove_files'] as $fileId) {
                    $this->deleteOrderFile($fileId, $orderId, $userId);
                }

                // Перевіряємо, чи залишилися файли
                $remainingFiles = $this->db->query("SELECT COUNT(*) FROM order_media WHERE order_id = ?", [$orderId])->findColumn();
                if ($remainingFiles == 0) {
                    $this->db->query("UPDATE orders SET has_media = 0 WHERE id = ?", [$orderId]);
                }
            }

            // Запис в лог
            $this->user->logUserActivity($userId, 'order_updated', 'orders', $orderId);

            // Додаємо запис у історію замовлення
            $this->db->query(
                "INSERT INTO order_history (order_id, previous_status, new_status, comment, changed_by, changed_at)
                VALUES (?, ?, ?, ?, ?, NOW())",
                [
                    $orderId,
                    $order['status'],
                    $order['status'],
                    'Замовлення оновлено користувачем',
                    $userId
                ]
            );

            // Додаємо сповіщення для адміністраторів
            $admins = $this->db->query("SELECT id FROM users WHERE role = 'admin'")->findAll();
            foreach ($admins as $admin) {
                $this->db->query(
                    "INSERT INTO notifications (user_id, order_id, type, title, content, created_at)
                    VALUES (?, ?, 'order_updated', 'Замовлення оновлено', ?, NOW())",
                    [
                        $admin['id'],
                        $orderId,
                        "Користувач оновив замовлення #{$orderId}"
                    ]
                );
            }

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            logError("Error updating order: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Скасування замовлення
     * @param int $orderId ID замовлення
     * @param int $userId ID користувача
     * @param string|null $reason Причина скасування
     * @return bool Результат скасування
     * @throws Exception При помилці скасування
     */
    public function cancelOrder($orderId, $userId, $reason = null) {
        try {
            $this->db->beginTransaction();

            // Перевірка, чи існує замовлення і чи належить воно користувачу
            $order = $this->getById($orderId);
            if (!$order || $order['user_id'] != $userId) {
                throw new Exception("Замовлення не знайдено або у вас немає прав для його скасування");
            }

            // Перевірка, чи можна скасувати замовлення
            if (!$this->isOrderCancellable($order['status'])) {
                throw new Exception("Замовлення з поточним статусом не можна скасувати");
            }

            // Оновлюємо статус
            $this->updateStatus($orderId, 'Скасовано', $reason, $userId);

            // Запис в лог
            $this->user->logUserActivity($userId, 'order_cancelled', 'orders', $orderId, [
                'reason' => $reason
            ]);

            // Додаємо сповіщення для адміністраторів
            $admins = $this->db->query("SELECT id FROM users WHERE role = 'admin'")->findAll();
            foreach ($admins as $admin) {
                $this->db->query(
                    "INSERT INTO notifications (user_id, order_id, type, title, content, created_at)
                    VALUES (?, ?, 'order_cancelled', 'Замовлення скасовано', ?, NOW())",
                    [
                        $admin['id'],
                        $orderId,
                        "Користувач скасував замовлення #{$orderId}" . ($reason ? ". Причина: {$reason}" : "")
                    ]
                );
            }

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            logError("Error cancelling order: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Отримання всіх замовлень користувача з фільтрацією та пагінацією
     * @param int $userId ID користувача
     * @param array $filters Параметри фільтрації
     * @param int $page Номер сторінки
     * @param int $perPage Кількість записів на сторінку
     * @return array Замовлення користувача та параметри пагінації
     */
    public function getUserOrders($userId, $filters = [], $page = 1, $perPage = 10) {
        try {
            $offset = ($page - 1) * $perPage;

            $params = ['user_id' => $userId];
            $whereClauses = ["user_id = :user_id"];

            // Фільтрація за статусом
            if (!empty($filters['status'])) {
                $whereClauses[] = "status = :status";
                $params['status'] = $filters['status'];
            }

            // Фільтрація за послугою
            if (!empty($filters['service'])) {
                $whereClauses[] = "service = :service";
                $params['service'] = $filters['service'];
            }

            // Пошук за ключовими словами
            if (!empty($filters['search'])) {
                $whereClauses[] = "(id LIKE :search OR device_type LIKE :search OR details LIKE :search OR service LIKE :search)";
                $params['search'] = '%' . $filters['search'] . '%';
            }

            // Складання WHERE частини запиту
            $whereClause = implode(" AND ", $whereClauses);

            // Підрахунок загальної кількості записів
            $countSql = "SELECT COUNT(*) FROM orders WHERE $whereClause";
            $totalCount = $this->db->query($countSql, $params)->findColumn();

            // Запит на отримання даних з пагінацією
            $sql = "SELECT * FROM orders WHERE $whereClause ORDER BY created_at DESC LIMIT :offset, :perPage";
            $params['offset'] = $offset;
            $params['perPage'] = $perPage;

            $orders = $this->db->query($sql, $params)->findAll();

            // Додаємо додаткову інформацію до кожного замовлення
            foreach ($orders as &$order) {
                // Додаємо файли
                $order['files'] = $this->getOrderFiles($order['id']);

                // Додаємо коментарі
                $order['comments'] = $this->getOrderComments($order['id']);

                // Додаємо лічильник непрочитаних сповіщень
                $order['unread_count'] = $this->getUnreadNotificationsCount($userId, $order['id']);

                // Додаємо історію статусів
                $order['status_history'] = $this->getOrderStatusHistory($order['id'], 3); // Обмежуємо до 3 останніх оновлень
            }

            // Обчислення кількості сторінок
            $totalPages = ceil($totalCount / $perPage);
            if ($totalPages < 1) $totalPages = 1;

            return [
                'orders' => $orders,
                'pagination' => [
                    'total' => (int)$totalCount,
                    'pages' => $totalPages,
                    'current' => $page,
                    'perPage' => $perPage
                ]
            ];
        } catch (Exception $e) {
            logError("Error getting user orders: " . $e->getMessage());
            return [
                'orders' => [],
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
     * Отримання файлів для замовлення
     * @param int $orderId ID замовлення
     * @return array Файли замовлення
     */
    public function getOrderFiles($orderId) {
        try {
            return $this->db->query(
                "SELECT * FROM order_media WHERE order_id = ? AND is_visible = 1 ORDER BY created_at DESC",
                [$orderId]
            )->findAll();
        } catch (Exception $e) {
            logError("Error getting order files: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Отримання коментарів для замовлення
     * @param int $orderId ID замовлення
     * @return array Коментарі замовлення
     */
    public function getOrderComments($orderId) {
        try {
            return $this->db->query(
                "SELECT c.*, u.username as author_name, u.display_name as author_display_name, u.role as author_role, u.avatar 
                 FROM order_comments c 
                 LEFT JOIN users u ON c.user_id = u.id 
                 WHERE c.order_id = ? 
                 ORDER BY c.created_at DESC",
                [$orderId]
            )->findAll();
        } catch (Exception $e) {
            logError("Error getting order comments: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Отримання історії статусів замовлення
     * @param int $orderId ID замовлення
     * @param int $limit Обмеження кількості записів
     * @return array Історія статусів замовлення
     */
    public function getOrderStatusHistory($orderId, $limit = 0) {
        try {
            $sql = "SELECT h.*, u.username as changed_by_name, u.display_name as changed_by_display_name, u.role as changed_by_role 
                   FROM order_history h 
                   LEFT JOIN users u ON h.changed_by = u.id 
                   WHERE h.order_id = ? 
                   ORDER BY h.changed_at DESC";

            if ($limit > 0) {
                $sql .= " LIMIT ?";
                return $this->db->query($sql, [$orderId, $limit])->findAll();
            } else {
                return $this->db->query($sql, [$orderId])->findAll();
            }
        } catch (Exception $e) {
            logError("Error getting order status history: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Зміна статусу замовлення
     * @param int $orderId ID замовлення
     * @param string $newStatus Новий статус
     * @param string|null $comment Коментар до зміни статусу
     * @param int|null $changedBy ID користувача, який змінив статус
     * @return bool Результат зміни статусу
     * @throws Exception При помилці зміни статусу
     */
    public function updateStatus($orderId, $newStatus, $comment = null, $changedBy = null) {
        try {
            $this->db->beginTransaction();

            // Отримуємо поточне замовлення
            $order = $this->getById($orderId);
            if (!$order) {
                throw new Exception("Замовлення не знайдено");
            }

            $oldStatus = $order['status'];

            // Якщо статус не змінився, нічого не робимо
            if ($oldStatus == $newStatus) {
                $this->db->rollBack();
                return true;
            }

            // Оновлюємо статус
            $this->db->query(
                "UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ?",
                [$newStatus, $orderId]
            );

            // Записуємо історію зміни статусу
            $this->db->query(
                "INSERT INTO order_history (order_id, previous_status, new_status, comment, changed_by, changed_at) 
                VALUES (?, ?, ?, ?, ?, NOW())",
                [$orderId, $oldStatus, $newStatus, $comment, $changedBy]
            );

            // Додаємо сповіщення для користувача
            $this->db->query(
                "INSERT INTO notifications (user_id, order_id, type, title, content, created_at) 
                VALUES (?, ?, 'status_update', 'Зміна статусу замовлення', ?, NOW())",
                [$order['user_id'], $orderId, "Статус вашого замовлення змінено з \"{$oldStatus}\" на \"{$newStatus}\""]
            );

            // Запис в лог
            $this->user->logUserActivity($changedBy ?? $order['user_id'], 'order_status_updated', 'orders', $orderId, [
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'comment' => $comment
            ]);

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            logError("Error updating order status: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Перевірка, чи можна редагувати замовлення
     * @param string $status Поточний статус замовлення
     * @return bool true, якщо замовлення можна редагувати
     */
    public function isOrderEditable($status) {
        $status = mb_strtolower($status, 'UTF-8');

        $editableStatuses = ['новий', 'очікується', 'в роботі', 'прийнято'];

        foreach ($editableStatuses as $editableStatus) {
            if (mb_strpos($status, $editableStatus) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Перевірка, чи можна скасувати замовлення
     * @param string $status Поточний статус замовлення
     * @return bool true, якщо замовлення можна скасувати
     */
    public function isOrderCancellable($status) {
        $status = mb_strtolower($status, 'UTF-8');

        $cancellableStatuses = ['новий', 'очікується', 'прийнято'];
        $nonCancellableStatuses = ['виконано', 'завершено', 'скасовано', 'видано'];

        // Перевіряємо спочатку, чи статус НЕ входить до списку нескасовуваних
        foreach ($nonCancellableStatuses as $nonCancellableStatus) {
            if (mb_strpos($status, $nonCancellableStatus) !== false) {
                return false;
            }
        }

        // Якщо статус не в списку нескасовуваних, перевіряємо, чи він входить до списку скасовуваних
        foreach ($cancellableStatuses as $cancellableStatus) {
            if (mb_strpos($status, $cancellableStatus) !== false) {
                return true;
            }
        }

        // За замовчуванням дозволяємо скасовувати замовлення, якщо його статус не входить до списку нескасовуваних
        return true;
    }

    /**
     * Отримання кількості непрочитаних сповіщень для замовлення
     * @param int $userId ID користувача
     * @param int $orderId ID замовлення
     * @return int Кількість непрочитаних сповіщень
     */
    public function getUnreadNotificationsCount($userId, $orderId) {
        try {
            return (int)$this->db->query(
                "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND order_id = ? AND is_read = 0",
                [$userId, $orderId]
            )->findColumn();
        } catch (Exception $e) {
            logError("Error getting unread notifications count: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Отримання загальної кількості непрочитаних сповіщень
     * @param int $userId ID користувача
     * @return int Кількість непрочитаних сповіщень
     */
    public function getTotalUnreadNotifications($userId) {
        try {
            return (int)$this->db->query(
                "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0",
                [$userId]
            )->findColumn();
        } catch (Exception $e) {
            logError("Error getting total unread notifications: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Позначення сповіщень як прочитаних
     * @param int $userId ID користувача
     * @param array|null $notificationIds Масив ID сповіщень (якщо null, позначаються всі)
     * @return bool Результат оновлення
     */
    public function markNotificationsAsRead($userId, $notificationIds = null) {
        try {
            if (empty($notificationIds)) {
                // Якщо ID не вказані, позначаємо всі сповіщення як прочитані
                $this->db->query(
                    "UPDATE notifications SET is_read = 1, updated_at = NOW() WHERE user_id = ?",
                    [$userId]
                );
            } else {
                // Позначаємо лише вказані сповіщення
                $placeholders = implode(',', array_fill(0, count($notificationIds), '?'));
                $params = $notificationIds;
                array_unshift($params, $userId);

                $this->db->query(
                    "UPDATE notifications SET is_read = 1, updated_at = NOW() WHERE user_id = ? AND id IN ($placeholders)",
                    $params
                );
            }

            return $this->db->rowCount() > 0;
        } catch (Exception $e) {
            logError("Error marking notifications as read: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Отримання всіх статусів замовлень для користувача
     * @param int $userId ID користувача
     * @return array Масив унікальних статусів замовлень
     */
    public function getAllOrderStatuses($userId) {
        try {
            $statuses = $this->db->query(
                "SELECT DISTINCT status FROM orders WHERE user_id = ? ORDER BY status",
                [$userId]
            )->findAll();

            return array_column($statuses, 'status');
        } catch (Exception $e) {
            logError("Error getting all order statuses: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Отримання всіх послуг замовлень для користувача
     * @param int $userId ID користувача
     * @return array Масив унікальних послуг замовлень
     */
    public function getAllOrderServices($userId) {
        try {
            $services = $this->db->query(
                "SELECT DISTINCT service FROM orders WHERE user_id = ? ORDER BY service",
                [$userId]
            )->findAll();

            return array_column($services, 'service');
        } catch (Exception $e) {
            logError("Error getting all order services: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Отримання всіх категорій послуг ремонту
     * @return array Масив категорій послуг ремонту
     */
    public function getAllRepairCategories() {
        try {
            return $this->db->query(
                "SELECT * FROM repair_categories WHERE is_active = 1 ORDER BY sort_order, name"
            )->findAll();
        } catch (Exception $e) {
            logError("Error getting all repair categories: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Отримання всіх послуг ремонту
     * @return array Масив послуг ремонту
     */
    public function getAllRepairServices() {
        try {
            return $this->db->query(
                "SELECT s.*, c.name as category_name 
                FROM repair_services s 
                JOIN repair_categories c ON s.category_id = c.id 
                WHERE s.is_active = 1 AND c.is_active = 1 
                ORDER BY c.sort_order, s.sort_order, s.name"
            )->findAll();
        } catch (Exception $e) {
            logError("Error getting all repair services: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Отримання послуг ремонту за категорією
     * @param int $categoryId ID категорії
     * @return array Масив послуг ремонту для вказаної категорії
     */
    public function getRepairServicesByCategory($categoryId) {
        try {
            return $this->db->query(
                "SELECT * FROM repair_services WHERE category_id = ? AND is_active = 1 ORDER BY sort_order, name",
                [$categoryId]
            )->findAll();
        } catch (Exception $e) {
            logError("Error getting repair services by category: " . $e->getMessage());
            return [];
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
            $order = $this->getById($orderId);

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

            // Отримуємо додані коментарі
            $newComment = $this->db->query(
                "SELECT c.*, u.username as author_name, u.display_name as author_display_name, u.role as author_role, u.avatar 
                FROM order_comments c 
                LEFT JOIN users u ON c.user_id = u.id 
                WHERE c.id = ?",
                [$commentId]
            )->find();

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
     * Завантаження файлів для замовлення
     * @param int $orderId ID замовлення
     * @param array $files Масив файлів з $_FILES
     * @param int $userId ID користувача, який завантажує файли
     * @return int Кількість успішно завантажених файлів
     * @throws Exception При помилці завантаження
     */
    private function saveOrderFiles($orderId, $files, $userId) {
        try {
            // Перевіряємо, чи директорія існує
            $uploadDir = UPLOAD_DIR . '/orders/' . $orderId . '/';
            if (!is_dir($uploadDir)) {
                if (!mkdir($uploadDir, 0755, true)) {
                    throw new Exception("Не вдалося створити директорію для завантаження файлів");
                }
            }

            // Отримуємо список дозволених розширень
            $allowedExtensions = explode(',', ALLOWED_FILE_TYPES);

            // Обробка завантажених файлів
            $count = count($files['name']);
            $successfulUploads = 0;

            for ($i = 0; $i < $count; $i++) {
                if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                    continue;
                }

                // Перевірка розміру файлу
                if ($files['size'][$i] > MAX_FILE_SIZE) {
                    continue;
                }

                // Отримуємо інформацію про файл
                $originalName = $files['name'][$i];
                $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

                // Перевірка розширення
                if (!in_array($extension, $allowedExtensions)) {
                    continue;
                }

                // Генеруємо унікальне ім'я файлу
                $newFilename = uniqid('file_') . '_' . time() . '.' . $extension;
                $filePath = $uploadDir . $newFilename;

                // Переміщуємо завантажений файл
                if (move_uploaded_file($files['tmp_name'][$i], $filePath)) {
                    // Визначаємо тип файлу
                    $fileType = $this->getFileType($extension);

                    // Додаємо запис в базу даних
                    $this->db->query(
                        "INSERT INTO order_media (
                            order_id,
                            file_type,
                            file_path,
                            original_name,
                            file_size,
                            file_mime,
                            uploaded_by,
                            created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
                        [
                            $orderId,
                            $fileType,
                            '/uploads/orders/' . $orderId . '/' . $newFilename,
                            $originalName,
                            $files['size'][$i],
                            $files['type'][$i],
                            $userId
                        ]
                    );

                    $successfulUploads++;
                }
            }

            return $successfulUploads;
        } catch (Exception $e) {
            logError("Error saving order files: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Видалення файлу
     * @param int $fileId ID файлу
     * @param int $orderId ID замовлення
     * @param int $userId ID користувача
     * @return bool Результат видалення
     */
    public function deleteOrderFile($fileId, $orderId, $userId) {
        try {
            // Перевіряємо, чи файл належить до вказаного замовлення
            $file = $this->db->query(
                "SELECT * FROM order_media WHERE id = ? AND order_id = ?",
                [$fileId, $orderId]
            )->find();

            if (!$file) {
                return false;
            }

            // Отримуємо шлях до файлу
            $filePath = $_SERVER['DOCUMENT_ROOT'] . $file['file_path'];

            // Видаляємо файл, якщо він існує
            if (file_exists($filePath)) {
                unlink($filePath);
            }

            // Видаляємо запис про файл з бази даних
            $this->db->query(
                "DELETE FROM order_media WHERE id = ?",
                [$fileId]
            );

            // Записуємо дію в лог
            $this->user->logUserActivity($userId, 'order_file_deleted', 'order_media', $fileId, [
                'order_id' => $orderId,
                'file_name' => $file['original_name']
            ]);

            return true;
        } catch (Exception $e) {
            logError("Error deleting order file: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Визначення типу файлу за розширенням
     * @param string $extension Розширення файлу
     * @return string Тип файлу
     */
    private function getFileType($extension) {
        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];
        $videoExtensions = ['mp4', 'avi', 'mov', 'wmv', 'flv', 'mkv', 'webm'];
        $documentExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'];
        $textExtensions = ['txt', 'log', 'md', 'rtf', 'csv'];

        if (in_array($extension, $imageExtensions)) {
            return 'image';
        } elseif (in_array($extension, $videoExtensions)) {
            return 'video';
        } elseif (in_array($extension, $documentExtensions)) {
            return 'document';
        } elseif (in_array($extension, $textExtensions)) {
            return 'text';
        } else {
            return 'other';
        }
    }

    /**
     * Перевірка обмежень на створення замовлень
     * @param int $userId ID користувача
     * @return array Результат перевірки
     */
    private function checkOrderLimits($userId) {
        try {
            // Отримуємо поточні обмеження
            $limits = $this->db->query(
                "SELECT * FROM order_limits WHERE user_id = ?",
                [$userId]
            )->findAll();

            // Якщо обмежень немає, ініціалізуємо їх
            if (empty($limits)) {
                $this->initializeOrderLimits($userId);
                return ['allowed' => true, 'message' => ''];
            }

            // Перевіряємо обмеження
            foreach ($limits as $limit) {
                // Перевіряємо, чи минув час скидання обмежень
                $resetNeeded = false;

                if ($limit['reset_date']) {
                    $resetDate = strtotime($limit['reset_date']);

                    switch ($limit['limit_type']) {
                        case 'daily':
                            $resetNeeded = time() > $resetDate + 86400; // 24 години
                            break;
                        case 'weekly':
                            $resetNeeded = time() > $resetDate + 604800; // 7 днів
                            break;
                        case 'monthly':
                            $resetNeeded = time() > $resetDate + 2592000; // 30 днів
                            break;
                    }

                    if ($resetNeeded) {
                        $this->db->query(
                            "UPDATE order_limits SET current_count = 0, reset_date = NOW() WHERE id = ?",
                            [$limit['id']]
                        );

                        // Оновлюємо значення ліміту
                        $limit['current_count'] = 0;
                    }
                }

                // Перевіряємо, чи досягнуто обмеження
                if ($limit['current_count'] >= $limit['max_orders']) {
                    return [
                        'allowed' => false,
                        'message' => $this->getLimitErrorMessage($limit['limit_type'], $limit['max_orders'])
                    ];
                }
            }

            return ['allowed' => true, 'message' => ''];
        } catch (Exception $e) {
            logError("Error checking order limits: " . $e->getMessage());
            return ['allowed' => true, 'message' => '']; // За замовчуванням дозволяємо створення замовлення
        }
    }
    /**
     * Перевіряє, чи можна додавати коментарі до замовлення
     * @param string $status Статус замовлення
     * @return bool Результат перевірки
     */
    public function canAddComments($status) {
        $nonCommentableStatuses = ['Завершено', 'Скасовано', 'Відмінено'];
        return !in_array($status, $nonCommentableStatuses);
    }

    /**
     * Отримання повідомлення про помилку при досягненні ліміту
     * @param string $limitType Тип ліміту
     * @param int $maxOrders Максимальна кількість замовлень
     * @return string Повідомлення про помилку
     */
    private function getLimitErrorMessage($limitType, $maxOrders) {
        switch ($limitType) {
            case 'daily':
                return "Ви досягли денного ліміту замовлень ({$maxOrders}). Спробуйте завтра.";
            case 'weekly':
                return "Ви досягли тижневого ліміту замовлень ({$maxOrders}). Спробуйте наступного тижня.";
            case 'monthly':
                return "Ви досягли місячного ліміту замовлень ({$maxOrders}). Спробуйте наступного місяця.";
            case 'total':
                return "Ви досягли загального ліміту замовлень ({$maxOrders}). Зверніться до адміністратора.";
            default:
                return "Ви досягли ліміту замовлень. Спробуйте пізніше.";
        }
    }

    /**
     * Оновлення лічильників обмежень замовлень
     * @param int $userId ID користувача
     * @return bool Результат оновлення
     */
    private function updateOrderLimits($userId) {
        try {
            // Оновлюємо лічильники обмежень
            $this->db->query(
                "UPDATE order_limits 
                SET current_count = current_count + 1, 
                    reset_date = CASE WHEN reset_date IS NULL THEN NOW() ELSE reset_date END 
                WHERE user_id = ?",
                [$userId]
            );

            return true;
        } catch (Exception $e) {
            logError("Error updating order limits: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Ініціалізація обмежень замовлень
     * @param int $userId ID користувача
     */
    private function initializeOrderLimits($userId) {
        $limits = [
            ['daily', 3], // 3 замовлення на день
            ['weekly', 10], // 10 замовлень на тиждень
            ['monthly', 30], // 30 замовлень на місяць
            ['total', 100] // 100 замовлень загалом
        ];

        foreach ($limits as $limit) {
            $this->db->query(
                "INSERT INTO order_limits (user_id, limit_type, max_orders, current_count, reset_date) 
                VALUES (?, ?, ?, 0, NOW())",
                [$userId, $limit[0], $limit[1]]
            );
        }
    }

    /**
     * Повідомлення адміністраторів про нове замовлення
     * @param int $orderId ID замовлення
     * @param int $userId ID користувача, який створив замовлення
     * @return bool Результат відправки повідомлення
     */
    private function notifyAdminsAboutNewOrder($orderId, $userId) {
        try {
            // Отримуємо дані про користувача
            $user = $this->db->query("SELECT username, display_name FROM users WHERE id = ?", [$userId])->find();
            $displayName = $user['display_name'] ?? $user['username'] ?? "Користувач ID{$userId}";

            // Отримуємо список адміністраторів
            $admins = $this->db->query("SELECT id FROM users WHERE role = 'admin'")->findAll();

            foreach ($admins as $admin) {
                $this->db->query(
                    "INSERT INTO notifications (user_id, order_id, type, title, content, created_at)
                    VALUES (?, ?, 'new_order', 'Нове замовлення', ?, NOW())",
                    [
                        $admin['id'],
                        $orderId,
                        "Користувач {$displayName} створив нове замовлення #{$orderId}"
                    ]
                );
            }

            return true;
        } catch (Exception $e) {
            logError("Error notifying admins about new order: " . $e->getMessage());
            return false;
        }
    }
}