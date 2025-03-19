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
$stmt = $conn->prepare("SELECT email, first_name, last_name, middle_name, profile_pic, phone, address, delivery_method FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_result = $stmt->get_result();
$user_data = $user_result->fetch_assoc();

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
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // Recipients
        $mail->setFrom('service@example.com', 'Service Center');
        $mail->addAddress($to);

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

// Перевірка кількості активних замовлень
$stmt = $conn->prepare("SELECT COUNT(*) as active_orders FROM orders WHERE user_id = ? AND is_closed = 0");
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

    // Перевірка ліміту замовлень
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
                'files' => []
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
    // Просто ігноруємо помилку фільтрів
}

// Функція для обробки відповідей адміністратора з файлами
function processAdminResponse($conn, $user_id, $order_id) {
    // Перевіряємо наявність нових повідомлень від адміністратора
    $stmt = $conn->prepare("SELECT c.id, c.content, c.created_at, u.username as admin_name, c.file_attachment FROM comments c 
                           JOIN users u ON c.user_id = u.id 
                           WHERE c.order_id = ? AND c.is_read = 0 ORDER BY c.created_at DESC");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $new_messages = [];
    while ($comment = $result->fetch_assoc()) {
        $new_messages[] = $comment;

        // Позначаємо повідомлення як прочитане
        $read_stmt = $conn->prepare("UPDATE comments SET is_read = 1 WHERE id = ?");
        $read_stmt->bind_param("i", $comment['id']);
        $read_stmt->execute();

        // Якщо є прикріплений файл, повідомляємо користувача
        if (!empty($comment['file_attachment'])) {
            // Отримати email користувача
            $user_stmt = $conn->prepare("SELECT email FROM users WHERE id = ?");
            $user_stmt->bind_param("i", $user_id);
            $user_stmt->execute();
            $user_result = $user_stmt->get_result();
            $user_email = $user_result->fetch_assoc()['email'];

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
                    <p><strong>Прикріплений файл:</strong> Для перегляду файлу, будь ласка, перейдіть до особистого кабінету.</p>
                </body>
                </html>
            ";

            // Відправка email
            sendNotificationEmail($user_email, $subject, $message);
        }
    }

    return $new_messages;
}

// Перевіряємо нові повідомлення для кожного замовлення користувача
foreach ($orders as &$order) {
    $order['new_messages'] = processAdminResponse($conn, $user_id, $order['id']);
}
?>

<!DOCTYPE html>
<html lang="uk" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Особистий кабінет - <?= htmlspecialchars($username) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        :root {
            --primary-color: #3498db;
            --secondary-color: #2980b9;
            --text-color: #333;
            --bg-color: #f8f9fa;
            --card-bg: #fff;
            --border-color: #ddd;
            --success-color: #28a745;
            --error-color: #dc3545;
            --sidebar-width: 280px;
            --sidebar-collapsed-width: 70px;
            --header-height: 60px;
            --transition-speed: 0.3s;
        }

        [data-theme="dark"] {
            --primary-color: #2196F3;
            --secondary-color: #1976D2;
            --text-color: #e4e6eb;
            --bg-color: #18191a;
            --card-bg: #242526;
            --border-color: #3a3b3c;
            --success-color: #4caf50;
            --error-color: #f44336;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: var(--text-color);
            background-color: var(--bg-color);
            transition: background-color var(--transition-speed), color var(--transition-speed);
            min-height: 100vh;
            display: flex;
            overflow-x: hidden;
        }

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
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
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
        }

        .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .user-name {
            font-weight: bold;
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
        }

        .sidebar-menu a {
            padding: 12px 20px;
            color: var(--text-color);
            text-decoration: none;
            display: flex;
            align-items: center;
            transition: background-color 0.2s;
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

        .main-content {
            margin-left: var(--sidebar-width);
            flex: 1;
            padding: 20px;
            transition: margin-left var(--transition-speed);
        }

        .sidebar.collapsed + .main-content {
            margin-left: var(--sidebar-collapsed-width);
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }

        .theme-toggle {
            background: none;
            border: none;
            color: var(--text-color);
            cursor: pointer;
            font-size: 1.2rem;
            margin-left: 10px;
        }

        .header-actions {
            display: flex;
            align-items: center;
        }

        .current-time {
            margin-right: 20px;
            font-size: 0.9rem;
            color: var(--text-color);
        }

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
            transition: background-color 0.2s;
            margin: 3px;
            white-space: nowrap;
        }

        .btn:hover {
            background-color: var(--secondary-color);
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

        .alert {
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
            position: relative;
        }

        .alert-success {
            background: rgba(40, 167, 69, 0.1);
            border-left: 4px solid var(--success-color);
            color: var(--success-color);
        }

        .alert-error {
            background: rgba(220, 53, 69, 0.1);
            border-left: 4px solid var(--error-color);
            color: var(--error-color);
        }

        .alert-block {
            background: rgba(255, 193, 7, 0.1);
            border-left: 4px solid #ffc107;
            color: #856404;
        }

        .card {
            background-color: var(--card-bg);
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            padding: 20px;
            margin-bottom: 20px;
            transition: background-color var(--transition-speed);
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
            transition: border-color 0.2s;
        }

        .form-control:focus {
            border-color: var(--primary-color);
            outline: none;
        }

        select.form-control {
            cursor: pointer;
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        .order-card {
            background-color: var(--card-bg);
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            overflow: hidden;
            transition: box-shadow 0.2s, transform 0.2s;
        }

        .order-card:hover {
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }

        .order-header {
            padding: 15px 20px;
            background-color: rgba(0,0,0,0.03);
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }

        .order-id {
            font-weight: bold;
            font-size: 1.1rem;
            margin: 0;
        }

        .order-meta {
            display: flex;
            align-items: center;
            gap: 15px;
            font-size: 0.9rem;
        }

        .status-badge {
            padding: 3px 8px;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 500;
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
        }

        .order-actions {
            margin-top: 20px;
            display: flex;
            gap: 10px;
        }

        .order-files {
            margin-top: 20px;
        }

        .file-item {
            display: flex;
            align-items: center;
            padding: 8px;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            margin-bottom: 8px;
            background-color: rgba(0,0,0,0.01);
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
        }

        .comment-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 0.9rem;
        }

        .comment-author {
            font-weight: 600;
        }

        .comment-date {
            color: rgba(var(--text-color-rgb), 0.6);
        }

        .filters-bar {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            margin-bottom: 20px;
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
        }

        /* Модальне вікно */
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
        }

        .modal-content {
            background-color: var(--card-bg);
            margin: 50px auto;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            width: 80%;
            max-width: 700px;
            max-height: 80vh;
            overflow-y: auto;
            color: var(--text-color);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 10px;
            margin-bottom: 20px;
        }

        .modal-title {
            font-size: 1.25rem;
            font-weight: 600;
        }

        .close-modal {
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text-color);
            background: none;
            border: none;
        }

        .tabs {
            display: flex;
            border-bottom: 1px solid var(--border-color);
            margin-bottom: 20px;
        }

        .tab {
            padding: 10px 20px;
            cursor: pointer;
            border-bottom: 2px solid transparent;
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
        }

        .view-more-btn {
            display: block;
            text-align: center;
            padding: 5px;
            background: linear-gradient(to bottom, rgba(255,255,255,0) 0%, var(--card-bg) 75%);
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            cursor: pointer;
            color: var(--primary-color);
            font-weight: 500;
        }

        /* Стилі для drag-and-drop */
        .drop-zone {
            border: 2px dashed var(--border-color);
            border-radius: 5px;
            padding: 25px;
            text-align: center;
            cursor: pointer;
            margin-bottom: 15px;
            transition: border-color 0.3s;
        }

        .drop-zone:hover, .drop-zone.active {
            border-color: var(--primary-color);
        }

        .drop-zone-prompt {
            color: var(--text-color);
            margin-bottom: 10px;
        }

        .drop-zone-thumb {
            display: inline-flex;
            align-items: center;
            margin: 5px;
            padding: 5px 10px;
            background: rgba(0,0,0,0.05);
            border-radius: 4px;
        }

        /* Стилі для згортання форми створення замовлення */
        .collapsible-section {
            margin-bottom: 15px;
        }

        .collapsible-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px;
            background-color: rgba(0,0,0,0.02);
            border-radius: 5px;
            cursor: pointer;
        }

        .collapsible-header h3 {
            margin: 0;
            font-size: 1rem;
        }

        .collapsible-content {
            padding: 10px 0;
            display: none;
        }

        .collapsible-section.open .collapsible-content {
            display: block;
        }

        .rotate-icon {
            transition: transform 0.3s;
        }

        .collapsible-section.open .rotate-icon {
            transform: rotate(180deg);
        }

        .temp-message {
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            padding: 15px 25px;
            background-color: var(--primary-color);
            color: white;
            border-radius: 5px;
            box-shadow: 0 3px 6px rgba(0,0,0,0.2);
            z-index: 1000;
            animation: fadeOut 2s forwards;
            animation-delay: 2s;
        }

        @keyframes fadeOut {
            from {opacity: 1;}
            to {opacity: 0; visibility: hidden;}
        }

        /* Стилі для відображення файлів */
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

        /* Новий стиль для нових повідомлень */
        .new-message-indicator {
            position: relative;
            display: inline-block;
            width: 8px;
            height: 8px;
            background-color: #f44336;
            border-radius: 50%;
            margin-left: 5px;
            animation: pulse 1.5s infinite;
        }

        @keyframes pulse {
            0% {
                transform: scale(0.95);
                box-shadow: 0 0 0 0 rgba(244, 67, 54, 0.7);
            }

            70% {
                transform: scale(1);
                box-shadow: 0 0 0 5px rgba(244, 67, 54, 0);
            }

            100% {
                transform: scale(0.95);
                box-shadow: 0 0 0 0 rgba(244, 67, 54, 0);
            }
        }

        /* Стиль для тем */
        .theme-selector {
            margin-top: 10px;
            display: flex;
            gap: 10px;
        }

        .theme-option {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            cursor: pointer;
            border: 2px solid transparent;
        }

        .theme-option.active {
            border-color: var(--primary-color);
        }

        .theme-option-light {
            background-color: #f8f9fa;
        }

        .theme-option-dark {
            background-color: #18191a;
        }

        .theme-option-blue {
            background-color: #e3f2fd;
        }

        /* Адаптивність */
        @media (max-width: 768px) {
            .sidebar {
                width: var(--sidebar-collapsed-width);
            }

            .sidebar.expanded {
                width: var(--sidebar-width);
                box-shadow: 0 0 15px rgba(0,0,0,0.2);
            }

            .main-content {
                margin-left: var(--sidebar-collapsed-width);
                padding: 15px;
            }

            .sidebar.expanded + .main-content {
                margin-left: var(--sidebar-collapsed-width);
            }

            .sidebar:not(.expanded) .sidebar-menu .menu-text,
            .sidebar:not(.expanded) .user-name,
            .sidebar:not(.expanded) .sidebar-header .logo-text {
                display: none;
            }

            .order-meta {
                margin-top: 10px;
                flex-basis: 100%;
            }

            .order-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .filters-bar {
                flex-direction: column;
                gap: 10px;
            }

            .filter-group {
                width: 100%;
            }

            .search-bar {
                max-width: none;
            }
        }

        /* Стилі для тексту обов'язкових полів */
        .required-field::after {
            content: " *";
            color: var(--error-color);
        }
    </style>
</head>
<body>
    <!-- Сайдбар -->
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
                    <img src="../assets/images/default_avatar.png" alt="Фото профілю за замовчуванням">
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
                </a>
            </li>
            <li>
                <a href="#new-order" data-tab="new-order">
                    <i class="fas fa-plus icon"></i>
                    <span class="menu-text">Створити замовлення</span>
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

    <!-- Основний контент -->
<div class="main-content">
    <div class="header">
        <h1>Особистий кабінет</h1>
        <div class="header-actions">
            <div class="current-time" id="current-time">
                <?= date('d.m.Y H:i', strtotime('2025-03-19 07:20:02')) ?>
            </div>
            <div class="theme-selector">
                <div class="theme-option theme-option-light active" data-theme="light" title="Світла тема"></div>
                <div class="theme-option theme-option-dark" data-theme="dark" title="Темна тема"></div>
                <div class="theme-option theme-option-blue" data-theme="blue" title="Синя тема"></div>
            </div>
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

    <!-- Контент вкладок -->
    <div class="tab-content active" id="dashboard-content">
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Статистика</h2>
        </div>
        <div class="order-stats">
            <p>Активних замовлень: <?= $active_orders_count ?></p>
            <p>Всього замовлень: <?= count($orders) ?></p>
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
        $has_new_messages = !empty($order['new_messages']);
        ?>
    <div class="order-card <?= $has_new_messages ? 'has-new-messages' : '' ?>">
        <div class="order-header">
            <h3 class="order-id">
                Замовлення #<?= $order['id'] ?>
                <?php if ($has_new_messages): ?>
                    <span class="new-message-indicator" title="Нові повідомлення"></span>
                <?php endif; ?>
            </h3>
            <div class="order-meta">
                                    <span class="status-badge status-<?= strtolower(str_replace(' ', '-', $order['status'])) ?>">
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
                                                    <span class="comment-author"><?= htmlspecialchars($comment['admin_name'] ?? 'Адмін') ?></span>
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
                                                <button class="btn btn-sm delete-comment" data-id="<?= $order['id'] ?>" title="Видалити коментар" style="float:right; margin-top: 5px;">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <div class="view-more-btn">Переглянути повну інформацію <i class="fas fa-chevron-down"></i></div>

                                <?php if (!$order['is_closed'] && !$block_message): ?>
                                    <div class="order-actions">
                                        <button class="btn btn-sm edit-order" data-id="<?= $order['id'] ?>">
                                            <i class="fas fa-edit"></i> Редагувати
                                        </button>
                                        <button class="btn btn-sm add-comment" data-id="<?= $order['id'] ?>">
                                            <i class="fas fa-comment"></i> Додати коментар
                                        </button>
                                    </div>
                                <?php elseif ($order['is_closed']): ?>
                                    <div class="order-closed-notice">
                                        <em>Замовлення завершено, редагування недоступне</em>
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
                            $has_new_messages = !empty($order['new_messages']);
                        ?>
                            <div class="order-card <?= $has_new_messages ? 'has-new-messages' : '' ?>">
                                <div class="order-header">
                                    <h3 class="order-id">
                                        Замовлення #<?= $order['id'] ?>
                                        <?php if ($has_new_messages): ?>
                                            <span class="new-message-indicator" title="Нові повідомлення"></span>
                                        <?php endif; ?>
                                    </h3>
                                    <div class="order-meta">
                                        <span class="status-badge status-<?= strtolower(str_replace(' ', '-', $order['status'])) ?>">
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
                                                        <span class="comment-author"><?= htmlspecialchars($comment['admin_name'] ?? 'Адмін') ?></span>
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
                                                    <button class="btn btn-sm delete-comment" data-id="<?= $order['id'] ?>" title="Видалити коментар" style="float:right; margin-top: 5px;">
                                                        <i class="fas fa-trash-alt"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <div class="view-more-btn">Переглянути повну інформацію <i class="fas fa-chevron-down"></i></div>

                                    <?php if (!$order['is_closed'] && !$block_message): ?>
                                        <div class="order-actions">
                                            <button class="btn btn-sm edit-order" data-id="<?= $order['id'] ?>">
                                                <i class="fas fa-edit"></i> Редагувати
                                            </button>
                                            <button class="btn btn-sm add-comment" data-id="<?= $order['id'] ?>">
                                                <i class="fas fa-comment"></i> Додати коментар
                                            </button>
                                        </div>
                                    <?php elseif ($order['is_closed']): ?>
                                        <div class="order-closed-notice">
                                            <em>Замовлення завершено, редагування недоступне</em>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p>Замовлення не знайдені.</p>
                <?php endif; ?>
            </div>
        </div>

    <div class="tab-content" id="new-order-content">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Створити нове замовлення</h2>
            </div>

            <?php if ($active_orders_count >= 5 && !$block_message): ?>
                <div class="alert alert-error">
                    Ви досягли ліміту активних замовлень (5). Будь ласка, дочекайтесь обробки існуючих замовлень.
                </div>
            <?php elseif (!$block_message): ?>
                <form method="post" enctype="multipart/form-data" class="order-form">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="create_order" value="1">
                    <input type="hidden" name="dropped_files" id="dropped_files_data" value="">

                    <!-- Основні дані замовлення - можна згорнути -->
                    <div class="collapsible-section open">
                        <div class="collapsible-header">
                            <h3>Інформація про послугу</h3>
                            <i class="fas fa-chevron-down rotate-icon"></i>
                        </div>
                        <div class="collapsible-content">
                            <div class="form-group">
                                <label for="service" class="required-field">Послуга:</label>
                                <select name="service" id="service" class="form-control" required>
                                    <option value="">Виберіть послугу</option>
                                    <option value="Ремонт комп'ютера">Ремонт комп'ютера</option>
                                    <option value="Ремонт ноутбука">Ремонт ноутбука</option>
                                    <option value="Ремонт телефона">Ремонт телефона</option>
                                    <option value="Ремонт МФУ">Ремонт МФУ</option>
                                    <option value="Заправка картриджів">Заправка картриджів</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="device_type" class="required-field">Тип пристрою:</label>
                                <select name="device_type" id="device_type" class="form-control" required>
                                    <option value="">Виберіть тип пристрою</option>
                                    <option value="Комп'ютер">Комп'ютер</option>
                                    <option value="Ноутбук">Ноутбук</option>
                                    <option value="Телефон сенсорний">Телефон сенсорний</option>
                                    <option value="Телефон кнопковий">Телефон кнопковий</option>
                                    <option value="МФУ">МФУ</option>
                                    <option value="Принтер">Принтер</option>
                                    <option value="Інше">Інше</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="details" class="required-field">Деталі замовлення:</label>
                                <textarea name="details" id="details" class="form-control" rows="5" required></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Контактні дані - можна згорнути -->
                    <div class="collapsible-section">
                        <div class="collapsible-header">
                            <h3>Контактна інформація</h3>
                            <i class="fas fa-chevron-down rotate-icon"></i>
                        </div>
                        <div class="collapsible-content">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="first_name" class="required-field">Ім'я:</label>
                                    <input type="text" name="first_name" id="first_name" class="form-control" required value="<?= htmlspecialchars($user_data['first_name'] ?? '') ?>">
                                </div>

                                <div class="form-group">
                                    <label for="last_name" class="required-field">Прізвище:</label>
                                    <input type="text" name="last_name" id="last_name" class="form-control" required value="<?= htmlspecialchars($user_data['last_name'] ?? '') ?>">
                                </div>

                                <div class="form-group">
                                    <label for="middle_name">По батькові:</label>
                                    <input type="text" name="middle_name" id="middle_name" class="form-control" value="<?= htmlspecialchars($user_data['middle_name'] ?? '') ?>">
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="phone" class="required-field">Контактний телефон:</label>
                                <input type="tel" name="phone" id="phone" class="form-control" required value="<?= htmlspecialchars($user_data['phone'] ?? '') ?>">
                            </div>

                            <div class="form-group">
                                <label for="address" class="required-field">Адреса:</label>
                                <textarea name="address" id="address" class="form-control" rows="2" required><?= htmlspecialchars($user_data['address'] ?? '') ?></textarea>
                            </div>

                            <div class="form-group">
                                <label for="delivery_method" class="required-field">Спосіб доставки:</label>
                                <select name="delivery_method" id="delivery_method" class="form-control" required>
                                    <option value="">Виберіть спосіб доставки</option>
                                    <option value="Самовивіз" <?= ($user_data['delivery_method'] ?? '') === 'Самовивіз' ? 'selected' : '' ?>>Самовивіз</option>
                                    <option value="Кур'єр" <?= ($user_data['delivery_method'] ?? '') === 'Кур\'єр' ? 'selected' : '' ?>>Кур'єр</option>
                                    <option value="Нова пошта" <?= ($user_data['delivery_method'] ?? '') === 'Нова пошта' ? 'selected' : '' ?>>Нова пошта</option>
                                    <option value="Укрпошта" <?= ($user_data['delivery_method'] ?? '') === 'Укрпошта' ? 'selected' : '' ?>>Укрпошта</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Завантаження файлів - можна згорнути -->
                    <div class="collapsible-section">
                        <div class="collapsible-header">
                            <h3>Файли та додатки</h3>
                            <i class="fas fa-chevron-down rotate-icon"></i>
                        </div>
                        <div class="collapsible-content">
                            <div class="form-group">
                                <label>Завантаження файлів:</label>

                                <!-- Drag & Drop зона -->
                                <div class="drop-zone" id="drop-zone">
                                    <div class="drop-zone-prompt">
                                        <i class="fas fa-cloud-upload-alt fa-2x"></i>
                                        <p>Перетягніть файли сюди або натисніть для вибору файлів</p>
                                        <small>Максимальний розмір файлу: 10 МБ. Дозволені формати: jpg, jpeg, png, gif, mp4, avi, mov, pdf, doc, docx, txt</small>
                                    </div>
                                    <input type="file" name="order_files[]" id="drop-zone-input" class="form-control" multiple hidden>
                                </div>
                                <div id="file-preview-container"></div>
                            </div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn">
                            <i class="fas fa-paper-plane"></i> Відправити замовлення
                        </button>
                    </div>
                </form>
            <?php else: ?>
                <div class="alert alert-block">
                    Ваш обліковий запис заблоковано. Створення замовлень недоступне.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="tab-content" id="settings-content">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Налаштування профілю</h2>
            </div>

            <div class="tabs">
                <div class="tab active" data-target="profile-info">Особиста інформація</div>
                <div class="tab" data-target="change-email">Зміна email</div>
                <div class="tab" data-target="change-username">Зміна логіну</div>
                <div class="tab" data-target="change-password">Зміна пароля</div>
            </div>

            <div class="tab-content active" id="profile-info-content">
                <form method="post" enctype="multipart/form-data" class="profile-form">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="update_profile" value="1">

                    <div class="form-group">
                        <label>Фото профілю:</label>
                        <div class="profile-pic-container">
                            <?php if (!empty($user_data['profile_pic']) && file_exists('../' . $user_data['profile_pic'])): ?>
                                <img src="../<?= htmlspecialchars($user_data['profile_pic']) ?>" alt="Фото профілю" class="profile-preview" style="max-width: 150px; border-radius: 5px; margin-bottom: 10px;">
                            <?php else: ?>
                                <img src="../assets/images/default_avatar.png" alt="Фото профілю за замовчуванням" class="profile-preview" style="max-width: 150px; border-radius: 5px; margin-bottom: 10px;">
                            <?php endif; ?>
                        </div>

                        <form method="post" enctype="multipart/form-data" class="profile-pic-form">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="update_profile_pic" value="1">
                            <div class="form-group">
                                <!-- Drag & Drop для фото профілю -->
                                <div class="drop-zone" id="profile-drop-zone">
                                    <div class="drop-zone-prompt">
                                        <p>Перетягніть фото сюди або натисніть для вибору файлу</p>
                                    </div>
                                    <input type="file" name="profile_pic" id="profile_pic" class="form-control" accept="image/*" hidden>
                                </div>
                                <small>Максимальний розмір: 2 МБ. Формати: jpg, jpeg, png, gif</small>
                            </div>
                            <button type="submit" class="btn">Оновити фото</button>
                        </form>
                    </div>

                    <div class="form-group">
                        <label for="first_name" class="required-field">Ім'я:</label>
                        <input type="text" name="first_name" id="first_name" class="form-control" value="<?= htmlspecialchars($user_data['first_name'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label for="last_name" class="required-field">Прізвище:</label>
                        <input type="text" name="last_name" id="last_name" class="form-control" value="<?= htmlspecialchars($user_data['last_name'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label for="middle_name">По батькові:</label>
                        <input type="text" name="middle_name" id="middle_name" class="form-control" value="<?= htmlspecialchars($user_data['middle_name'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label for="profile_phone" class="required-field">Контактний телефон:</label>
                        <input type="tel" name="phone" id="profile_phone" class="form-control" value="<?= htmlspecialchars($user_data['phone'] ?? '') ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="profile_address" class="required-field">Адреса:</label>
                        <textarea name="address" id="profile_address" class="form-control" rows="2" required><?= htmlspecialchars($user_data['address'] ?? '') ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="profile_delivery_method" class="required-field">Спосіб доставки за замовчуванням:</label>
                        <select name="delivery_method" id="profile_delivery_method" class="form-control" required>
                            <option value="">Виберіть спосіб доставки</option>
                            <option value="Самовивіз" <?= ($user_data['delivery_method'] ?? '') === 'Самовивіз' ? 'selected' : '' ?>>Самовивіз</option>
                            <option value="Кур'єр" <?= ($user_data['delivery_method'] ?? '') === 'Кур\'єр' ? 'selected' : '' ?>>Кур'єр</option>
                            <option value="Нова пошта" <?= ($user_data['delivery_method'] ?? '') === 'Нова пошта' ? 'selected' : '' ?>>Нова пошта</option>
                            <option value="Укрпошта" <?= ($user_data['delivery_method'] ?? '') === 'Укрпошта' ? 'selected' : '' ?>>Укрпошта</option>
                        </select>
                    </div>

                    <button type="submit" class="btn">Зберегти зміни</button>
                </form>
            </div>

            <div class="tab-content" id="change-email-content">
                <form method="post" class="email-form">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="update_email" value="1">

                    <div class="form-group">
                        <label for="current_email">Поточна email адреса:</label>
                        <input type="email" id="current_email" class="form-control" value="<?= htmlspecialchars($user_data['email'] ?? '') ?>" readonly>
                    </div>

                    <div class="form-group">
                        <label for="new_email">Нова email адреса:</label>
                        <input type="email" name="new_email" id="new_email" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label for="password_email">Введіть пароль для підтвердження:</label>
                        <input type="password" name="password" id="password_email" class="form-control" required>
                    </div>

                    <button type="submit" class="btn">Змінити email</button>
                </form>
            </div>

            <div class="tab-content" id="change-username-content">
                <form method="post" class="username-form">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="update_username" value="1">

                    <div class="form-group">
                        <label for="current_username">Поточний логін:</label>
                        <input type="text" id="current_username" class="form-control" value="<?= htmlspecialchars($username) ?>" readonly>
                    </div>

                    <div class="form-group">
                        <label for="new_username">Новий логін:</label>
                        <input type="text" name="new_username" id="new_username" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label for="password_username">Введіть пароль для підтвердження:</label>
                        <input type="password" name="password" id="password_username" class="form-control" required>
                    </div>

                    <button type="submit" class="btn">Змінити логін</button>
                </form>
            </div>

            <div class="tab-content" id="change-password-content">
                <form method="post" class="password-form">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="update_password" value="1">

                    <div class="form-group">
                        <label for="current_password">Поточний пароль:</label>
                        <input type="password" name="current_password" id="current_password" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label for="new_password">Новий пароль:</label>
                        <input type="password" name="new_password" id="new_password" class="form-control" required>
                        <small>Пароль повинен містити не менше 8 символів</small>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Підтвердження пароля:</label>
                        <input type="password" name="confirm_password" id="confirm_password" class="form-control" required>
                    </div>

                    <button type="submit" class="btn">Змінити пароль</button>
                </form>
            </div>
        </div>
    </div>
</div>

    <!-- Модальне вікно для перегляду файлів -->
    <div class="modal" id="fileViewerModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Перегляд файлу</h3>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="media-viewer"></div>
            </div>
        </div>
    </div>

    <!-- Модальне вікно для редагування замовлення -->
    <div class="modal" id="editOrderModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Редагування замовлення</h3>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <form method="post" enctype="multipart/form-data" id="edit-order-form">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="edit_order" value="1">
                    <input type="hidden" name="order_id" id="edit-order-id">
                    <input type="hidden" name="dropped_files" id="edit_dropped_files_data" value="">

                    <div class="form-group">
                        <label for="edit-service" class="required-field">Послуга:</label>
                        <select name="service" id="edit-service" class="form-control" required>
                            <option value="">Виберіть послугу</option>
                            <option value="Ремонт комп'ютера">Ремонт комп'ютера</option>
                            <option value="Ремонт ноутбука">Ремонт ноутбука</option>
                            <option value="Ремонт телефона">Ремонт телефона</option>
                            <option value="Ремонт МФУ">Ремонт МФУ</option>
                            <option value="Заправка картриджів">Заправка картриджів</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="edit-device-type" class="required-field">Тип пристрою:</label>
                        <select name="device_type" id="edit-device-type" class="form-control" required>
                            <option value="">Виберіть тип пристрою</option>
                            <option value="Комп'ютер">Комп'ютер</option>
                            <option value="Ноутбук">Ноутбук</option>
                            <option value="Телефон сенсорний">Телефон сенсорний</option>
                            <option value="Телефон кнопковий">Телефон кнопковий</option>
                            <option value="МФУ">МФУ</option>
                            <option value="Принтер">Принтер</option>
                            <option value="Інше">Інше</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="edit-details" class="required-field">Деталі замовлення:</label>
                        <textarea name="details" id="edit-details" class="form-control" rows="5" required></textarea>
                    </div>

                    <div class="form-group">
                        <label for="edit-phone" class="required-field">Контактний телефон:</label>
                        <input type="tel" name="phone" id="edit-phone" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label for="edit-address" class="required-field">Адреса:</label>
                        <textarea name="address" id="edit-address" class="form-control" rows="2" required></textarea>
                    </div>

                    <div class="form-group">
                        <label for="edit-delivery-method" class="required-field">Спосіб доставки:</label>
                        <select name="delivery_method" id="edit-delivery-method" class="form-control" required>
                            <option value="">Виберіть спосіб доставки</option>
                            <option value="Самовивіз">Самовивіз</option>
                            <option value="Кур'єр">Кур'єр</option>
                            <option value="Нова пошта">Нова пошта</option>
                            <option value="Укрпошта">Укрпошта</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="edit-user-comment">Коментар до замовлення:</label>
                        <textarea name="user_comment" id="edit-user-comment" class="form-control" rows="3"></textarea>
                    </div>

                    <div class="form-group">
                        <label>Поточні файли:</label>
                        <div id="edit-current-files"></div>
                    </div>

                    <div class="form-group">
                        <label>Додати нові файли:</label>
                        <!-- Drag & Drop зона для редагування -->
                        <div class="drop-zone" id="edit-drop-zone">
                            <div class="drop-zone-prompt">
                                <i class="fas fa-cloud-upload-alt fa-2x"></i>
                                <p>Перетягніть файли сюди або натисніть для вибору файлів</p>
                                <small>Максимальний розмір файлу: 10 МБ. Дозволені формати: jpg, jpeg, png, gif, mp4, avi, mov, pdf, doc, docx, txt</small>
                            </div>
                            <input type="file" name="order_files[]" id="edit-drop-zone-input" class="form-control" multiple hidden>
                        </div>
                        <div id="edit-file-preview-container"></div>
                    </div>

                    <button type="submit" class="btn">Зберегти зміни</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Модальне вікно для додавання коментаря -->
    <div class="modal" id="addCommentModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Додати коментар</h3>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <form method="post" id="add-comment-form">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="add_comment" value="1">
                    <input type="hidden" name="order_id" id="comment-order-id">

                    <div class="form-group">
                        <label for="comment">Ваш коментар:</label>
                        <textarea name="comment" id="comment" class="form-control" rows="5" required></textarea>
                    </div>

                    <button type="submit" class="btn">Додати коментар</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Оновлення часу кожну хвилину
        function updateTime() {
            const timeElement = document.getElementById('current-time');
            const now = new Date();
            const formattedTime = new Intl.DateTimeFormat('uk-UA', {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            }).format(now);
            timeElement.textContent = formattedTime;
        }
        setInterval(updateTime, 60000); // Оновлювати кожну хвилину
        updateTime(); // Запустити відразу після завантаження

        // Конвертація UTC часу до локального часу користувача
        document.querySelectorAll('.local-time').forEach(function(element) {
            const utcTime = element.getAttribute('data-utc');
            if (utcTime) {
                const localTime = new Date(utcTime);
                element.textContent = new Intl.DateTimeFormat('uk-UA', {
                    day: '2-digit',
                    month: '2-digit',
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                }).format(localTime);
            }
        });

        // Переключення між вкладками сайдбара
        document.querySelectorAll('.sidebar-menu a').forEach(function(link) {
            link.addEventListener('click', function(e) {
                e.preventDefault();

                // Видалення активного класу з усіх пунктів меню
                document.querySelectorAll('.sidebar-menu a').forEach(function(item) {
                    item.classList.remove('active');
                });

                // Додавання активного класу до натиснутого пункту
                this.classList.add('active');

                // Визначення цільового контенту
                const targetId = this.getAttribute('data-tab');
                if (targetId) {
                    // Приховати всі вкладки контенту
                    document.querySelectorAll('.main-content .tab-content').forEach(function(content) {
                        content.classList.remove('active');
                    });

                    // Показати цільову вкладку
                    document.getElementById(targetId + '-content').classList.add('active');
                }
            });
        });

        // Переключення між вкладками налаштувань
        document.querySelectorAll('.tab').forEach(function(tab) {
            tab.addEventListener('click', function() {
                // Видалення активного класу з усіх вкладок
                document.querySelectorAll('.tab').forEach(function(t) {
                    t.classList.remove('active');
                });

                // Додавання активного класу до натиснутої вкладки
                this.classList.add('active');

                // Визначення цільового контенту
                const targetId = this.getAttribute('data-target');

                // Приховати всі вкладки контенту
                document.querySelectorAll('#settings-content .tab-content').forEach(function(content) {
                    content.classList.remove('active');
                });

                // Показати цільову вкладку
                document.getElementById(targetId + '-content').classList.add('active');
            });
        });

        // Згортання/розгортання сайдбару
        document.getElementById('toggle-sidebar').addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('collapsed');
            sidebar.classList.toggle('expanded');
        });

        // Згортання/розгортання елементів замовлення
        document.querySelectorAll('.view-more-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const orderBody = this.closest('.order-body');
                orderBody.classList.toggle('expanded');
                if (orderBody.classList.contains('expanded')) {
                    this.innerHTML = 'Згорнути <i class="fas fa-chevron-up"></i>';
                } else {
                    this.innerHTML = 'Переглянути повну інформацію <i class="fas fa-chevron-down"></i>';
                }
            });
        });

        // Функціонал модального вікна для перегляду файлів
        const fileViewerModal = document.getElementById('fileViewerModal');
        const mediaViewer = document.querySelector('.media-viewer');

        document.querySelectorAll('.view-file').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const filePath = this.getAttribute('data-path');
                const fileName = this.getAttribute('data-filename');

                // Очищення перегляду
                mediaViewer.innerHTML = '';

                // Визначення типу файлу
                const fileExt = fileName.split('.').pop().toLowerCase();
                const modalTitle = document.querySelector('#fileViewerModal .modal-title');
                modalTitle.textContent = 'Файл: ' + fileName;

                if(['jpg', 'jpeg', 'png', 'gif'].includes(fileExt)) {
                    // Зображення
                    const img = document.createElement('img');
                    img.src = filePath;
                    img.alt = fileName;
                    mediaViewer.appendChild(img);
                } else if(['mp4', 'avi', 'mov'].includes(fileExt)) {
                    // Відео
                    const video = document.createElement('video');
                    video.src = filePath;
                    video.controls = true;
                    video.autoplay = false;
                    mediaViewer.appendChild(video);
                } else if(['pdf'].includes(fileExt)) {
                    // PDF
                    const embed = document.createElement('embed');
                    embed.src = filePath;
                    embed.type = "application/pdf";
                    embed.width = "100%";
                    embed.height = "500px";
                    mediaViewer.appendChild(embed);
                } else if(['txt'].includes(fileExt)) {
                    // Текстовий файл
                    fetch(filePath)
                        .then(response => response.text())
                        .then(text => {
                            const pre = document.createElement('pre');
                            pre.className = 'file-viewer';
                            pre.textContent = text;
                            mediaViewer.appendChild(pre);
                        })
                        .catch(error => {
                            mediaViewer.innerHTML = `<div class="alert alert-error">Помилка завантаження файлу: ${error.message}</div>`;
                        });
                } else {
                    // Інші файли
                    const link = document.createElement('a');
                    link.href = filePath;
                    link.download = fileName;
                    link.className = 'btn';
                    link.innerHTML = '<i class="fas fa-download"></i> Завантажити файл';

                    const message = document.createElement('p');
                    message.textContent = 'Цей тип файлу не можна переглянути безпосередньо. Завантажте файл для перегляду.';

                    mediaViewer.appendChild(message);
                    mediaViewer.appendChild(link);
                }

                // Відображення модального вікна
                fileViewerModal.style.display = 'block';
            });
        });

        // Закриття модальних вікон
        document.querySelectorAll('.close-modal').forEach(function(closeBtn) {
            closeBtn.addEventListener('click', function() {
                this.closest('.modal').style.display = 'none';
                // Для відео - зупинка відтворення при закритті
                const videos = this.closest('.modal').querySelectorAll('video');
                videos.forEach(video => video.pause());
            });
        });

        // Закриття модальних вікон при кліку поза ними
        window.addEventListener('click', function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
                // Для відео - зупинка відтворення при закритті
                const videos = event.target.querySelectorAll('video');
                videos.forEach(video => video.pause());
            }
        });

        // Функціонал модального вікна для редагування замовлення
        const editOrderModal = document.getElementById('editOrderModal');

        document.querySelectorAll('.edit-order').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const orderId = this.getAttribute('data-id');

                // Знаходження даних замовлення за ID
                const orderCard = this.closest('.order-card');

                if(orderCard) {
                    document.getElementById('edit-order-id').value = orderId;

                    // Заповнення форми даними замовлення
                    document.getElementById('edit-service').value =
                        orderCard.querySelector('.order-detail:nth-child(1) div:last-child').textContent.trim();

                    document.getElementById('edit-device-type').value =
                        orderCard.querySelector('.order-detail:nth-child(2) div:last-child').textContent.trim();

                    document.getElementById('edit-details').value =
                        orderCard.querySelector('.order-detail:nth-child(3) div:last-child').innerText.trim();

                    // Телефон і адреса можуть бути в різних місцях, тому шукаємо за текстом заголовка
                    const orderDetails = orderCard.querySelectorAll('.order-detail');
                    orderDetails.forEach(detail => {
                        const label = detail.querySelector('.order-detail-label').textContent;

                        if(label.includes('Контактний телефон')) {
                            document.getElementById('edit-phone').value =
                                detail.querySelector('div:last-child').textContent.trim();
                        }

                        if(label.includes('Адреса')) {
                            document.getElementById('edit-address').value =
                                detail.querySelector('div:last-child').textContent.trim();
                        }

                        if(label.includes('Спосіб доставки')) {
                            document.getElementById('edit-delivery-method').value =
                                detail.querySelector('div:last-child').textContent.trim();
                        }
                    });

                    // Коментар користувача
                    const userCommentSection = orderCard.querySelector('.user-comment-section');
                    if(userCommentSection) {
                        const userComment = userCommentSection.querySelector('.comment').textContent.trim();
                        document.getElementById('edit-user-comment').value = userComment;
                    } else {
                        document.getElementById('edit-user-comment').value = '';
                    }

                    // Відображення поточних файлів
                    const currentFilesContainer = document.getElementById('edit-current-files');
                    currentFilesContainer.innerHTML = '';

                    const fileList = orderCard.querySelector('.file-list');
                    if(fileList) {
                        const fileItems = fileList.querySelectorAll('.file-item');

                        if(fileItems.length > 0) {
                            fileItems.forEach(item => {
                                const fileName = item.querySelector('.file-name').textContent;
                                const viewBtn = item.querySelector('.view-file');
                                const filePath = viewBtn ? viewBtn.getAttribute('data-path') : '';

                                const fileElement = document.createElement('div');
                                fileElement.className = 'file-item';
                                fileElement.innerHTML = `
                                    <i class="${item.querySelector('.file-icon').className}"></i>
                                    <span class="file-name">${fileName}</span>
                                    <div class="file-actions">
                                        <button class="btn btn-sm view-file" data-path="${filePath}" data-filename="${fileName}">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <a class="btn btn-sm" href="${filePath}" download="${fileName}">
                                            <i class="fas fa-download"></i>
                                        </a>
                                    </div>
                                `;

                                currentFilesContainer.appendChild(fileElement);
                            });
                        } else {
                            currentFilesContainer.innerHTML = '<p>Немає прикріплених файлів</p>';
                        }
                    } else {
                        currentFilesContainer.innerHTML = '<p>Немає прикріплених файлів</p>';
                    }

                    // Очищаємо контейнер для попередження нових файлів
                    document.getElementById('edit-file-preview-container').innerHTML = '';
                }

                // Відображення модального вікна
                editOrderModal.style.display = 'block';

                // Оновлення функціональності перегляду файлів у модальному вікні редагування
                document.querySelectorAll('#edit-current-files .view-file').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        const filePath = this.getAttribute('data-path');
                        const fileName = this.getAttribute('data-filename');
                        showFileInViewer(filePath, fileName);
                    });
                });
            });
        });

        // Функція для відображення файлу у в'юері
        function showFileInViewer(filePath, fileName) {
            // Очищення перегляду
            mediaViewer.innerHTML = '';

            // Визначення типу файлу
            const fileExt = fileName.split('.').pop().toLowerCase();
            const modalTitle = document.querySelector('#fileViewerModal .modal-title');
            modalTitle.textContent = 'Файл: ' + fileName;

            if(['jpg', 'jpeg', 'png', 'gif'].includes(fileExt)) {
                // Зображення
                const img = document.createElement('img');
                img.src = filePath;
                img.alt = fileName;
                mediaViewer.appendChild(img);
            } else if(['mp4', 'avi', 'mov'].includes(fileExt)) {
                // Відео
                const video = document.createElement('video');
                video.src = filePath;
                video.controls = true;
                video.autoplay = false;
                mediaViewer.appendChild(video);
            } else if(['pdf'].includes(fileExt)) {
                // PDF
                const embed = document.createElement('embed');
                embed.src = filePath;
                embed.type = "application/pdf";
                embed.width = "100%";
                embed.height = "500px";
                mediaViewer.appendChild(embed);
            } else if(['txt'].includes(fileExt)) {
                // Текстовий файл
                fetch(filePath)
                    .then(response => response.text())
                    .then(text => {
                        const pre = document.createElement('pre');
                        pre.className = 'file-viewer';
                        pre.textContent = text;
                        mediaViewer.appendChild(pre);
                    })
                    .catch(error => {
                        mediaViewer.innerHTML = `<div class="alert alert-error">Помилка завантаження файлу: ${error.message}</div>`;
                    });
            } else {
                // Інші файли
                const link = document.createElement('a');
                link.href = filePath;
                link.download = fileName;
                link.className = 'btn';
                link.innerHTML = '<i class="fas fa-download"></i> Завантажити файл';

                const message = document.createElement('p');
                message.textContent = 'Цей тип файлу не можна переглянути безпосередньо. Завантажте файл для перегляду.';

                mediaViewer.appendChild(message);
                mediaViewer.appendChild(link);
            }

            // Відображення модального вікна
            fileViewerModal.style.display = 'block';
        }

        // Функціонал модального вікна для додавання коментаря
        const addCommentModal = document.getElementById('addCommentModal');

        document.querySelectorAll('.add-comment').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const orderId = this.getAttribute('data-id');
                document.getElementById('comment-order-id').value = orderId;

                // Очищення попереднього коментаря
                document.getElementById('comment').value = '';

                // Відображення модального вікна
                addCommentModal.style.display = 'block';
            });
        });

        // Налаштування drag-and-drop для файлів замовлення
        const dropZone = document.getElementById('drop-zone');
        const dropZoneInput = document.getElementById('drop-zone-input');
        const filePreviewContainer = document.getElementById('file-preview-container');
        let droppedFiles = [];

        if(dropZone && dropZoneInput) {
            // Відкриття вікна вибору файлів при кліку на зону
            dropZone.addEventListener('click', function() {
                dropZoneInput.click();
            });

            // Обробка вибору файлів через діалог
            dropZoneInput.addEventListener('change', function() {
                handleFiles(this.files);
            });

            // Обробка drag-and-drop подій
            ['dragover', 'dragleave', 'drop'].forEach(eventName => {
                dropZone.addEventListener(eventName, function(e) {
                    e.preventDefault();
                    e.stopPropagation();

                    if(eventName === 'dragover') {
                        dropZone.classList.add('active');
                    } else if(eventName === 'dragleave') {
                        dropZone.classList.remove('active');
                    } else if(eventName === 'drop') {
                        dropZone.classList.remove('active');
                        const dt = e.dataTransfer;
                        handleFiles(dt.files);
                    }
                });
            });
        }

        // Налаштування drag-and-drop для форми редагування
        const editDropZone = document.getElementById('edit-drop-zone');
        const editDropZoneInput = document.getElementById('edit-drop-zone-input');
        const editFilePreviewContainer = document.getElementById('edit-file-preview-container');
        let editDroppedFiles = [];

        if(editDropZone && editDropZoneInput) {
            // Відкриття вікна вибору файлів при кліку на зону
            editDropZone.addEventListener('click', function() {
                editDropZoneInput.click();
            });

            // Обробка вибору файлів через діалог
            editDropZoneInput.addEventListener('change', function() {
                handleFilesForEdit(this.files);
            });

            // Обробка drag-and-drop подій
            ['dragover', 'dragleave', 'drop'].forEach(eventName => {
                editDropZone.addEventListener(eventName, function(e) {
                    e.preventDefault();
                    e.stopPropagation();

                    if(eventName === 'dragover') {
                        editDropZone.classList.add('active');
                    } else if(eventName === 'dragleave') {
                        editDropZone.classList.remove('active');
                    } else if(eventName === 'drop') {
                        editDropZone.classList.remove('active');
                        const dt = e.dataTransfer;
                        handleFilesForEdit(dt.files);
                    }
                });
            });
        }

        // Обробка файлів для нового замовлення
        function handleFiles(files) {
            if(!files || files.length === 0) return;

            Array.from(files).forEach(file => {
                // Перевірка типу файлу
                const validTypes = ['image/jpeg', 'image/png', 'image/gif', 'video/mp4', 'video/avi', 'video/quicktime', 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'text/plain'];
                const maxFileSize = 10 * 1024 * 1024; // 10 MB

                if(!validTypes.includes(file.type)) {
                    showMessage('Недозволений тип файлу: ' + file.name);
                    return;
                }

                if(file.size > maxFileSize) {
                    showMessage('Файл занадто великий: ' + file.name);
                    return;
                }

                // Додавання попереднього перегляду файлу
                const reader = new FileReader();

                reader.onload = function(e) {
                    const filePreview = document.createElement('div');
                    filePreview.className = 'file-item';

                    let iconClass = 'fa-file';
                    if(file.type.startsWith('image/')) {
                        iconClass = 'fa-file-image';
                    } else if(file.type.startsWith('video/')) {
                        iconClass = 'fa-file-video';
                    } else if(file.type === 'application/pdf') {
                        iconClass = 'fa-file-pdf';
                    } else if(file.type === 'application/msword' || file.type === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
                        iconClass = 'fa-file-word';
                    } else if(file.type === 'text/plain') {
                        iconClass = 'fa-file-alt';
                    }

                    filePreview.innerHTML = `
                        <i class="fas ${iconClass} file-icon"></i>
                        <span class="file-name">${file.name}</span>
                        <button type="button" class="btn btn-sm remove-file">
                            <i class="fas fa-trash"></i>
                        </button>
                    `;

                    filePreviewContainer.appendChild(filePreview);

                    // Для зображень можна додати попередній перегляд
                    if(file.type.startsWith('image/')) {
                        const imgPreview = document.createElement('img');
                        imgPreview.src = e.target.result;
                        imgPreview.style.maxWidth = '100px';
                        imgPreview.style.maxHeight = '100px';
                        imgPreview.style.marginTop = '5px';
                        filePreview.appendChild(imgPreview);
                    }

                    // Додавання функціональності для видалення файлу
                    const removeBtn = filePreview.querySelector('.remove-file');
                    removeBtn.addEventListener('click', function() {
                        filePreview.remove();
                    });

                    // Збереження файлу для завантаження через AJAX
                    if(file.type.startsWith('image/')) {
                        droppedFiles.push({
                            name: file.name,
                            data: e.target.result
                        });
                        document.getElementById('dropped_files_data').value = JSON.stringify(droppedFiles);
                    }
                };

                reader.readAsDataURL(file);
            });
        }

        // Обробка файлів для редагування замовлення
        function handleFilesForEdit(files) {
            if(!files || files.length === 0) return;

            Array.from(files).forEach(file => {
                // Перевірка типу файлу
                const validTypes = ['image/jpeg', 'image/png', 'image/gif', 'video/mp4', 'video/avi', 'video/quicktime', 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'text/plain'];
                const maxFileSize = 10 * 1024 * 1024; // 10 MB

                if(!validTypes.includes(file.type)) {
                    showMessage('Недозволений тип файлу: ' + file.name);
                    return;
                }

                if(file.size > maxFileSize) {
                    showMessage('Файл занадто великий: ' + file.name);
                    return;
                }

                // Додавання попереднього перегляду файлу
                const reader = new FileReader();

                reader.onload = function(e) {
                    const filePreview = document.createElement('div');
                    filePreview.className = 'file-item';

                    let iconClass = 'fa-file';
                    if(file.type.startsWith('image/')) {
                        iconClass = 'fa-file-image';
                    } else if(file.type.startsWith('video/')) {
                        iconClass = 'fa-file-video';
                    } else if(file.type === 'application/pdf') {
                        iconClass = 'fa-file-pdf';
                    } else if(file.type === 'application/msword' || file.type === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
                        iconClass = 'fa-file-word';
                    } else if(file.type === 'text/plain') {
                        iconClass = 'fa-file-alt';
                    }

                    filePreview.innerHTML = `
                        <i class="fas ${iconClass} file-icon"></i>
                        <span class="file-name">${file.name}</span>
                        <button type="button" class="btn btn-sm remove-file">
                            <i class="fas fa-trash"></i>
                        </button>
                    `;

                    editFilePreviewContainer.appendChild(filePreview);

                    // Для зображень можна додати попередній перегляд
                    if(file.type.startsWith('image/')) {
                        const imgPreview = document.createElement('img');
                        imgPreview.src = e.target.result;
                        imgPreview.style.maxWidth = '100px';
                        imgPreview.style.maxHeight = '100px';
                        imgPreview.style.marginTop = '5px';
                        filePreview.appendChild(imgPreview);
                    }

                    // Додавання функціональності для видалення файлу
                    const removeBtn = filePreview.querySelector('.remove-file');
                    removeBtn.addEventListener('click', function() {
                        filePreview.remove();
                    });

                    // Збереження файлу для завантаження через AJAX
                    if(file.type.startsWith('image/')) {
                        editDroppedFiles.push({
                            name: file.name,
                            data: e.target.result
                        });
                        document.getElementById('edit_dropped_files_data').value = JSON.stringify(editDroppedFiles);
                    }
                };

                reader.readAsDataURL(file);
            });
        }

        // Функція для відображення тимчасових повідомлень
        function showMessage(message, isSuccess = false) {
            const messageDiv = document.createElement('div');
            messageDiv.className = 'temp-message';
            messageDiv.textContent = message;

            if(isSuccess) {
                messageDiv.style.backgroundColor = 'var(--success-color)';
            }

            document.body.appendChild(messageDiv);

            setTimeout(() => {
                messageDiv.remove();
            }, 4000); // Автоматичне видалення повідомлення через 4 секунди
        }

        // Згортання/розгортання розділів форми
        document.querySelectorAll('.collapsible-header').forEach(function(header) {
            header.addEventListener('click', function() {
                const section = this.closest('.collapsible-section');
                section.classList.toggle('open');
            });
        });

        // Обробник для видалення коментарів
        document.querySelectorAll('.delete-comment').forEach(function(btn) {
            btn.addEventListener('click', function() {
                if(confirm('Ви дійсно хочете видалити цей коментар?')) {
                    const orderId = this.getAttribute('data-id');
                    const csrfToken = document.querySelector('input[name="csrf_token"]').value;

                    // Відправка AJAX запиту
                    const xhr = new XMLHttpRequest();
                    xhr.open('POST', 'dashboard.php', true);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

                    xhr.onload = function() {
                        if(xhr.status === 200) {
                            try {
                                const response = JSON.parse(xhr.responseText);
                                if(response.success) {
                                    // Видалення коментаря з DOM
                                    const commentSection = btn.closest('.user-comment-section');
                                    if(commentSection) {
                                        commentSection.remove();
                                    }
                                    showMessage(response.message, true);
                                } else {
                                    showMessage(response.message);
                                }
                            } catch(e) {
                                showMessage('Помилка обробки відповіді сервера');
                            }
                        } else {
                            showMessage('Помилка сервера. Спробуйте ще раз.');
                        }
                    };

                    xhr.onerror = function() {
                        showMessage('Помилка підключення. Перевірте з\'єднання з інтернетом.');
                    };

                    xhr.send(`delete_comment=1&order_id=${orderId}&csrf_token=${csrfToken}`);
                }
            });
        });

        // AJAX відправка форми коментарів
        document.getElementById('add-comment-form').addEventListener('submit', function(e) {
            e.preventDefault();

            const form = this;
            const formData = new FormData(form);

            // Відправка AJAX запиту
            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'dashboard.php', true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

            xhr.onload = function() {
                if(xhr.status === 200) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if(response.success) {
                            // Закрити модальне вікно
                            document.getElementById('addCommentModal').style.display = 'none';
                            showMessage(response.message, true);

                            // Оновлення сторінки через 2 секунди
                            setTimeout(() => {
                                window.location.reload();
                            }, 2000);
                        } else {
                            showMessage(response.message);
                        }
                    } catch(e) {
                        showMessage('Помилка обробки відповіді сервера');
                    }
                } else {
                    showMessage('Помилка сервера. Спробуйте ще раз.');
                }
            };

            xhr.send(formData);
        });

        // Налаштування теми
        document.querySelectorAll('.theme-option').forEach(function(option) {
            option.addEventListener('click', function() {
                const theme = this.getAttribute('data-theme');

                // Видалення активного класу з усіх опцій
                document.querySelectorAll('.theme-option').forEach(function(opt) {
                    opt.classList.remove('active');
                });

                // Додавання активного класу до вибраної опції
                this.classList.add('active');

                // Встановлення теми
                document.documentElement.setAttribute('data-theme', theme);

                // Збереження теми в localStorage
                localStorage.setItem('theme', theme);
            });
        });

        // Завантаження теми з localStorage
        const savedTheme = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', savedTheme);

        // Встановлення активної кнопки теми
        document.querySelectorAll('.theme-option').forEach(function(option) {
            if(option.getAttribute('data-theme') === savedTheme) {
                option.classList.add('active');
            } else {
                option.classList.remove('active');
            }
        });

        // Для підтримки drag-and-drop для фото профілю
        const profileDropZone = document.getElementById('profile-drop-zone');
        const profilePicInput = document.getElementById('profile_pic');

        if(profileDropZone && profilePicInput) {
            // Відкриття вікна вибору файлів при кліку на зону
            profileDropZone.addEventListener('click', function() {
                profilePicInput.click();
            });

            // Обробка вибору файлу через діалог
            profilePicInput.addEventListener('change', function() {
                handleProfilePic(this.files[0]);
            });

            // Обробка drag-and-drop подій
            ['dragover', 'dragleave', 'drop'].forEach(eventName => {
                profileDropZone.addEventListener(eventName, function(e) {
                    e.preventDefault();
                    e.stopPropagation();

                    if(eventName === 'dragover') {
                        profileDropZone.classList.add('active');
                    } else if(eventName === 'dragleave') {
                        profileDropZone.classList.remove('active');
                    } else if(eventName === 'drop') {
                        profileDropZone.classList.remove('active');
                        const dt = e.dataTransfer;
                        if(dt.files.length) {
                            handleProfilePic(dt.files[0]);
                        }
                    }
                });
            });
        }

        // Обробка завантаження фото профілю
        function handleProfilePic(file) {
            if(!file) return;

            // Перевірка типу файлу
            const validTypes = ['image/jpeg', 'image/png', 'image/gif'];
            const maxFileSize = 2 * 1024 * 1024; // 2 MB

            if(!validTypes.includes(file.type)) {
                showMessage('Недозволений тип файлу. Дозволені: jpg, jpeg, png, gif');
                return;
            }

            if(file.size > maxFileSize) {
                showMessage('Файл занадто великий. Максимальний розмір: 2MB');
                return;
            }

            // Показати попередній перегляд зображення
            const reader = new FileReader();
            reader.onload = function(e) {
                const previewImg = document.querySelector('.profile-preview');
                if(previewImg) {
                    previewImg.src = e.target.result;
                } else {
                    const newPreview = document.createElement('img');
                    newPreview.src = e.target.result;
                    newPreview.alt = 'Попередній перегляд фото';
                    newPreview.className = 'profile-preview';
                    newPreview.style.maxWidth = '150px';
                    newPreview.style.borderRadius = '5px';
                    newPreview.style.marginBottom = '10px';
                    profileDropZone.parentNode.insertBefore(newPreview, profileDropZone);
                }

                // Відправка форми автоматично після вибору файлу
                profileDropZone.closest('form').submit();
            };
            reader.readAsDataURL(file);
        }

        // Автоматична фільтрація при зміні вибору в фільтрах
        document.querySelectorAll('#filter-form select').forEach(function(select) {
            select.addEventListener('change', function() {
                document.getElementById('filter-form').submit();
            });
        });

        // Додати перевірку ключових полів при створенні та редагуванні замовлення
        [document.querySelector('.order-form'), document.getElementById('edit-order-form')].forEach(form => {
            if(form) {
                form.addEventListener('submit', function(e) {
                    // Список обов'язкових полів і їх дружніх назв
                    const requiredFields = [
                        {id: form.querySelector('select[name="service"]').id, name: 'Послуга'},
                        {id: form.querySelector('select[name="device_type"]').id, name: 'Тип пристрою'},
                        {id: form.querySelector('textarea[name="details"]').id, name: 'Деталі замовлення'},
                        {id: form.querySelector('input[name="phone"]').id, name: 'Контактний телефон'},
                        {id: form.querySelector('textarea[name="address"]').id, name: 'Адреса'},
                        {id: form.querySelector('select[name="delivery_method"]').id, name: 'Спосіб доставки'}
                    ];

                    // Перевірка кожного поля
                    let hasErrors = false;
                    requiredFields.forEach(field => {
                        const input = document.getElementById(field.id);
                        if(!input.value.trim()) {
                            e.preventDefault();
                            input.style.borderColor = 'var(--error-color)';
                            hasErrors = true;

                            // Показати повідомлення про помилку
                            const errorMsg = document.createElement('div');
                            errorMsg.className = 'input-error';
                            errorMsg.textContent = `Поле "${field.name}" є обов'язковим`;
                            errorMsg.style.color = 'var(--error-color)';
                            errorMsg.style.fontSize = '0.8rem';
                            errorMsg.style.marginTop = '5px';

                            // Перевірка чи немає вже повідомлення про помилку
                            const existingError = input.parentNode.querySelector('.input-error');
                            if(!existingError) {
                                input.parentNode.appendChild(errorMsg);
                            }

                            // Видалити помилку при фокусі
                            input.addEventListener('focus', function() {
                                this.style.borderColor = '';
                                const error = this.parentNode.querySelector('.input-error');
                                if(error) {
                                    error.remove();
                                }
                            });
                        }
                    });

                    if(hasErrors) {
                        showMessage('Будь ласка, заповніть всі обов\'язкові поля');
                    }
                });
            }
        });

        // Активація вкладок з URL хеша
        function activateTabFromHash() {
            const hash = window.location.hash;
            if(hash) {
                const tabLink = document.querySelector(`.sidebar-menu a[href="${hash}"]`);
                if(tabLink) {
                    // Імітація кліку по вкладці з потрібним ID
                    tabLink.click();
                }
            }
        }

        // Виклик функції при завантаженні сторінки
        activateTabFromHash();

        // Зміна хеша при перемиканні вкладок
        document.querySelectorAll('.sidebar-menu a').forEach(function(link) {
            link.addEventListener('click', function() {
                window.location.hash = this.getAttribute('href');
            });
        });

        // Валідація для форми створення замовлення
        const validatePhone = (phone) => {
            // Перевірка формату телефону - приймаємо різні українські формати
            const patterns = [
                /^\+380\d{9}$/,           // +380XXXXXXXXX
                /^0\d{9}$/,              // 0XXXXXXXXX
                /^80\d{9}$/,             // 80XXXXXXXXX
                /^\+380 \(\d{2}\) \d{3}-\d{2}-\d{2}$/, // +380 (XX) XXX-XX-XX
                /^0\d{2} \d{3} \d{4}$/   // 0XX XXX XXXX
            ];

            return patterns.some(pattern => pattern.test(phone));
        };

        // Додавання валідації до форм
        [document.querySelector('.order-form'), document.getElementById('edit-order-form')].forEach(form => {
            if(form) {
                const phoneInput = form.querySelector('input[name="phone"]');
                if(phoneInput) {
                    phoneInput.addEventListener('blur', function() {
                        if(!validatePhone(this.value)) {
                            this.style.borderColor = 'var(--error-color)';

                            // Показати повідомлення про помилку
                            const errorMsg = document.createElement('div');
                            errorMsg.className = 'input-error';
                            errorMsg.textContent = 'Введіть коректний номер телефону';
                            errorMsg.style.color = 'var(--error-color)';
                            errorMsg.style.fontSize = '0.8rem';
                            errorMsg.style.marginTop = '5px';

                            // Перевірка чи немає вже повідомлення про помилку
                            const existingError = this.parentNode.querySelector('.input-error');
                            if(!existingError) {
                                this.parentNode.appendChild(errorMsg);
                            }
                        } else {
                            this.style.borderColor = '';
                            const error = this.parentNode.querySelector('.input-error');
                            if(error) {
                                error.remove();
                            }
                        }
                    });
                }

                // Додаємо перевірку при відправці форми
                form.addEventListener('submit', function(e) {
                    const phoneInput = this.querySelector('input[name="phone"]');
                    if(phoneInput && !validatePhone(phoneInput.value)) {
                        e.preventDefault();
                        showMessage('Будь ласка, введіть коректний номер телефону');
                    }
                });
            }
        });

        // Швидка дія для копіювання контактних даних з профілю при створенні замовлення
        const createOrderForm = document.querySelector('.order-form');
        if(createOrderForm) {
            // Додамо кнопку швидкого копіювання даних з профілю
            const contactInfoHeader = createOrderForm.querySelector('.collapsible-header:nth-child(2)');
            if(contactInfoHeader) {
                const quickFillBtn = document.createElement('button');
                quickFillBtn.type = 'button';
                quickFillBtn.className = 'btn btn-sm';
                quickFillBtn.style.marginLeft = 'auto';
                quickFillBtn.style.marginRight = '10px';
                quickFillBtn.innerHTML = '<i class="fas fa-user-check"></i> Заповнити з профілю';

                contactInfoHeader.insertBefore(quickFillBtn, contactInfoHeader.querySelector('.rotate-icon'));

                // Логіка для швидкого заповнення
                quickFillBtn.addEventListener('click', function(e) {
                    e.stopPropagation(); // Щоб не спрацьовувало згортання секції

                    // Отримуємо дані з профілю
                    const profileFirstName = document.getElementById('first_name').value;
                    const profileLastName = document.getElementById('last_name').value;
                    const profileMiddleName = document.getElementById('middle_name').value;
                    const profilePhone = document.getElementById('profile_phone').value;
                    const profileAddress = document.getElementById('profile_address').value;
                    const profileDelivery = document.getElementById('profile_delivery_method').value;

                    // Заповнюємо форму замовлення
                    createOrderForm.querySelector('input[name="first_name"]').value = profileFirstName;
                    createOrderForm.querySelector('input[name="last_name"]').value = profileLastName;
                    createOrderForm.querySelector('input[name="middle_name"]').value = profileMiddleName;
                    createOrderForm.querySelector('input[name="phone"]').value = profilePhone;
                    createOrderForm.querySelector('textarea[name="address"]').value = profileAddress;
                    createOrderForm.querySelector('select[name="delivery_method"]').value = profileDelivery;

                    // Показати повідомлення про успіх
                    showMessage('Контактні дані заповнено з профілю', true);

                    // Розгорнути секцію, якщо вона згорнута
                    const section = contactInfoHeader.closest('.collapsible-section');
                    if(!section.classList.contains('open')) {
                        section.classList.add('open');
                    }
                });
            }
        }

        // Додаткова логіка для відображення нових повідомлень
        document.querySelectorAll('.has-new-messages').forEach(function(orderCard) {
            orderCard.style.transition = 'box-shadow 0.5s';
            orderCard.style.boxShadow = '0 0 0 2px var(--primary-color)';
        });

        // Поточний час на сервері: <?= date('d.m.Y H:i:s', strtotime('2025-03-19 07:28:11')) ?>
        // Користувач: <?= htmlspecialchars('1GodofErath') ?>

        // Додаємо унікальний ідентифікатор сесії для запобігання кешування
        const sessionId = '<?= session_id() ?>';
    </script>
</body>
</html>
