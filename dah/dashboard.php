<?php
// Константа для перевірки прямого доступу
define('SECURITY_CHECK', true);


// Початок сесії
session_start();

// Підключення необхідних файлів
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/User.php';
require_once __DIR__ . '/includes/Order.php';
require_once __DIR__ . '/includes/Comment.php';
require_once __DIR__ . '/includes/Mailer.php';

// Функція для автоматичного завантаження класів
function classAutoloader($class) {
    $file = __DIR__ . "/includes/{$class}.php";
    if (file_exists($file)) {
        require_once $file;
    }
}
spl_autoload_register('classAutoloader');

// Перевірка авторизації користувача
if (!isset($_SESSION['user_id'])) {
    // Перевірка токена "запам'ятати мене"
    if (isset($_COOKIE['remember_token']) && !empty($_COOKIE['remember_token'])) {
        $user = new User();
        $session = $user->validateSession($_COOKIE['remember_token']);

        if ($session) {
            // Створюємо нову сесію
            $_SESSION['user_id'] = $session['user_id'];
            $_SESSION['username'] = $session['username'];
            $_SESSION['role'] = $session['role'];
            $_SESSION['last_activity'] = time();

            // Оновлюємо активність сесії
            $user->updateSessionActivity($_COOKIE['remember_token']);
        } else {
            // Видаляємо недійсний токен
            setcookie('remember_token', '', time() - 3600, '/');

            // Якщо це AJAX-запит, повертаємо повідомлення про помилку
            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => 'Термін дії вашої сесії закінчився. Будь ласка, увійдіть знову.',
                    'redirect' => '/login.php'
                ]);
                exit;
            }

            // Перенаправлення на сторінку входу
            header('Location: /login.php');
            exit;
        }
    } else {
        // Якщо це AJAX-запит, повертаємо повідомлення про помилку
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Ваш сеанс завершений. Будь ласка, увійдіть знову.',
                'redirect' => '/login.php'
            ]);
            exit;
        }

        // Перенаправлення на сторінку входу
        header('Location: /login.php');
        exit;
    }
}

// Ініціалізація об'єктів
$db = Database::getInstance();
$user = new User();
$order = new Order();
$comment = new Comment();
$mailer = new Mailer();

// Отримання даних користувача
$userId = $_SESSION['user_id'];
$userData = $user->getById($userId);

if (!$userData) {
    // Якщо не знайдено користувача, виходимо з системи
    session_unset();
    session_destroy();
    setcookie('remember_token', '', time() - 3600, '/');
    header('Location: /login.php?error=invalid_user');
    exit;
}

// Тема оформлення
$theme = $userData['theme'] ?? 'light';

// Перевірка блокування користувача
$blockStatus = $user->isBlocked($userId);
if ($blockStatus) {
    $blockInfo = [
        'blocked' => true,
        'reason' => $blockStatus['reason'],
        'until' => isset($blockStatus['until']) ? date('d.m.Y H:i', strtotime($blockStatus['until'])) : 'Назавжди',
        'permanent' => $blockStatus['permanent'] ?? true
    ];
} else {
    $blockInfo = ['blocked' => false];
}

// Оновлення часу останньої активності
$_SESSION['last_activity'] = time();

// Перевірка на неактивність (30 хвилин)
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > SESSION_LIFETIME)) {
    // Завершення сесії
    session_unset();
    session_destroy();
    setcookie('remember_token', '', time() - 3600, '/');

    // Якщо це AJAX-запит, повертаємо повідомлення про помилку
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Ваш сеанс завершено через неактивність.',
            'redirect' => '/login.php?timeout=1'
        ]);
        exit;
    }

    // Перенаправлення на сторінку входу
    header('Location: /login.php?timeout=1');
    exit;
}

// Генерація CSRF токена
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Обробка AJAX запитів
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json');

    // Перевірка CSRF-токену для POST-запитів
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Помилка безпеки: недійсний CSRF-токен'
        ]);
        exit;
    }

    // Отримання даних замовлення
    if (isset($_GET['get_order_details']) && is_numeric($_GET['get_order_details'])) {
        $orderId = (int)$_GET['get_order_details'];
        $orderData = $order->getById($orderId);

        // Перевіряємо, чи замовлення належить поточному користувачу
        if (!$orderData || $orderData['user_id'] != $userId) {
            echo json_encode([
                'success' => false,
                'message' => 'Замовлення не знайдено або у вас немає прав для його перегляду'
            ]);
            exit;
        }

        // Додаємо файли для замовлення
        $orderData['files'] = $order->getOrderFiles($orderId);

        // Додаємо коментарі для замовлення
        $orderData['comments'] = $comment->getOrderComments($orderId);

        // Додаємо історію статусів
        $orderData['status_history'] = $order->getOrderStatusHistory($orderId);

        // Позначаємо сповіщення для цього замовлення як прочитані
        $db->query(
            "UPDATE notifications SET is_read = 1, updated_at = NOW() WHERE user_id = ? AND order_id = ?",
            [$userId, $orderId]
        );

        echo json_encode([
            'success' => true,
            'order' => $orderData
        ]);
        exit;
    }
// Add these handlers to the AJAX section of dashboard.php

// Get active sessions
    if (isset($_POST['get_sessions'])) {
        // Get current user's sessions
        $sessions = $db->query(
            "SELECT * FROM user_sessions WHERE user_id = ? ORDER BY last_activity DESC",
            [$userId]
        )->findAll();

        $currentToken = $_COOKIE['remember_token'] ?? '';

        // Process sessions for display
        $sessionsData = [];
        foreach ($sessions as $session) {
            $browser = '';
            if (!empty($session['user_agent'])) {
                if (strpos($session['user_agent'], 'Firefox') !== false) {
                    $browser = 'Firefox';
                } elseif (strpos($session['user_agent'], 'Chrome') !== false && strpos($session['user_agent'], 'Edg') !== false) {
                    $browser = 'Edge';
                } elseif (strpos($session['user_agent'], 'Chrome') !== false) {
                    $browser = 'Chrome';
                } elseif (strpos($session['user_agent'], 'Safari') !== false) {
                    $browser = 'Safari';
                } elseif (strpos($session['user_agent'], 'MSIE') !== false || strpos($session['user_agent'], 'Trident') !== false) {
                    $browser = 'Internet Explorer';
                } else {
                    $browser = 'Інший браузер';
                }

                // Add device type
                if (strpos($session['user_agent'], 'Mobile') !== false) {
                    $browser .= ' (Мобільний)';
                } elseif (strpos($session['user_agent'], 'Tablet') !== false) {
                    $browser .= ' (Планшет)';
                } else {
                    $browser .= ' (Комп\'ютер)';
                }
            }

            $sessionsData[] = [
                'id' => $session['id'],
                'session_token' => $session['session_token'],
                'ip_address' => $session['ip_address'] ?? 'Невідомо',
                'browser' => $browser,
                'last_activity' => $session['last_activity'],
                'is_current' => ($session['session_token'] === $currentToken)
            ];
        }

        echo json_encode([
            'success' => true,
            'sessions' => $sessionsData
        ]);
        exit;
    }

// Terminate a specific session
    if (isset($_POST['terminate_session']) && isset($_POST['session_token'])) {
        $sessionToken = $_POST['session_token'];
        $currentToken = $_COOKIE['remember_token'] ?? '';

        // Prevent terminating current session
        if ($sessionToken === $currentToken) {
            echo json_encode([
                'success' => false,
                'message' => 'Не можна завершити поточний сеанс'
            ]);
            exit;
        }

        // Delete the session
        $success = $user->deleteSession($sessionToken);

        if ($success) {
            $user->logUserActivity($userId, 'session_terminated', 'user_sessions', null, [
                'session_token' => substr($sessionToken, 0, 8) . '...' // Log only part of the token for security
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Сеанс успішно завершено'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Помилка при завершенні сеансу'
            ]);
        }
        exit;
    }

// Terminate all other sessions
    if (isset($_POST['terminate_all_sessions'])) {
        $currentToken = $_COOKIE['remember_token'] ?? '';

        // Delete all sessions except the current one
        $success = $user->deleteAllSessions($userId, $currentToken);

        if ($success) {
            $user->logUserActivity($userId, 'all_sessions_terminated', 'user_sessions', null);

            echo json_encode([
                'success' => true,
                'message' => 'Всі інші сеанси успішно завершено'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Помилка при завершенні сеансів'
            ]);
        }
        exit;
    }

// Get activity log
    if (isset($_POST['get_activity_log'])) {
        $page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
        $perPage = 15;
        $offset = ($page - 1) * $perPage;

        // Get logs with pagination
        $logs = $db->query(
            "SELECT * FROM user_activity_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?",
            [$userId, $perPage, $offset]
        )->findAll();

        // Count total logs for pagination
        $totalLogs = $db->query(
            "SELECT COUNT(*) FROM user_activity_logs WHERE user_id = ?",
            [$userId]
        )->findColumn();

        $hasMore = ($offset + $perPage) < $totalLogs;

        echo json_encode([
            'success' => true,
            'logs' => $logs,
            'page' => $page,
            'has_more' => $hasMore,
            'total' => $totalLogs
        ]);
        exit;
    }
    // Додавання коментаря
    if (isset($_POST['add_comment']) && isset($_POST['order_id']) && isset($_POST['comment'])) {
        $orderId = (int)$_POST['order_id'];
        $commentText = trim($_POST['comment']);

        if (empty($commentText)) {
            echo json_encode([
                'success' => false,
                'message' => 'Коментар не може бути порожнім'
            ]);
            exit;
        }

        $result = $comment->addComment($orderId, $userId, $commentText);
        echo json_encode($result);
        exit;
    }

    // Видалення коментаря
    if (isset($_POST['delete_comment']) && isset($_POST['comment_id'])) {
        $commentId = (int)$_POST['comment_id'];
        $result = $comment->deleteComment($commentId, $userId);
        echo json_encode($result);
        exit;
    }

    // Позначення сповіщення як прочитаного
    if (isset($_POST['mark_notification_read']) && isset($_POST['notification_id'])) {
        $notificationId = (int)$_POST['notification_id'];
        $result = $comment->markNotificationAsRead($notificationId, $userId);

        echo json_encode([
            'success' => $result,
            'unreadCount' => $comment->getUnreadNotificationsCount($userId)
        ]);
        exit;
    }

    // Позначення всіх сповіщень як прочитаних
    if (isset($_POST['mark_all_notifications_read'])) {
        $result = $comment->markAllNotificationsAsRead($userId);

        echo json_encode([
            'success' => $result,
            'unreadCount' => 0 // Після позначення всіх сповіщень, лічильник дорівнює 0
        ]);
        exit;
    }

    // Зміна теми
    if (isset($_POST['change_theme'])) {
        $newTheme = $_POST['theme'] ?? 'light';

        // Перевіряємо, що тема допустима
        $allowedThemes = ['light', 'dark', 'blue', 'grey'];
        if (!in_array($newTheme, $allowedThemes)) {
            $newTheme = 'light';
        }

        $result = $user->changeTheme($userId, $newTheme);

        echo json_encode([
            'success' => $result,
            'theme' => $newTheme
        ]);
        exit;
    }

    // Створення нового замовлення
    // Створення нового замовлення
    if (isset($_POST['create_order'])) {
        try {
            // Перевіряємо обов'язкові поля
            $requiredFields = ['device_type', 'details', 'phone', 'service'];
            foreach ($requiredFields as $field) {
                if (empty($_POST[$field])) {
                    throw new Exception("Поле '{$field}' є обов'язковим");
                }
            }

            // Перевірка наявності файлів
            $hasFiles = !empty($_FILES['files']) && is_array($_FILES['files']['name']) && !empty($_FILES['files']['name'][0]);

            $orderData = [
                'device_type' => $_POST['device_type'],
                'details' => $_POST['details'],
                'phone' => $_POST['phone'],
                'service' => $_POST['service'],
                'address' => $_POST['address'] ?? null,
                'delivery_method' => $_POST['delivery_method'] ?? null,
                'user_comment' => $_POST['comment'] ?? null
            ];

            if (!empty($_POST['service_id']) && is_numeric($_POST['service_id'])) {
                $orderData['service_id'] = (int)$_POST['service_id'];
            }

            $orderId = $order->create($userId, $orderData);

            // Обробка файлів, якщо вони є
            if ($hasFiles) {
                // Для обробки файлів, якщо є функціональність
                // Код буде залежати від вашої реалізації
            }

            // Відправлення повідомлення про створення замовлення
            $newOrderData = $order->getById($orderId);
            if ($newOrderData) {
                $mailer->sendNewOrderNotification($userData, $newOrderData);
            }

            echo json_encode([
                'success' => true,
                'orderId' => $orderId,
                'message' => 'Замовлення успішно створено',
                'redirect' => '?tab=orders'
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Помилка при створенні замовлення: ' . $e->getMessage()
            ]);
        }
        exit;
    }

    // Оновлення замовлення
    if (isset($_POST['update_order']) && isset($_POST['order_id'])) {
        try {
            $orderId = (int)$_POST['order_id'];

            // Перевіряємо обов'язкові поля
            $requiredFields = ['device_type', 'details', 'phone'];
            foreach ($requiredFields as $field) {
                if (empty($_POST[$field])) {
                    throw new Exception("Поле '{$field}' є обов'язковим");
                }
            }

            $orderData = [
                'device_type' => $_POST['device_type'],
                'details' => $_POST['details'],
                'phone' => $_POST['phone'],
                'address' => $_POST['address'] ?? null,
                'delivery_method' => $_POST['delivery_method'] ?? null,
                'user_comment' => $_POST['comment'] ?? null
            ];

            if (!empty($_POST['remove_files']) && is_array($_POST['remove_files'])) {
                $orderData['remove_files'] = $_POST['remove_files'];
            }

            $result = $order->update($orderId, $userId, $orderData);

            echo json_encode([
                'success' => true,
                'message' => 'Замовлення успішно оновлено'
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Помилка при оновленні замовлення: ' . $e->getMessage()
            ]);
        }
        exit;
    }

    // Скасування замовлення
    if (isset($_POST['cancel_order']) && isset($_POST['order_id'])) {
        try {
            $orderId = (int)$_POST['order_id'];
            $reason = $_POST['reason'] ?? null;

            $oldOrder = $order->getById($orderId);
            $result = $order->cancelOrder($orderId, $userId, $reason);

            // Відправлення повідомлення про зміну статусу
            if ($result && $oldOrder) {
                $updatedOrder = $order->getById($orderId);
                $mailer->sendOrderStatusChangedNotification($userData, $updatedOrder, $oldOrder['status']);
            }

            echo json_encode([
                'success' => true,
                'message' => 'Замовлення успішно скасовано'
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Помилка при скасуванні замовлення: ' . $e->getMessage()
            ]);
        }
        exit;
    }

    // Оновлення профілю
    if (isset($_POST['update_profile'])) {
        try {
            // Перевіряємо обов'язкові поля
            if (empty($_POST['display_name']) || empty($_POST['email'])) {
                throw new Exception("Ім'я та email є обов'язковими полями");
            }

            // Перевірка формату email
            if (!filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Введіть коректну email адресу");
            }

            // Підготовка даних для оновлення
            $updateData = [
                'display_name' => $_POST['display_name'],
                'email' => $_POST['email'],
                'phone' => $_POST['phone'] ?? null,
                'bio' => $_POST['bio'] ?? null
            ];

            // Перевіряємо, чи змінився email
            $emailChanged = ($userData['email'] !== $_POST['email']);

            // Оновлення профілю
            $result = $user->update($userId, $updateData);

            // Оновлення додаткових полів
            if (isset($_POST['additional_fields']) && is_array($_POST['additional_fields'])) {
                $additionalFields = $_POST['additional_fields'];

                // Видаляємо старі поля
                $db->query("DELETE FROM user_additional_fields WHERE user_id = ?", [$userId]);

                // Додаємо нові поля
                foreach ($additionalFields as $key => $value) {
                    if (!empty($value)) {
                        $db->query(
                            "INSERT INTO user_additional_fields (user_id, field_key, field_value) VALUES (?, ?, ?)",
                            [$userId, $key, $value]
                        );
                    }
                }
            }

            // Якщо email змінився, відправляємо підтвердження
            if ($emailChanged) {
                $updatedUser = $user->getById($userId);
                $mailer->sendEmailVerification($updatedUser['email'], $updatedUser['display_name'], $updatedUser['email_verification_token']);
                $mailer->sendEmailChangeNotification($userData['email'], $userData['display_name'], $updatedUser['email']);
            }

            echo json_encode([
                'success' => true,
                'message' => $emailChanged ?
                    'Профіль успішно оновлено. Для підтвердження нової електронної пошти перевірте вашу поштову скриньку.' :
                    'Профіль успішно оновлено'
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Помилка при оновленні профілю: ' . $e->getMessage()
            ]);
        }
        exit;
    }

    // Зміна пароля
    if (isset($_POST['change_password'])) {
        try {
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            // Перевіряємо заповнення полів
            if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
                throw new Exception("Всі поля повинні бути заповнені");
            }

            // Перевіряємо співпадіння паролів
            if ($newPassword !== $confirmPassword) {
                throw new Exception("Новий пароль та підтвердження не співпадають");
            }

            // Перевіряємо довжину пароля
            if (strlen($newPassword) < 8) {
                throw new Exception("Новий пароль має бути не менше 8 символів");
            }

            // Перевіряємо поточний пароль
            if (!password_verify($currentPassword, $userData['password'])) {
                throw new Exception("Неправильний поточний пароль");
            }

            // Оновлюємо пароль
            $hashedPassword = password_hash($newPassword, PASSWORD_ALGORITHM, PASSWORD_OPTIONS);
            $db->query("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?", [$hashedPassword, $userId]);

            // Записуємо дію в лог
            $user->logUserActivity($userId, 'password_changed', 'users', $userId);

            // Відправка сповіщення на email
            $mailer->sendPasswordChangedNotification($userData);

            echo json_encode([
                'success' => true,
                'message' => 'Пароль успішно змінено'
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Помилка при зміні пароля: ' . $e->getMessage()
            ]);
        }
        exit;
    }

    // Зміна email
    if (isset($_POST['change_email'])) {
        try {
            $newEmail = $_POST['new_email'] ?? '';
            $password = $_POST['password'] ?? '';

            // Перевіряємо заповнення полів
            if (empty($newEmail) || empty($password)) {
                throw new Exception("Всі поля повинні бути заповнені");
            }

            // Перевіряємо формат email
            if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Введіть коректну email адресу");
            }

            // Перевіряємо, що новий email відрізняється від поточного
            if ($newEmail === $userData['email']) {
                throw new Exception("Новий email збігається з поточним");
            }

            // Перевіряємо, що email не використовується іншим користувачем
            $existingUser = $user->getByEmail($newEmail);
            if ($existingUser && $existingUser['id'] != $userId) {
                throw new Exception("Цей email вже використовується іншим користувачем");
            }

            // Перевіряємо пароль
            if (!password_verify($password, $userData['password'])) {
                throw new Exception("Неправильний пароль");
            }

            // Створюємо токен для підтвердження email
            $emailToken = bin2hex(random_bytes(32));

            // Оновлюємо email та токен
            $db->query(
                "UPDATE users SET email = ?, email_verification_token = ?, email_verified = 0, updated_at = NOW() WHERE id = ?",
                [$newEmail, $emailToken, $userId]
            );

            // Записуємо дію в лог
            $user->logUserActivity($userId, 'email_changed', 'users', $userId, [
                'old_email' => $userData['email'],
                'new_email' => $newEmail
            ]);

            // Відправка листа для підтвердження на новий email
            $mailer->sendEmailVerification($newEmail, $userData['display_name'], $emailToken);

            // Відправка сповіщення на старий email
            $mailer->sendEmailChangeNotification($userData['email'], $userData['display_name'], $newEmail);

            echo json_encode([
                'success' => true,
                'message' => 'Email успішно змінено. На вашу нову адресу відправлено лист для підтвердження.'
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Помилка при зміні email: ' . $e->getMessage()
            ]);
        }
        exit;
    }

    // Завантаження аватара
    if (isset($_FILES['avatar'])) {
        try {
            // Перевіряємо, що файл є зображенням
            if (!getimagesize($_FILES['avatar']['tmp_name'])) {
                throw new Exception("Файл не є зображенням");
            }

            // Перевіряємо розмір файлу
            if ($_FILES['avatar']['size'] > 2 * 1024 * 1024) { // 2 MB
                throw new Exception("Розмір зображення не повинен перевищувати 2 МБ");
            }

            // Перевіряємо тип файлу
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if (!in_array($_FILES['avatar']['type'], $allowedTypes)) {
                throw new Exception("Дозволені типи файлів: JPEG, PNG, GIF, WEBP");
            }

            // Створюємо директорію, якщо вона не існує
            $uploadDir = UPLOAD_DIR . '/avatars/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            // Отримуємо розширення файлу
            $fileExtension = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));

            // Генеруємо нове ім'я файлу
            $newFileName = 'avatar_' . $userId . '_' . bin2hex(random_bytes(8)) . '.' . $fileExtension;
            $uploadPath = $uploadDir . $newFileName;

            // Видаляємо старий аватар, якщо він існує
            if ($userData['avatar'] && file_exists($uploadDir . $userData['avatar'])) {
                unlink($uploadDir . $userData['avatar']);
            }

            // Завантажуємо новий файл
            if (move_uploaded_file($_FILES['avatar']['tmp_name'], $uploadPath)) {
                // Оновлюємо дані користувача
                $db->query(
                    "UPDATE users SET avatar = ?, updated_at = NOW() WHERE id = ?",
                    [$newFileName, $userId]
                );

                // Записуємо дію в лог
                $user->logUserActivity($userId, 'avatar_updated', 'users', $userId);

                echo json_encode([
                    'success' => true,
                    'message' => 'Аватар успішно оновлено',
                    'avatarUrl' => '/uploads/avatars/' . $newFileName
                ]);
            } else {
                throw new Exception("Помилка при завантаженні файлу");
            }
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Помилка при завантаженні аватара: ' . $e->getMessage()
            ]);
        }
        exit;
    }

    // Отримання послуг ремонту за категорією
    if (isset($_GET['get_services_by_category']) && is_numeric($_GET['get_services_by_category'])) {
        $categoryId = (int)$_GET['get_services_by_category'];
        $services = $order->getRepairServicesByCategory($categoryId);

        echo json_encode([
            'success' => true,
            'services' => $services
        ]);
        exit;
    }

    // Перевірка активної сесії (для автоматичного виходу)
    if (isset($_POST['check_session'])) {
        echo json_encode([
            'success' => true,
            'active' => true,
            'remainingTime' => SESSION_LIFETIME - (time() - $_SESSION['last_activity'])
        ]);
        exit;
    }

    // Якщо запит не відповідає жодному з обробників
    echo json_encode([
        'success' => false,
        'message' => 'Невідомий запит'
    ]);
    exit;
}

// Обробка запиту на вихід
if (isset($_GET['logout'])) {
    // Видаляємо токен "запам'ятати мене"
    if (isset($_COOKIE['remember_token'])) {
        $user->deleteSession($_COOKIE['remember_token']);
        setcookie('remember_token', '', time() - 3600, '/');
    }

    // Записуємо дію в лог
    $user->logUserActivity($userId, 'user_logout');

    // Завершення сесії
    session_unset();
    session_destroy();

    // Перенаправлення на головну сторінку
    header('Location: /');
    exit;
}

// Отримання активної вкладки
$activeTab = 'orders';
if (isset($_GET['tab']) && in_array($_GET['tab'], ['orders', 'new-order', 'profile', 'settings', 'notifications'])) {
    $activeTab = $_GET['tab'];
}

// Отримання параметрів пагінації для замовлень
$currentPage = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 10; // За замовчуванням 10 записів на сторінку

// Перевіряємо, чи є у користувача налаштування для кількості записів на сторінці
$userSettings = $db->query(
    "SELECT setting_value FROM user_settings WHERE user_id = ? AND setting_key = 'items_per_page'",
    [$userId]
)->find();

if ($userSettings && !empty($userSettings['setting_value'])) {
    $perPage = (int)$userSettings['setting_value'];
}

// Отримання фільтрів
$filterStatus = $_GET['status'] ?? '';
$filterService = $_GET['service'] ?? '';
$searchQuery = $_GET['search'] ?? '';

// Отримання замовлень з фільтрацією та пагінацією
$filters = [
    'status' => $filterStatus,
    'service' => $filterService,
    'search' => $searchQuery
];

// Отримання даних для замовлень
$ordersData = $order->getUserOrders($userId, $filters, $currentPage, $perPage);
$orders = $ordersData['orders'];
$pagination = $ordersData['pagination'];

// Отримання даних для фільтрів
$allStatuses = $order->getAllOrderStatuses($userId);
$allServices = $order->getAllOrderServices($userId);

// Отримання непрочитаних сповіщень
$unreadNotificationsData = $comment->getUserNotifications($userId, 1, 10, 0);
$unreadNotifications = $unreadNotificationsData['notifications'] ?? [];
$totalUnreadNotifications = $comment->getUnreadNotificationsCount($userId);

// Отримання категорій і послуг ремонту
$repairCategories = $order->getAllRepairCategories();
$repairServices = $order->getAllRepairServices();

// Отримання додаткових полів користувача
$additionalFieldsData = $db->query(
    "SELECT field_key, field_value FROM user_additional_fields WHERE user_id = ?",
    [$userId]
)->findAll();

$additionalFields = [];
foreach ($additionalFieldsData as $field) {
    $additionalFields[$field['field_key']] = $field['field_value'];
}

// Функції форматування дати та часу
function formatDate($date) {
    if (!$date) return '';
    return date('d.m.Y', strtotime($date));
}

function formatDateTime($date) {
    if (!$date) return '';
    return date('d.m.Y H:i', strtotime($date));
}

function formatTimeAgo($date) {
    if (!$date) return '';

    $time = strtotime($date);
    $now = time();
    $diff = $now - $time;

    if ($diff < 60) {
        return 'щойно';
    } elseif ($diff < 3600) {
        $minutes = floor($diff / 60);
        return $minutes . ' ' . numWord($minutes, ['хвилину', 'хвилини', 'хвилин']) . ' тому';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' ' . numWord($hours, ['годину', 'години', 'годин']) . ' тому';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' ' . numWord($days, ['день', 'дні', 'днів']) . ' тому';
    } else {
        return formatDate($date);
    }
}

function numWord($num, $words) {
    $num = abs($num) % 100;
    $num_x = $num % 10;

    if ($num > 10 && $num < 20) {
        return $words[2];
    }

    if ($num_x > 1 && $num_x < 5) {
        return $words[1];
    }

    if ($num_x == 1) {
        return $words[0];
    }

    return $words[2];
}

// Функція отримання назви статусного класу
function getStatusClass($status) {
    if (!$status) return 'status-default';

    $status = mb_strtolower($status);

    if (strpos($status, 'нов') !== false) {
        return 'status-new';
    } else if (strpos($status, 'робот') !== false || strpos($status, 'в роботі') !== false) {
        return 'status-in-progress';
    } else if (strpos($status, 'очіку') !== false) {
        return 'status-pending';
    } else if (strpos($status, 'заверш') !== false || strpos($status, 'готов') !== false || strpos($status, 'викон') !== false) {
        return 'status-completed';
    } else if (strpos($status, 'скасова') !== false || strpos($status, 'відмін') !== false) {
        return 'status-cancelled';
    }

    return 'status-default';
}

// Функція переведення типу доставки в читабельний формат
function getDeliveryMethodName($method) {
    if (!$method) return '';

    switch ($method) {
        case 'self':
            return 'Самовивіз';
        case 'courier':
            return 'Кур\'єр';
        case 'nova-poshta':
            return 'Нова Пошта';
        case 'ukrposhta':
            return 'Укрпошта';
        default:
            return $method;
    }
}
?>

<!DOCTYPE html>
<html lang="uk" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Особистий кабінет - Сервіс ремонту Lagodi</title>

    <!-- Favicon -->
    <link rel="icon" href="/favicon.ico" type="image/x-icon">
    <link rel="shortcut icon" href="/favicon.ico" type="image/x-icon">

    <!-- CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/main.css">
    <link rel="stylesheet" href="/../style/dahm/dash1.css">

    <!-- Мета теги для автоматичного завершення сесії -->
    <meta name="session-lifetime" content="<?= SESSION_LIFETIME ?>">
    <meta name="session-last-activity" content="<?= $_SESSION['last_activity'] ?>">
    <meta name="csrf-token" content="<?= $_SESSION['csrf_token'] ?>">
</head>
<body>
<!-- Блок для повідомлень -->
<div id="notification-container"></div>

<!-- Верхня панель -->
<header class="header">
    <div class="container">
        <div class="header-content">
            <div class="header-left">
                <button id="menu-toggle" class="menu-toggle">
                    <i class="fas fa-bars"></i>
                </button>
                <a href="/" class="logo">
                    <img src="/assets/images/logo.svg" alt="Lagodi">
                </a>
            </div>

            <div class="header-right">
                <!-- Кнопка сповіщень -->
                <div class="notifications-dropdown">
                    <button class="notifications-btn" id="notifications-toggle">
                        <i class="fas fa-bell"></i>
                        <?php if ($totalUnreadNotifications > 0): ?>
                            <span class="badge"><?= $totalUnreadNotifications ?></span>
                        <?php endif; ?>
                    </button>

                    <div class="dropdown-menu" id="notifications-dropdown">
                        <div class="dropdown-header">
                            <h3>Сповіщення</h3>
                            <?php if ($totalUnreadNotifications > 0): ?>
                                <button id="mark-all-read" class="btn-link">Позначити всі як прочитані</button>
                            <?php endif; ?>
                        </div>

                        <div class="notifications-list">
                            <?php if (empty($unreadNotifications)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-bell-slash"></i>
                                    <p>Немає нових сповіщень</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($unreadNotifications as $notification): ?>
                                    <div class="notification-item" data-id="<?= $notification['id'] ?>">
                                        <div class="notification-icon">
                                            <?php
                                            $iconClass = 'fas fa-bell';
                                            switch ($notification['type']) {
                                                case 'comment': $iconClass = 'fas fa-comment-alt'; break;
                                                case 'status_update': $iconClass = 'fas fa-sync-alt'; break;
                                                case 'admin_message': $iconClass = 'fas fa-envelope'; break;
                                            }
                                            ?>
                                            <i class="<?= $iconClass ?>"></i>
                                        </div>
                                        <div class="notification-content">
                                            <div class="notification-title"><?= htmlspecialchars($notification['title']) ?></div>
                                            <div class="notification-text"><?= htmlspecialchars($notification['content']) ?></div>
                                            <div class="notification-time"><?= formatTimeAgo($notification['created_at']) ?></div>
                                        </div>
                                        <button class="mark-read-btn" title="Позначити як прочитане">
                                            <i class="fas fa-check"></i>
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <div class="dropdown-footer">
                            <a href="?tab=notifications" class="btn-link">
                                Переглянути всі сповіщення
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Користувацьке меню -->
                <div class="user-dropdown">
                    <button class="user-btn" id="user-toggle">
                        <div class="user-avatar">
                            <?php if (!empty($userData['avatar'])): ?>
                                <img src="/uploads/avatars/<?= htmlspecialchars($userData['avatar']) ?>" alt="<?= htmlspecialchars($userData['display_name'] ?? 'Користувач') ?>">
                            <?php else: ?>
                                <i class="fas fa-user"></i>
                            <?php endif; ?>
                        </div>
                        <span class="user-name"><?= htmlspecialchars($userData['display_name'] ?? 'Користувач') ?></span>
                        <i class="fas fa-chevron-down"></i>
                    </button>

                    <div class="dropdown-menu" id="user-dropdown">
                        <a href="?tab=profile" class="dropdown-item">
                            <i class="fas fa-user"></i> Мій профіль
                        </a>
                        <a href="?tab=settings" class="dropdown-item">
                            <i class="fas fa-cog"></i> Налаштування
                        </a>
                        <div class="dropdown-divider"></div>
                        <a href="?logout=1" class="dropdown-item text-danger">
                            <i class="fas fa-sign-out-alt"></i> Вийти
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>

<!-- Основний контент -->
<div class="main-container">
    <!-- Бічне меню -->
    <aside class="sidebar" id="sidebar">
        <nav class="sidebar-nav">
            <ul class="nav">
                <li class="nav-item<?= $activeTab === 'orders' ? ' active' : '' ?>">
                    <a href="?tab=orders" class="nav-link">
                        <i class="fas fa-clipboard-list"></i>
                        <span>Мої замовлення</span>
                    </a>
                </li>
                <li class="nav-item<?= $activeTab === 'new-order' ? ' active' : '' ?>">
                    <a href="?tab=new-order" class="nav-link">
                        <i class="fas fa-plus-circle"></i>
                        <span>Нове замовлення</span>
                    </a>
                </li>
                <li class="nav-item<?= $activeTab === 'notifications' ? ' active' : '' ?>">
                    <a href="?tab=notifications" class="nav-link">
                        <i class="fas fa-bell"></i>
                        <span>Повідомлення</span>
                        <?php if ($totalUnreadNotifications > 0): ?>
                            <span class="badge"><?= $totalUnreadNotifications ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="nav-item<?= $activeTab === 'profile' ? ' active' : '' ?>">
                    <a href="?tab=profile" class="nav-link">
                        <i class="fas fa-user"></i>
                        <span>Мій профіль</span>
                    </a>
                </li>
                <li class="nav-item<?= $activeTab === 'settings' ? ' active' : '' ?>">
                    <a href="?tab=settings" class="nav-link">
                        <i class="fas fa-cog"></i>
                        <span>Налаштування</span>
                    </a>
                </li>
            </ul>
        </nav>

        <div class="sidebar-footer">
            <a href="?logout=1" class="btn btn-outline btn-sm">
                <i class="fas fa-sign-out-alt"></i> Вийти
            </a>
        </div>
    </aside>

    <!-- Вміст сторінки -->
    <main class="content">
        <div class="container">
            <?php
            // Відображення вмісту відповідно до обраної вкладки
            switch ($activeTab) {
                case 'orders':
                    include 'templates/orders_tab.php';
                    break;
                case 'new-order':
                    include 'templates/new_order_tab.php';
                    break;
                case 'notifications':
                    include 'templates/notifications_tab.php';
                    break;
                case 'profile':
                    include 'templates/profile_tab.php';
                    break;
                case 'settings':
                    include 'templates/settings_tab.php';
                    break;
                default:
                    include 'templates/orders_tab.php';
            }
            ?>
        </div>
    </main>
</div>

<!-- Модальні вікна -->

<!-- Модальне вікно перегляду замовлення -->
<div id="view-order-modal" class="modal">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Замовлення #<span id="view-order-id"></span></h3>
                <button type="button" class="modal-close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="tabs" id="order-tabs">
                    <div class="tabs-header">
                        <button class="tab-btn active" data-tab="info">Інформація</button>
                        <button class="tab-btn" data-tab="files">Файли</button>
                        <button class="tab-btn" data-tab="comments">Коментарі</button>
                        <button class="tab-btn" data-tab="history">Історія змін</button>
                    </div>
                    <div class="tabs-content">
                        <div id="tab-info" class="tab-pane active">
                            <div class="loading-spinner"></div>
                            <div id="order-info-content"></div>
                        </div>
                        <div id="tab-files" class="tab-pane">
                            <div class="loading-spinner"></div>
                            <div id="order-files-content"></div>
                        </div>
                        <div id="tab-comments" class="tab-pane">
                            <div class="loading-spinner"></div>
                            <div id="order-comments-content"></div>
                            <div class="comment-form">
                                <textarea id="comment-text" placeholder="Напишіть коментар..." class="form-control"></textarea>
                                <button id="send-comment" class="btn btn-primary">Відправити</button>
                            </div>
                        </div>
                        <div id="tab-history" class="tab-pane">
                            <div class="loading-spinner"></div>
                            <div id="order-history-content"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <div class="btn-group">
                    <button id="edit-order-btn" class="btn btn-primary btn-sm" style="display: none;">
                        <i class="fas fa-edit"></i> Редагувати
                    </button>
                    <button id="cancel-order-btn" class="btn btn-danger btn-sm" style="display: none;">
                        <i class="fas fa-times"></i> Скасувати
                    </button>
                </div>
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Закрити</button>
            </div>
        </div>
    </div>
</div>

<!-- Модальне вікно редагування замовлення -->
<div id="edit-order-modal" class="modal">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Редагування замовлення #<span id="edit-order-id"></span></h3>
                <button type="button" class="modal-close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <form id="edit-order-form" enctype="multipart/form-data">
                    <input type="hidden" id="edit-order-id-input" name="order_id">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                    <div class="form-group">
                        <label for="edit-device-type">Тип пристрою <span class="required">*</span></label>
                        <input type="text" id="edit-device-type" name="device_type" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label for="edit-details">Опис проблеми <span class="required">*</span></label>
                        <textarea id="edit-details" name="details" class="form-control" rows="5" required></textarea>
                    </div>

                    <div class="form-group">
                        <label for="edit-phone">Контактний телефон <span class="required">*</span></label>
                        <input type="tel" id="edit-phone" name="phone" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label for="edit-address">Адреса</label>
                        <input type="text" id="edit-address" name="address" class="form-control">
                        <div class="form-hint">Вкажіть для доставки кур'єром</div>
                    </div>

                    <div class="form-group">
                        <label for="edit-delivery">Спосіб доставки</label>
                        <select id="edit-delivery" name="delivery_method" class="form-control">
                            <option value="">Виберіть спосіб доставки</option>
                            <option value="self">Самовивіз</option>
                            <option value="courier">Кур'єр</option>
                            <option value="nova-poshta">Нова Пошта</option>
                            <option value="ukrposhta">Укрпошта</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="edit-comment">Коментар</label>
                        <textarea id="edit-comment" name="comment" class="form-control" rows="2"></textarea>
                    </div>

                    <div class="form-group">
                        <label>Поточні файли</label>
                        <div id="edit-files-list" class="files-grid">
                            <!-- Сюди будуть додані файли через JavaScript -->
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="edit-new-files">Додати нові файли</label>
                        <div class="file-upload">
                            <input type="file" id="edit-new-files" name="files[]" multiple class="file-input"
                                   accept="image/*, video/*, .pdf, .doc, .docx, .txt, .log">
                            <label for="edit-new-files" class="file-label">
                                <i class="fas fa-cloud-upload-alt"></i>
                                <span>Виберіть файли або перетягніть їх сюди</span>
                            </label>
                        </div>
                        <div id="edit-files-preview" class="file-preview"></div>
                        <div class="form-hint">
                            Допустимі формати: зображення (JPG, PNG, GIF), відео (MP4, AVI), документи (PDF, DOC, DOCX), текст (TXT, LOG)<br>
                            Максимальний розмір файлу: 10 МБ
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" id="save-order-btn" class="btn btn-primary">Зберегти зміни</button>
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Скасувати</button>
            </div>
        </div>
    </div>
</div>

<!-- Модальне вікно скасування замовлення -->
<div id="cancel-order-modal" class="modal">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Скасування замовлення</h3>
                <button type="button" class="modal-close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <p>Ви дійсно бажаєте скасувати замовлення #<span id="cancel-order-id"></span>?</p>
                <p>Цю дію неможливо скасувати.</p>

                <div class="form-group">
                    <label for="cancel-reason">Причина скасування</label>
                    <textarea id="cancel-reason" class="form-control" rows="3" placeholder="Вкажіть причину скасування (необов'язково)"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" id="confirm-cancel-btn" class="btn btn-danger">Скасувати замовлення</button>
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Відмінити</button>
            </div>
        </div>
    </div>
</div>

<!-- Модальне вікно зміни пароля -->
<div id="change-password-modal" class="modal">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Зміна пароля</h3>
                <button type="button" class="modal-close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <form id="change-password-form">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                    <div class="form-group">
                        <label for="current-password">Поточний пароль <span class="required">*</span></label>
                        <input type="password" id="current-password" name="current_password" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label for="new-password">Новий пароль <span class="required">*</span></label>
                        <input type="password" id="new-password" name="new_password" class="form-control" required>
                        <div class="password-strength" id="password-strength"></div>
                    </div>

                    <div class="form-group">
                        <label for="confirm-password">Підтвердження пароля <span class="required">*</span></label>
                        <input type="password" id="confirm-password" name="confirm_password" class="form-control" required>
                        <div id="password-match" class="form-hint"></div>
                    </div>

                    <div class="form-hint">
                        <i class="fas fa-info-circle"></i> Пароль повинен складатися щонайменше з 8 символів
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" id="save-password-btn" class="btn btn-primary">Змінити пароль</button>
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Скасувати</button>
            </div>
        </div>
    </div>
</div>

<!-- Модальне вікно зміни електронної пошти -->
<div id="change-email-modal" class="modal">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Зміна електронної пошти</h3>
                <button type="button" class="modal-close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <form id="change-email-form">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                    <div class="form-group">
                        <label for="current-email">Поточна електронна пошта</label>
                        <input type="email" id="current-email" class="form-control" value="<?= htmlspecialchars($userData['email']) ?>" readonly>
                    </div>

                    <div class="form-group">
                        <label for="new-email">Нова електронна пошта <span class="required">*</span></label>
                        <input type="email" id="new-email" name="new_email" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label for="email-password">Пароль для підтвердження <span class="required">*</span></label>
                        <input type="password" id="email-password" name="password" class="form-control" required>
                    </div>

                    <div class="form-hint">
                        <i class="fas fa-info-circle"></i> На нову адресу буде відправлено лист підтвердження
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" id="save-email-btn" class="btn btn-primary">Змінити email</button>
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Скасувати</button>
            </div>
        </div>
    </div>
</div>

<!-- Модальне вікно зміни теми -->
<div id="change-theme-modal" class="modal">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Вибір теми оформлення</h3>
                <button type="button" class="modal-close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="themes-grid">
                    <div class="theme-item <?= $theme === 'light' ? 'active' : '' ?>" data-theme="light">
                        <div class="theme-preview light-preview"></div>
                        <div class="theme-name">Світла</div>
                    </div>
                    <div class="theme-item <?= $theme === 'dark' ? 'active' : '' ?>" data-theme="dark">
                        <div class="theme-preview dark-preview"></div>
                        <div class="theme-name">Темна</div>
                    </div>
                    <div class="theme-item <?= $theme === 'blue' ? 'active' : '' ?>" data-theme="blue">
                        <div class="theme-preview blue-preview"></div>
                        <div class="theme-name">Блакитна</div>
                    </div>
                    <div class="theme-item <?= $theme === 'grey' ? 'active' : '' ?>" data-theme="grey">
                        <div class="theme-preview grey-preview"></div>
                        <div class="theme-name">Сіра</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Закрити</button>
            </div>
        </div>
    </div>
</div>

<!-- Модальне вікно для блокованих користувачів -->
<?php if ($blockInfo['blocked']): ?>
<div id="blocked-modal" class="modal active">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Акаунт заблоковано</h3>
            </div>
            <div class="modal-body">
                <div class="blocked-message">
                    <div class="blocked-icon">
                        <i class="fas fa-lock"></i>
                    </div>
                    <h4>Ваш акаунт заблоковано</h4>
                    <p><strong>Причина:</strong> <?= htmlspecialchars($blockInfo['reason']) ?></p>
                    <?php if (!$blockInfo['permanent']): ?>
                        <p><strong>Блокування діє до:</strong> <?= htmlspecialchars($blockInfo['until']) ?></p>
                    <?php else: ?>
                        <p><strong>Тип блокування:</strong> Постійне</p>
                    <?php endif; ?>
                    <p>Якщо ви вважаєте, що сталася помилка, зверніться до адміністрації за адресою <a href="mailto:support@lagodiy.com">support@lagodiy.com</a></p>
                </div>
            </div>
            <div class="modal-footer">
                <a href="?logout=1" class="btn btn-primary">Вийти</a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Скрипти -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="/../jawa/dahj/lagodi-ui.js"></script>

<script> 0
    // Глобальні змінні
    const config = {
        csrfToken: '<?= $_SESSION['csrf_token'] ?>',
        userId: <?= $userId ?>,
        theme: '<?= htmlspecialchars($theme) ?>',
        sessionLifetime: <?= SESSION_LIFETIME ?>,
        lastActivity: <?= $_SESSION['last_activity'] ?>,
        currentTime: <?= time() ?>
    };
</script>
</body>
</html>