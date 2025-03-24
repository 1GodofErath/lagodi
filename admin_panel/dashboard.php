<?php
session_start();
require_once '../db.php';
require_once '../vendor/autoload.php'; // For PHPMailer

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Перевірка авторизації
if (!isset($_SESSION['user_id'])) {
    header("Location: /../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

// Перевірка на неактивність користувача (автоматичне завершення сесії)
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 300)) { // 5 хвилин неактивності (300 секунд)
    session_unset();     // Видаляємо всі змінні сесії
    session_destroy();   // Знищуємо сесію
    header("Location: /../login.php?message=timeout");
    exit();
}
$_SESSION['last_activity'] = time(); // Оновлюємо час останньої активності

// Перевірка блокування користувача
$stmt = $conn->prepare("SELECT blocked_until, block_reason FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$block_result = $stmt->get_result();
$block_data = $block_result->fetch_assoc();

if ($block_data && $block_data['blocked_until'] && strtotime($block_data['blocked_until']) > time()) {
    $block_reason = $block_data['block_reason'] ?? 'Не вказано причину';
    $_SESSION['block_message'] = "Вітаємо, {$username}, ви заблоковані з такої причини: {$block_reason}";
    $_SESSION['blocked_until'] = $block_data['blocked_until'];
}

// Додаткова інформація користувача
$stmt = $conn->prepare("SELECT email, first_name, last_name, middle_name, profile_pic, phone, address, delivery_method, notification_preferences FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_result = $stmt->get_result();
$user_data = $user_result->fetch_assoc();

// Парсимо налаштування сповіщень або встановлюємо значення за замовчуванням
if (isset($user_data['notification_preferences'])) {
    $notification_prefs = json_decode($user_data['notification_preferences'], true);
} else {
    $notification_prefs = [
        'email_notifications' => true,
        'email_order_status' => true,
        'email_new_comment' => true,
        'push_notifications' => false,
        'push_order_status' => false,
        'push_new_comment' => false
    ];
}

// Обробка повідомлень з сесії
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
$block_message = $_SESSION['block_message'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// Генерація CSRF токена
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Функція для логування дій користувача
function logUserAction($conn, $user_id, $action, $details = '') {
    $stmt = $conn->prepare("INSERT INTO users_logs (user_id, action, details) VALUES (?, ?, ?)");
    $stmt->bind_param("iss", $user_id, $action, $details);
    $stmt->execute();
}

// Функція для відправки email з можливістю прикріплення файлів
function sendNotificationEmail($to, $subject, $message, $attachments = []) {
    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = 'hostch02.fornex.host';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'lagodiy.info@lagodiy.com';
        $mail->Password   = '3zIDVnH#tu?2&uIn';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = 465;

        // Recipients
        $mail->setFrom('no-reply@lagodiy.com', 'Lagodiy Service');
        $mail->addAddress($to);
        $mail->isHTML(true);

        // Attachments
        if (!empty($attachments)) {
            foreach ($attachments as $attachment) {
                if (file_exists($attachment['path'])) {
                    $mail->addAttachment($attachment['path'], $attachment['name']);
                }
            }
        }

        // Content
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $subject;
        $mail->Body    = $message;

        $mail->send();
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// Перевірка чи існує таблиця сповіщень і створення її якщо потрібно
function ensureNotificationsTable($conn) {
    try {
        $conn->query("
            CREATE TABLE IF NOT EXISTS notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                order_id INT NULL,
                type VARCHAR(50) NOT NULL, -- 'comment', 'status', 'system', etc.
                title VARCHAR(255) NOT NULL,
                message TEXT NOT NULL,
                icon VARCHAR(100) DEFAULT 'fa-bell',
                link VARCHAR(255) NULL,
                is_read TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                expires_at TIMESTAMP NULL,
                metadata TEXT NULL, -- JSON для додаткових даних
                INDEX (user_id, is_read),
                INDEX (created_at),
                INDEX (type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        // Додамо таблицю для push-підписок
        $conn->query("
            CREATE TABLE IF NOT EXISTS push_subscriptions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                endpoint VARCHAR(500) NOT NULL,
                p256dh VARCHAR(255) NOT NULL,
                auth VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
                browser VARCHAR(100) NULL,
                device_type VARCHAR(50) NULL,
                UNIQUE(user_id, endpoint),
                INDEX (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        return true;
    } catch (Exception $e) {
        error_log("Error creating notifications table: " . $e->getMessage());
        return false;
    }
}

// Переконаємося, що таблиці сповіщень існують
ensureNotificationsTable($conn);

// Функція для створення сповіщення
function createNotification($conn, $user_id, $type, $title, $message, $order_id = null, $icon = 'fa-bell', $link = null, $expires_days = 30, $metadata = null) {
    try {
        $expires_at = date('Y-m-d H:i:s', strtotime("+{$expires_days} days"));

        $metadata_json = null;
        if ($metadata !== null) {
            $metadata_json = json_encode($metadata);
        }

        $stmt = $conn->prepare("
            INSERT INTO notifications 
            (user_id, type, title, message, order_id, icon, link, expires_at, metadata) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("isssssss", $user_id, $type, $title, $message, $order_id, $icon, $link, $expires_at, $metadata_json);
        $result = $stmt->execute();

        return $result ? $conn->insert_id : false;
    } catch (Exception $e) {
        error_log("Error creating notification: " . $e->getMessage());
        return false;
    }
}

// Функція для отримання сповіщень користувача
function getUserNotifications($conn, $user_id, $limit = 20, $offset = 0, $filter = '') {
    try {
        $query = "
            SELECT * FROM notifications 
            WHERE user_id = ? 
            AND (expires_at IS NULL OR expires_at > NOW())
        ";

        // Додаємо фільтр за типом (прочитані/непрочитані)
        if ($filter === 'unread') {
            $query .= " AND is_read = 0";
        } else if ($filter === 'read') {
            $query .= " AND is_read = 1";
        }

        // Сортування та обмеження
        $query .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";

        $stmt = $conn->prepare($query);
        $stmt->bind_param("iii", $user_id, $limit, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $notifications = [];
        while ($row = $result->fetch_assoc()) {
            // Розкодуємо метадані, якщо вони є
            if (!empty($row['metadata'])) {
                $row['metadata'] = json_decode($row['metadata'], true);
            }
            $notifications[] = $row;
        }

        return $notifications;
    } catch (Exception $e) {
        error_log("Error getting notifications: " . $e->getMessage());
        return [];
    }
}

// Функція для підрахунку непрочитаних сповіщень
function countUnreadNotifications($conn, $user_id) {
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*) as count FROM notifications 
            WHERE user_id = ? AND is_read = 0 
            AND (expires_at IS NULL OR expires_at > NOW())
        ");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        return $row['count'] ?? 0;
    } catch (Exception $e) {
        error_log("Error counting unread notifications: " . $e->getMessage());
        return 0;
    }
}

// Функція для позначення сповіщень як прочитаних
function markNotificationsAsRead($conn, $user_id, $notification_id = null) {
    try {
        if ($notification_id) {
            // Позначаємо конкретне сповіщення як прочитане
            $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
            $stmt->bind_param("ii", $notification_id, $user_id);
        } else {
            // Позначаємо всі сповіщення як прочитані
            $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
            $stmt->bind_param("i", $user_id);
        }

        $stmt->execute();
        return $stmt->affected_rows;
    } catch (Exception $e) {
        error_log("Error marking notifications as read: " . $e->getMessage());
        return 0;
    }
}

// Функція для позначення сповіщень замовлення як прочитаних
function markOrderNotificationsAsRead($conn, $user_id, $order_id) {
    try {
        $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND order_id = ? AND is_read = 0");
        $stmt->bind_param("ii", $user_id, $order_id);
        $stmt->execute();
        return $stmt->affected_rows;
    } catch (Exception $e) {
        error_log("Error marking order notifications as read: " . $e->getMessage());
        return 0;
    }
}

// Функція для надсилання push-сповіщень
function sendPushNotification($conn, $user_id, $title, $body, $url = null, $icon = null) {
    // Ця функція буде реалізована через WebPush або FCM API
    // Тут потрібно отримати підписки користувача з бази даних і надіслати їм повідомлення
    // Приклад реалізації буде надано пізніше

    // Отримуємо всі підписки користувача
    $stmt = $conn->prepare("SELECT * FROM push_subscriptions WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    // Тут буде код для надсилання push-повідомлення через веб-push або FCM
    // Зараз просто логуємо, що спроба була
    logUserAction($conn, $user_id, 'push_notification_attempted', "Title: $title, Body: $body");

    return true;
}

// Обробка запитів для сповіщень через AJAX
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Обробка позначення сповіщень як прочитаних
    if (isset($_POST['mark_notification_read'])) {
        $notification_id = $_POST['notification_id'] ?? null;
        $result = markNotificationsAsRead($conn, $user_id, $notification_id);
        echo json_encode(['success' => true, 'affected' => $result]);
        exit();
    }

    // Обробка позначення всіх сповіщень як прочитаних
    if (isset($_POST['mark_all_notifications_read'])) {
        $result = markNotificationsAsRead($conn, $user_id);
        echo json_encode(['success' => true, 'affected' => $result]);
        exit();
    }

    // Обробка позначення сповіщень замовлення як прочитаних
    if (isset($_POST['mark_order_notifications_read'])) {
        $order_id = $_POST['order_id'] ?? 0;
        $result = markOrderNotificationsAsRead($conn, $user_id, $order_id);
        echo json_encode(['success' => true, 'affected' => $result]);
        exit();
    }

    // Обробка збереження налаштувань сповіщень
    if (isset($_POST['save_notification_preferences'])) {
        $email_notifications = isset($_POST['email_notifications']) ? 1 : 0;
        $email_order_status = isset($_POST['email_order_status']) ? 1 : 0;
        $email_new_comment = isset($_POST['email_new_comment']) ? 1 : 0;
        $push_notifications = isset($_POST['push_notifications']) ? 1 : 0;
        $push_order_status = isset($_POST['push_order_status']) ? 1 : 0;
        $push_new_comment = isset($_POST['push_new_comment']) ? 1 : 0;

        $preferences = [
            'email_notifications' => (bool)$email_notifications,
            'email_order_status' => (bool)$email_order_status,
            'email_new_comment' => (bool)$email_new_comment,
            'push_notifications' => (bool)$push_notifications,
            'push_order_status' => (bool)$push_order_status,
            'push_new_comment' => (bool)$push_new_comment
        ];

        $preferences_json = json_encode($preferences);

        // Оновлюємо налаштування в базі даних
        $stmt = $conn->prepare("UPDATE users SET notification_preferences = ? WHERE id = ?");
        $stmt->bind_param("si", $preferences_json, $user_id);

        if ($stmt->execute()) {
            $notification_prefs = $preferences; // Оновлюємо локальну змінну
            echo json_encode(['success' => true, 'message' => 'Налаштування сповіщень успішно збережено']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Помилка збереження налаштувань']);
        }
        exit();
    }

    // Обробка запиту на збереження push-підписки
    if (isset($_POST['save_push_subscription'])) {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!empty($data['endpoint']) && !empty($data['keys']['p256dh']) && !empty($data['keys']['auth'])) {
            $endpoint = $data['endpoint'];
            $p256dh = $data['keys']['p256dh'];
            $auth = $data['keys']['auth'];
            $browser = $_POST['browser'] ?? null;
            $device_type = $_POST['device_type'] ?? null;

            // Перевіряємо чи існує вже така підписка
            $stmt = $conn->prepare("SELECT id FROM push_subscriptions WHERE user_id = ? AND endpoint = ?");
            $stmt->bind_param("is", $user_id, $endpoint);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                // Оновлюємо існуючу підписку
                $sub_id = $result->fetch_assoc()['id'];
                $stmt = $conn->prepare("UPDATE push_subscriptions SET p256dh = ?, auth = ?, browser = ?, device_type = ?, updated_at = NOW() WHERE id = ?");
                $stmt->bind_param("ssssi", $p256dh, $auth, $browser, $device_type, $sub_id);
            } else {
                // Створюємо нову підписку
                $stmt = $conn->prepare("INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth, browser, device_type) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("isssss", $user_id, $endpoint, $p256dh, $auth, $browser, $device_type);
            }

            if ($stmt->execute()) {
                // Оновлюємо налаштування користувача
                $notification_prefs['push_notifications'] = true;
                $preferences_json = json_encode($notification_prefs);

                $stmt = $conn->prepare("UPDATE users SET notification_preferences = ? WHERE id = ?");
                $stmt->bind_param("si", $preferences_json, $user_id);
                $stmt->execute();

                echo json_encode(['success' => true, 'message' => 'Підписка на push-сповіщення успішно збережена']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Помилка збереження підписки']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Недостатньо даних для підписки']);
        }
        exit();
    }
}

// Перевірка кількості активних замовлень зі статусами "новий", "в роботі", "очікує товар"
$stmt = $conn->prepare("SELECT COUNT(*) as active_orders FROM orders WHERE user_id = ? AND is_closed = 0 AND (status = 'Нове' OR status = 'В роботі' OR status = 'Очікує товар')");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$active_result = $stmt->get_result();
$active_data = $active_result->fetch_assoc();
$active_orders_count = $active_data['active_orders'];

// Обробка видалення коментаря
if (isset($_POST['delete_comment']) && !$block_message) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Невалідний CSRF токен!");
    }

    $order_id = $_POST['order_id'] ?? 0;

    // Перевірка статусу замовлення
    $stmt = $conn->prepare("SELECT is_closed FROM orders WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $order_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows == 0) {
        $_SESSION['error'] = "Замовлення не знайдено або немає доступу!";
        header("Location: dashboard.php");
        exit();
    }

    $order_status = $result->fetch_assoc();

    if ($order_status['is_closed'] == 1) {
        $_SESSION['error'] = "Замовлення завершено, видалення коментарів недоступне";
        header("Location: dashboard.php");
        exit();
    }

    try {
        // Отримання старого коментаря перед видаленням для логування
        $stmt = $conn->prepare("SELECT user_comment FROM orders WHERE id = ?");
        $stmt->bind_param("i", $order_id);
        $stmt->execute();
        $old_comment_result = $stmt->get_result();
        $old_comment = $old_comment_result->fetch_assoc()['user_comment'] ?? '';

        // Видалення коментаря (оновлення поля до пустого значення)
        $empty_comment = '';
        $stmt = $conn->prepare("UPDATE orders SET user_comment = ? WHERE id = ? AND user_id = ?");
        $stmt->bind_param("sii", $empty_comment, $order_id, $user_id);

        if ($stmt->execute()) {
            logUserAction($conn, $user_id, 'delete_comment', 'Видалено коментар до замовлення #' . $order_id . '. Вміст: ' . $old_comment);

            // Створюємо тимчасове повідомлення про успішне видалення
            echo json_encode(['success' => true, 'message' => 'Коментар успішно видалено!']);
            exit();
        } else {
            echo json_encode(['success' => false, 'message' => 'Помилка бази даних: ' . $conn->error]);
            exit();
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Помилка: ' . $e->getMessage()]);
        exit();
    }
}

// Обробка завантаження фотографії профілю
if (isset($_POST['update_profile_pic']) && !$block_message) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Невалідний CSRF токен!");
    }

    if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $filename = $_FILES['profile_pic']['name'];
        $ext = pathinfo($filename, PATHINFO_EXTENSION);

        if (in_array(strtolower($ext), $allowed)) {
            $new_filename = uniqid() . '.' . $ext;
            $upload_dir = '../uploads/profiles/';

            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }

            $upload_path = $upload_dir . $new_filename;

            if (move_uploaded_file($_FILES['profile_pic']['tmp_name'], $upload_path)) {
                // Видалення старої фотографії, якщо вона існує
                if (!empty($user_data['profile_pic']) && file_exists('../' . $user_data['profile_pic'])) {
                    unlink('../' . $user_data['profile_pic']);
                }

                $db_path = 'uploads/profiles/' . $new_filename;
                $stmt = $conn->prepare("UPDATE users SET profile_pic = ? WHERE id = ?");
                $stmt->bind_param("si", $db_path, $user_id);

                if ($stmt->execute()) {
                    $user_data['profile_pic'] = $db_path;
                    $_SESSION['success'] = "Фотографію профілю оновлено!";
                    logUserAction($conn, $user_id, 'update_profile_pic', 'Оновлено фотографію профілю');
                } else {
                    $_SESSION['error'] = "Помилка оновлення фотографії в БД";
                }

                header("Location: dashboard.php");
                exit();
            } else {
                $_SESSION['error'] = "Помилка завантаження файлу";
            }
        } else {
            $_SESSION['error'] = "Недозволений тип файлу. Дозволено тільки: " . implode(', ', $allowed);
        }

        header("Location: dashboard.php");
        exit();
    }
}

// Обробка оновлення профілю користувача
if (isset($_POST['update_profile']) && !$block_message) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Невалідний CSRF токен!");
    }

    $first_name = $_POST['first_name'] ?? '';
    $last_name = $_POST['last_name'] ?? '';
    $middle_name = $_POST['middle_name'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $address = $_POST['address'] ?? '';
    $delivery_method = $_POST['delivery_method'] ?? '';

    // Перевірка обов'язкових полів
    if (empty($phone) || empty($address) || empty($delivery_method)) {
        $_SESSION['error'] = "Номер телефону, адреса та метод доставки є обов'язковими полями!";
        header("Location: dashboard.php");
        exit();
    }

    $stmt = $conn->prepare("UPDATE users SET first_name = ?, last_name = ?, middle_name = ?, phone = ?, address = ?, delivery_method = ? WHERE id = ?");
    $stmt->bind_param("ssssssi", $first_name, $last_name, $middle_name, $phone, $address, $delivery_method, $user_id);

    if ($stmt->execute()) {
        $_SESSION['success'] = "Профіль успішно оновлено!";
        $user_data['first_name'] = $first_name;
        $user_data['last_name'] = $last_name;
        $user_data['middle_name'] = $middle_name;
        $user_data['phone'] = $phone;
        $user_data['address'] = $address;
        $user_data['delivery_method'] = $delivery_method;
        logUserAction($conn, $user_id, 'update_profile', 'Оновлено персональні дані');

        // Додаємо системне сповіщення про оновлення профілю
        createNotification(
            $conn,
            $user_id,
            'system',
            'Профіль оновлено',
            'Ваші особисті дані були успішно оновлені.',
            null,
            'fa-user-edit'
        );
    } else {
        $_SESSION['error'] = "Помилка оновлення профілю: " . $conn->error;
    }

    header("Location: dashboard.php");
    exit();
}

// Обробка зміни електронної пошти
if (isset($_POST['update_email']) && !$block_message) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Невалідний CSRF токен!");
    }

    $new_email = $_POST['new_email'] ?? '';
    $password = $_POST['password'] ?? '';

    if (empty($new_email) || empty($password)) {
        $_SESSION['error'] = "Заповніть всі поля!";
        header("Location: dashboard.php");
        exit();
    }

    // Перевірка валідності email
    if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = "Невалідний формат email!";
        header("Location: dashboard.php");
        exit();
    }

    // Перевірка пароля
    $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user_password = $result->fetch_assoc()['password'];

    if (!password_verify($password, $user_password)) {
        $_SESSION['error'] = "Неправильний пароль!";
        header("Location: dashboard.php");
        exit();
    }

    // Перевірка унікальності email
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $stmt->bind_param("si", $new_email, $user_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $_SESSION['error'] = "Цей email вже використовується!";
        header("Location: dashboard.php");
        exit();
    }

    // Оновлення email
    $stmt = $conn->prepare("UPDATE users SET email = ? WHERE id = ?");
    $stmt->bind_param("si", $new_email, $user_id);

    if ($stmt->execute()) {
        $old_email = $user_data['email'];
        $user_data['email'] = $new_email;
        $_SESSION['success'] = "Email успішно оновлено!";
        logUserAction($conn, $user_id, 'update_email', 'Змінено email з ' . $old_email . ' на ' . $new_email);

        // Додаємо системне сповіщення про зміну email
        createNotification(
            $conn,
            $user_id,
            'system',
            'Email змінено',
            'Вашу адресу електронної пошти було успішно змінено на ' . $new_email,
            null,
            'fa-envelope'
        );

        // Відправляємо email-повідомлення на новий email
        if ($notification_prefs['email_notifications']) {
            $subject = "Зміна електронної пошти на сайті Lagodiy";
            $message = "
                <html>
                <head>
                    <title>Зміна email адреси</title>
                </head>
                <body>
                    <h2>Вітаємо!</h2>
                    <p>Вашу електронну адресу було успішно змінено з {$old_email} на {$new_email}.</p>
                    <p>Якщо ви не робили цієї зміни, будь ласка, негайно зв'яжіться з адміністрацією сайту.</p>
                    <p>З повагою,<br>Команда Lagodiy</p>
                </body>
                </html>
            ";

            // Відправка на стару адресу
            if (!empty($old_email)) {
                sendNotificationEmail($old_email, $subject, $message);
            }

            // Відправка на нову адресу
            sendNotificationEmail($new_email, $subject, $message);
        }
    } else {
        $_SESSION['error'] = "Помилка оновлення email: " . $conn->error;
    }

    header("Location: dashboard.php");
    exit();
}

// Обробка зміни логіна
if (isset($_POST['update_username']) && !$block_message) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Невалідний CSRF токен!");
    }

    $new_username = $_POST['new_username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (empty($new_username) || empty($password)) {
        $_SESSION['error'] = "Заповніть всі поля!";
        header("Location: dashboard.php");
        exit();
    }

    // Перевірка пароля
    $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user_password = $result->fetch_assoc()['password'];

    if (!password_verify($password, $user_password)) {
        $_SESSION['error'] = "Неправильний пароль!";
        header("Location: dashboard.php");
        exit();
    }

    // Перевірка унікальності логіна
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
    $stmt->bind_param("si", $new_username, $user_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $_SESSION['error'] = "Цей логін вже використовується!";
        header("Location: dashboard.php");
        exit();
    }

    // Оновлення логіна
    $stmt = $conn->prepare("UPDATE users SET username = ? WHERE id = ?");
    $stmt->bind_param("si", $new_username, $user_id);

    if ($stmt->execute()) {
        $old_username = $username;
        $username = $new_username;
        $_SESSION['username'] = $new_username;
        $_SESSION['success'] = "Логін успішно оновлено!";
        logUserAction($conn, $user_id, 'update_username', 'Змінено логін з ' . $old_username . ' на ' . $new_username);

        // Додаємо системне сповіщення про зміну логіна
        createNotification(
            $conn,
            $user_id,
            'system',
            'Логін змінено',
            'Ваш логін успішно змінено з ' . $old_username . ' на ' . $new_username,
            null,
            'fa-user-edit'
        );
    } else {
        $_SESSION['error'] = "Помилка оновлення логіна: " . $conn->error;
    }

    header("Location: dashboard.php");
    exit();
}

// Обробка зміни пароля
if (isset($_POST['update_password']) && !$block_message) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Невалідний CSRF токен!");
    }

    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $_SESSION['error'] = "Заповніть всі поля!";
        header("Location: dashboard.php");
        exit();
    }

    if ($new_password !== $confirm_password) {
        $_SESSION['error'] = "Паролі не співпадають!";
        header("Location: dashboard.php");
        exit();
    }

    if (strlen($new_password) < 8) {
        $_SESSION['error'] = "Пароль повинен містити не менше 8 символів!";
        header("Location: dashboard.php");
        exit();
    }

    // Перевірка поточного пароля
    $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user_password = $result->fetch_assoc()['password'];

    if (!password_verify($current_password, $user_password)) {
        $_SESSION['error'] = "Неправильний поточний пароль!";
        header("Location: dashboard.php");
        exit();
    }

    // Хешування та оновлення пароля
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
    $stmt->bind_param("si", $hashed_password, $user_id);

    if ($stmt->execute()) {
        $_SESSION['success'] = "Пароль успішно оновлено!";
        logUserAction($conn, $user_id, 'update_password', 'Оновлено пароль');

        // Додаємо системне сповіщення про зміну пароля
        createNotification(
            $conn,
            $user_id,
            'system',
            'Пароль змінено',
            'Ваш пароль було успішно змінено. Якщо ви не робили цієї зміни, негайно зв\'яжіться з адміністрацією.',
            null,
            'fa-lock'
        );

        // Відправляємо email про зміну пароля
        if ($notification_prefs['email_notifications']) {
            $email = $user_data['email'];
            $subject = "Зміна пароля на сайті Lagodiy";
            $message = "
                <html>
                <head>
                    <title>Зміна пароля</title>
                </head>
                <body>
                    <h2>Вітаємо!</h2>
                    <p>Ваш пароль на сайті Lagodiy було успішно змінено.</p>
                    <p>Якщо ви не робили цієї зміни, будь ласка, негайно зв'яжіться з адміністрацією сайту.</p>
                    <p>З повагою,<br>Команда Lagodiy</p>
                </body>
                </html>
            ";

            sendNotificationEmail($email, $subject, $message);
        }
    } else {
        $_SESSION['error'] = "Помилка оновлення пароля: " . $conn->error;
    }

    header("Location: dashboard.php");
    exit();
}

// Обробка форми замовлення
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_order']) && !$block_message) {
    // Валідація CSRF токена
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Невалідний CSRF токен!");
    }

    // Перевірка ліміту замовлень (тільки для активних замовлень)
    if ($active_orders_count >= 5) {
        $_SESSION['error'] = "Ви досягли максимальної кількості активних замовлень (5). Будь ласка, дочекайтесь обробки існуючих замовлень.";
        header("Location: dashboard.php");
        exit();
    }

    $service = $_POST['service'] ?? '';
    $details = $_POST['details'] ?? '';
    $device_type = $_POST['device_type'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $address = $_POST['address'] ?? '';
    $delivery_method = $_POST['delivery_method'] ?? '';
    $first_name = $_POST['first_name'] ?? '';
    $last_name = $_POST['last_name'] ?? '';
    $middle_name = $_POST['middle_name'] ?? '';

    if (empty($service) || empty($details) || empty($device_type) || empty($phone) || empty($address) || empty($delivery_method)) {
        $_SESSION['error'] = "Заповніть всі обов'язкові поля (послуга, деталі, тип пристрою, телефон, адреса та спосіб доставки)!";
        header("Location: dashboard.php");
        exit();
    }

    try {
        // Додавання замовлення
        $stmt = $conn->prepare("INSERT INTO orders (user_id, service, details, device_type, phone, address, delivery_method, first_name, last_name, middle_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isssssssss", $user_id, $service, $details, $device_type, $phone, $address, $delivery_method, $first_name, $last_name, $middle_name);

        if ($stmt->execute()) {
            $order_id = $stmt->insert_id;
            $_SESSION['success'] = "Замовлення #" . $order_id . " успішно створено!";
            logUserAction($conn, $user_id, 'create_order', 'Створено замовлення #' . $order_id);

            // Додаємо сповіщення про створення замовлення
            createNotification(
                $conn,
                $user_id,
                'order',
                'Нове замовлення #' . $order_id,
                'Ваше замовлення #' . $order_id . ' успішно створено. Статус: Нове',
                $order_id,
                'fa-file-alt',
                'dashboard.php#orders'
            );

            // Відправляємо email про створення замовлення
            if ($notification_prefs['email_notifications'] && $notification_prefs['email_order_status']) {
                $email = $user_data['email'];
                $subject = "Нове замовлення #" . $order_id . " на сайті Lagodiy";
                $message = "
                    <html>
                    <head>
                        <title>Нове замовлення #" . $order_id . "</title>
                    </head>
                    <body>
                        <h2>Нове замовлення #" . $order_id . "</h2>
                        <p>Вітаємо! Ваше замовлення успішно створено.</p>
                        <p><strong>Послуга:</strong> " . htmlspecialchars($service) . "</p>
                        <p><strong>Тип пристрою:</strong> " . htmlspecialchars($device_type) . "</p>
                        <p><strong>Деталі:</strong> " . htmlspecialchars($details) . "</p>
                        <p><strong>Спосіб доставки:</strong> " . htmlspecialchars($delivery_method) . "</p>
                        <p>Ви можете переглянути статус замовлення в <a href='https://lagodiy.com/admin_panel/dashboard.php'>особистому кабінеті</a>.</p>
                        <p>З повагою,<br>Команда Lagodiy</p>
                    </body>
                    </html>
                ";

                sendNotificationEmail($email, $subject, $message);
            }

            // Обробка завантаження файлів
            $uploaded_files = [];

            // Обробка звичайних завантажених файлів
            if (isset($_FILES['order_files']) && is_array($_FILES['order_files']['name'])) {
                $upload_dir = '../uploads/orders/' . $order_id . '/';

                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }

                // Розширений список дозволених розширень
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'mp4', 'avi', 'mov', 'pdf', 'doc', 'docx', 'txt'];
                $max_file_size = 10 * 1024 * 1024; // 10 MB

                for ($i = 0; $i < count($_FILES['order_files']['name']); $i++) {
                    if ($_FILES['order_files']['error'][$i] === 0) {
                        $file_name = $_FILES['order_files']['name'][$i];
                        $file_size = $_FILES['order_files']['size'][$i];
                        $file_tmp = $_FILES['order_files']['tmp_name'][$i];
                        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

                        if (in_array($file_ext, $allowed_extensions) && $file_size <= $max_file_size) {
                            $new_file_name = uniqid() . '.' . $file_ext;
                            $file_path = $upload_dir . $new_file_name;

                            if (move_uploaded_file($file_tmp, $file_path)) {
                                $db_path = 'uploads/orders/' . $order_id . '/' . $new_file_name;
                                $stmt = $conn->prepare("INSERT INTO order_files (order_id, file_name, file_path) VALUES (?, ?, ?)");
                                $stmt->bind_param("iss", $order_id, $file_name, $db_path);
                                $stmt->execute();
                                $uploaded_files[] = $file_name;
                            }
                        }
                    }
                }
            }

            // Обробка перетягнутих файлів (drag-and-drop)
            if (isset($_POST['dropped_files']) && !empty($_POST['dropped_files'])) {
                $dropped_files = json_decode($_POST['dropped_files'], true);
                $upload_dir = '../uploads/orders/' . $order_id . '/';

                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }

                foreach ($dropped_files as $file_data) {
                    if (!empty($file_data['data']) && !empty($file_data['name'])) {
                        $file_name = $file_data['name'];
                        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'mp4', 'avi', 'mov', 'pdf', 'doc', 'docx', 'txt'];

                        if (in_array($file_ext, $allowed_extensions)) {
                            // Декодуємо дані файлу
                            $file_data_decoded = base64_decode(preg_replace('#^data:.*?;base64,#', '', $file_data['data']));
                            $new_file_name = uniqid() . '.' . $file_ext;
                            $file_path = $upload_dir . $new_file_name;

                            if (file_put_contents($file_path, $file_data_decoded)) {
                                $db_path = 'uploads/orders/' . $order_id . '/' . $new_file_name;
                                $stmt = $conn->prepare("INSERT INTO order_files (order_id, file_name, file_path) VALUES (?, ?, ?)");
                                $stmt->bind_param("iss", $order_id, $file_name, $db_path);
                                $stmt->execute();
                                $uploaded_files[] = $file_name;
                            }
                        }
                    }
                }
            }
        } else {
            $_SESSION['error'] = "Помилка бази даних: " . $conn->error;
        }
    } catch (Exception $e) {
        $_SESSION['error'] = "Помилка: " . $e->getMessage();
    }

    header("Location: dashboard.php");
    exit();
}

// Обробка редагування замовлення
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_order']) && !$block_message) {
    // Валідація CSRF токена
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Невалідний CSRF токен!");
    }

    $order_id = $_POST['order_id'] ?? 0;

    // Перевірка статусу замовлення
    $stmt = $conn->prepare("SELECT status, is_closed FROM orders WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $order_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows == 0) {
        $_SESSION['error'] = "Замовлення не знайдено або немає доступу!";
        header("Location: dashboard.php");
        exit();
    }

    $order_status = $result->fetch_assoc();

    // Перевірка чи замовлення завершено
    if ($order_status['is_closed'] == 1) {
        $_SESSION['error'] = "Замовлення завершено, редагування недоступне";
        header("Location: dashboard.php");
        exit();
    }

    $service = $_POST['service'] ?? '';
    $details = $_POST['details'] ?? '';
    $device_type = $_POST['device_type'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $address = $_POST['address'] ?? '';
    $delivery_method = $_POST['delivery_method'] ?? '';
    $user_comment = $_POST['user_comment'] ?? '';

    if (empty($service) || empty($details) || empty($device_type) || empty($phone) || empty($address) || empty($delivery_method)) {
        $_SESSION['error'] = "Заповніть всі обов'язкові поля!";
        header("Location: dashboard.php");
        exit();
    }

    try {
        // Оновлення замовлення
        $stmt = $conn->prepare("UPDATE orders SET service = ?, details = ?, device_type = ?, phone = ?, address = ?, delivery_method = ?, user_comment = ? WHERE id = ? AND user_id = ?");
        $stmt->bind_param("sssssssii", $service, $details, $device_type, $phone, $address, $delivery_method, $user_comment, $order_id, $user_id);

        if ($stmt->execute()) {
            $_SESSION['success'] = "Замовлення #" . $order_id . " успішно оновлено!";
            logUserAction($conn, $user_id, 'edit_order', 'Оновлено замовлення #' . $order_id);

            // Додаємо сповіщення про оновлення замовлення
            createNotification(
                $conn,
                $user_id,
                'order',
                'Замовлення #' . $order_id . ' оновлено',
                'Дані замовлення #' . $order_id . ' були успішно оновлені.',
                $order_id,
                'fa-edit',
                'dashboard.php#orders'
            );

            // Обробка нових файлів
            $uploaded_files = [];

            // Обробка звичайних завантажених файлів
            if (isset($_FILES['order_files']) && is_array($_FILES['order_files']['name'])) {
                $upload_dir = '../uploads/orders/' . $order_id . '/';

                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }

                $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'mp4', 'avi', 'mov', 'pdf', 'doc', 'docx', 'txt'];
                $max_file_size = 10 * 1024 * 1024; // 10 MB

                for ($i = 0; $i < count($_FILES['order_files']['name']); $i++) {
                    if ($_FILES['order_files']['error'][$i] === 0) {
                        $file_name = $_FILES['order_files']['name'][$i];
                        $file_size = $_FILES['order_files']['size'][$i];
                        $file_tmp = $_FILES['order_files']['tmp_name'][$i];
                        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

                        if (in_array($file_ext, $allowed_extensions) && $file_size <= $max_file_size) {
                            $new_file_name = uniqid() . '.' . $file_ext;
                            $file_path = $upload_dir . $new_file_name;

                            if (move_uploaded_file($file_tmp, $file_path)) {
                                $db_path = 'uploads/orders/' . $order_id . '/' . $new_file_name;
                                $stmt = $conn->prepare("INSERT INTO order_files (order_id, file_name, file_path) VALUES (?, ?, ?)");
                                $stmt->bind_param("iss", $order_id, $file_name, $db_path);
                                $stmt->execute();
                                $uploaded_files[] = $file_name;
                            }
                        }
                    }
                }
            }

            // Обробка перетягнутих файлів (drag-and-drop)
            if (isset($_POST['dropped_files']) && !empty($_POST['dropped_files'])) {
                $dropped_files = json_decode($_POST['dropped_files'], true);
                $upload_dir = '../uploads/orders/' . $order_id . '/';

                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }

                foreach ($dropped_files as $file_data) {
                    if (!empty($file_data['data']) && !empty($file_data['name'])) {
                        $file_name = $file_data['name'];
                        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'mp4', 'avi', 'mov', 'pdf', 'doc', 'docx', 'txt'];

                        if (in_array($file_ext, $allowed_extensions)) {
                            // Декодуємо дані файлу
                            $file_data_decoded = base64_decode(preg_replace('#^data:.*?;base64,#', '', $file_data['data']));
                            $new_file_name = uniqid() . '.' . $file_ext;
                            $file_path = $upload_dir . $new_file_name;

                            if (file_put_contents($file_path, $file_data_decoded)) {
                                $db_path = 'uploads/orders/' . $order_id . '/' . $new_file_name;
                                $stmt = $conn->prepare("INSERT INTO order_files (order_id, file_name, file_path) VALUES (?, ?, ?)");
                                $stmt->bind_param("iss", $order_id, $file_name, $db_path);
                                $stmt->execute();
                                $uploaded_files[] = $file_name;
                            }
                        }
                    }
                }
            }
        } else {
            $_SESSION['error'] = "Помилка бази даних: " . $conn->error;
        }
    } catch (Exception $e) {
        $_SESSION['error'] = "Помилка: " . $e->getMessage();
    }

    header("Location: dashboard.php");
    exit();
}

// Обробка додавання коментаря до замовлення
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_comment']) && !$block_message) {
    // Валідація CSRF токена
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Невалідний CSRF токен!");
    }

    $order_id = $_POST['order_id'] ?? 0;
    $comment = $_POST['comment'] ?? '';

    if (empty($comment)) {
        $_SESSION['error'] = "Коментар не може бути порожнім!";
        header("Location: dashboard.php");
        exit();
    }

    // Перевірка статусу замовлення
    $stmt = $conn->prepare("SELECT is_closed FROM orders WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $order_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows == 0) {
        $_SESSION['error'] = "Замовлення не знайдено або немає доступу!";
        header("Location: dashboard.php");
        exit();
    }

    $order_status = $result->fetch_assoc();

    if ($order_status['is_closed'] == 1) {
        $_SESSION['error'] = "Замовлення завершено, додавання коментарів недоступне";
        header("Location: dashboard.php");
        exit();
    }

    try {
        // Оновлення коментаря користувача
        $stmt = $conn->prepare("UPDATE orders SET user_comment = ? WHERE id = ? AND user_id = ?");
        $stmt->bind_param("sii", $comment, $order_id, $user_id);

        if ($stmt->execute()) {
            $_SESSION['success'] = "Коментар успішно додано!";
            logUserAction($conn, $user_id, 'add_comment', 'Додано коментар до замовлення #' . $order_id);

            // Додаємо сповіщення про додавання коментаря
            createNotification(
                $conn,
                $user_id,
                'order',
                'Коментар додано',
                'Ви додали коментар до замовлення #' . $order_id,
                $order_id,
                'fa-comment',
                'dashboard.php#orders'
            );

            // Ajax відповідь для автоматичного зникання через 2 секунди
            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                echo json_encode(['success' => true, 'message' => 'Коментар успішно додано!']);
                exit();
            }
        } else {
            $_SESSION['error'] = "Помилка бази даних: " . $conn->error;
        }
    } catch (Exception $e) {
        $_SESSION['error'] = "Помилка: " . $e->getMessage();
    }

    header("Location: dashboard.php");
    exit();
}

// Отримання замовлень з коментарями та файлами
$orders = [];
$filter_status = $_GET['status'] ?? '';
$filter_service = $_GET['service'] ?? '';
$search_query = $_GET['search'] ?? '';

try {
    $query = "
                SELECT 
            o.id,
            o.service,
            o.details,
            o.status,
            o.created_at,
            o.user_comment,
            o.is_closed,
            o.phone,
            o.address,
            o.delivery_method,
            o.device_type,
            o.first_name,
            o.last_name,
            o.middle_name,
            c.id AS comment_id,
            c.content AS admin_comment,
            c.created_at AS comment_date,
            u.username AS admin_name
        FROM orders o
        LEFT JOIN comments c ON o.id = c.order_id
        LEFT JOIN users u ON c.user_id = u.id
        WHERE o.user_id = ?
    ";

    // Додавання фільтрів
    $params = [$user_id];
    $types = "i";

    if (!empty($filter_status)) {
        $query .= " AND o.status = ?";
        $params[] = $filter_status;
        $types .= "s";
    }

    if (!empty($filter_service)) {
        $query .= " AND o.service = ?";
        $params[] = $filter_service;
        $types .= "s";
    }

    if (!empty($search_query)) {
        $query .= " AND (o.id LIKE ? OR o.details LIKE ? OR o.service LIKE ?)";
        $search_param = "%{$search_query}%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= "sss";
    }

    // Сортування: нові замовлення зверху, завершені - знизу
    $query .= " ORDER BY o.is_closed ASC, o.created_at DESC";

    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $orders_data = $result->fetch_all(MYSQLI_ASSOC);

    // Групування замовлень та коментарів
    $grouped_orders = [];
    foreach ($orders_data as $row) {
        $order_id = $row['id'];
        if (!isset($grouped_orders[$order_id])) {
            $grouped_orders[$order_id] = [
                'id' => $row['id'],
                'service' => $row['service'],
                'details' => $row['details'],
                'status' => $row['status'],
                'created_at' => $row['created_at'],
                'user_comment' => $row['user_comment'],
                'is_closed' => $row['is_closed'],
                'phone' => $row['phone'],
                'address' => $row['address'],
                'delivery_method' => $row['delivery_method'],
                'device_type' => $row['device_type'],
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
                'middle_name' => $row['middle_name'],
                'comments' => [],
                'files' => [],
                'unread_notifications' => 0,
                'last_status' => $row['status']
            ];
        }

        if (!empty($row['comment_id'])) {
            $grouped_orders[$order_id]['comments'][] = [
                'id' => $row['comment_id'],
                'content' => $row['admin_comment'],
                'created_at' => $row['comment_date'],
                'admin_name' => $row['admin_name']
            ];
        }
    }

    // Отримання файлів для кожного замовлення
    $order_ids = array_keys($grouped_orders);
    if (!empty($order_ids)) {
        $placeholders = str_repeat('?,', count($order_ids) - 1) . '?';
        $file_query = "SELECT * FROM order_files WHERE order_id IN ($placeholders) ORDER BY uploaded_at DESC";

        $stmt = $conn->prepare($file_query);
        $types = str_repeat('i', count($order_ids));
        $stmt->bind_param($types, ...$order_ids);
        $stmt->execute();
        $file_result = $stmt->get_result();

        while ($file = $file_result->fetch_assoc()) {
            $grouped_orders[$file['order_id']]['files'][] = $file;
        }
    }

    // Отримання непрочитаних сповіщень для кожного замовлення
    if (!empty($order_ids)) {
        $placeholders = str_repeat('?,', count($order_ids) - 1) . '?';
        $notifications_query = "SELECT order_id, COUNT(*) as unread_count FROM notifications 
                               WHERE order_id IN ($placeholders) AND user_id = ? AND is_read = 0
                               GROUP BY order_id";

        $params = array_merge($order_ids, [$user_id]);
        $types = str_repeat('i', count($order_ids)) . 'i';

        $stmt = $conn->prepare($notifications_query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $notifications_result = $stmt->get_result();

        while ($notification = $notifications_result->fetch_assoc()) {
            $grouped_orders[$notification['order_id']]['unread_notifications'] = $notification['unread_count'];
        }
    }

    $orders = array_values($grouped_orders);
} catch (Exception $e) {
    $error = "Помилка завантаження даних: " . $e->getMessage();
}

// Отримуємо унікальні статуси та послуги для фільтрів
$statuses = [];
$services = [];
try {
    $stmt = $conn->prepare("SELECT DISTINCT status FROM orders WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $statuses[] = $row['status'];
    }

    $stmt = $conn->prepare("SELECT DISTINCT service FROM orders WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $services[] = $row['service'];
    }
} catch (Exception $e) {
    // Ігноруємо помилку фільтрів
}

// Отримання сповіщень для користувача
$user_notifications = getUserNotifications($conn, $user_id, 20);
$unread_notifications_count = countUnreadNotifications($conn, $user_id);

// Обробка зміни статусу замовлення
function checkOrderStatusChanges($conn, $user_id, $order_id, $notification_prefs) {
    // Отримуємо поточний статус замовлення
    $stmt = $conn->prepare("SELECT status, service FROM orders WHERE id = ?");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $order_data = $result->fetch_assoc();
    $current_status = $order_data['status'] ?? '';
    $service = $order_data['service'] ?? '';

    // Перевіряємо, чи був змінений статус замовлення (останнє сповіщення про статус)
    $stmt = $conn->prepare("SELECT metadata FROM notifications 
                            WHERE order_id = ? AND user_id = ? AND type = 'status_change' 
                            ORDER BY created_at DESC LIMIT 1");
    $stmt->bind_param("ii", $order_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $notif_data = $result->fetch_assoc();

    $metadata = $notif_data ? json_decode($notif_data['metadata'], true) : null;
    $last_known_status = $metadata ? ($metadata['new_status'] ?? null) : null;

    // Якщо статус змінено або це перше відстеження статусу
    if ($last_known_status !== $current_status) {
        // Додаємо нове сповіщення про зміну статусу
        $old_status = $last_known_status ?? 'Не встановлено';

        $title = "Статус замовлення #$order_id змінено";
        $message = "Статус вашого замовлення #$order_id змінено з \"$old_status\" на \"$current_status\"";

        $metadata = [
            'old_status' => $old_status,
            'new_status' => $current_status
        ];

        createNotification(
            $conn,
            $user_id,
            'status_change',
            $title,
            $message,
            $order_id,
            'fa-exchange-alt',
            "dashboard.php#orders",
            30,
            $metadata
        );

        // Відправляємо email про зміну статусу
        if ($notification_prefs['email_notifications'] && $notification_prefs['email_order_status']) {
            // Отримуємо email користувача
            $stmt = $conn->prepare("SELECT email FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $user_result = $stmt->get_result();
            $user_email = $user_result->fetch_assoc()['email'] ?? '';

            if (!empty($user_email)) {
                $subject = "Зміна статусу замовлення #$order_id";
                $message = "
                    <html>
                    <head>
                        <title>Зміна статусу замовлення #$order_id</title>
                    </head>
                    <body>
                        <h2>Зміна статусу замовлення #$order_id</h2>
                        <p>Доброго дня!</p>
                        <p>Інформуємо вас про зміну статусу вашого замовлення:</p>
                        <p><strong>Послуга:</strong> $service</p>
                        <p><strong>Старий статус:</strong> $old_status</p>
                        <p><strong>Новий статус:</strong> $current_status</p>
                        <p>Ви можете переглянути деталі замовлення в <a href='https://lagodiy.com/admin_panel/dashboard.php'>особистому кабінеті</a>.</p>
                        <p>З повагою,<br>Команда Lagodiy</p>
                    </body>
                    </html>
                ";

                sendNotificationEmail($user_email, $subject, $message);
            }
        }

        // Відправляємо push-сповіщення, якщо користувач підписаний
        if ($notification_prefs['push_notifications'] && $notification_prefs['push_order_status']) {
            $title = "Статус замовлення #$order_id змінено";
            $body = "Новий статус: $current_status";
            $url = "https://lagodiy.com/admin_panel/dashboard.php#orders";

            sendPushNotification($conn, $user_id, $title, $body, $url);
        }

        return true;
    }

    return false;
}

// Перевірка нових коментарів адміністратора
function checkNewAdminComments($conn, $user_id, $order_id, $notification_prefs) {
    // Перевіряємо наявність нових коментарів адміністратора
    $stmt = $conn->prepare("
        SELECT c.id, c.content, c.created_at, u.username as admin_name, c.file_attachment,
               (SELECT COUNT(*) FROM notifications WHERE type = 'admin_comment' AND user_id = ? AND metadata LIKE CONCAT('%\"comment_id\":\"', c.id, '\"%')) as is_notified
        FROM comments c 
        JOIN users u ON c.user_id = u.id 
        WHERE c.order_id = ?
        ORDER BY c.created_at DESC
    ");
    $stmt->bind_param("ii", $user_id, $order_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $new_comments = [];
    $new_comments_count = 0;

    while ($comment = $result->fetch_assoc()) {
        // Якщо про цей коментар ще не було сповіщення
        if ($comment['is_notified'] == 0) {
            $new_comments[] = $comment;
            $new_comments_count++;

            // Створюємо сповіщення про новий коментар
            $title = "Новий коментар до замовлення #$order_id";
            $message = "Адміністратор ({$comment['admin_name']}) додав новий коментар до вашого замовлення #$order_id";

            $metadata = [
                'comment_id' => $comment['id'],
                'admin_name' => $comment['admin_name'],
                'has_attachment' => !empty($comment['file_attachment'])
            ];

            createNotification(
                $conn,
                $user_id,
                'admin_comment',
                $title,
                $message,
                $order_id,
                'fa-comment-dots',
                "dashboard.php#orders",
                30,
                $metadata
            );

            // Відправляємо email про новий коментар
            if ($notification_prefs['email_notifications'] && $notification_prefs['email_new_comment']) {
                // Отримуємо email та інформацію про замовлення
                $stmt2 = $conn->prepare("
                    SELECT u.email, o.service
                    FROM users u
                    JOIN orders o ON u.id = o.user_id
                    WHERE u.id = ? AND o.id = ?
                ");
                $stmt2->bind_param("ii", $user_id, $order_id);
                $stmt2->execute();
                $user_result = $stmt2->get_result();
                $user_data = $user_result->fetch_assoc();

                if (!empty($user_data['email'])) {
                    $subject = "Новий коментар до замовлення #$order_id";
                    $message = "
                        <html>
                        <head>
                            <title>Новий коментар до замовлення #$order_id</title>
                        </head>
                        <body>
                            <h2>Новий коментар до замовлення #$order_id</h2>
                            <p>Доброго дня!</p>
                            <p>Адміністратор {$comment['admin_name']} додав новий коментар до вашого замовлення:</p>
                            <p><strong>Послуга:</strong> {$user_data['service']}</p>
                            <p><strong>Коментар:</strong><br>" . nl2br(htmlspecialchars($comment['content'])) . "</p>
                            " . (!empty($comment['file_attachment']) ? "<p><strong>До коментаря додано файл.</strong></p>" : "") . "
                            <p>Ви можете переглянути деталі в <a href='https://lagodiy.com/admin_panel/dashboard.php'>особистому кабінеті</a>.</p>
                            <p>З повагою,<br>Команда Lagodiy</p>
                        </body>
                        </html>
                    ";

                    sendNotificationEmail($user_data['email'], $subject, $message);
                }
            }

            // Відправляємо push-сповіщення, якщо користувач підписаний
            if ($notification_prefs['push_notifications'] && $notification_prefs['push_new_comment']) {
                $title = "Новий коментар до замовлення #$order_id";
                $body = "Адміністратор {$comment['admin_name']} додав коментар";
                $url = "https://lagodiy.com/admin_panel/dashboard.php#orders";

                sendPushNotification($conn, $user_id, $title, $body, $url);
            }
        }
    }

    return $new_comments_count;
}

// Перевіряємо зміни для всіх замовлень користувача
foreach ($orders as &$order) {
    // Перевіряємо зміну статусу
    $status_changed = checkOrderStatusChanges($conn, $user_id, $order['id'], $notification_prefs);

    // Перевіряємо нові коментарі
    $new_comments_count = checkNewAdminComments($conn, $user_id, $order['id'], $notification_prefs);

    // Оновлюємо дані замовлення
    $order['status_changed'] = $status_changed;
    $order['new_comments_count'] = $new_comments_count;

    // Отримуємо кількість непрочитаних сповіщень для цього замовлення
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND order_id = ? AND is_read = 0");
    $stmt->bind_param("ii", $user_id, $order['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $count_data = $result->fetch_assoc();
    $order['unread_notifications'] = $count_data['count'] ?? 0;
}
?>

    <!DOCTYPE html>
    <html lang="uk" data-theme="light">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Особистий кабінет - <?= htmlspecialchars($username) ?></title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
        <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
        <style>
            :root {
                --primary-color: #3498db;
                --secondary-color: #2980b9;
                --success-color: #2ecc71;
                --info-color: #3498db;
                --warning-color: #f39c12;
                --danger-color: #e74c3c;
                --text-color: #333;
                --text-muted: #6c757d;
                --bg-color: #f8f9fa;
                --card-bg: #fff;
                --border-color: #ddd;
                --sidebar-width: 280px;
                --sidebar-collapsed-width: 70px;
                --header-height: 60px;
                --transition-speed: 0.3s;
                --notification-color: #e74c3c;
                --shadow: 0 2px 8px rgba(0,0,0,0.1);
            }

            [data-theme="dark"] {
                --primary-color: #2196F3;
                --secondary-color: #1976D2;
                --success-color: #4caf50;
                --info-color: #2196F3;
                --warning-color: #ff9800;
                --danger-color: #f44336;
                --text-color: #e4e6eb;
                --text-muted: #adb5bd;
                --bg-color: #18191a;
                --card-bg: #242526;
                --border-color: #3a3b3c;
                --shadow: 0 2px 8px rgba(0,0,0,0.3);
            }

            [data-theme="blue"] {
                --primary-color: #1e88e5;
                --secondary-color: #1565c0;
                --success-color: #43a047;
                --info-color: #039be5;
                --warning-color: #fdd835;
                --danger-color: #e53935;
                --text-color: #212121;
                --text-muted: #757575;
                --bg-color: #e3f2fd;
                --card-bg: #fff;
                --border-color: #bbdefb;
                --shadow: 0 2px 8px rgba(25,118,210,0.1);
            }

            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }

            body {
                font-family: 'Montserrat', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                color: var(--text-color);
                background-color: var(--bg-color);
                transition: background-color var(--transition-speed), color var(--transition-speed);
                min-height: 100vh;
                display: flex;
                overflow-x: hidden;
                line-height: 1.5;
            }

            /* Sidebar styling */
            .sidebar {
                width: var(--sidebar-width);
                background-color: var(--card-bg);
                border-right: 1px solid var(--border-color);
                height: 100vh;
                position: fixed;
                left: 0;
                top: 0;
                transition: width var(--transition-speed);
                z-index: 100;
                box-shadow: var(--shadow);
                overflow-y: auto;
            }

            .sidebar.collapsed {
                width: var(--sidebar-collapsed-width);
            }

            .sidebar-header {
                height: var(--header-height);
                display: flex;
                align-items: center;
                padding: 0 20px;
                border-bottom: 1px solid var(--border-color);
            }

            .sidebar-header .logo {
                font-size: 1.5rem;
                font-weight: bold;
                color: var(--primary-color);
                white-space: nowrap;
                overflow: hidden;
            }

            .toggle-sidebar {
                margin-left: auto;
                background: none;
                border: none;
                color: var(--text-color);
                cursor: pointer;
                font-size: 1.2rem;
            }

            .user-info {
                padding: 20px;
                text-align: center;
                border-bottom: 1px solid var(--border-color);
            }

            .user-avatar {
                width: 80px;
                height: 80px;
                border-radius: 50%;
                margin: 0 auto 10px;
                overflow: hidden;
                border: 2px solid var(--primary-color);
                background-color: #f8f9fa;
                display: flex;
                justify-content: center;
                align-items: center;
            }

            .user-avatar img {
                width: 100%;
                height: 100%;
                object-fit: cover;
            }

            .user-name {
                font-weight: 600;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .sidebar.collapsed .user-info {
                padding: 10px;
            }

            .sidebar.collapsed .user-avatar {
                width: 40px;
                height: 40px;
            }

            .sidebar.collapsed .user-name,
            .sidebar.collapsed .sidebar-header .logo-text {
                display: none;
            }

            .sidebar-menu {
                padding: 20px 0;
                list-style-type: none;
            }

            .sidebar-menu li {
                padding: 0;
                margin-bottom: 5px;
                position: relative;
            }

            .sidebar-menu a {
                padding: 12px 20px;
                color: var(--text-color);
                text-decoration: none;
                display: flex;
                align-items: center;
                transition: background-color 0.2s;
                border-radius: 4px;
                margin: 0 5px;
            }

            .sidebar-menu a:hover {
                background-color: rgba(0,0,0,0.05);
            }

            .sidebar-menu a.active {
                background-color: var(--primary-color);
                color: #fff;
            }

            .sidebar-menu .icon {
                margin-right: 15px;
                font-size: 1.2rem;
                width: 20px;
                text-align: center;
            }

            .sidebar.collapsed .sidebar-menu .menu-text {
                display: none;
            }

            /* Notification badge */
            .notification-badge {
                position: absolute;
                top: 5px;
                right: 10px;
                background-color: var(--notification-color);
                color: white;
                border-radius: 50%;
                width: 18px;
                height: 18px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 0.7rem;
                font-weight: bold;
                box-shadow: 0 2px 5px rgba(0,0,0,0.2);
                animation: pulse 2s infinite;
            }

            .sidebar.collapsed .notification-badge {
                right: 5px;
            }

            @keyframes pulse {
                0% {
                    transform: scale(0.95);
                    box-shadow: 0 0 0 0 rgba(231, 76, 60, 0.7);
                }
                70% {
                    transform: scale(1.1);
                    box-shadow: 0 0 0 10px rgba(231, 76, 60, 0);
                }
                100% {
                    transform: scale(0.95);
                    box-shadow: 0 0 0 0 rgba(231, 76, 60, 0);
                }
            }

            /* Main content */
            .main-content {
                margin-left: var(--sidebar-width);
                flex: 1;
                padding: 20px;
                transition: margin-left var(--transition-speed);
            }

            .sidebar.collapsed + .main-content {
                margin-left: var(--sidebar-collapsed-width);
            }

            /* Header section */
            .header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 30px;
                padding-bottom: 15px;
                border-bottom: 1px solid var(--border-color);
            }

            .header-title h1 {
                font-size: 1.8rem;
                font-weight: 600;
                margin: 0;
            }

            .header-actions {
                display: flex;
                align-items: center;
            }

            .current-time {
                margin-right: 20px;
                font-size: 0.9rem;
                color: var(--text-muted);
            }

            /* Buttons */
            .btn {
                padding: 8px 16px;
                background-color: var(--primary-color);
                color: white;
                border: none;
                border-radius: 4px;
                cursor: pointer;
                text-decoration: none;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                transition: background-color 0.2s, transform 0.1s;
                margin: 3px;
                white-space: nowrap;
                font-weight: 500;
            }

            .btn:hover {
                background-color: var(--secondary-color);
                transform: translateY(-1px);
            }

            .btn:active {
                transform: translateY(1px);
            }

            .btn-sm {
                padding: 5px 10px;
                font-size: 0.9rem;
            }

            .btn-text {
                padding: 5px;
                background: none;
                border: none;
                color: var(--primary-color);
                cursor: pointer;
                text-decoration: underline;
                font-size: 0.9rem;
            }

            .btn-success {
                background-color: var(--success-color);
            }

            .btn-success:hover {
                background-color: var(--success-color);
                filter: brightness(0.9);
            }

            .btn-warning {
                background-color: var(--warning-color);
                color: var(--text-color);
            }

            .btn-warning:hover {
                background-color: var(--warning-color);
                filter: brightness(0.9);
            }

            .btn-danger {
                background-color: var(--danger-color);
            }

            .btn-danger:hover {
                background-color: var(--danger-color);
                filter: brightness(0.9);
            }

            /* Alerts */
            .alert {
                padding: 15px;
                margin: 20px 0;
                border-radius: 5px;
                position: relative;
                border-left: 4px solid transparent;
            }

            .alert-success {
                background: rgba(46, 204, 113, 0.1);
                border-left-color: var(--success-color);
                color: var(--success-color);
            }

            .alert-error {
                background: rgba(231, 76, 60, 0.1);
                border-left-color: var(--danger-color);
                color: var(--danger-color);
            }

            .alert-block {
                background: rgba(243, 156, 18, 0.1);
                border-left-color: var(--warning-color);
                color: var(--warning-color);
            }

            /* Cards */
            .card {
                background-color: var(--card-bg);
                border-radius: 8px;
                box-shadow: var(--shadow);
                padding: 20px;
                margin-bottom: 20px;
                transition: box-shadow 0.3s, transform 0.3s;
            }

            .card:hover {
                box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            }

            .card-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 15px;
                border-bottom: 1px solid var(--border-color);
                padding-bottom: 10px;
            }

            .card-title {
                font-size: 1.25rem;
                font-weight: 600;
                margin: 0;
            }

            /* Forms */
            .form-group {
                margin-bottom: 15px;
            }

            .form-group label {
                display: block;
                margin-bottom: 5px;
                font-weight: 500;
            }

            .form-control {
                width: 100%;
                padding: 10px;
                border: 1px solid var(--border-color);
                border-radius: 4px;
                background-color: var(--card-bg);
                color: var(--text-color);
                transition: border-color 0.2s, box-shadow 0.2s;
            }

            .form-control:focus {
                border-color: var(--primary-color);
                outline: none;
                box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.2);
            }

            select.form-control {
                cursor: pointer;
                appearance: none;
                background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%236c757d' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
                background-repeat: no-repeat;
                background-position: right 10px center;
                padding-right: 30px;
            }

            textarea.form-control {
                resize: vertical;
                min-height: 100px;
            }

            /* Order cards */
            .order-card {
                background-color: var(--card-bg);
                border-radius: 8px;
                box-shadow: var(--shadow);
                margin-bottom: 20px;
                overflow: hidden;
                transition: box-shadow 0.2s, transform 0.2s;
                position: relative;
                border: 1px solid var(--border-color);
            }

            .order-card:hover {
                box-shadow: 0 8px 16px rgba(0,0,0,0.1);
                transform: translateY(-2px);
            }

            /* Notification indicator for orders */
            .order-card.has-notifications {
                border-left: 4px solid var(--notification-color);
            }

            .order-header {
                padding: 15px 20px;
                background-color: rgba(0,0,0,0.02);
                border-bottom: 1px solid var(--border-color);
                display: flex;
                justify-content: space-between;
                align-items: center;
                flex-wrap: wrap;
            }

            .order-id {
                font-weight: 600;
                font-size: 1.1rem;
                margin: 0;
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .order-meta {
                display: flex;
                align-items: center;
                gap: 15px;
                font-size: 0.9rem;
            }

            .status-badge {
                padding: 3px 10px;
                border-radius: 15px;
                font-size: 0.8rem;
                font-weight: 500;
                display: inline-flex;
                align-items: center;
                gap: 5px;
            }

            .status-new {
                background-color: #e3f2fd;
                color: #0d47a1;
            }

            .status-processing {
                background-color: #fff8e1;
                color: #ff8f00;
            }

            .status-completed {
                background-color: #e8f5e9;
                color: #2e7d32;
            }

            .status-canceled {
                background-color: #ffebee;
                color: #c62828;
            }

            .order-body {
                padding: 20px;
                max-height: 200px;
                overflow: hidden;
                position: relative;
                transition: max-height 0.3s ease;
            }

            .order-body.expanded {
                max-height: none;
            }

            .order-detail {
                margin-bottom: 15px;
            }

            .order-detail-label {
                font-weight: 600;
                margin-bottom: 5px;
                color: var(--text-muted);
                font-size: 0.9rem;
                text-transform: uppercase;
            }

            .order-actions {
                margin-top: 20px;
                display: flex;
                gap: 10px;
                flex-wrap: wrap;
            }

            .order-files {
                margin-top: 20px;
            }

            .file-item {
                display: flex;
                align-items: center;
                padding: 8px 12px;
                border: 1px solid var(--border-color);
                border-radius: 4px;
                margin-bottom: 8px;
                background-color: rgba(0,0,0,0.01);
                transition: background-color 0.2s;
            }

            .file-item:hover {
                background-color: rgba(0,0,0,0.03);
            }

            .file-icon {
                margin-right: 10px;
                font-size: 1.2rem;
                color: var(--primary-color);
            }

            .file-name {
                flex: 1;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            /* Comments section */
            .comments-section {
                margin-top: 20px;
                border-top: 1px solid var(--border-color);
                padding-top: 20px;
            }

            .comment {
                background-color: rgba(0,0,0,0.02);
                border-radius: 8px;
                padding: 15px;
                margin-top: 10px;
                position: relative;
            }

            .comment.new-comment {
                border-left: 3px solid var(--notification-color);
                animation: highlight-fade 2s forwards;
            }

            @keyframes highlight-fade {
                0% {
                    background-color: rgba(231, 76, 60, 0.1);
                }
                100% {
                    background-color: rgba(0,0,0,0.02);
                }
            }

            .comment-header {
                display: flex;
                justify-content: space-between;
                margin-bottom: 8px;
                font-size: 0.9rem;
            }

            .comment-author {
                font-weight: 600;
                display: flex;
                align-items: center;
                gap: 5px;
            }

            .comment-date {
                color: var(--text-muted);
            }

            /* Filters and search */
            .filters-bar {
                display: flex;
                justify-content: space-between;
                flex-wrap: wrap;
                margin-bottom: 20px;
                gap: 10px;
            }

            .filter-group {
                display: flex;
                align-items: center;
                gap: 10px;
                flex-wrap: wrap;
            }

            .search-bar {
                flex: 1;
                max-width: 300px;
                position: relative;
            }

            .search-bar .form-control {
                padding-left: 35px;
            }

            .search-icon {
                position: absolute;
                left: 10px;
                top: 50%;
                transform: translateY(-50%);
                color: var(--text-muted);
            }

            /* Modal styling */
            .modal {
                display: none;
                position: fixed;
                z-index: 1000;
                left: 0;
                top: 0;
                width: 100%;
                height: 100%;
                background-color: rgba(0,0,0,0.5);
                overflow: auto;
                animation: fadeIn 0.3s ease;
            }

            @keyframes fadeIn {
                from {opacity: 0;}
                to {opacity: 1;}
            }

            .modal-content {
                background-color: var(--card-bg);
                margin: 50px auto;
                padding: 20px;
                border-radius: 8px;
                box-shadow: 0 5px 15px rgba(0,0,0,0.3);
                width: 90%;
                max-width: 700px;
                max-height: 85vh;
                overflow-y: auto;
                animation: slideDown 0.3s ease;
            }

            @keyframes slideDown {
                from {transform: translateY(-50px); opacity: 0;}
                to {transform: translateY(0); opacity: 1;}
            }

            .modal-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                border-bottom: 1px solid var(--border-color);
                padding-bottom: 15px;
                margin-bottom: 20px;
            }

            .modal-title {
                font-size: 1.25rem;
                font-weight: 600;
                margin: 0;
            }

            .close-modal {
                font-size: 1.5rem;
                cursor: pointer;
                color: var(--text-color);
                background: none;
                border: none;
                line-height: 1;
                padding: 0;
                transition: transform 0.2s;
            }

            .close-modal:hover {
                transform: scale(1.1);
            }

            /* Tabs */
            .tabs {
                display: flex;
                border-bottom: 1px solid var(--border-color);
                margin-bottom: 20px;
                overflow-x: auto;
                scrollbar-width: none;
            }

            .tabs::-webkit-scrollbar {
                display: none;
            }

            .tab {
                padding: 10px 20px;
                cursor: pointer;
                border-bottom: 2px solid transparent;
                white-space: nowrap;
                transition: all 0.2s;
                font-weight: 500;
            }

            .tab:hover {
                background-color: rgba(0,0,0,0.02);
            }

            .tab.active {
                border-bottom-color: var(--primary-color);
                color: var(--primary-color);
                font-weight: 600;
            }

            .tab-content {
                display: none;
            }

            .tab-content.active {
                display: block;
                animation: fadeIn 0.3s ease;
            }

            /* View more button */
            .view-more-btn {
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 8px;
                background: linear-gradient(to bottom, rgba(255,255,255,0) 0%, var(--card-bg) 50%);
                position: absolute;
                bottom: 0;
                left: 0;
                right: 0;
                cursor: pointer;
                color: var(--primary-color);
                font-weight: 500;
                transition: all 0.2s;
                gap: 5px;
            }

            .view-more-btn:hover {
                color: var(--secondary-color);
            }

            /* Drag and drop files */
            .drop-zone {
                border: 2px dashed var(--border-color);
                border-radius: 5px;
                padding: 25px;
                text-align: center;
                cursor: pointer;
                margin-bottom: 15px;
                transition: border-color 0.3s, background-color 0.3s;
            }

            .drop-zone:hover, .drop-zone.active {
                border-color: var(--primary-color);
                background-color: rgba(52, 152, 219, 0.05);
            }

            .drop-zone-prompt {
                color: var(--text-muted);
                margin-bottom: 10px;
                font-size: 0.9rem;
            }

            .drop-zone-thumb {
                display: inline-flex;
                align-items: center;
                margin: 5px;
                padding: 5px 10px;
                background: rgba(0,0,0,0.05);
                border-radius: 4px;
                font-size: 0.9rem;
            }

            /* Collapsible sections */
            .collapsible-section {
                margin-bottom: 15px;
            }

            .collapsible-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 10px 15px;
                background-color: rgba(0,0,0,0.02);
                border-radius: 5px;
                cursor: pointer;
                transition: background-color 0.2s;
            }

            .collapsible-header:hover {
                background-color: rgba(0,0,0,0.05);
            }

            .collapsible-header h3 {
                margin: 0;
                font-size: 1rem;
                font-weight: 600;
            }

            .collapsible-content {
                padding: 15px 0;
                display: none;
            }

            .collapsible-section.open .collapsible-content {
                display: block;
                animation: fadeIn 0.3s ease;
            }

            .rotate-icon {
                transition: transform 0.3s;
            }

            .collapsible-section.open .rotate-icon {
                transform: rotate(180deg);
            }

            /* Temporary message */
            .temp-message {
                position: fixed;
                bottom: 20px;
                left: 50%;
                transform: translateX(-50%);
                padding: 12px 20px;
                background-color: var(--primary-color);
                color: white;
                border-radius: 5px;
                box-shadow: 0 3px 10px rgba(0,0,0,0.2);
                z-index: 1000;
                animation: fadeInUp 0.3s forwards, fadeOut 0.3s forwards 2s;
                display: flex;
                align-items: center;
                gap: 10px;
            }

            @keyframes fadeInUp {
                from {transform: translate(-50%, 20px); opacity: 0;}
                to {transform: translate(-50%, 0); opacity: 1;}
            }

            @keyframes fadeOut {
                from {opacity: 1; visibility: visible;}
                to {opacity: 0; visibility: hidden;}
            }

            /* Media viewer */
            .media-viewer {
                max-width: 100%;
                margin-top: 10px;
            }

            .media-viewer img,
            .media-viewer video {
                max-width: 100%;
                max-height: 400px;
                border-radius: 4px;
                display: block;
                margin: 0 auto;
                box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            }

            .file-viewer {
                max-height: 400px;
                overflow-y: auto;
                border: 1px solid var(--border-color);
                padding: 15px;
                border-radius: 4px;
                background-color: rgba(0,0,0,0.02);
                white-space: pre-wrap;
                font-family: monospace;
            }

            /* Notification system styling */
            .notification-indicator {
                position: relative;
                display: inline-block;
                width: 10px;
                height: 10px;
                background-color: var(--notification-color);
                border-radius: 50%;
                margin-left: 8px;
                animation: pulse 1.5s infinite;
            }

            /* Notification container */
            .notifications-container {
                position: absolute;
                top: 60px;
                right: 20px;
                background-color: var(--card-bg);
                border-radius: 8px;
                box-shadow: 0 5px 20px rgba(0,0,0,0.2);
                width: 350px;
                max-height: 500px;
                overflow: hidden;
                z-index: 1000;
                display: none;
                flex-direction: column;
                animation: slideInDown 0.3s ease;
                border: 1px solid var(--border-color);
            }

            @keyframes slideInDown {
                from {transform: translateY(-20px); opacity: 0;}
                to {transform: translateY(0); opacity: 1;}
            }

            .notifications-header {
                padding: 15px;
                border-bottom: 1px solid var(--border-color);
                display: flex;
                justify-content: space-between;
                align-items: center;
                position: sticky;
                top: 0;
                background-color: var(--card-bg);
                z-index: 2;
            }

            .notifications-header h3 {
                margin: 0;
                font-size: 1.1rem;
                font-weight: 600;
            }

            .notifications-content {
                overflow-y: auto;
                max-height: 400px;
                scrollbar-width: thin;
            }

            .notifications-content::-webkit-scrollbar {
                width: 6px;
            }

            .notifications-content::-webkit-scrollbar-thumb {
                background-color: var(--border-color);
                border-radius: 3px;
            }

            .notifications-content::-webkit-scrollbar-track {
                background-color: transparent;
            }

            .notification-item {
                padding: 12px 15px;
                border-bottom: 1px solid var(--border-color);
                transition: background-color 0.2s;
                cursor: pointer;
                position: relative;
            }

            .notification-item:hover {
                background-color: rgba(0,0,0,0.03);
            }

            .notification-item.unread {
                background-color: rgba(52, 152, 219, 0.08);
            }

            .notification-item.unread::before {
                content: '';
                position: absolute;
                left: 0;
                top: 0;
                bottom: 0;
                width: 3px;
                background-color: var(--primary-color);
            }

            .notification-icon {
                float: left;
                margin-right: 12px;
                width: 40px;
                height: 40px;
                border-radius: 50%;
                background-color: rgba(52, 152, 219, 0.1);
                display: flex;
                align-items: center;
                justify-content: center;
                color: var(--primary-color);
            }

            .notification-icon.status {
                background-color: rgba(46, 204, 113, 0.1);
                color: var(--success-color);
            }

            .notification-icon.comment {
                background-color: rgba(52, 152, 219, 0.1);
                color: var(--info-color);
            }

            .notification-icon.system {
                background-color: rgba(243, 156, 18, 0.1);
                color: var(--warning-color);
            }

            .notification-icon.alert {
                background-color: rgba(231, 76, 60, 0.1);
                color: var(--danger-color);
            }

            .notification-content {
                display: flex;
                flex-direction: column;
                margin-left: 52px;
            }

            .notification-title {
                font-weight: 600;
                margin-bottom: 3px;
                font-size: 0.95rem;
            }

            .notification-message {
                font-size: 0.85rem;
                margin-bottom: 5px;
                color: var(--text-muted);
                display: -webkit-box;
                -webkit-line-clamp: 2;
                -webkit-box-orient: vertical;
                overflow: hidden;
            }

            .notification-time {
                font-size: 0.75rem;
                color: var(--text-muted);
            }

            .mark-all-read {
                color: var(--primary-color);
                background: none;
                border: none;
                cursor: pointer;
                font-size: 0.8rem;
                padding: 5px 10px;
                border-radius: 4px;
                transition: background-color 0.2s;
            }

            .mark-all-read:hover {
                background-color: rgba(52, 152, 219, 0.1);
            }

            .notifications-empty {
                padding: 30px 20px;
                text-align: center;
                color: var(--text-muted);
            }

            .notifications-footer {
                padding: 10px 15px;
                border-top: 1px solid var(--border-color);
                display: flex;
                justify-content: center;
                background-color: var(--card-bg);
            }

            .notifications-footer a {
                color: var(--primary-color);
                text-decoration: none;
                font-size: 0.9rem;
            }

            /* Header notifications */
            .notifications-bell {
                position: relative;
                margin-right: 20px;
                font-size: 1.2rem;
                color: var(--text-color);
                cursor: pointer;
                width: 40px;
                height: 40px;
                display: flex;
                align-items: center;
                justify-content: center;
                border-radius: 50%;
                transition: background-color 0.2s;
            }

            .notifications-bell:hover {
                background-color: rgba(0,0,0,0.05);
            }

            .notifications-count {
                position: absolute;
                top: 0;
                right: 0;
                background-color: var(--notification-color);
                color: white;
                border-radius: 10px;
                min-width: 18px;
                height: 18px;
                padding: 0 5px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 0.7rem;
                font-weight: bold;
                box-shadow: 0 2px 5px rgba(0,0,0,0.2);
            }

            /* Status update notification */
            .status-update {
                display: flex;
                align-items: flex-start;
                background-color: rgba(52, 152, 219, 0.08);
                padding: 10px 15px;
                border-radius: 4px;
                margin: 10px 0;
            }

            .status-update-icon {
                margin-right: 10px;
                color: var(--primary-color);
                flex-shrink: 0;
                margin-top: 3px;
            }

            .status-update-message {
                font-size: 0.9rem;
                flex: 1;
            }

            /* Theme selector */
            .theme-selector {
                display: flex;
                gap: 8px;
            }

            .theme-option {
                width: 25px;
                height: 25px;
                border-radius: 50%;
                cursor: pointer;
                border: 2px solid transparent;
                transition: transform 0.2s, border-color 0.2s;
            }

            .theme-option:hover {
                transform: scale(1.1);
            }

            .theme-option.active {
                border-color: var(--primary-color);
            }

            .theme-option-light {
                background-color: #f8f9fa;
                box-shadow: 0 0 0 1px #ddd;
            }

            .theme-option-dark {
                background-color: #18191a;
                box-shadow: 0 0 0 1px #333;
            }

            .theme-option-blue {
                background-color: #e3f2fd;
                box-shadow: 0 0 0 1px #bbdefb;
            }

            /* Mobile-specific styles */
            .mobile-notifications-btn {
                display: none;
                position: fixed;
                bottom: 20px;
                right: 20px;
                width: 50px;
                height: 50px;
                border-radius: 50%;
                background-color: var(--primary-color);
                color: white;
                justify-content: center;
                align-items: center;
                box-shadow: 0 4px 10px rgba(0,0,0,0.2);
                z-index: 99;
                animation: fadeIn 0.3s ease;
            }

            /* Push notification styling */
            .push-notification-panel {
                max-width: 500px;
                margin: 0 auto;
                padding: 20px;
                background-color: var(--card-bg);
                border-radius: 8px;
                box-shadow: var(--shadow);
            }

            .push-notification-status {
                padding: 15px;
                border-radius: 8px;
                background-color: rgba(46, 204, 113, 0.1);
                margin-bottom: 15px;
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .push-notification-status.not-supported {
                background-color: rgba(231, 76, 60, 0.1);
                color: var(--danger-color);
            }

            .push-notification-status.not-enabled {
                background-color: rgba(243, 156, 18, 0.1);
                color: var(--warning-color);
            }

            /* Animation for new notifications */
            @keyframes newNotification {
                0% { transform: scale(1); }
                50% { transform: scale(1.2); }
                100% { transform: scale(1); }
            }

            .new-notification {
                animation: newNotification 0.5s ease-in-out;
            }

            /* Required fields */
            .required-field::after {
                content: " *";
                color: var(--danger-color);
            }

            /* Highlight animation for order cards */
            @keyframes highlight {
                0% { background-color: rgba(52, 152, 219, 0.2); }
                100% { background-color: transparent; }
            }

            .highlight-animation {
                animation: highlight 2s ease;
            }

            /* Responsive styles */
            @media (max-width: 768px) {
                .sidebar {
                    width: var(--sidebar-collapsed-width);
                    transform: translateX(-100%);
                    position: fixed;
                    z-index: 1000;
                }

                .sidebar.expanded {
                    transform: translateX(0);
                    width: 250px;
                }

                .main-content {
                    margin-left: 0;
                    padding: 15px;
                }

                .header {
                    flex-direction: column;
                    align-items: flex-start;
                    gap: 10px;
                }

                .header-actions {
                    width: 100%;
                    justify-content: space-between;
                }

                .order-header {
                    flex-direction: column;
                    align-items: flex-start;
                    gap: 10px;
                }

                .order-meta {
                    width: 100%;
                    justify-content: space-between;
                }

                .filters-bar {
                    flex-direction: column;
                    gap: 10px;
                }

                .filter-group {
                    width: 100%;
                    justify-content: space-between;
                }

                .search-bar {
                    max-width: none;
                    width: 100%;
                }

                .notifications-container {
                    width: 100%;
                    max-width: none;
                    left: 0;
                    right: 0;
                    top: 0;
                    bottom: 0;
                    max-height: 100%;
                    border-radius: 0;
                }

                .notifications-content {
                    max-height: calc(100vh - 120px);
                }

                .mobile-notifications-btn {
                    display: flex;
                }

                .modal-content {
                    width: 95%;
                    margin: 10px auto;
                    max-height: 95vh;
                }

                .tabs {
                    flex-wrap: nowrap;
                    overflow-x: auto;
                }

                .tab {
                    flex: 0 0 auto;
                }
            }
        </style>
        <!-- Manifest file for web push notifications -->
        <link rel="manifest" href="../manifest.json">
    </head>
<body>
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <span class="logo-text">Сервісний центр</span>
            </div>
            <button class="toggle-sidebar" id="toggle-sidebar">
                <i class="fas fa-bars"></i>
            </button>
        </div>

        <div class="user-info">
            <div class="user-avatar">
                <?php if (!empty($user_data['profile_pic']) && file_exists('../' . $user_data['profile_pic'])): ?>
                    <img src="../<?= htmlspecialchars($user_data['profile_pic']) ?>" alt="Фото профілю">
                <?php else: ?>
                    <i class="fas fa-user-circle fa-3x" style="color: #ccc;"></i>
                <?php endif; ?>
            </div>
            <div class="user-name"><?= htmlspecialchars($username) ?></div>
        </div>

        <ul class="sidebar-menu">
            <li>
                <a href="#dashboard" class="active" data-tab="dashboard">
                    <i class="fas fa-home icon"></i>
                    <span class="menu-text">Головна</span>
                </a>
            </li>
            <li>
                <a href="#orders" data-tab="orders">
                    <i class="fas fa-list-alt icon"></i>
                    <span class="menu-text">Мої замовлення</span>
                    <?php if ($unread_notifications_count > 0): ?>
                        <span class="notification-badge"><?= $unread_notifications_count > 9 ? '9+' : $unread_notifications_count ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li>
                <a href="#new-order" data-tab="new-order">
                    <i class="fas fa-plus icon"></i>
                    <span class="menu-text">Створити замовлення</span>
                </a>
            </li>
            <li>
                <a href="#notifications" data-tab="notifications">
                    <i class="fas fa-bell icon"></i>
                    <span class="menu-text">Сповіщення</span>
                    <?php if ($unread_notifications_count > 0): ?>
                        <span class="notification-badge"><?= $unread_notifications_count > 9 ? '9+' : $unread_notifications_count ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li>
                <a href="#settings" data-tab="settings">
                    <i class="fas fa-cog icon"></i>
                    <span class="menu-text">Налаштування</span>
                </a>
            </li>
            <li>
                <a href="../logout.php">
                    <i class="fas fa-sign-out-alt icon"></i>
                    <span class="menu-text">Вийти</span>
                </a>
            </li>
        </ul>
    </div>

    <!-- Main content -->
<div class="main-content">
    <div class="header">
        <div class="header-title">
            <h1>Особистий кабінет</h1>
        </div>
        <div class="header-actions">
            <!-- Notifications bell icon -->
            <div class="notifications-bell" id="notifications-toggle">
                <i class="fas fa-bell"></i>
                <?php if ($unread_notifications_count > 0): ?>
                    <span class="notifications-count"><?= $unread_notifications_count > 9 ? '9+' : $unread_notifications_count ?></span>
                <?php endif; ?>
            </div>

            <div class="current-time" id="current-time">
                <?= date('d.m.Y H:i') ?>
            </div>
            <div class="theme-selector">
                <div class="theme-option theme-option-light active" data-theme="light" title="Світла тема"></div>
                <div class="theme-option theme-option-dark" data-theme="dark" title="Темна тема"></div>
                <div class="theme-option theme-option-blue" data-theme="blue" title="Синя тема"></div>
            </div>
        </div>
    </div>

    <!-- Notifications dropdown -->
    <div class="notifications-container" id="notifications-container">
    <div class="notifications-header">
        <h3>Сповіщення</h3>
        <?php if ($unread_notifications_count > 0): ?>
            <button class="mark-all-read" id="mark-all-read">
                <i class="fas fa-check-double"></i> Позначити всі як прочитані
            </button>
        <?php endif; ?>
    </div>
    <div class="notifications-content">
<?php if (!empty($user_notifications)): ?>
    <?php foreach ($user_notifications as $notification): ?>
    <div class="notification-item <?= $notification['is_read'] ? '' : 'unread' ?>"
         data-id="<?= $notification['id'] ?>"
         data-order-id="<?= $notification['order_id'] ?>"
         data-link="<?= htmlspecialchars($notification['link']) ?>">
        <div class="notification-icon <?= htmlspecialchars($notification['type']) ?>">
            <i class="fas <?= htmlspecialchars($notification['icon']) ?>"></i>
        </div>
        <div class="notification-content">
        <div class="notification-title"><?= htmlspecialchars($notification['title']) ?></div>
            <div class="notification-message"><?= htmlspecialchars($notification['message']) ?></div>
            <div class="notification-time"><?= date('d.m.Y H:i', strtotime($notification['created_at'])) ?></div>
        </div>
    </div>
    <?php endforeach; ?>
<?php else: ?>
    <div class="notifications-empty">
        <div><i class="fas fa-bell-slash fa-2x" style="color: var(--text-muted); margin-bottom: 10px;"></i></div>
        <div>У вас немає сповіщень</div>
    </div>
<?php endif; ?>
    </div>
        <div class="notifications-footer">
            <a href="#notifications" data-tab="notifications">Переглянути всі сповіщення</a>
        </div>
    </div>

    <?php if ($block_message): ?>
        <div class="alert alert-block">
            <?= htmlspecialchars($block_message) ?>
            <p>Ваш обліковий запис буде розблоковано: <?= date('d.m.Y H:i', strtotime($_SESSION['blocked_until'])) ?></p>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <!-- Dashboard tab -->
    <div class="tab-content active" id="dashboard-content">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Статистика</h2>
            </div>
            <div class="order-stats">
                <p><i class="fas fa-clipboard-list fa-fw"></i> Активних замовлень: <strong><?= $active_orders_count ?></strong></p>
                <p><i class="fas fa-history fa-fw"></i> Всього замовлень: <strong><?= count($orders) ?></strong></p>
                <?php if ($unread_notifications_count > 0): ?>
                    <p><i class="fas fa-bell fa-fw"></i> Непрочитаних сповіщень: <strong><?= $unread_notifications_count ?></strong></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Останні замовлення</h2>
                <a href="#orders" class="btn-text view-all" data-tab="orders">Переглянути всі</a>
            </div>
            <?php
            $recent_orders = array_slice($orders, 0, 3);
            if (!empty($recent_orders)):
                foreach ($recent_orders as $order):
                    $has_notifications = $order['unread_notifications'] > 0;
                    ?>
                    <div class="order-card <?= $has_notifications ? 'has-notifications' : '' ?>" data-order-id="<?= $order['id'] ?>">
                        <div class="order-header">
                            <h3 class="order-id">
                                Замовлення #<?= $order['id'] ?>
                                <?php if ($has_notifications): ?>
                                    <span class="notification-indicator" title="Нові сповіщення"></span>
                                <?php endif; ?>
                            </h3>
                            <div class="order-meta">
                                    <span class="status-badge status-<?= strtolower(str_replace(' ', '-', $order['status'])) ?>">
                                        <i class="fas fa-circle" style="font-size: 8px;"></i>
                                        <?= htmlspecialchars($order['status']) ?>
                                    </span>
                                <span class="order-date">
                                        <i class="far fa-calendar-alt"></i>
                                        <span class="local-time" data-utc="<?= $order['created_at'] ?>">
                                            <?= date('d.m.Y H:i', strtotime($order['created_at'])) ?>
                                        </span>
                                    </span>
                            </div>
                        </div>
                        <div class="order-body">
                            <div class="order-detail">
                                <div class="order-detail-label">Послуга:</div>
                                <div><?= htmlspecialchars($order['service']) ?></div>
                            </div>
                            <div class="order-detail">
                                <div class="order-detail-label">Тип пристрою:</div>
                                <div><?= htmlspecialchars($order['device_type']) ?></div>
                            </div>
                            <div class="order-detail">
                                <div class="order-detail-label">Деталі:</div>
                                <div><?= nl2br(htmlspecialchars($order['details'])) ?></div>
                            </div>

                            <?php if ($has_notifications): ?>
                                <div class="status-update">
                                    <i class="fas fa-bell status-update-icon"></i>
                                    <div class="status-update-message">
                                        <?php if ($order['status_changed']): ?>
                                            <div>Статус замовлення змінено на: <strong><?= htmlspecialchars($order['status']) ?></strong></div>
                                        <?php endif; ?>
                                        <?php if ($order['new_comments_count'] > 0): ?>
                                            <div>Нові коментарі від адміністратора (<?= $order['new_comments_count'] ?>)</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($order['files'])): ?>
                                <div class="order-files">
                                    <div class="order-detail-label">Файли:</div>
                                    <div class="file-list">
                                        <?php foreach ($order['files'] as $file): ?>
                                            <div class="file-item">
                                                <?php
                                                $ext = pathinfo($file['file_name'], PATHINFO_EXTENSION);
                                                $icon = 'fa-file';
                                                if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) {
                                                    $icon = 'fa-file-image';
                                                } elseif (in_array($ext, ['mp4', 'avi', 'mov'])) {
                                                    $icon = 'fa-file-video';
                                                } elseif (in_array($ext, ['pdf'])) {
                                                    $icon = 'fa-file-pdf';
                                                } elseif (in_array($ext, ['doc', 'docx'])) {
                                                    $icon = 'fa-file-word';
                                                } elseif (in_array($ext, ['txt'])) {
                                                    $icon = 'fa-file-alt';
                                                }
                                                ?>
                                                <i class="fas <?= $icon ?> file-icon"></i>
                                                <span class="file-name"><?= htmlspecialchars($file['file_name']) ?></span>
                                                <div class="file-actions">
                                                    <button class="btn btn-sm view-file" data-path="../<?= htmlspecialchars($file['file_path']) ?>" data-filename="<?= htmlspecialchars($file['file_name']) ?>">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <a class="btn btn-sm" href="../<?= htmlspecialchars($file['file_path']) ?>" download="<?= htmlspecialchars($file['file_name']) ?>">
                                                        <i class="fas fa-download"></i>
                                                    </a>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($order['comments'])): ?>
                                <div class="comments-section">
                                    <div class="order-detail-label">Коментарі адміністратора:</div>
                                    <?php foreach ($order['comments'] as $comment): ?>
                                        <div class="comment">
                                            <div class="comment-header">
                                                    <span class="comment-author">
                                                        <i class="fas fa-user-shield"></i>
                                                        <?= htmlspecialchars($comment['admin_name'] ?? 'Адмін') ?>
                                                    </span>
                                                <span class="comment-date local-time" data-utc="<?= $comment['created_at'] ?>">
                                                        <?= date('d.m.Y H:i', strtotime($comment['created_at'])) ?>
                                                    </span>
                                            </div>
                                            <div class="comment-body">
                                                <?= nl2br(htmlspecialchars($comment['content'])) ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($order['user_comment']): ?>
                                <div class="user-comment-section">
                                    <div class="order-detail-label">Ваш коментар:</div>
                                    <div class="comment">
                                        <?= nl2br(htmlspecialchars($order['user_comment'])) ?>
                                        <?php if (!$order['is_closed'] && !$block_message): ?>
                                            <button class="btn btn-sm btn-danger delete-comment" data-id="<?= $order['id'] ?>" title="Видалити коментар" style="float:right; margin-top: 5px;">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="view-more-btn">
                                Переглянути повну інформацію <i class="fas fa-chevron-down"></i>
                            </div>

                            <?php if (!$order['is_closed'] && !$block_message): ?>
                                <div class="order-actions">
                                    <button class="btn btn-sm edit-order" data-id="<?= $order['id'] ?>">
                                        <i class="fas fa-edit"></i> Редагувати
                                    </button>
                                    <button class="btn btn-sm btn-primary add-comment" data-id="<?= $order['id'] ?>">
                                        <i class="fas fa-comment"></i> Додати коментар
                                    </button>
                                </div>
                            <?php elseif ($order['is_closed']): ?>
                                <div class="order-closed-notice" style="margin-top: 15px; color: var(--text-muted);">
                                    <em><i class="fas fa-lock"></i> Замовлення завершено, редагування недоступне</em>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p>Ви ще не маєте замовлень. Створіть нове замовлення.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Orders tab -->
    <div class="tab-content" id="orders-content">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Мої замовлення</h2>
            </div>

            <div class="filters-bar">
                <form action="" method="get" class="filter-form" id="filter-form">
                    <div class="filter-group">
                        <select name="status" class="form-control">
                            <option value="">Всі статуси</option>
                            <?php foreach ($statuses as $status): ?>
                                <option value="<?= htmlspecialchars($status) ?>" <?= $filter_status === $status ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($status) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <select name="service" class="form-control">
                            <option value="">Всі послуги</option>
                            <?php foreach ($services as $service): ?>
                                <option value="<?= htmlspecialchars($service) ?>" <?= $filter_service === $service ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($service) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <div class="search-bar">
                            <i class="fas fa-search search-icon"></i>
                            <input type="text" name="search" class="form-control" placeholder="Пошук замовлень..." value="<?= htmlspecialchars($search_query) ?>">
                        </div>

                        <button type="submit" class="btn">
                            <i class="fas fa-filter"></i> Фільтрувати
                        </button>
                    </div>
                </form>
            </div>

            <?php if (!empty($orders)): ?>
                <div class="orders-list">
                    <?php foreach ($orders as $order):
                        $has_notifications = $order['unread_notifications'] > 0;
                        ?>
                        <div class="order-card <?= $has_notifications ? 'has-notifications' : '' ?>" data-order-id="<?= $order['id'] ?>">
                            <div class="order-header">
                                <h3 class="order-id">
                                    Замовлення #<?= $order['id'] ?>
                                    <?php if ($has_notifications): ?>
                                        <span class="notification-indicator" title="Нові сповіщення"></span>
                                    <?php endif; ?>
                                </h3>
                                <div class="order-meta">
                                        <span class="status-badge status-<?= strtolower(str_replace(' ', '-', $order['status'])) ?>">
                                            <i class="fas fa-circle" style="font-size: 8px;"></i>
                                            <?= htmlspecialchars($order['status']) ?>
                                        </span>
                                    <span class="order-date">
                                            <i class="far fa-calendar-alt"></i>
                                            <span class="local-time" data-utc="<?= $order['created_at'] ?>">
                                                <?= date('d.m.Y H:i', strtotime($order['created_at'])) ?>
                                            </span>
                                        </span>
                                </div>
                            </div>
                            <div class="order-body">
                                <div class="order-detail">
                                    <div class="order-detail-label">Послуга:</div>
                                    <div><?= htmlspecialchars($order['service']) ?></div>
                                </div>
                                <div class="order-detail">
                                    <div class="order-detail-label">Тип пристрою:</div>
                                    <div><?= htmlspecialchars($order['device_type']) ?></div>
                                </div>
                                <div class="order-detail">
                                    <div class="order-detail-label">Деталі:</div>
                                    <div><?= nl2br(htmlspecialchars($order['details'])) ?></div>
                                </div>
                                <div class="order-detail">
                                    <div class="order-detail-label">Контактний телефон:</div>
                                    <div><?= htmlspecialchars($order['phone']) ?></div>
                                </div>
                                <?php if (!empty($order['address'])): ?>
                                    <div class="order-detail">
                                        <div class="order-detail-label">Адреса:</div>
                                        <div><?= htmlspecialchars($order['address']) ?></div>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($order['delivery_method'])): ?>
                                    <div class="order-detail">
                                        <div class="order-detail-label">Спосіб доставки:</div>
                                        <div><?= htmlspecialchars($order['delivery_method']) ?></div>
                                    </div>
                                <?php endif; ?>

                                <?php if ($has_notifications): ?>
                                    <div class="status-update">
                                        <i class="fas fa-bell status-update-icon"></i>
                                        <div class="status-update-message">
                                            <?php if ($order['status_changed']): ?>
                                                <div>Статус замовлення змінено на: <strong><?= htmlspecialchars($order['status']) ?></strong></div>
                                            <?php endif; ?>
                                            <?php if ($order['new_comments_count'] > 0): ?>
                                                <div>Нові коментарі від адміністратора (<?= $order['new_comments_count'] ?>)</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($order['files'])): ?>
                                    <div class="order-files">
                                        <div class="order-detail-label">Файли:</div>
                                        <div class="file-list">
                                            <?php foreach ($order['files'] as $file): ?>
                                                <div class="file-item">
                                                    <?php
                                                    $ext = pathinfo($file['file_name'], PATHINFO_EXTENSION);
                                                    $icon = 'fa-file';
                                                    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) {
                                                        $icon = 'fa-file-image';
                                                    } elseif (in_array($ext, ['mp4', 'avi', 'mov'])) {
                                                        $icon = 'fa-file-video';
                                                    } elseif (in_array($ext, ['pdf'])) {
                                                        $icon = 'fa-file-pdf';
                                                    } elseif (in_array($ext, ['doc', 'docx'])) {
                                                        $icon = 'fa-file-word';
                                                    } elseif (in_array($ext, ['txt'])) {
                                                        $icon = 'fa-file-alt';
                                                    }
                                                    ?>
                                                    <i class="fas <?= $icon ?> file-icon"></i>
                                                    <span class="file-name"><?= htmlspecialchars($file['file_name']) ?></span>
                                                    <div class="file-actions">
                                                        <button class="btn btn-sm view-file" data-path="../<?= htmlspecialchars($file['file_path']) ?>" data-filename="<?= htmlspecialchars($file['file_name']) ?>">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                        <a class="btn btn-sm" href="../<?= htmlspecialchars($file['file_path']) ?>" download="<?= htmlspecialchars($file['file_name']) ?>">
                                                            <i class="fas fa-download"></i>
                                                        </a>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($order['comments'])): ?>
                                    <div class="comments-section">
                                        <div class="order-detail-label">Коментарі адміністратора:</div>
                                        <?php foreach ($order['comments'] as $comment): ?>
                                            <div class="comment">
                                                <div class="comment-header">
                                                        <span class="comment-author">
                                                            <i class="fas fa-user-shield"></i>
                                                            <?= htmlspecialchars($comment['admin_name'] ?? 'Адмін') ?>
                                                        </span>
                                                    <span class="comment-date local-time" data-utc="<?= $comment['created_at'] ?>">
                                                            <?= date('d.m.Y H:i', strtotime($comment['created_at'])) ?>
                                                        </span>
                                                </div>
                                                <div class="comment-body">
                                                    <?= nl2br(htmlspecialchars($comment['content'])) ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if ($order['user_comment']): ?>
                                    <div class="user-comment-section">
                                        <div class="order-detail-label">Ваш коментар:</div>
                                        <div class="comment">
                                            <?= nl2br(htmlspecialchars($order['user_comment'])) ?>
                                            <?php if (!$order['is_closed'] && !$block_message): ?>
                                                <button class="btn btn-sm btn-danger delete-comment" data-id="<?= $order['id'] ?>" title="Видалити коментар" style="float:right; margin-top: 5px;">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <div class="view-more-btn">
                                    Переглянути повну інформацію <i class="fas fa-chevron-down"></i>
                                </div>

                                <?php if (!$order['is_closed'] && !$block_message): ?>
                                    <div class="order-actions">
                                        <button class="btn btn-sm edit-order" data-id="<?= $order['id'] ?>">
                                            <i class="fas fa-edit"></i> Редагувати
                                        </button>
                                        <button class="btn btn-sm btn-primary add-comment" data-id="<?= $order['id'] ?>">
                                            <i class="fas fa-comment"></i> Додати коментар
                                        </button>
                                    </div>
                                <?php elseif ($order['is_closed']): ?>
                                    <div class="order-closed-notice" style="margin-top: 15px; color: var(--text-muted);">
                                        <em><i class="fas fa-lock"></i> Замовлення завершено, редагування недоступне</em>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div style="text-align: center; padding: 30px 0;">
                    <i class="fas fa-clipboard-list fa-3x" style="color: var(--text-muted); margin-bottom: 15px;"></i>
                    <p>Замовлення не знайдені.</p>
                    <a href="#new-order" class="btn" data-tab="new-order" style="margin-top: 15px;">
                        <i class="fas fa-plus"></i> Створити замовлення
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- New order tab -->
    <div class="tab-content" id="new-order-content">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Створення нового замовлення</h2>
            </div>

            <?php if ($active_orders_count >= 5): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> Ви досягли максимальної кількості активних замовлень (5).
                    Будь ласка, дочекайтесь обробки існуючих замовлень.
                </div>
            <?php else: ?>
                <form action="dashboard.php" method="post" enctype="multipart/form-data" id="create-order-form">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="create_order" value="1">
                    <input type="hidden" name="dropped_files" id="dropped_files_data" value="">

                    <div class="form-group">
                        <label for="service" class="required-field">Послуга</label>
                        <select name="service" id="service" class="form-control" required>
                            <option value="">Виберіть послугу</option>
                            <option value="Ремонт комп'ютера">Ремонт комп'ютера</option>
                            <option value="Ремонт ноутбука">Ремонт ноутбука</option>
                            <option value="Ремонт телефону">Ремонт телефону</option>
                            <option value="Ремонт планшету">Ремонт планшету</option>
                            <option value="Діагностика">Діагностика</option>
                            <option value="Апгрейд">Апгрейд</option>
                            <option value="Інше">Інше</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="device_type" class="required-field">Тип пристрою</label>
                        <select name="device_type" id="device_type" class="form-control" required>
                            <option value="">Виберіть тип пристрою</option>
                            <option value="Комп'ютер">Комп'ютер</option>
                            <option value="Ноутбук">Ноутбук</option>
                            <option value="Телефон">Телефон</option>
                            <option value="Планшет">Планшет</option>
                            <option value="Інше">Інше</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="details" class="required-field">Деталі проблеми</label>
                        <textarea name="details" id="details" class="form-control" required
                                  placeholder="Опишіть проблему детально. Вкажіть модель пристрою, симптоми несправності, тощо."
                        ></textarea>
                    </div>

                    <div class="form-group">
                        <label for="drop-zone">Додаткові файли (фото, відео, документи)</label>
                        <div id="drop-zone" class="drop-zone">
                                <span class="drop-zone-prompt">
                                    <i class="fas fa-cloud-upload-alt"></i> Перетягніть файли сюди або натисніть для вибору
                                </span>
                            <input type="file" name="order_files[]" id="drop-zone-input" multiple accept=".jpg,.jpeg,.png,.gif,.mp4,.avi,.mov,.pdf,.doc,.docx,.txt" class="drop-zone-input" style="display: none;">
                            <div id="file-preview-container" style="margin-top: 15px;"></div>
                        </div>
                        <small style="color: var(--text-muted);">Підтримувані формати: JPG, PNG, GIF, MP4, AVI, MOV, PDF, DOC, DOCX, TXT. Макс. розмір: 10 МБ.</small>
                    </div>

                    <div class="collapsible-section">
                        <div class="collapsible-header">
                            <h3>Контактна інформація</h3>
                            <i class="fas fa-chevron-down rotate-icon"></i>
                        </div>
                        <div class="collapsible-content">
                            <div class="form-group">
                                <label for="phone" class="required-field">Телефон</label>
                                <input type="tel" name="phone" id="phone" class="form-control" value="<?= htmlspecialchars($user_data['phone'] ?? '') ?>" required
                                       placeholder="+380xxxxxxxxx">
                            </div>

                            <div class="form-group">
                                <label for="address" class="required-field">Адреса</label>
                                <input type="text" name="address" id="address" class="form-control" value="<?= htmlspecialchars($user_data['address'] ?? '') ?>" required
                                       placeholder="Місто, вулиця, будинок, квартира">
                            </div>

                            <div class="form-group">
                                <label for="delivery_method" class="required-field">Спосіб доставки</label>
                                <select name="delivery_method" id="delivery_method" class="form-control" required>
                                    <option value="">Виберіть спосіб доставки</option>
                                    <option value="Самовивіз" <?= ($user_data['delivery_method'] ?? '') === 'Самовивіз' ? 'selected' : '' ?>>Самовивіз</option>
                                    <option value="Кур'єр" <?= ($user_data['delivery_method'] ?? '') === 'Кур\'єр' ? 'selected' : '' ?>>Кур'єр</option>
                                    <option value="Нова Пошта" <?= ($user_data['delivery_method'] ?? '') === 'Нова Пошта' ? 'selected' : '' ?>>Нова Пошта</option>
                                    <option value="Укрпошта" <?= ($user_data['delivery_method'] ?? '') === 'Укрпошта' ? 'selected' : '' ?>>Укрпошта</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="first_name">Ім'я</label>
                                <input type="text" name="first_name" id="first_name" class="form-control" value="<?= htmlspecialchars($user_data['first_name'] ?? '') ?>">
                            </div>

                            <div class="form-group">
                                <label for="last_name">Прізвище</label>
                                <input type="text" name="last_name" id="last_name" class="form-control" value="<?= htmlspecialchars($user_data['last_name'] ?? '') ?>">
                            </div>

                            <div class="form-group">
                                <label for="middle_name">По батькові</label>
                                <input type="text" name="middle_name" id="middle_name" class="form-control" value="<?= htmlspecialchars($user_data['middle_name'] ?? '') ?>">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-plus"></i> Створити замовлення
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Notifications tab -->
    <div class="tab-content" id="notifications-content">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Сповіщення</h2>
                <?php if ($unread_notifications_count > 0): ?>
                    <button class="btn btn-sm" id="global-mark-all-read">
                        <i class="fas fa-check-double"></i> Позначити всі як прочитані
                    </button>
                <?php endif; ?>
            </div>

            <div class="tabs">
                <div class="tab active" data-notification-type="all">Всі</div>
                <div class="tab" data-notification-type="unread">Непрочитані
                    <?php if ($unread_notifications_count > 0): ?>
                        <span class="badge" style="background-color: var(--notification-color); color: white; border-radius: 50%; width: 20px; height: 20px; display: inline-flex; align-items: center; justify-content: center; font-size: 0.7rem; margin-left: 5px;"><?= $unread_notifications_count ?></span>
                    <?php endif; ?>
                </div>
                <div class="tab" data-notification-type="order">Замовлення</div>
                <div class="tab" data-notification-type="status_change">Статуси</div>
                <div class="tab" data-notification-type="admin_comment">Коментарі</div>
                <div class="tab" data-notification-type="system">Системні</div>
            </div>

            <div id="notifications-list">
                <?php if (!empty($user_notifications)): ?>
                    <?php foreach ($user_notifications as $notification): ?>
                        <div class="notification-item <?= $notification['is_read'] ? '' : 'unread' ?>"
                             data-id="<?= $notification['id'] ?>"
                             data-type="<?= htmlspecialchars($notification['type']) ?>"
                             data-order-id="<?= $notification['order_id'] ?>"
                             data-link="<?= htmlspecialchars($notification['link']) ?>">
                            <div class="notification-icon <?= htmlspecialchars($notification['type']) ?>">
                                <i class="fas <?= htmlspecialchars($notification['icon']) ?>"></i>
                            </div>
                            <div class="notification-content">
                                <div class="notification-title"><?= htmlspecialchars($notification['title']) ?></div>
                                <div class="notification-message"><?= htmlspecialchars($notification['message']) ?></div>
                                <div class="notification-time"><?= date('d.m.Y H:i', strtotime($notification['created_at'])) ?></div>
                            </div>
                            <?php if (!$notification['is_read']): ?>
                                <button class="btn btn-sm mark-read" data-id="<?= $notification['id'] ?>" title="Позначити як прочитане">
                                    <i class="fas fa-check"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="notifications-empty">
                        <div><i class="fas fa-bell-slash fa-3x" style="color: var(--text-muted); margin-bottom: 15px;"></i></div>
                        <div>У вас немає сповіщень</div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Settings tab -->
    <div class="tab-content" id="settings-content">
        <div class="tabs">
            <div class="tab active" data-target="profile">Профіль</div>
            <div class="tab" data-target="security">Безпека</div>
            <div class="tab" data-target="notifications-settings">Сповіщення</div>
        </div>

        <!-- Profile settings -->
        <div class="tab-content active" id="profile-content">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Профіль користувача</h2>
                </div>

                <div style="display: flex; justify-content: center; margin-bottom: 20px;">
                    <form method="post" enctype="multipart/form-data" id="profile-pic-form">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="update_profile_pic" value="1">
                        <div id="profile-drop-zone" style="width: 120px; height: 120px; border-radius: 50%; overflow: hidden; border: 2px dashed var(--border-color); cursor: pointer; display: flex; align-items: center; justify-content: center; position: relative;">
                            <?php if (!empty($user_data['profile_pic']) && file_exists('../' . $user_data['profile_pic'])): ?>
                                <img src="../<?= htmlspecialchars($user_data['profile_pic']) ?>" alt="Фото профілю" class="profile-preview" style="width: 100%; height: 100%; object-fit: cover;">
                            <?php else: ?>
                                <i class="fas fa-user-circle fa-5x" style="color: #ccc;"></i>
                            <?php endif; ?>
                            <input type="file" name="profile_pic" id="profile_pic" style="display: none;" accept=".jpg,.jpeg,.png,.gif">
                            <div style="position: absolute; bottom: 0; left: 0; right: 0; background: rgba(0,0,0,0.6); color: white; font-size: 0.8rem; text-align: center; padding: 3px 0;">
                                <i class="fas fa-camera"></i> Змінити
                            </div>
                        </div>
                    </form>
                </div>

                <form action="dashboard.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="update_profile" value="1">

                    <div class="form-group">
                        <label for="profile_phone" class="required-field">Телефон</label>
                        <input type="tel" name="phone" id="profile_phone" class="form-control" value="<?= htmlspecialchars($user_data['phone'] ?? '') ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="profile_address" class="required-field">Адреса</label>
                        <input type="text" name="address" id="profile_address" class="form-control" value="<?= htmlspecialchars($user_data['address'] ?? '') ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="profile_delivery_method" class="required-field">Спосіб доставки за замовчуванням</label>
                        <select name="delivery_method" id="profile_delivery_method" class="form-control" required>
                            <option value="">Виберіть спосіб доставки</option>
                            <option value="Самовивіз" <?= ($user_data['delivery_method'] ?? '') === 'Самовивіз' ? 'selected' : '' ?>>Самовивіз</option>
                            <option value="Кур'єр" <?= ($user_data['delivery_method'] ?? '') === 'Кур\'єр' ? 'selected' : '' ?>>Кур'єр</option>
                            <option value="Нова Пошта" <?= ($user_data['delivery_method'] ?? '') === 'Нова Пошта' ? 'selected' : '' ?>>Нова Пошта</option>
                            <option value="Укрпошта" <?= ($user_data['delivery_method'] ?? '') === 'Укрпошта' ? 'selected' : '' ?>>Укрпошта</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="profile_first_name">Ім'я</label>
                        <input type="text" name="first_name" id="profile_first_name" class="form-control" value="<?= htmlspecialchars($user_data['first_name'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label for="profile_last_name">Прізвище</label>
                        <input type="text" name="last_name" id="profile_last_name" class="form-control" value="<?= htmlspecialchars($user_data['last_name'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label for="profile_middle_name">По батькові</label>
                        <input type="text" name="middle_name" id="profile_middle_name" class="form-control" value="<?= htmlspecialchars($user_data['middle_name'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <button type="submit" class="btn">
                            <i class="fas fa-save"></i> Зберегти зміни
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Security settings -->
        <div class="tab-content" id="security-content">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Зміна email</h2>
                </div>
                <form action="dashboard.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="update_email" value="1">

                    <div class="form-group">
                        <label for="current_email">Поточний email</label>
                        <input type="email" id="current_email" class="form-control" value="<?= htmlspecialchars($user_data['email'] ?? '') ?>" disabled>
                    </div>

                    <div class="form-group">
                        <label for="new_email" class="required-field">Новий email</label>
                        <input type="email" name="new_email" id="new_email" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label for="email_password" class="required-field">Пароль для підтвердження</label>
                        <input type="password" name="password" id="email_password" class="form-control" required>
                        <small class="text-muted">Введіть ваш поточний пароль для підтвердження зміни</small>
                    </div>

                    <div class="form-group">
                        <button type="submit" class="btn">
                            <i class="fas fa-envelope"></i> Змінити email
                        </button>
                    </div>
                </form>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Зміна логіна</h2>
                </div>
                <form action="dashboard.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="update_username" value="1">

                    <div class="form-group">
                        <label for="current_username">Поточний логін</label>
                        <input type="text" id="current_username" class="form-control" value="<?= htmlspecialchars($username) ?>" disabled>
                    </div>

                    <div class="form-group">
                        <label for="new_username" class="required-field">Новий логін</label>
                        <input type="text" name="new_username" id="new_username" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label for="username_password" class="required-field">Пароль для підтвердження</label>
                        <input type="password" name="password" id="username_password" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <button type="submit" class="btn">
                            <i class="fas fa-user-edit"></i> Змінити логін
                        </button>
                    </div>
                </form>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Зміна пароля</h2>
                </div>
                <form action="dashboard.php" method="post" id="password-form">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="update_password" value="1">

                    <div class="form-group">
                        <label for="current_password" class="required-field">Поточний пароль</label>
                        <input type="password" name="current_password" id="current_password" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label for="new_password" class="required-field">Новий пароль</label>
                        <input type="password" name="new_password" id="new_password" class="form-control" required minlength="8">
                        <small>Мінімум 8 символів</small>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password" class="required-field">Підтвердження пароля</label>
                        <input type="password" name="confirm_password" id="confirm_password" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <button type="submit" class="btn">
                            <i class="fas fa-lock"></i> Змінити пароль
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Notification settings -->
        <div class="tab-content" id="notifications-settings-content">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Налаштування сповіщень</h2>
                </div>
                <form action="dashboard.php" method="post" id="notification-preferences-form">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="save_notification_preferences" value="1">

                    <h3 style="margin: 20px 0 15px; font-size: 1.1rem;">Email сповіщення</h3>

                    <div class="form-group" style="display: flex; align-items: center;">
                        <label class="toggle-switch">
                            <input type="checkbox" name="email_notifications" id="email_notifications" <?= ($notification_prefs['email_notifications'] ?? true) ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                        <label for="email_notifications" style="margin-left: 15px; margin-bottom: 0;">Отримувати email сповіщення</label>
                    </div>

                    <div class="notification-sub-options" id="email-sub-options" style="margin-left: 30px; margin-bottom: 20px;">
                        <div class="form-group" style="display: flex; align-items: center; margin-bottom: 10px;">
                            <input type="checkbox" name="email_order_status" id="email_order_status" <?= ($notification_prefs['email_order_status'] ?? true) ? 'checked' : '' ?>>
                            <label for="email_order_status" style="margin-left: 10px; margin-bottom: 0;">Зміна статусу замовлення</label>
                        </div>

                        <div class="form-group" style="display: flex; align-items: center;">
                            <input type="checkbox" name="email_new_comment" id="email_new_comment" <?= ($notification_prefs['email_new_comment'] ?? true) ? 'checked' : '' ?>>
                            <label for="email_new_comment" style="margin-left: 10px; margin-bottom: 0;">Нові коментарі адміністратора</label>
                        </div>
                    </div>

                    <h3 style="margin: 20px 0 15px; font-size: 1.1rem;">Push сповіщення</h3>

                    <div class="form-group" style="display: flex; align-items: center;">
                        <label class="toggle-switch">
                            <input type="checkbox" name="push_notifications" id="push_notifications" <?= ($notification_prefs['push_notifications'] ?? false) ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                        <label for="push_notifications" style="margin-left: 15px; margin-bottom: 0;">Отримувати push сповіщення</label>
                    </div>

                    <div class="notification-sub-options" id="push-sub-options" style="margin-left: 30px; margin-bottom: 20px;">
                        <div class="form-group" style="display: flex; align-items: center; margin-bottom: 10px;">
                            <input type="checkbox" name="push_order_status" id="push_order_status" <?= ($notification_prefs['push_order_status'] ?? false) ? 'checked' : '' ?>>
                            <label for="push_order_status" style="margin-left: 10px; margin-bottom: 0;">Зміна статусу замовлення</label>
                        </div>

                        <div class="form-group" style="display: flex; align-items: center;">
                            <input type="checkbox" name="push_new_comment" id="push_new_comment" <?= ($notification_prefs['push_new_comment'] ?? false) ? 'checked' : '' ?>>
                            <label for="push_new_comment" style="margin-left: 10px; margin-bottom: 0;">Нові коментарі адміністратора</label>
                        </div>
                    </div>

                    <div id="push-notification-status" class="push-notification-panel">
                        <div class="push-notification-status not-supported" id="browser-support-status">
                            <i class="fas fa-exclamation-circle fa-fw"></i>
                            <div>Перевірка підтримки push-сповіщень...</div>
                        </div>

                        <div class="form-group" style="margin-top: 15px;">
                            <button type="button" id="enable-push-notifications" class="btn" style="display:none;">
                                <i class="fas fa-bell"></i> Підписатися на push-сповіщення
                            </button>
                        </div>
                    </div>

                    <div class="form-group" style="margin-top: 20px;">
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save"></i> Зберегти налаштування
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modals -->
    <!-- Add Comment Modal -->
    <div class="modal" id="add-comment-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Додати коментар до замовлення <span id="comment-order-id"></span></h3>
                <button type="button" class="close-modal">&times;</button>
            </div>
            <form id="add-comment-form" method="post">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="add_comment" value="1">
                <input type="hidden" name="order_id" id="comment-order-id-input" value="">

                <div class="form-group">
                    <label for="comment">Ваш коментар</label>
                    <textarea name="comment" id="comment" class="form-control" rows="5" required></textarea>
                </div>

                <div class="form-group">
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-paper-plane"></i> Додати коментар
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Order Modal -->
    <div class="modal" id="edit-order-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Редагування замовлення <span id="edit-order-id"></span></h3>
                <button type="button" class="close-modal">&times;</button>
            </div>
            <form id="edit-order-form" method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="edit_order" value="1">
                <input type="hidden" name="order_id" id="modal-order-id" value="">
                <input type="hidden" name="dropped_files" id="edit-dropped_files_data" value="">

                <div class="form-group">
                    <label for="edit-service" class="required-field">Послуга</label>
                    <select name="service" id="edit-service" class="form-control" required>
                        <option value="">Виберіть послугу</option>
                        <option value="Ремонт комп'ютера">Ремонт комп'ютера</option>
                        <option value="Ремонт ноутбука">Ремонт ноутбука</option>
                        <option value="Ремонт телефону">Ремонт телефону</option>
                        <option value="Ремонт планшету">Ремонт планшету</option>
                        <option value="Діагностика">Діагностика</option>
                        <option value="Апгрейд">Апгрейд</option>
                        <option value="Інше">Інше</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="edit-device_type" class="required-field">Тип пристрою</label>
                    <select name="device_type" id="edit-device_type" class="form-control" required>
                        <option value="">Виберіть тип пристрою</option>
                        <option value="Комп'ютер">Комп'ютер</option>
                        <option value="Ноутбук">Ноутбук</option>
                        <option value="Телефон">Телефон</option>
                        <option value="Планшет">Планшет</option>
                        <option value="Інше">Інше</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="edit-details" class="required-field">Деталі проблеми</label>
                    <textarea name="details" id="edit-details" class="form-control" required rows="5"></textarea>
                </div>

                <div class="form-group">
                    <label for="edit-user_comment">Ваш коментар</label>
                    <textarea name="user_comment" id="edit-user_comment" class="form-control" rows="3"></textarea>
                </div>

                <div class="form-group">
                    <label for="edit-drop-zone">Додати ще файли</label>
                    <div id="edit-drop-zone" class="drop-zone">
                        <span class="drop-zone-prompt">Перетягніть файли сюди або натисніть для вибору</span>
                        <input type="file" name="order_files[]" id="edit-drop-zone-input" multiple accept=".jpg,.jpeg,.png,.gif,.mp4,.avi,.mov,.pdf,.doc,.docx,.txt" class="drop-zone-input" style="display: none;">
                        <div id="edit-file-preview-container" style="margin-top: 15px;"></div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="edit-phone" class="required-field">Телефон</label>
                    <input type="tel" name="phone" id="edit-phone" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="edit-address" class="required-field">Адреса</label>
                    <input type="text" name="address" id="edit-address" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="edit-delivery_method" class="required-field">Спосіб доставки</label>
                    <select name="delivery_method" id="edit-delivery_method" class="form-control" required>
                        <option value="">Виберіть спосіб доставки</option>
                        <option value="Самовивіз">Самовивіз</option>
                        <option value="Кур'єр">Кур'єр</option>
                        <option value="Нова Пошта">Нова Пошта</option>
                        <option value="Укрпошта">Укрпошта</option>
                    </select>
                </div>

                <div class="form-group">
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save"></i> Зберегти зміни
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- File Viewer Modal -->
    <div class="modal" id="file-view-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="file-name-title">Перегляд файлу</h3>
                <button type="button" class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <div id="file-content-container" class="media-viewer"></div>
            </div>
        </div>
    </div>

    <!-- Mobile notifications button -->
    <div class="mobile-notifications-btn" id="mobile-notifications-btn">
        <i class="fas fa-bell"></i>
        <?php if ($unread_notifications_count > 0): ?>
            <span class="notifications-count"><?= $unread_notifications_count > 9 ? '9+' : $unread_notifications_count ?></span>
        <?php endif; ?>
    </div>

    <!-- Hidden CSRF token input for AJAX requests -->
    <input type="hidden" id="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

    <script>
        // Service Worker Registration for Push Notifications
        function registerServiceWorker() {
            if ('serviceWorker' in navigator) {
                navigator.serviceWorker.register('../sw.js')
                    .then(function(registration) {
                        console.log('Service Worker registered with scope:', registration.scope);
                        initPushNotifications(registration);
                    })
                    .catch(function(error) {
                        console.error('Service Worker registration failed:', error);
                        updatePushNotificationStatus('error', 'Помилка реєстрації Service Worker: ' + error);
                    });
            } else {
                console.warn('Service Worker not supported in this browser');
                updatePushNotificationStatus('not-supported', 'Ваш браузер не підтримує Push-сповіщення');
            }
        }

        // Push Notification Initialization
        function initPushNotifications(swRegistration) {
            // Check if Push API is supported
            if (!('PushManager' in window)) {
                console.warn('Push notifications not supported');
                updatePushNotificationStatus('not-supported', 'Ваш браузер не підтримує Push-сповіщення');
                return;
            }

            // Check permission status
            checkNotificationPermission().then(permission => {
                if (permission === 'granted') {
                    updatePushNotificationStatus('enabled', 'Push-сповіщення увімкнені');

                    // Update UI to show subscription is active
                    document.getElementById('push_notifications').checked = true;

                    // Get existing subscription
                    swRegistration.pushManager.getSubscription()
                        .then(subscription => {
                            if (!subscription) {
                                // User has permission but not subscribed yet
                                document.getElementById('enable-push-notifications