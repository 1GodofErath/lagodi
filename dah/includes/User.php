<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
// Запобігання прямому доступу до файлу
if (!defined('SECURITY_CHECK')) {
    die('Прямий доступ до цього файлу заборонений');
}

/**
 * Клас для роботи з користувачами
 */
class User {
    private $db;
    
    /**
     * Конструктор класу
     */
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Отримання користувача за ID
     * @param int $id ID користувача
     * @return array|false Дані користувача або false, якщо не знайдено
     */
    public function getById($id) {
        $result = $this->db->query("SELECT * FROM users WHERE id = ?", [$id])->find();
        return $result ? $result : false;
    }
    
    /**
     * Отримання користувача за email
     * @param string $email Email користувача
     * @return array|false Дані користувача або false, якщо не знайдено
     */
    public function getByEmail($email) {
        $result = $this->db->query("SELECT * FROM users WHERE email = ?", [$email])->find();
        return $result ? $result : false;
    }
    
    /**
     * Отримання користувача за логіном
     * @param string $username Логін користувача
     * @return array|false Дані користувача або false, якщо не знайдено
     */
    public function getByUsername($username) {
        $result = $this->db->query("SELECT * FROM users WHERE username = ?", [$username])->find();
        return $result ? $result : false;
    }
    
    /**
     * Перевірка, чи заблокований користувач
     * @param int $userId ID користувача
     * @return array|false Інформація про блокування або false, якщо користувач не заблокований
     */
    public function isBlocked($userId) {
        $user = $this->getById($userId);
        
        if (!$user) return false;
        
        // Якщо користувач заблокований постійно
        if ($user['is_blocked'] == 1 && empty($user['blocked_until'])) {
            return [
                'status' => true,
                'permanent' => true,
                'reason' => $user['block_reason'] ?? 'Акаунт заблоковано'
            ];
        }
        
        // Якщо користувач заблокований тимчасово і час блокування ще не минув
        if (($user['is_blocked'] == 1 || $user['blocked_until']) && 
            $user['blocked_until'] && strtotime($user['blocked_until']) > time()) {
            return [
                'status' => true,
                'permanent' => false,
                'until' => $user['blocked_until'],
                'reason' => $user['block_reason'] ?? 'Акаунт тимчасово заблоковано'
            ];
        }
        
        // Якщо блокування користувача закінчилось, знімаємо обмеження
        if ($user['blocked_until'] && strtotime($user['blocked_until']) <= time()) {
            $this->unblock($userId);
        }
        
        return false;
    }
    
    /**
     * Реєстрація нового користувача
     * @param array $data Дані нового користувача
     * @return array Результат реєстрації
     * @throws Exception При помилці реєстрації
     */
    public function register($data) {
        try {
            $this->db->beginTransaction();
            
            // Перевірка унікальності email та username
            if ($this->getByEmail($data['email'])) {
                throw new Exception("Користувач з такою електронною поштою вже існує");
            }
            
            if ($this->getByUsername($data['username'])) {
                throw new Exception("Користувач з таким логіном вже існує");
            }
            
            // Хешування пароля
            $hashedPassword = password_hash($data['password'], PASSWORD_ALGORITHM, PASSWORD_OPTIONS);
            
            // Підготовка даних для вставки
            $params = [
                'username' => $data['username'],
                'email' => $data['email'],
                'password' => $hashedPassword,
                'display_name' => $data['display_name'] ?? $data['username'],
                'phone' => $data['phone'] ?? null,
                'role' => 'user', // За замовчуванням новий користувач має роль "user"
                'theme' => 'light', // За замовчуванням використовується світла тема
                'email_verified' => 0,
                'email_verification_token' => bin2hex(random_bytes(32))
            ];
            
            $sql = "INSERT INTO users (username, email, password, display_name, phone, role, theme, email_verified, email_verification_token, created_at) 
                    VALUES (:username, :email, :password, :display_name, :phone, :role, :theme, :email_verified, :email_verification_token, NOW())";
                    
            $this->db->query($sql, $params);
            $userId = $this->db->lastInsertId();
            
            // Ініціалізація обмежень замовлень для нового користувача
            $this->initializeOrderLimits($userId);
            
            // Записуємо дію в лог
            $this->logUserActivity($userId, 'user_registered', 'users', $userId, [
                'username' => $data['username'],
                'email' => $data['email']
            ]);
            
            $this->db->commit();
            return [
                'user_id' => $userId,
                'email_verification_token' => $params['email_verification_token']
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            logError("Error registering user: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Аутентифікація користувача
     * @param string $username Логін або email
     * @param string $password Пароль
     * @param bool $rememberMe Чи запам'ятовувати користувача
     * @return array Результат аутентифікації
     */
    public function login($username, $password, $rememberMe = false) {
        try {
            // Отримуємо користувача за логіном або email
            $user = $this->getByUsername($username);
            if (!$user) {
                $user = $this->getByEmail($username);
            }
            
            if (!$user) {
                return [
                    'success' => false,
                    'error' => 'Користувача з такими даними не знайдено'
                ];
            }
            
            // Перевірка блокування
            $blockStatus = $this->isBlocked($user['id']);
            if ($blockStatus) {
                return [
                    'success' => false,
                    'blocked' => true,
                    'reason' => $blockStatus['reason'],
                    'until' => $blockStatus['until'] ?? null,
                    'permanent' => $blockStatus['permanent'] ?? false
                ];
            }
            
            // Перевірка кількості невдалих спроб входу
            if ($user['failed_login_attempts'] >= 5) {
                // Блокуємо користувача на 30 хвилин після 5 невдалих спроб
                $blockUntil = date('Y-m-d H:i:s', time() + 1800); // 30 хвилин
                $this->block($user['id'], 'Забагато невдалих спроб входу', $blockUntil);
                
                return [
                    'success' => false,
                    'blocked' => true,
                    'reason' => 'Забагато невдалих спроб входу. Акаунт тимчасово заблоковано.',
                    'until' => $blockUntil
                ];
            }
            
            // Перевірка пароля
            if (password_verify($password, $user['password'])) {
                // Скидання лічильника невдалих спроб
                $this->db->query(
                    "UPDATE users SET 
                    failed_login_attempts = 0, 
                    last_login = NOW(),
                    last_ip = ?,
                    updated_at = NOW()
                    WHERE id = ?", 
                    [$_SERVER['REMOTE_ADDR'] ?? null, $user['id']]
                );
                
                // Створюємо сесію
                $sessionToken = $this->createSession($user['id'], $rememberMe);
                
                // Записуємо дію в лог
                $this->logUserActivity($user['id'], 'user_login', 'users', $user['id']);
                
                return [
                    'success' => true,
                    'user' => $user,
                    'session_token' => $sessionToken
                ];
            } else {
                // Збільшуємо лічильник невдалих спроб
                $this->db->query(
                    "UPDATE users SET 
                    failed_login_attempts = failed_login_attempts + 1, 
                    last_login_attempt = NOW(),
                    updated_at = NOW()
                    WHERE id = ?", 
                    [$user['id']]
                );
                
                // Записуємо дію в лог
                $this->logUserActivity($user['id'], 'login_failed', 'users', $user['id'], [
                    'attempts' => $user['failed_login_attempts'] + 1
                ]);
                
                return [
                    'success' => false,
                    'error' => 'Невірний пароль'
                ];
            }
        } catch (Exception $e) {
            logError("Error during login: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Помилка аутентифікації. Будь ласка, спробуйте пізніше.'
            ];
        }
    }
    
    /**
     * Оновлення даних користувача
     * @param int $userId ID користувача
     * @param array $data Дані для оновлення
     * @return bool Результат оновлення
     * @throws Exception При помилці оновлення
     */
    public function update($userId, $data) {
        try {
            $this->db->beginTransaction();
            
            // Перевірка унікальності email, якщо він змінився
            $currentUser = $this->getById($userId);
            if (isset($data['email']) && $data['email'] != $currentUser['email']) {
                if ($this->getByEmail($data['email'])) {
                    throw new Exception("Користувач з такою електронною поштою вже існує");
                }
                
                // Якщо email змінився, потрібно повторно перевірити його
                $data['email_verified'] = 0;
                $data['email_verification_token'] = bin2hex(random_bytes(32));
            }
            
            // Підготовка запиту та параметрів
            $sql = "UPDATE users SET ";
            $params = [];
            
            // Додаємо поля, які можна оновити
            $updatableFields = [
                'email' => 'email',
                'display_name' => 'display_name',
                'phone' => 'phone',
                'bio' => 'bio',
                'email_verified' => 'email_verified',
                'email_verification_token' => 'email_verification_token',
                'theme' => 'theme'
            ];
            
            $updates = [];
            foreach ($updatableFields as $field => $paramName) {
                if (isset($data[$field])) {
                    $updates[] = "$field = :$paramName";
                    $params[$paramName] = $data[$field];
                }
            }
            
            if (!empty($updates)) {
                $sql .= implode(", ", $updates);
                $sql .= ", updated_at = NOW() WHERE id = :userId";
                $params['userId'] = $userId;
                
                $this->db->query($sql, $params);
            }
            
            // Зміна пароля, якщо він вказаний
            if (!empty($data['password'])) {
                $hashedPassword = password_hash($data['password'], PASSWORD_ALGORITHM, PASSWORD_OPTIONS);
                $this->db->query(
                    "UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?",
                    [$hashedPassword, $userId]
                );
            }
            
            // Записуємо дію в лог
            $this->logUserActivity($userId, 'user_updated', 'users', $userId);
            
            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            logError("Error updating user: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Зміна теми користувача
     * @param int $userId ID користувача
     * @param string $theme Нова тема
     * @return bool Результат оновлення
     */
    public function changeTheme($userId, $theme) {
        try {
            $this->db->query(
                "UPDATE users SET theme = ?, updated_at = NOW() WHERE id = ?",
                [$theme, $userId]
            );
            
            // Записуємо дію в лог
            $this->logUserActivity($userId, 'theme_changed', 'users', $userId, [
                'theme' => $theme
            ]);
            
            return $this->db->rowCount() > 0;
        } catch (Exception $e) {
            logError("Error changing theme: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Створення сесії для користувача
     * @param int $userId ID користувача
     * @param bool $rememberMe Чи запам'ятовувати користувача
     * @return string|false Токен сесії або false при помилці
     */
    private function createSession($userId, $rememberMe = false) {
        try {
            // Генеруємо токен сесії
            $sessionToken = bin2hex(random_bytes(32));
            
            // Визначаємо час завершення сесії
            $expiresAt = date('Y-m-d H:i:s', time() + ($rememberMe ? 30 * 86400 : SESSION_LIFETIME)); // 30 днів або стандартний час
            
            // Створюємо запис про сесію
            $this->db->query(
                "INSERT INTO user_sessions (
                    user_id, 
                    session_token, 
                    ip_address, 
                    user_agent, 
                    created_at, 
                    expires_at, 
                    last_activity
                ) VALUES (?, ?, ?, ?, NOW(), ?, NOW())",
                [
                    $userId,
                    $sessionToken,
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null,
                    $expiresAt
                ]
            );
            
            return $sessionToken;
        } catch (Exception $e) {
            logError("Error creating session: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Перевірка дійсності сесії
     * @param string $sessionToken Токен сесії
     * @return array|false Дані сесії або false, якщо сесія недійсна
     */
    public function validateSession($sessionToken) {
        try {
            $session = $this->db->query(
                "SELECT s.*, u.* 
                FROM user_sessions s
                INNER JOIN users u ON s.user_id = u.id
                WHERE s.session_token = ? AND s.expires_at > NOW()",
                [$sessionToken]
            )->find();
            
            if (!$session) {
                return false;
            }
            
            // Оновлюємо час останньої активності
            $this->updateSessionActivity($sessionToken);
            
            return $session;
        } catch (Exception $e) {
            logError("Error validating session: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Оновлення часу останньої активності сесії
     * @param string $sessionToken Токен сесії
     * @return bool Результат оновлення
     */
    public function updateSessionActivity($sessionToken) {
        try {
            $this->db->query(
                "UPDATE user_sessions SET last_activity = NOW() WHERE session_token = ?",
                [$sessionToken]
            );
            
            return $this->db->rowCount() > 0;
        } catch (Exception $e) {
            logError("Error updating session activity: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Видалення сесії
     * @param string $sessionToken Токен сесії
     * @return bool Результат видалення
     */
    public function deleteSession($sessionToken) {
        try {
            $this->db->query(
                "DELETE FROM user_sessions WHERE session_token = ?",
                [$sessionToken]
            );
            
            return $this->db->rowCount() > 0;
        } catch (Exception $e) {
            logError("Error deleting session: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Видалення всіх сесій користувача
     * @param int $userId ID користувача
     * @param string|null $exceptToken Токен сесії, яку не потрібно видаляти
     * @return bool Результат видалення
     */
    public function deleteAllSessions($userId, $exceptToken = null) {
        try {
            $sql = "DELETE FROM user_sessions WHERE user_id = ?";
            $params = [$userId];
            
            if ($exceptToken) {
                $sql .= " AND session_token != ?";
                $params[] = $exceptToken;
            }
            
            $this->db->query($sql, $params);
            
            return true;
        } catch (Exception $e) {
            logError("Error deleting all sessions: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Ініціалізація обмежень замовлень для користувача
     * @param int $userId ID користувача
     */
    public function initializeOrderLimits($userId) {
        $limits = [
            ['daily', 3], // 3 замовлення на день
            ['weekly', 10], // 10 замовлень на тиждень
            ['monthly', 30], // 30 замовлень на місяць
            ['total', 100] // 100 замовлень загалом
        ];
        
        foreach ($limits as $limit) {
            $this->db->query(
                "INSERT INTO order_limits (user_id, limit_type, max_orders, current_count) VALUES (?, ?, ?, 0)",
                [$userId, $limit[0], $limit[1]]
            );
        }
    }
    
    /**
     * Запис активності користувача в лог
     * @param int $userId ID користувача
     * @param string $action Дія
     * @param string|null $entityType Тип сутності
     * @param int|null $entityId ID сутності
     * @param array|null $details Додаткові деталі
     * @return int|false ID запису або false при помилці
     */
    public function logUserActivity($userId, $action, $entityType = null, $entityId = null, $details = null) {
        try {
            $params = [
                'user_id' => $userId,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'details' => $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
            ];
            
            $this->db->query(
                "INSERT INTO user_activity_logs (user_id, action, entity_type, entity_id, details, ip_address, user_agent, created_at) 
                VALUES (:user_id, :action, :entity_type, :entity_id, :details, :ip_address, :user_agent, NOW())",
                $params
            );
            
            return $this->db->lastInsertId();
        } catch (Exception $e) {
            logError("Error logging user activity: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Блокування користувача
     * @param int $userId ID користувача
     * @param string|null $reason Причина блокування
     * @param string|null $until Дата закінчення блокування (NULL для постійного блокування)
     * @return bool Результат блокування
     */
    public function block($userId, $reason = null, $until = null) {
        try {
            $permanent = ($until === null);
            
            $params = [
                'is_blocked' => 1,
                'block_reason' => $reason,
                'userId' => $userId
            ];
            
            $sql = "UPDATE users SET is_blocked = :is_blocked, block_reason = :block_reason";
            
            if ($permanent) {
                $sql .= ", blocked_until = NULL";
            } else {
                $sql .= ", blocked_until = :blocked_until";
                $params['blocked_until'] = $until;
            }
            
            $sql .= " WHERE id = :userId";
            
            $this->db->query($sql, $params);
            
            // Записуємо дію в лог
            $this->logUserActivity($userId, 'user_blocked', 'users', $userId, [
                'reason' => $reason,
                'until' => $until,
                'permanent' => $permanent
            ]);
            
            return $this->db->rowCount() > 0;
        } catch (Exception $e) {
            logError("Error blocking user: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Розблокування користувача
     * @param int $userId ID користувача
     * @return bool Результат розблокування
     */
    public function unblock($userId) {
        try {
            $this->db->query(
                "UPDATE users SET is_blocked = 0, blocked_until = NULL, failed_login_attempts = 0 WHERE id = ?",
                [$userId]
            );
            
            // Записуємо дію в лог
            $this->logUserActivity($userId, 'user_unblocked', 'users', $userId);
            
            return $this->db->rowCount() > 0;
        } catch (Exception $e) {
            logError("Error unblocking user: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Збереження налаштування користувача
     * @param int $userId ID користувача
     * @param string $key Ключ налаштування
     * @param mixed $value Значення налаштування
     * @return bool Результат збереження
     */
    public function saveSetting($userId, $key, $value) {
        try {
            // Перевіряємо, чи існує таке налаштування
            $existingSetting = $this->db->query(
                "SELECT id FROM user_settings WHERE user_id = ? AND setting_key = ?",
                [$userId, $key]
            )->find();
            
            if ($existingSetting) {
                // Оновлюємо існуюче налаштування
                $this->db->query(
                    "UPDATE user_settings SET setting_value = ?, updated_at = NOW() WHERE id = ?",
                    [$value, $existingSetting['id']]
                );
            } else {
                // Додаємо нове налаштування
                $this->db->query(
                    "INSERT INTO user_settings (user_id, setting_key, setting_value, created_at)
                    VALUES (?, ?, ?, NOW())",
                    [$userId, $key, $value]
                );
            }
            
            return true;
        } catch (Exception $e) {
            logError("Error saving user setting: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Отримання налаштування користувача
     * @param int $userId ID користувача
     * @param string $key Ключ налаштування
     * @param mixed $default Значення за замовчуванням
     * @return mixed Значення налаштування
     */
    public function getSetting($userId, $key, $default = null) {
        try {
            $setting = $this->db->query(
                "SELECT setting_value FROM user_settings WHERE user_id = ? AND setting_key = ?",
                [$userId, $key]
            )->find();
            
            return $setting ? $setting['setting_value'] : $default;
        } catch (Exception $e) {
            logError("Error getting user setting: " . $e->getMessage());
            return $default;
        }
    }
}