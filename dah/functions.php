<?php
/**
 * Файл з функціями для особистого кабінету користувача
 * Містить функції для роботи з базою даних, повідомленнями, сповіщеннями
 */

/**
 * Перевірка блокування користувача
 *
 * @param mysqli $conn З'єднання з базою даних
 * @param int $user_id ID користувача
 * @return array|false Дані про блокування або false
 */
function checkUserBlock($conn, $user_id) {
    $stmt = $conn->prepare("SELECT blocked_until, block_reason FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

/**
 * Отримання даних користувача
 *
 * @param mysqli $conn З'єднання з базою даних
 * @param int $user_id ID користувача
 * @return array Дані користувача
 */
function getUserData($conn, $user_id) {
    $stmt = $conn->prepare("SELECT email, first_name, last_name, middle_name, profile_pic, phone, address, delivery_method, notification_preferences FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

/**
 * Логування дій користувача
 *
 * @param mysqli $conn З'єднання з базою даних
 * @param int $user_id ID користувача
 * @param string $action Назва дії
 * @param string $details Деталі дії
 */
function logUserAction($conn, $user_id, $action, $details = '') {
    $stmt = $conn->prepare("INSERT INTO users_logs (user_id, action, details) VALUES (?, ?, ?)");
    $stmt->bind_param("iss", $user_id, $action, $details);
    $stmt->execute();
}

/**
 * Відправка email з можливістю прикріплення файлів
 *
 * @param string $to Email отримувача
 * @param string $subject Тема листа
 * @param string $message Вміст листа
 * @param array $attachments Прикріплені файли
 * @return bool Результат відправки
 */
function sendNotificationEmail($to, $subject, $message, $attachments = []) {
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = 'hostch02.fornex.host';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'lagodiy.info@lagodiy.com';
        $mail->Password   = '3zIDVnH#tu?2&uIn';
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
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

/**
 * Відправка push-сповіщень
 *
 * @param int $user_id ID користувача
 * @param string $title Заголовок сповіщення
 * @param string $body Текст сповіщення
 * @param mysqli $conn З'єднання з базою даних
 * @return bool Результат відправки
 */
function sendPushNotification($user_id, $title, $body, $conn) {
    try {
        // Отримання Firebase токенів користувача
        $stmt = $conn->prepare("SELECT push_token FROM user_push_tokens WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $tokens = [];
        while ($row = $result->fetch_assoc()) {
            $tokens[] = $row['push_token'];
        }

        if (empty($tokens)) {
            return false; // Немає зареєстрованих токенів для користувача
        }

        // Ініціалізація Firebase SDK
        $factory = (new Kreait\Firebase\Factory)->withServiceAccount('firebase-credentials.json');
        $messaging = $factory->createMessaging();

        // Налаштування повідомлення
        $config = Kreait\Firebase\Messaging\WebPushConfig::fromArray([
            'notification' => [
                'title' => $title,
                'body' => $body,
                'icon' => 'assets/images/logo.png',
                'click_action' => 'https://lagodiy.com/dan/dashboard.php'
            ]
        ]);

        // Відправка повідомлення всім токенам користувача
        foreach ($tokens as $token) {
            $message = Kreait\Firebase\Messaging\CloudMessage::withTarget('token', $token)
                ->withWebPushConfig($config);

            $messaging->send($message);
        }

        return true;
    } catch (\Exception $e) {
        return false;
    }
}

/**
 * Обробка POST-запитів
 *
 * @param mysqli $conn З'єднання з базою даних
 * @param int $user_id ID користувача
 * @param string $username Ім'я користувача
 * @param array $user_data Дані користувача
 * @param bool $email_notifications_enabled Чи включені email-сповіщення
 * @param bool $push_notifications_enabled Чи включені push-сповіщення
 */
function handlePostRequests($conn, $user_id, $username, $user_data, $email_notifications_enabled, $push_notifications_enabled) {
    // Валідація CSRF токена для всіх POST-запитів
    if (isset($_POST['csrf_token']) && $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Невалідний CSRF токен!");
    }

    // Реєстрація push-токена
    if (isset($_POST['register_push_token'])) {
        registerPushToken($conn, $user_id);
    }

    // Оновлення налаштувань сповіщень
    elseif (isset($_POST['update_notification_settings'])) {
        updateNotificationSettings($conn, $user_id);
    }

    // Видалення коментаря
    elseif (isset($_POST['delete_comment'])) {
        deleteComment($conn, $user_id);
    }

    // Оновлення фотографії профілю
    elseif (isset($_POST['update_profile_pic'])) {
        updateProfilePic($conn, $user_id, $user_data);
    }

    // Оновлення профілю користувача
    elseif (isset($_POST['update_profile'])) {
        updateProfile($conn, $user_id);
    }

    // Зміна електронної пошти
    elseif (isset($_POST['update_email'])) {
        updateEmail($conn, $user_id, $user_data);
    }

    // Зміна логіна
    elseif (isset($_POST['update_username'])) {
        updateUsername($conn, $user_id, $username);
    }

    // Зміна пароля
    elseif (isset($_POST['update_password'])) {
        updatePassword($conn, $user_id);
    }

    // Створення нового замовлення
    elseif (isset($_POST['create_order'])) {
        createOrder($conn, $user_id, $user_data, $username, $email_notifications_enabled, $push_notifications_enabled);
    }

    // Редагування замовлення
    elseif (isset($_POST['edit_order'])) {
        editOrder($conn, $user_id, $user_data, $username, $email_notifications_enabled, $push_notifications_enabled);
    }

    // Додавання коментаря до замовлення
    elseif (isset($_POST['add_comment'])) {
        addComment($conn, $user_id);
    }
}

/**
 * Обробка AJAX-запитів
 *
 * @param mysqli $conn З'єднання з базою даних
 * @param int $user_id ID користувача
 */
function handleAjaxRequests($conn, $user_id) {
    // Позначення сповіщень як прочитаних
    if (isset($_POST['mark_notifications_read'])) {
        $order_id = $_POST['order_id'] ?? 0;
        $affected = markNotificationsAsRead($conn, $user_id, $order_id);
        echo json_encode(['success' => true, 'affected' => $affected]);
        exit();
    }

    // Оновлення налаштувань WebPush
    elseif (isset($_POST['update_webpush_settings'])) {
        $enabled = isset($_POST['enabled']) ? filter_var($_POST['enabled'], FILTER_VALIDATE_BOOLEAN) : false;

        // Оновлюємо налаштування у профілі користувача
        $stmt = $conn->prepare("SELECT notification_preferences FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user_data = $result->fetch_assoc();

        $notification_prefs = json_decode($user_data['notification_preferences'] ?? '{}', true);
        $notification_prefs['push'] = $enabled;

        $prefs_json = json_encode($notification_prefs);
        $stmt = $conn->prepare("UPDATE users SET notification_preferences = ? WHERE id = ?");
        $stmt->bind_param("si", $prefs_json, $user_id);
        $success = $stmt->execute();

        echo json_encode(['success' => $success]);
        exit();
    }

    // Оновлення списку сповіщень
    elseif (isset($_GET['update_notifications'])) {
        $new_notifications = getUnreadNotifications($conn, $user_id, 20);
        $new_total_count = countTotalUnreadNotifications($conn, $user_id);

        echo json_encode([
            'success' => true,
            'notifications' => $new_notifications,
            'total_count' => $new_total_count
        ]);
        exit();
    }
}

/**
 * Реєстрація push-токена
 *
 * @param mysqli $conn З'єднання з базою даних
 * @param int $user_id ID користувача
 */
function registerPushToken($conn, $user_id) {
    $token = $_POST['token'] ?? '';
    $device_info = $_POST['device_info'] ?? '';

    if (!empty($token)) {
        $stmt = $conn->prepare("INSERT INTO user_push_tokens (user_id, push_token, device_info) VALUES (?, ?, ?) 
                               ON DUPLICATE KEY UPDATE device_info = ?");
        $stmt->bind_param("isss", $user_id, $token, $device_info, $device_info);
        $result = $stmt->execute();

        echo json_encode(['success' => $result]);
        exit();
    }

    echo json_encode(['success' => false, 'error' => 'Token is required']);
    exit();
}

/**
 * Оновлення налаштувань сповіщень
 *
 * @param mysqli $conn З'єднання з базою даних
 * @param int $user_id ID користувача
 */
function updateNotificationSettings($conn, $user_id) {
    $email_notifications = isset($_POST['email_notifications']) ? 1 : 0;
    $push_notifications = isset($_POST['push_notifications']) ? 1 : 0;

    $notification_preferences = [
        'email' => (bool)$email_notifications,
        'push' => (bool)$push_notifications
    ];

    $preferences_json = json_encode($notification_preferences);

    $stmt = $conn->prepare("UPDATE users SET notification_preferences = ? WHERE id = ?");
    $stmt->bind_param("si", $preferences_json, $user_id);

    if ($stmt->execute()) {
        $_SESSION['success'] = "Налаштування сповіщень успішно оновлено!";
        logUserAction($conn, $user_id, 'update_notification_settings', 'Оновлено налаштування сповіщень');
    } else {
        $_SESSION['error'] = "Помилка оновлення налаштувань сповіщень: " . $conn->error;
    }

    header("Location: dashboard.php#settings");
    exit();
}

/**
 * Видалення коментаря
 *
 * @param mysqli $conn З'єднання з базою даних
 * @param int $user_id ID користувача
 */
function deleteComment($conn, $user_id) {
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

/**
 * Оновлення фотографії профілю
 *
 * @param mysqli $conn З'єднання з базою даних
 * @param int $user_id ID користувача
 * @param array $user_data Дані користувача
 */
function updateProfilePic($conn, $user_id, $user_data) {
    if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $filename = $_FILES['profile_pic']['name'];
        $ext = pathinfo($filename, PATHINFO_EXTENSION);

        if (in_array(strtolower($ext), $allowed)) {
            $new_filename = uniqid() . '.' . $ext;
            $upload_dir = 'uploads/profiles/';

            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }

            $upload_path = $upload_dir . $new_filename;

            if (move_uploaded_file($_FILES['profile_pic']['tmp_name'], $upload_path)) {
                // Видалення старої фотографії, якщо вона існує
                if (!empty($user_data['profile_pic']) && file_exists($user_data['profile_pic'])) {
                    unlink($user_data['profile_pic']);
                }

                $db_path = $upload_path;
                $stmt = $conn->prepare("UPDATE users SET profile_pic = ? WHERE id = ?");
                $stmt->bind_param("si", $db_path, $user_id);

                if ($stmt->execute()) {
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

/**
 * Оновлення профілю користувача
 *
 * @param mysqli $conn З'єднання з базою даних
 * @param int $user_id ID користувача
 */
function updateProfile($conn, $user_id) {
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
        logUserAction($conn, $user_id, 'update_profile', 'Оновлено персональні дані');
    } else {
        $_SESSION['error'] = "Помилка оновлення профілю: " . $conn->error;
    }

    header("Location: dashboard.php");
    exit();
}

/**
 * Оновлення email користувача
 *
 * @param mysqli $conn З'єднання з базою даних
 * @param int $user_id ID користувача
 * @param array $user_data Дані користувача
 */
function updateEmail($conn, $user_id, $user_data) {
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
        $_SESSION['success'] = "Email успішно оновлено!";
        logUserAction($conn, $user_id, 'update_email', 'Змінено email з ' . $old_email . ' на ' . $new_email);
    } else {
        $_SESSION['error'] = "Помилка оновлення email: " . $conn->error;
    }

    header("Location: dashboard.php");
    exit();
}

/**
 * Оновлення імені користувача (логіна)
 *
 * @param mysqli $conn З'єднання з базою даних
 * @param int $user_id ID користувача
 * @param string $username Поточне ім'я користувача
 */
function updateUsername($conn, $user_id, $username) {
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
        $_SESSION['username'] = $new_username;
        $_SESSION['success'] = "Логін успішно оновлено!";
        logUserAction($conn, $user_id, 'update_username', 'Змінено логін з ' . $old_username . ' на ' . $new_username);
    } else {
        $_SESSION['error'] = "Помилка оновлення логіна: " . $conn->error;
    }

    header("Location: dashboard.php");
    exit();
}

/**
 * Оновлення пароля користувача
 *
 * @param mysqli $conn З'єднання з базою даних
 * @param int $user_id ID користувача
 */
function updatePassword($conn, $user_id) {
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
    } else {
        $_SESSION['error'] = "Помилка оновлення пароля: " . $conn->error;
    }

    header("Location: dashboard.php");
    exit();
}

/**
 * Створення нового замовлення
 *
 * @param mysqli $conn З'єднання з базою даних
 * @param int $user_id ID користувача
 * @param array $user_data Дані користувача
 * @param string $username Ім'я користувача
 * @param bool $email_notifications_enabled Статус email-сповіщень
 * @param bool $push_notifications_enabled Статус push-сповіщень
 */
function createOrder($conn, $user_id, $user_data, $username, $email_notifications_enabled, $push_notifications_enabled) {
    // Перевірка ліміту замовлень (тільки для активних замовлень)
    $active_orders_count = countActiveOrders($conn, $user_id);

    if ($active_orders_count >= 5) {
        $_SESSION['error'] = "Ви досягли максимальної кількості активних замовлень (5). Будь ласка, дочекайтесь обробки поточних замовлень.";
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

            $uploaded_files = handleFileUpload($conn, $order_id, 'create');

            // Відправляємо email-сповіщення про створення замовлення
            if ($email_notifications_enabled) {
                sendOrderConfirmationEmail($user_data['email'], $order_id, $service, $username);
            }

            // Відправляємо push-сповіщення, якщо вони увімкнені
            if ($push_notifications_enabled) {
                $push_title = "Нове замовлення #" . $order_id;
                $push_body = "Ваше замовлення на " . $service . " успішно створено і очікує обробки";

                sendPushNotification($user_id, $push_title, $push_body, $conn);
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

/**
 * Редагування існуючого замовлення
 *
 * @param mysqli $conn З'єднання з базою даних
 * @param int $user_id ID користувача
 * @param array $user_data Дані користувача
 * @param string $username Ім'я користувача
 * @param bool $email_notifications_enabled Статус email-сповіщень
 * @param bool $push_notifications_enabled Статус push-сповіщень
 */
function editOrder($conn, $user_id, $user_data, $username, $email_notifications_enabled, $push_notifications_enabled) {
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

            $uploaded_files = handleFileUpload($conn, $order_id, 'edit');

            // Відправляємо email-сповіщення про оновлення замовлення
            if ($email_notifications_enabled) {
                sendOrderUpdateEmail($user_data['email'], $order_id, $service, $details, $username);
            }

            // Відправляємо push-сповіщення про оновлення замовлення
            if ($push_notifications_enabled) {
                $push_title = "Замовлення #" . $order_id . " оновлено";
                $push_body = "Ви успішно оновили інформацію про замовлення на " . $service;

                sendPushNotification($user_id, $push_title, $push_body, $conn);
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

/**
 * Додавання коментаря до замовлення
 *
 * @param mysqli $conn З'єднання з базою даних
 * @param int $user_id ID користувача
 */
function addComment($conn, $user_id) {
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

            // Додаємо запис у таблицю admin_notifications
            try {
                $conn->query("
                    CREATE TABLE IF NOT EXISTS admin_notifications (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        order_id INT NOT NULL,
                        type VARCHAR(50) NOT NULL,
                        content TEXT,
                        is_read TINYINT(1) DEFAULT 0,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX (order_id, is_read)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                ");

                $stmt = $conn->prepare("INSERT INTO admin_notifications (order_id, type, content) VALUES (?, 'user_comment', ?)");
                $stmt->bind_param("is", $order_id, $comment);
                $stmt->execute();
            } catch (Exception $e) {
                // Ігноруємо помилку створення таблиці, якщо вона вже існує
            }

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

/**
 * Обробка завантаження файлів
 *
 * @param mysqli $conn З'єднання з базою даних
 * @param int $order_id ID замовлення
 * @param string $action Тип дії (create/edit)
 * @return array Масив завантажених файлів
 */
function handleFileUpload($conn, $order_id, $action = 'create') {
    $uploaded_files = [];

    // Створення таблиці для файлів замовлень, якщо вона ще не існує
    $conn->query("
        CREATE TABLE IF NOT EXISTS order_files (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(255) NOT NULL,
            file_type VARCHAR(50),
            file_size INT,
            uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Обробка звичайних завантажених файлів
    if (isset($_FILES['order_files']) && is_array($_FILES['order_files']['name'])) {
        $upload_dir = 'uploads/orders/' . $order_id . '/';

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
                $file_type = $_FILES['order_files']['type'][$i];

                if (in_array($file_ext, $allowed_extensions) && $file_size <= $max_file_size) {
                    $new_file_name = uniqid() . '.' . $file_ext;
                    $file_path = $upload_dir . $new_file_name;

                    if (move_uploaded_file($file_tmp, $file_path)) {
                        $db_path = $file_path;
                        $stmt = $conn->prepare("INSERT INTO order_files (order_id, file_name, file_path, file_type, file_size) VALUES (?, ?, ?, ?, ?)");
                        $stmt->bind_param("isssi", $order_id, $file_name, $db_path, $file_type, $file_size);
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
        $upload_dir = 'uploads/orders/' . $order_id . '/';

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
                        $db_path = $file_path;
                        $file_size = filesize($file_path);
                        $file_type = mime_content_type($file_path);

                        $stmt = $conn->prepare("INSERT INTO order_files (order_id, file_name, file_path, file_type, file_size) VALUES (?, ?, ?, ?, ?)");
                        $stmt->bind_param("isssi", $order_id, $file_name, $db_path, $file_type, $file_size);
                        $stmt->execute();
                        $uploaded_files[] = $file_name;
                    }
                }
            }
        }
    }

    return $uploaded_files;
}

/**
 * Відправляє email про створення замовлення
 *
 * @param string $email Email користувача
 * @param int $order_id ID замовлення
 * @param string $service Назва послуги
 * @param string $username Ім'я користувача
 * @return bool Результат відправки
 */
function sendOrderConfirmationEmail($email, $order_id, $service, $username) {
    $email_subject = "Нове замовлення #" . $order_id . " створено";
    $email_message = "
        <html>
        <head>
            <title>Ваше замовлення успішно створено</title>
        </head>
        <body>
            <h2>Вітаємо, " . htmlspecialchars($username) . "!</h2>
            <p>Ваше замовлення <strong>#" . $order_id . "</strong> успішно створено.</p>
            <p><strong>Послуга:</strong> " . htmlspecialchars($service) . "</p>
            <p><strong>Статус:</strong> Нове</p>
                <p>Ви можете відстежувати статус вашого замовлення у <a href='https://lagodiy.com/dashboard.php'>особистому кабінеті</a>.</p>
            <p>Дякуємо за довіру до нашого сервісу!</p>
            <hr>
            <p style='font-size: 12px;'>Це автоматичне повідомлення, будь ласка, не відповідайте на нього.</p>
        </body>
        </html>
    ";

    return sendNotificationEmail($email, $email_subject, $email_message);
}

/**
 * Відправляє email про оновлення замовлення
 *
 * @param string $email Email користувача
 * @param int $order_id ID замовлення
 * @param string $service Назва послуги
 * @param string $details Деталі замовлення
 * @param string $username Ім'я користувача
 * @return bool Результат відправки
 */
function sendOrderUpdateEmail($email, $order_id, $service, $details, $username) {
    $email_subject = "Замовлення #" . $order_id . " оновлено";
    $email_message = "
        <html>
        <head>
            <title>Ваше замовлення оновлено</title>
        </head>
        <body>
            <h2>Вітаємо, " . htmlspecialchars($username) . "!</h2>
            <p>Ваше замовлення <strong>#" . $order_id . "</strong> було успішно оновлено.</p>
            <p><strong>Послуга:</strong> " . htmlspecialchars($service) . "</p>
            <p><strong>Деталі:</strong> " . htmlspecialchars($details) . "</p>
            <p>Ви можете переглянути всі деталі у <a href='https://lagodiy.com/dashboard.php'>особистому кабінеті</a>.</p>
            <hr>
            <p style='font-size: 12px;'>Це автоматичне повідомлення, будь ласка, не відповідайте на нього.</p>
        </body>
        </html>
    ";

    return sendNotificationEmail($email, $email_subject, $email_message);
}

/**
 * Обробка сповіщень для замовлень
 *
 * @param mysqli $conn З'єднання з базою даних
 * @param int $user_id ID користувача
 * @param int $order_id ID замовлення
 * @return array Масив з даними про сповіщення
 */
function processNotifications($conn, $user_id, $order_id) {
    try {
        // Створюємо таблицю notifications, якщо вона ще не існує
        $conn->query("
            CREATE TABLE IF NOT EXISTS notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                order_id INT NOT NULL,
                type VARCHAR(20) NOT NULL, -- 'comment', 'status' або 'system'
                content TEXT,
                old_status VARCHAR(50),
                new_status VARCHAR(50),
                is_read TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (user_id, order_id, is_read)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    } catch (Exception $e) {
        // Ігноруємо помилку, якщо таблиця вже існує
    }

    // Отримуємо поточний статус замовлення
    $stmt = $conn->prepare("SELECT status FROM orders WHERE id = ?");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $order_result = $stmt->get_result();
    $current_status = $order_result->fetch_assoc()['status'] ?? '';

    // Перевіряємо, чи був змінений статус замовлення
    $stmt = $conn->prepare("SELECT old_status, new_status FROM notifications 
                           WHERE order_id = ? AND user_id = ? AND type = 'status' 
                           ORDER BY created_at DESC LIMIT 1");
    $stmt->bind_param("ii", $order_id, $user_id);
    $stmt->execute();
    $status_result = $stmt->get_result();
    $status_data = $status_result->fetch_assoc();

    $last_known_status = $status_data['new_status'] ?? null;

    // Якщо статус змінено або це перше відстеження статусу
    if ($last_known_status !== $current_status) {
        // Додаємо нове сповіщення про зміну статусу
        $old_status = $last_known_status ?? 'Не встановлено';
        $stmt = $conn->prepare("INSERT INTO notifications (user_id, order_id, type, old_status, new_status) 
                               VALUES (?, ?, 'status', ?, ?)");
        $stmt->bind_param("iiss", $user_id, $order_id, $old_status, $current_status);
        $stmt->execute();

        // Отримуємо налаштування сповіщень користувача
        $user_stmt = $conn->prepare("SELECT email, notification_preferences FROM users WHERE id = ?");
        $user_stmt->bind_param("i", $user_id);
        $user_stmt->execute();
        $user_result = $user_stmt->get_result();
        $user_data = $user_result->fetch_assoc();

        $notification_preferences = json_decode($user_data['notification_preferences'] ?? '{}', true);
        $email_notifications_enabled = $notification_preferences['email'] ?? true;
        $push_notifications_enabled = $notification_preferences['push'] ?? true;

        // Відправляємо email-сповіщення про зміну статусу
        if ($email_notifications_enabled && !empty($user_data['email'])) {
            $email_subject = "Статус замовлення #" . $order_id . " змінено";
            $email_message = "
                <html>
                <head>
                    <title>Оновлення статусу замовлення</title>
                </head>
                <body>
                    <h2>Статус вашого замовлення оновлено</h2>
                    <p>Замовлення: <strong>#" . $order_id . "</strong></p>
                    <p>Попередній статус: <strong>" . htmlspecialchars($old_status) . "</strong></p>
                    <p>Новий статус: <strong>" . htmlspecialchars($current_status) . "</strong></p>
                    <p>Для перегляду деталей перейдіть до <a href='https://lagodiy.com/dashboard.php'>особистого кабінету</a>.</p>
                    <hr>
                    <p style='font-size: 12px;'>Це автоматичне повідомлення, будь ласка, не відповідайте на нього.</p>
                </body>
                </html>
            ";

            sendNotificationEmail($user_data['email'], $email_subject, $email_message);
        }

        // Відправляємо push-сповіщення про зміну статусу
        if ($push_notifications_enabled) {
            $push_title = "Статус замовлення #" . $order_id . " змінено";
            $push_body = "Новий статус: " . $current_status;

            sendPushNotification($user_id, $push_title, $push_body, $conn);
        }
    }

    // Перевіряємо наявність нових коментарів адміністратора
    $stmt = $conn->prepare("
        SELECT c.id, c.content, c.created_at, u.username as admin_name, c.file_attachment 
        FROM comments c 
        JOIN users u ON c.user_id = u.id 
        LEFT JOIN notifications n ON c.id = CAST(n.content AS UNSIGNED) AND n.type = 'comment' AND n.user_id = ?
        WHERE c.order_id = ? AND n.id IS NULL
        ORDER BY c.created_at DESC
    ");
    $stmt->bind_param("ii", $user_id, $order_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $new_comments = [];
    while ($comment = $result->fetch_assoc()) {
        $new_comments[] = $comment;

        // Додаємо сповіщення про новий коментар
        $stmt = $conn->prepare("INSERT INTO notifications (user_id, order_id, type, content) VALUES (?, ?, 'comment', ?)");
        $comment_id = (string)$comment['id'];
        $stmt->bind_param("iis", $user_id, $order_id, $comment_id);
        $stmt->execute();

        // Отримуємо налаштування сповіщень користувача
        $user_stmt = $conn->prepare("SELECT email, notification_preferences FROM users WHERE id = ?");
        $user_stmt->bind_param("i", $user_id);
        $user_stmt->execute();
        $user_result = $user_stmt->get_result();
        $user_data = $user_result->fetch_assoc();

        $notification_preferences = json_decode($user_data['notification_preferences'] ?? '{}', true);
        $email_notifications_enabled = $notification_preferences['email'] ?? true;
        $push_notifications_enabled = $notification_preferences['push'] ?? true;

        // Відправляємо email про новий коментар
        if ($email_notifications_enabled && !empty($user_data['email'])) {
            // Підготовка даних для email
            $subject = "Нова відповідь від адміністратора по замовленню #{$order_id}";
            $message = "
                <html>
                <head>
                    <title>Нова відповідь адміністратора</title>
                </head>
                <body>
                    <h2>Повідомлення від адміністратора</h2>
                    <p><strong>Замовлення:</strong> #{$order_id}</p>
                    <p><strong>Адміністратор:</strong> {$comment['admin_name']}</p>
                    <p><strong>Повідомлення:</strong><br>{$comment['content']}</p>
                    <p>Для перегляду повного повідомлення та відповіді, будь ласка, перейдіть до <a href='https://lagodiy.com/dashboard.php'>особистого кабінету</a>.</p>
            ";

            // Додаємо інформацію про прикріплений файл, якщо він є
            if (!empty($comment['file_attachment'])) {
                $message .= "<p><strong>Прикріплений файл:</strong> Для перегляду файлу, будь ласка, перейдіть до особистого кабінету.</p>";
            }

            $message .= "
                    <hr>
                    <p style='font-size: 12px;'>Це автоматичне повідомлення, будь ласка, не відповідайте на нього.</p>
                </body>
                </html>
            ";

            // Відправка email
            sendNotificationEmail($user_data['email'], $subject, $message);
        }

        // Відправляємо push-сповіщення про новий коментар
        if ($push_notifications_enabled) {
            $push_title = "Новий коментар по замовленню #{$order_id}";
            $push_body = "Адміністратор {$comment['admin_name']} додав новий коментар";

            sendPushNotification($user_id, $push_title, $push_body, $conn);
        }
    }

    // Отримуємо кількість непрочитаних сповіщень
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM notifications WHERE user_id = ? AND order_id = ? AND is_read = 0");
    $stmt->bind_param("ii", $user_id, $order_id);
    $stmt->execute();
    $count_result = $stmt->get_result();
    $unread_count = $count_result->fetch_assoc()['total'] ?? 0;

    return [
        'new_comments' => $new_comments,
        'unread_count' => $unread_count,
        'status_changed' => $last_known_status !== $current_status,
        'current_status' => $current_status
    ];
}

/**
 * Отримання списку замовлень користувача з фільтрацією
 *
 * @param mysqli $conn З'єднання з базою даних
 * @param int $user_id ID користувача
 * @param string $filter_status Фільтр за статусом
 * @param string $filter_service Фільтр за послугою
 * @param string $search_query Пошуковий запит
 * @return array Масив замовлень
 */
function getOrders($conn, $user_id, $filter_status = '', $filter_service = '', $search_query = '') {
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

        return array_values($grouped_orders);
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Отримання унікальних статусів замовлень
 *
 * @param mysqli $conn З'єднання з базою даних
 * @param int $user_id ID користувача
 * @return array Список унікальних статусів
 */
function getUniqueOrderStatuses($conn, $user_id) {
    $statuses = [];
    try {
        $stmt = $conn->prepare("SELECT DISTINCT status FROM orders WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $statuses[] = $row['status'];
        }
    } catch (Exception $e) {
        // Ігноруємо помилку
    }
    return $statuses;
}

/**
 * Отримання унікальних послуг замовлень
 *
 * @param mysqli $conn З'єднання з базою даних
 * @param int $user_id ID користувача
 * @return array Список унікальних послуг
 */
function getUniqueOrderServices($conn, $user_id) {
    $services = [];
    try {
        $stmt = $conn->prepare("SELECT DISTINCT service FROM orders WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $services[] = $row['service'];
        }
    } catch (Exception $e) {
        // Ігноруємо помилку
    }
    return $services;
}

/**
 * Підрахунок активних замовлень
 *
 * @param mysqli $conn З'єднання з базою даних
 * @param int $user_id ID користувача
 * @return int Кількість активних замовлень
 */
function countActiveOrders($conn, $user_id) {
    $stmt = $conn->prepare("SELECT COUNT(*) as active_orders FROM orders WHERE user_id = ? AND is_closed = 0 AND (status = 'Нове' OR status = 'В роботі' OR status = 'Очікує тощо')");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    return $data['active_orders'] ?? 0;
}

/**
 * Отримання непрочитаних сповіщень
 *
 * @param mysqli $conn З'єднання з базою даних
 * @param int $user_id ID користувача
 * @param int $limit Максимальна кількість сповіщень
 * @return array Список непрочитаних сповіщень
 */
function getUnreadNotifications($conn, $user_id, $limit = 10) {
    $stmt = $conn->prepare("
        SELECT n.*, o.service 
        FROM notifications n 
        JOIN orders o ON n.order_id = o.id 
        WHERE n.user_id = ? AND n.is_read = 0 
        ORDER BY n.created_at DESC 
        LIMIT ?
    ");
    $stmt->bind_param("ii", $user_id, $limit);
    $stmt->execute();
    $result = $stmt->get_result();

    $notifications = [];
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

                $title = "Новий коментар до замовлення #{$row['order_id']}";
                $description = "Адміністратор {$comment_data['username']} додав коментар";
                break;

            case 'system':
                $title = "Системне сповіщення для замовлення #{$row['order_id']}";
                $description = $row['content'];
                break;
        }

        $notifications[] = [
            'id' => $row['id'],
            'order_id' => $row['order_id'],
            'type' => $row['type'],
            'title' => $title,
            'description' => $description,
            'created_at' => $row['created_at'],
            'service' => $row['service']
        ];
    }

    return $notifications;
}

/**
 * Підрахунок загальної кількості непрочитаних сповіщень
 *
 * @param mysqli $conn З'єднання з базою даних
 * @param int $user_id ID користувача
 * @return int Загальна кількість непрочитаних сповіщень
 */
function countTotalUnreadNotifications($conn, $user_id) {
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return $row['total'] ?? 0;
}

/**
 * Позначення сповіщень як прочитаних
 *
 * @param mysqli $conn З'єднання з базою даних
 * @param int $user_id ID користувача
 * @param int $order_id ID замовлення
 * @return int Кількість оновлених записів
 */
function markNotificationsAsRead($conn, $user_id, $order_id) {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND order_id = ?");
    $stmt->bind_param("ii", $user_id, $order_id);
    $stmt->execute();
    return $stmt->affected_rows;
}