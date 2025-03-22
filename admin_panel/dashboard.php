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
        $mail->Port       = 465;

        // Recipients
        $mail->setFrom('no-reply@lagodiy.com', 'Lagodiy Service');
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

// ЗМІНЕНО: Перевірка кількості активних замовлень зі статусами "новий", "в роботі", "очікує товар"
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

    // ЗМІНЕНО: Перевірка ліміту замовлень (тільки для активних замовлень)
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

    // НОВА ФУНКЦІОНАЛЬНІСТЬ: Отримання непрочитаних сповіщень (нові коментарі адміністратора та зміни статусу)
    if (!empty($order_ids)) {
        $placeholders = str_repeat('?,', count($order_ids) - 1) . '?';

        // Отримуємо непрочитані коментарі
        $notifications_query = "SELECT order_id, COUNT(*) as unread_count FROM notifications 
                               WHERE order_id IN ($placeholders) AND user_id = ? AND is_read = 0 AND type = 'comment'
                               GROUP BY order_id";

        $params = array_merge($order_ids, [$user_id]);
        $types = str_repeat('i', count($order_ids)) . 'i';

        $stmt = $conn->prepare($notifications_query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $notifications_result = $stmt->get_result();

        while ($notification = $notifications_result->fetch_assoc()) {
            $grouped_orders[$notification['order_id']]['unread_notifications'] += $notification['unread_count'];
        }

        // Отримуємо непрочитані зміни статусу
        $status_query = "SELECT order_id, COUNT(*) as unread_count FROM notifications 
                        WHERE order_id IN ($placeholders) AND user_id = ? AND is_read = 0 AND type = 'status'
                        GROUP BY order_id";

        $stmt = $conn->prepare($status_query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $status_result = $stmt->get_result();

        while ($status = $status_result->fetch_assoc()) {
            $grouped_orders[$status['order_id']]['unread_notifications'] += $status['unread_count'];
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

// ЗМІНЕНО: Функція для обробки відповідей адміністратора та статусу замовлення
function processNotifications($conn, $user_id, $order_id) {
    // Створюємо таблицю notifications, якщо вона ще не існує
    try {
        $conn->query("
            CREATE TABLE IF NOT EXISTS notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                order_id INT NOT NULL,
                type VARCHAR(20) NOT NULL, -- 'comment' або 'status'
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

        // Відправляємо email про новий коментар, якщо є прикріплений файл
        if (!empty($comment['file_attachment'])) {
            // Отримуємо email користувача
            $user_stmt = $conn->prepare("SELECT email FROM users WHERE id = ?");
            $user_stmt->bind_param("i", $user_id);
            $user_stmt->execute();
            $user_result = $user_stmt->get_result();
            $user_email = $user_result->fetch_assoc()['email'] ?? '';

            if (!empty($user_email)) {
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
                        <p><strong>Прикріплений файл:</strong> Для перегляду файлу, будь ласка, перейдіть до особистого кабінету</p>
                    </body>
                    </html>
                ";

                // Відправка email
                sendNotificationEmail($user_email, $subject, $message);
            }
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

// ЗМІНЕНО: Функція для позначення сповіщень як прочитаних
function markNotificationsAsRead($conn, $user_id, $order_id) {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND order_id = ?");
    $stmt->bind_param("ii", $user_id, $order_id);
    $stmt->execute();
    return $stmt->affected_rows;
}

// Обробка AJAX запиту на позначення сповіщень як прочитаних
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['mark_notifications_read'])) {
    $order_id = $_POST['order_id'] ?? 0;
    $affected = markNotificationsAsRead($conn, $user_id, $order_id);
    echo json_encode(['success' => true, 'affected' => $affected]);
    exit();
}

// Перевіряємо сповіщення для кожного замовлення користувача
foreach ($orders as &$order) {
    $notifications = processNotifications($conn, $user_id, $order['id']);
    $order['notifications'] = $notifications;
    $order['unread_count'] = $notifications['unread_count'];
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
            --notification-color: #f44336;
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
            position: relative;
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

        /* Новий дизайн індикатора сповіщень */
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
                box-shadow: 0 0 0 0 rgba(255, 0, 0, 0.7);
            }
            70% {
                transform: scale(1.1);
                box-shadow: 0 0 0 10px rgba(255, 0, 0, 0);
            }
            100% {
                transform: scale(0.95);
                box-shadow: 0 0 0 0 rgba(255, 0, 0, 0);
            }
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
            position: relative;
        }

        .order-card:hover {
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }

        /* Новий дизайн для замовлень з непрочитаними сповіщеннями */
        .order-card.has-notifications {
            border-left: 4px solid var(--notification-color);
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
            display: flex;
            align-items: center;
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

        /* Новий стиль для індикатора сповіщень */
        .notification-indicator {
            position: relative;
            display: inline-block;
            width: 12px;
            height: 12px;
            background-color: var(--notification-color);
            border-radius: 50%;
            margin-left: 8px;
            animation: pulse 1.5s infinite;
        }

        /* Контейнер для сповіщень на панелі навігації */
        .notifications-container {
            position: absolute;
            top: 60px;
            right: 20px;
            background-color: var(--card-bg);
            border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            width: 300px;
            max-height: 400px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
        }

        .notifications-header {
            padding: 10px 15px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .notification-item {
            padding: 10px 15px;
            border-bottom: 1px solid var(--border-color);
            transition: background-color 0.2s;
        }

        .notification-item:hover {
            background-color: rgba(0,0,0,0.02);
        }

        .notification-item.unread {
            background-color: rgba(52, 152, 219, 0.1);
            position: relative;
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

        .notification-title {
            font-weight: 600;
            margin-bottom: 5px;
        }

        .notification-time {
            font-size: 0.8rem;
            color: #777;
        }

        .mark-all-read {
            color: var(--primary-color);
            background: none;
            border: none;
            cursor: pointer;
            font-size: 0.8rem;
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

            .notifications-container {
                width: 100%;
                right: 0;
                left: 0;
            }
        }

        /* Стилі для тексту обов'язкових полів */
        .required-field::after {
            content: " *";
            color: var(--error-color);
        }

        /* Нові стилі для іконки сповіщень */
        .notifications-icon {
            position: relative;
            cursor: pointer;
            font-size: 1.2rem;
            margin-right: 15px;
        }

        .notifications-count {
            position: absolute;
            top: -7px;
            right: -7px;
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
        }

        /* Анімація появи нового сповіщення */
        @keyframes newNotification {
            0% { transform: scale(1); }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); }
        }

        .new-notification {
            animation: newNotification 0.5s ease-in-out;
        }

        /* Стилі для статусу прочитання сповіщень */
        .status-update {
            display: flex;
            align-items: center;
            background-color: rgba(52, 152, 219, 0.1);
            padding: 8px 12px;
            border-radius: 4px;
            margin-top: 10px;
        }

        .status-update-icon {
            margin-right: 10px;
            color: var(--primary-color);
        }

        .status-update-message {
            font-size: 0.9rem;
        }

        /* Стилі для плаваючої кнопки сповіщень на мобільних */
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
        }

        @media (max-width: 768px) {
            .mobile-notifications-btn {
                display: flex;
            }
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
                <?php
                // Підрахунок усіх непрочитаних сповіщень
                $total_notifications = 0;
                foreach ($orders as $order) {
                    $total_notifications += $order['unread_count'];
                }
                if ($total_notifications > 0): ?>
                    <span class="notification-badge"><?= $total_notifications > 9 ? '9+' : $total_notifications ?></span>
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
            <!-- Іконка сповіщень -->
            <div class="notifications-icon" id="notifications-toggle">
                <i class="fas fa-bell"></i>
                <?php if ($total_notifications > 0): ?>
                    <span class="notifications-count"><?= $total_notifications > 9 ? '9+' : $total_notifications ?></span>
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

    <!-- Контейнер для сповіщень -->
    <div class="notifications-container" id="notifications-container">
        <div class="notifications-header">
            <span>Сповіщення</span>
            <?php if ($total_notifications > 0): ?>
                <button class="mark-all-read" id="mark-all-read">Позначити всі як прочитані</button>
            <?php endif; ?>
        </div>
        <div class="notifications-content">
            <?php if ($total_notifications > 0): ?>
                <?php foreach ($orders as $order): ?>
                    <?php if ($order['unread_count'] > 0): ?>
                        <div class="notification-item unread" data-order-id="<?= $order['id'] ?>">
                            <div class="notification-title">Замовлення #<?= $order['id'] ?></div>
                            <div class="notification-message">
                                <?php if (isset($order['notifications']['status_changed']) && $order['notifications']['status_changed']): ?>
                                    <div>Статус замовлення змінено на: <?= $order['notifications']['current_status'] ?></div>
                                <?php endif; ?>
                                <?php if (!empty($order['notifications']['new_comments'])): ?>
                                    <div>Нові коментарі від адміністратора (<?= count($order['notifications']['new_comments']) ?>)</div>
                                <?php endif; ?>
                            </div>
                            <div class="notification-time">Натисніть для перегляду</div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="notification-item">
                    <div class="notification-message">Немає нових сповіщень</div>
                </div>
            <?php endif; ?>
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
                <?php if ($total_notifications > 0): ?>
                    <p>Непрочитаних сповіщень: <?= $total_notifications ?></p>
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
                    $has_notifications = $order['unread_count'] > 0;
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
                                        <?php if (isset($order['notifications']['status_changed']) && $order['notifications']['status_changed']): ?>
                                            <div>Статус замовлення змінено на: <strong><?= $order['notifications']['current_status'] ?></strong></div>
                                        <?php endif; ?>
                                        <?php if (!empty($order['notifications']['new_comments'])): ?>
                                            <div>Нові коментарі від адміністратора (<?= count($order['notifications']['new_comments']) ?>)</div>
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
                        $has_notifications = $order['unread_count'] > 0;
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
                                            <?php if (isset($order['notifications']['status_changed']) && $order['notifications']['status_changed']): ?>
                                                <div>Статус замовлення змінено на: <strong><?= $order['notifications']['current_status'] ?></strong></div>
                                            <?php endif; ?>
                                            <?php if (!empty($order['notifications']['new_comments'])): ?>
                                                <div>Нові коментарі від адміністратора (<?= count($order['notifications']['new_comments']) ?>)</div>
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

    <!-- Решта вмісту залишається без змін... -->

    <!-- Мобільна кнопка сповіщень -->
    <div class="mobile-notifications-btn" id="mobile-notifications-btn">
        <i class="fas fa-bell"></i>
        <?php if ($total_notifications > 0): ?>
            <span class="notifications-count"><?= $total_notifications > 9 ? '9+' : $total_notifications ?></span>
        <?php endif; ?>
    </div>

    <script>
        // Оновлення локального часу
        function updateLocalTimes() {
            document.getElementById('current-time').textContent = new Date().toLocaleString('uk-UA');

            const utcTimeElements = document.querySelectorAll('.local-time');
            utcTimeElements.forEach(element => {
                const utcTime = element.getAttribute('data-utc');
                if (utcTime) {
                    const localTime = new Date(utcTime).toLocaleString('uk-UA');
                    element.textContent = localTime;
                }
            });
        }

        // Функція для форматування розміру файлу
        function formatFileSize(bytes) {
            if (bytes < 1024) return bytes + ' байт';
            else if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' КБ';
            else return (bytes / 1048576).toFixed(1) + ' МБ';
        }

        // Ініціалізація сповіщень
        function initNotifications() {
            const notificationsToggle = document.getElementById('notifications-toggle');
            const notificationsContainer = document.getElementById('notifications-container');
            const mobileNotificationsBtn = document.getElementById('mobile-notifications-btn');
            const markAllRead = document.getElementById('mark-all-read');

            // Відкриття/закриття панелі сповіщень
            function toggleNotifications() {
                if (notificationsContainer.style.display === 'block') {
                    notificationsContainer.style.display = 'none';
                } else {
                    notificationsContainer.style.display = 'block';
                }
            }

            // Додавання обробників подій
            if (notificationsToggle) {
                notificationsToggle.addEventListener('click', toggleNotifications);
            }

            if (mobileNotificationsBtn) {
                mobileNotificationsBtn.addEventListener('click', toggleNotifications);
            }

            // Закриття панелі при кліці поза нею
            document.addEventListener('click', function(event) {
                const isClickInside = notificationsContainer.contains(event.target) ||
                    notificationsToggle.contains(event.target) ||
                    (mobileNotificationsBtn && mobileNotificationsBtn.contains(event.target));

                if (!isClickInside && notificationsContainer.style.display === 'block') {
                    notificationsContainer.style.display = 'none';
                }
            });

            // Обробка кліків по сповіщеннях
            const notificationItems = document.querySelectorAll('.notification-item');
            notificationItems.forEach(item => {
                item.addEventListener('click', function() {
                    const orderId = this.getAttribute('data-order-id');
                    if (orderId) {
                        // Перехід до замовлення та позначення сповіщень як прочитаних
                        markOrderNotificationsAsRead(orderId);

                        // Активуємо вкладку "Мої замовлення"
                        const ordersTab = document.querySelector('.sidebar-menu a[data-tab="orders"]');
                        if (ordersTab) ordersTab.click();

                        // Скролимо до замовлення
                        setTimeout(() => {
                            const orderCard = document.querySelector(`.order-card[data-order-id="${orderId}"]`);
                            if (orderCard) {
                                orderCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                orderCard.classList.add('highlight-animation');
                                setTimeout(() => {
                                    orderCard.classList.remove('highlight-animation');
                                }, 2000);
                            }
                        }, 300);

                        notificationsContainer.style.display = 'none';
                    }
                });
            });

            // Позначення всіх сповіщень як прочитаних
            if (markAllRead) {
                markAllRead.addEventListener('click', function(e) {
                    e.stopPropagation();
                    markAllNotificationsAsRead();
                });
            }

            // Обробка клікання на картці замовлення з сповіщеннями
            const orderCards = document.querySelectorAll('.order-card.has-notifications');
            orderCards.forEach(card => {
                card.addEventListener('click', function(e) {
                    // Перевіряємо, що клік не був по кнопках дій
                    if (!e.target.closest('.order-actions') && !e.target.closest('.view-more-btn') &&
                        !e.target.closest('.delete-comment') && !e.target.closest('.view-file')) {
                        const orderId = this.getAttribute('data-order-id');
                        if (orderId) {
                            markOrderNotificationsAsRead(orderId);
                        }
                    }
                });
            });
        }

        // Функція для позначення сповіщень замовлення як прочитаних
        function markOrderNotificationsAsRead(orderId) {
            const formData = new FormData();
            formData.append('mark_notifications_read', '1');
            formData.append('order_id', orderId);
            formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);

            fetch('dashboard.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Оновлюємо візуальні індикатори сповіщень
                        const orderCard = document.querySelector(`.order-card[data-order-id="${orderId}"]`);
                        if (orderCard) {
                            orderCard.classList.remove('has-notifications');
                            const indicator = orderCard.querySelector('.notification-indicator');
                            if (indicator) indicator.remove();

                            const statusUpdate = orderCard.querySelector('.status-update');
                            if (statusUpdate) statusUpdate.style.display = 'none';
                        }

                        // Оновлюємо лічильники сповіщень
                        updateNotificationCounters();
                    }
                })
                .catch(error => {
                    console.error('Error marking notifications as read:', error);
                });
        }

        // Функція для позначення всіх сповіщень як прочитаних
        function markAllNotificationsAsRead() {
            const orderIds = Array.from(document.querySelectorAll('.order-card.has-notifications'))
                .map(card => card.getAttribute('data-order-id'));

            if (orderIds.length === 0) return;

            // Створюємо масив промісів для всіх запитів
            const promises = orderIds.map(orderId => {
                const formData = new FormData();
                formData.append('mark_notifications_read', '1');
                formData.append('order_id', orderId);
                formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);

                return fetch('dashboard.php', {
                    method: 'POST',
                    body: formData
                }).then(response => response.json());
            });

            // Очікуємо завершення всіх запитів
            Promise.all(promises)
                .then(() => {
                    // Оновлюємо всі візуальні індикатори
                    document.querySelectorAll('.order-card.has-notifications').forEach(card => {
                        card.classList.remove('has-notifications');
                        const indicator = card.querySelector('.notification-indicator');
                        if (indicator) indicator.remove();

                        const statusUpdate = card.querySelector('.status-update');
                        if (statusUpdate) statusUpdate.style.display = 'none';
                    });

                    // Оновлюємо лічильники сповіщень
                    updateNotificationCounters();

                    // Оновлюємо вигляд панелі сповіщень
                    const notificationsContent = document.querySelector('.notifications-content');
                    if (notificationsContent) {
                        notificationsContent.innerHTML = '<div class="notification-item"><div class="notification-message">Немає нових сповіщень</div></div>';
                    }

                    // Приховуємо кнопку "Позначити всі як прочитані"
                    const markAllReadBtn = document.getElementById('mark-all-read');
                    if (markAllReadBtn) markAllReadBtn.style.display = 'none';

                    // Приховуємо панель сповіщень
                    const notificationsContainer = document.getElementById('notifications-container');
                    if (notificationsContainer) {
                        setTimeout(() => {
                            notificationsContainer.style.display = 'none';
                        }, 1000);
                    }
                })
                .catch(error => {
                    console.error('Error marking all notifications as read:', error);
                });
        }

        // Функція для оновлення лічильників сповіщень
        function updateNotificationCounters() {
            const notificationsCount = document.querySelectorAll('.order-card.has-notifications').length;

            // Оновлюємо лічильник на іконці сповіщень
            const countBadges = document.querySelectorAll('.notifications-count');
            countBadges.forEach(badge => {
                if (notificationsCount > 0) {
                    badge.textContent = notificationsCount > 9 ? '9+' : notificationsCount;
                    badge.style.display = 'flex';
                } else {
                    badge.style.display = 'none';
                }
            });

            // Оновлюємо індикатор у меню
            const menuBadge = document.querySelector('.sidebar-menu .notification-badge');
            if (menuBadge) {
                if (notificationsCount > 0) {
                    menuBadge.textContent = notificationsCount > 9 ? '9+' : notificationsCount;
                    menuBadge.style.display = 'flex';
                } else {
                    menuBadge.style.display = 'none';
                }
            }

            // Оновлюємо статистику на головній
            const notificationsStats = document.querySelector('.order-stats p:nth-child(3)');
            if (notificationsStats) {
                if (notificationsCount > 0) {
                    notificationsStats.textContent = `Непрочитаних сповіщень: ${notificationsCount}`;
                    notificationsStats.style.display = 'block';
                } else {
                    notificationsStats.style.display = 'none';
                }
            }
        }

        // Обробка перегляду деталей замовлення
        function initViewMoreButtons() {
            const viewMoreButtons = document.querySelectorAll('.view-more-btn');
            viewMoreButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const orderBody = this.closest('.order-body');
                    orderBody.classList.toggle('expanded');
                    this.innerHTML = orderBody.classList.contains('expanded')
                        ? 'Згорнути <i class="fas fa-chevron-up"></i>'
                        : 'Переглянути повну інформацію <i class="fas fa-chevron-down"></i>';
                });
            });
        }

        // Функція для ініціалізації Drag & Drop
        function initDragAndDrop(dropZoneId, inputId, previewContainerId, droppedFilesInputId) {
            const dropZone = document.getElementById(dropZoneId);
            const fileInput = document.getElementById(inputId);
            const previewContainer = document.getElementById(previewContainerId);
            const droppedFilesInput = document.getElementById(droppedFilesInputId);

            let droppedFilesData = [];

            if (!dropZone || !fileInput) return;

            // Обробка вибору файлів через клік
            dropZone.addEventListener('click', () => fileInput.click());

            // Обробка вибору файлів
            fileInput.addEventListener('change', function() {
                updateFilePreview(this.files);
            });

            // Обробка Drag & Drop
            dropZone.addEventListener('dragover', (e) => {
                e.preventDefault();
                dropZone.classList.add('active');
            });

            dropZone.addEventListener('dragleave', () => {
                dropZone.classList.remove('active');
            });

            dropZone.addEventListener('drop', (e) => {
                e.preventDefault();
                dropZone.classList.remove('active');

                if (e.dataTransfer.files.length > 0) {
                    handleDroppedFiles(e.dataTransfer.files);
                }
            });

            // Обробка перетягнутих файлів
            function handleDroppedFiles(files) {
                for (const file of files) {
                    const reader = new FileReader();
                    reader.readAsDataURL(file);
                    reader.onload = function() {
                        const fileData = {
                            name: file.name,
                            data: reader.result
                        };
                        droppedFilesData.push(fileData);
                        droppedFilesInput.value = JSON.stringify(droppedFilesData);

                        // Додавання превью файлу
                        const previewItem = document.createElement('div');
                        previewItem.className = 'drop-zone-thumb';

                        let iconClass = 'fa-file';
                        const ext = file.name.split('.').pop().toLowerCase();
                        if (['jpg', 'jpeg', 'png', 'gif'].includes(ext)) {
                            iconClass = 'fa-file-image';
                        } else if (['mp4', 'avi', 'mov'].includes(ext)) {
                            iconClass = 'fa-file-video';
                        } else if (ext === 'pdf') {
                            iconClass = 'fa-file-pdf';
                        } else if (['doc', 'docx'].includes(ext)) {
                            iconClass = 'fa-file-word';
                        } else if (ext === 'txt') {
                            iconClass = 'fa-file-alt';
                        }

                        previewItem.innerHTML = `
                        <i class="fas ${iconClass}" style="margin-right: 5px;"></i>
                        <span>${file.name}</span> (${formatFileSize(file.size)})
                        <button type="button" class="btn-text remove-file" data-index="${droppedFilesData.length - 1}">&times;</button>
                    `;
                        previewContainer.appendChild(previewItem);
                    };
                }
            }

            // Оновлення превью файлів
            function updateFilePreview(files) {
                for (const file of files) {
                    // Додавання превью файлу
                    const previewItem = document.createElement('div');
                    previewItem.className = 'drop-zone-thumb';

                    let iconClass = 'fa-file';
                    const ext = file.name.split('.').pop().toLowerCase();
                    if (['jpg', 'jpeg', 'png', 'gif'].includes(ext)) {
                        iconClass = 'fa-file-image';
                    } else if (['mp4', 'avi', 'mov'].includes(ext)) {
                        iconClass = 'fa-file-video';
                    } else if (ext === 'pdf') {
                        iconClass = 'fa-file-pdf';
                    } else if (['doc', 'docx'].includes(ext)) {
                        iconClass = 'fa-file-word';
                    } else if (ext === 'txt') {
                        iconClass = 'fa-file-alt';
                    }

                    previewItem.innerHTML = `
                    <i class="fas ${iconClass}" style="margin-right: 5px;"></i>
                    <span>${file.name}</span> (${formatFileSize(file.size)})
                `;
                    previewContainer.appendChild(previewItem);
                }
            }

            // Обробка видалення файлів
            previewContainer.addEventListener('click', function(e) {
                if (e.target.classList.contains('remove-file')) {
                    const index = parseInt(e.target.getAttribute('data-index'));
                    droppedFilesData.splice(index, 1);
                    droppedFilesInput.value = JSON.stringify(droppedFilesData);
                    e.target.closest('.drop-zone-thumb').remove();

                    // Оновлення індексів для інших кнопок видалення
                    const removeButtons = previewContainer.querySelectorAll('.remove-file');
                    removeButtons.forEach((button, i) => {
                        button.setAttribute('data-index', i);
                    });
                }
            });
        }

        // Функція для створення тимчасового повідомлення
        function showTempMessage(message, isSuccess = true) {
            const messageEl = document.createElement('div');
            messageEl.className = 'temp-message';
            messageEl.textContent = message;

            if (!isSuccess) {
                messageEl.style.backgroundColor = 'var(--error-color)';
            }

            document.body.appendChild(messageEl);

            setTimeout(() => {
                messageEl.remove();
            }, 4000); // Видаляємо через 4 секунди
        }

        // Ініціалізація згортання розділів
        function initCollapsibleSections() {
            const collapsibleHeaders = document.querySelectorAll('.collapsible-header');
            collapsibleHeaders.forEach(header => {
                header.addEventListener('click', function() {
                    const section = this.closest('.collapsible-section');
                    section.classList.toggle('open');
                });
            });
        }

        // Функція для зміни теми
        function initThemeSelector() {
            const themeOptions = document.querySelectorAll('.theme-option');
            const htmlElement = document.documentElement;

            // Перевірка збереженої теми
            const savedTheme = localStorage.getItem('theme') || 'light';
            htmlElement.setAttribute('data-theme', savedTheme);

            // Позначення активної теми
            themeOptions.forEach(option => {
                if (option.getAttribute('data-theme') === savedTheme) {
                    option.classList.add('active');
                } else {
                    option.classList.remove('active');
                }
            });

            // Обробка вибору теми
            themeOptions.forEach(option => {
                option.addEventListener('click', function() {
                    const theme = this.getAttribute('data-theme');
                    htmlElement.setAttribute('data-theme', theme);
                    localStorage.setItem('theme', theme);

                    themeOptions.forEach(op => op.classList.remove('active'));
                    this.classList.add('active');
                });
            });
        }

        // Функція для ініціалізації видалення коментарів користувача
        function initDeleteComments() {
            const deleteButtons = document.querySelectorAll('.delete-comment');
            deleteButtons.forEach(button => {
                button.addEventListener('click', function() {
                    if (confirm('Ви впевнені, що хочете видалити коментар?')) {
                        const orderId = this.getAttribute('data-id');
                        const csrfToken = document.querySelector('input[name="csrf_token"]').value;

                        const formData = new FormData();
                        formData.append('delete_comment', '1');
                        formData.append('order_id', orderId);
                        formData.append('csrf_token', csrfToken);

                        fetch('dashboard.php', {
                            method: 'POST',
                            body: formData
                        })
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    // Видаляємо коментар з DOM
                                    const commentSection = this.closest('.user-comment-section');
                                    commentSection.innerHTML = '';
                                    showTempMessage(data.message);
                                } else {
                                    showTempMessage(data.message, false);
                                }
                            })
                            .catch(error => {
                                console.error('Error:', error);
                                showTempMessage('Помилка при видаленні коментаря', false);
                            });
                    }
                });
            });
        }

        // Функція для ініціалізації перегляду файлів
        function initFileViewer() {
            const viewButtons = document.querySelectorAll('.view-file');
            const modal = document.getElementById('file-view-modal');
            const fileNameTitle = document.getElementById('file-name-title');
            const fileContentContainer = document.getElementById('file-content-container');
            const closeButton = modal.querySelector('.close-modal');

            viewButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const filePath = this.getAttribute('data-path');
                    const fileName = this.getAttribute('data-filename');
                    fileNameTitle.textContent = fileName;

                    // Очищення контейнера
                    fileContentContainer.innerHTML = '';

                    // Визначення типу файлу
                    const ext = fileName.split('.').pop().toLowerCase();

                    if (['jpg', 'jpeg', 'png', 'gif'].includes(ext)) {
                        // Зображення
                        const img = document.createElement('img');
                        img.src = filePath;
                        img.alt = fileName;
                        fileContentContainer.appendChild(img);
                    } else if (['mp4', 'avi', 'mov'].includes(ext)) {
                        // Відео
                        const video = document.createElement('video');
                        video.src = filePath;
                        video.controls = true;
                        fileContentContainer.appendChild(video);
                    } else if (ext === 'pdf') {
                        // PDF
                        const embed = document.createElement('embed');
                        embed.src = filePath;
                        embed.type = 'application/pdf';
                        embed.style.width = '100%';
                        embed.style.height = '500px';
                        fileContentContainer.appendChild(embed);
                    } else if (['doc', 'docx', 'txt'].includes(ext)) {
                        // Текстові файли або MS Word
                        const iframe = document.createElement('iframe');
                        iframe.src = filePath;
                        iframe.style.width = '100%';
                        iframe.style.height = '500px';
                        fileContentContainer.appendChild(iframe);

                        // Альтернативне посилання для скачування
                        const downloadLink = document.createElement('a');
                        downloadLink.href = filePath;
                        downloadLink.download = fileName;
                        downloadLink.textContent = 'Завантажити файл';
                        downloadLink.className = 'btn';
                        downloadLink.style.marginTop = '10px';
                        fileContentContainer.appendChild(downloadLink);
                    } else {
                        // Невідомий тип файлу
                        const downloadLink = document.createElement('a');
                        downloadLink.href = filePath;
                        downloadLink.download = fileName;
                        downloadLink.textContent = 'Завантажити файл';
                        downloadLink.className = 'btn';
                        fileContentContainer.appendChild(downloadLink);
                    }

                    modal.style.display = 'block';
                });
            });

            // Закриття модального вікна
            closeButton.addEventListener('click', function() {
                modal.style.display = 'none';
            });

            window.addEventListener('click', function(event) {
                if (event.target === modal) {
                    modal.style.display = 'none';
                }
            });
        }

        // Функція для ініціалізації модальних вікон
        function initModals() {
            // Загальна функція для всіх модальних вікон
            function setupModal(modalId, openButtons, dataAttribute) {
                const modal = document.getElementById(modalId);
                const closeButton = modal.querySelector('.close-modal');

                // Відкриття модального вікна
                openButtons.forEach(button => {
                    button.addEventListener('click', function() {
                        const id = this.getAttribute(dataAttribute);

                        if (modalId === 'edit-order-modal') {
                            document.getElementById('edit-order-id').textContent = '#' + id;
                            document.getElementById('modal-order-id').value = id;

                            // Заповнення форми даними замовлення
                            const orderCard = this.closest('.order-card');
                            const service = orderCard.querySelector('.order-detail:nth-child(1) div:nth-child(2)').textContent.trim();
                            const deviceType = orderCard.querySelector('.order-detail:nth-child(2) div:nth-child(2)').textContent.trim();
                            const details = orderCard.querySelector('.order-detail:nth-child(3) div:nth-child(2)').textContent.trim();
                            const phone = orderCard.querySelector('.order-detail:nth-child(4) div:nth-child(2)').textContent.trim();

                            let address = '';
                            const addressEl = orderCard.querySelector('.order-detail:nth-child(5) div:nth-child(2)');
                            if (addressEl) {
                                address = addressEl.textContent.trim();
                            }

                            let deliveryMethod = '';
                            const deliveryEl = orderCard.querySelector('.order-detail:nth-child(6) div:nth-child(2)');
                            if (deliveryEl) {
                                deliveryMethod = deliveryEl.textContent.trim();
                            }

                            let userComment = '';
                            const userCommentSection = orderCard.querySelector('.user-comment-section .comment');
                            if (userCommentSection) {
                                userComment = userCommentSection.textContent.trim();
                            }

                            document.getElementById('edit-service').value = service;
                            document.getElementById('edit-device_type').value = deviceType;
                            document.getElementById('edit-details').value = details;
                            document.getElementById('edit-phone').value = phone;
                            document.getElementById('edit-address').value = address;
                            document.getElementById('edit-delivery_method').value = deliveryMethod;
                            document.getElementById('edit-user_comment').value = userComment;
                        } else if (modalId === 'add-comment-modal') {
                            document.getElementById('comment-order-id').textContent = '#' + id;
                            document.getElementById('comment-order-id-input').value = id;
                        }

                        modal.style.display = 'block';
                    });
                });

                // Закриття модального вікна
                closeButton.addEventListener('click', function() {
                    modal.style.display = 'none';
                });

                window.addEventListener('click', function(event) {
                    if (event.target === modal) {
                        modal.style.display = 'none';
                    }
                });
            }

            // Ініціалізація модальних вікон
            setupModal('edit-order-modal', document.querySelectorAll('.edit-order'), 'data-id');
            setupModal('add-comment-modal', document.querySelectorAll('.add-comment'), 'data-id');
            setupModal('file-view-modal', document.querySelectorAll('.view-file'), 'data-path');

            // Ініціалізація Drag & Drop для редагування замовлення
            initDragAndDrop('edit-drop-zone', 'edit-drop-zone-input', 'edit-file-preview-container', 'edit-dropped_files_data');
        }

        // AJAX відправка форми додавання коментаря
        function initAjaxCommentForm() {
            const form = document.getElementById('add-comment-form');

            form.addEventListener('submit', function(e) {
                e.preventDefault();

                const formData = new FormData(form);

                fetch('dashboard.php', {
                    method: 'POST',
                    body: formData
                })
                    .then(response => {
                        document.getElementById('add-comment-modal').style.display = 'none';
                        showTempMessage('Коментар успішно додано!');
                        setTimeout(() => {
                            window.location.reload();
                        }, 1500);
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showTempMessage('Помилка при додаванні коментаря', false);
                    });
            });
        }

        // Перемикання вкладок
        function initTabs() {
            // Переключення вкладок навігації
            const menuTabs = document.querySelectorAll('.sidebar-menu a[data-tab]');
            const contentTabs = document.querySelectorAll('.main-content > .tab-content');

            menuTabs.forEach(tab => {
                tab.addEventListener('click', function(e) {
                    e.preventDefault();

                    const targetTabId = this.getAttribute('data-tab');

                    // Активний пункт меню
                    menuTabs.forEach(t => t.classList.remove('active'));
                    this.classList.add('active');

                    // Активна вкладка контенту
                    contentTabs.forEach(content => {
                        content.classList.remove('active');
                        if (content.id === targetTabId + '-content') {
                            content.classList.add('active');
                        }
                    });

                    // Оновлення URL з хешем
                    window.location.hash = targetTabId;
                });
            });

            // Переключення підвкладок в налаштуваннях
            const settingsTabs = document.querySelectorAll('.tab');
            const settingsContents = document.querySelectorAll('#settings-content .tab-content');

            settingsTabs.forEach(tab => {
                tab.addEventListener('click', function() {
                    const targetTabId = this.getAttribute('data-target');

                    // Активна вкладка
                    settingsTabs.forEach(t => t.classList.remove('active'));
                    this.classList.add('active');

                    // Активний контент
                    settingsContents.forEach(content => {
                        content.classList.remove('active');
                        if (content.id === targetTabId + '-content') {
                            content.classList.add('active');
                        }
                    });
                });
            });

            // Перевірка хешу для активації вкладок
            if (window.location.hash) {
                const hash = window.location.hash.substr(1);
                const targetTab = document.querySelector(`.sidebar-menu a[data-tab="${hash}"]`);
                if (targetTab) {
                    targetTab.click();
                }
            }

            // Обробка кнопки "Переглянути всі"
            const viewAllButtons = document.querySelectorAll('.view-all[data-tab]');
            viewAllButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    const targetTabId = this.getAttribute('data-tab');
                    const targetTab = document.querySelector(`.sidebar-menu a[data-tab="${targetTabId}"]`);
                    if (targetTab) {
                        targetTab.click();
                    }
                });
            });
        }

        // Ініціалізація шзгортання сайдбару
        function initSidebarToggle() {
            const toggleButton = document.getElementById('toggle-sidebar');
            const sidebar = document.getElementById('sidebar');

            toggleButton.addEventListener('click', function() {
                if (window.innerWidth <= 768) {
                    sidebar.classList.toggle('expanded');
                } else {
                    sidebar.classList.toggle('collapsed');
                }
            });

            // Автоматичне згортання на мобільних пристроях
            if (window.innerWidth <= 768) {
                sidebar.classList.remove('expanded');
            }

            window.addEventListener('resize', function() {
                if (window.innerWidth <= 768) {
                    sidebar.classList.remove('collapsed');
                    if (!sidebar.classList.contains('expanded')) {
                        sidebar.classList.remove('expanded');
                    }
                } else {
                    sidebar.classList.remove('expanded');
                }
            });
        }

        // Ініціалізація форми профілю для фото
        function initProfilePicForm() {
            const profileDropZone = document.getElementById('profile-drop-zone');
            const profilePicInput = document.getElementById('profile_pic');

            if (!profileDropZone || !profilePicInput) return;

            // Обробка вибору фото через клік
            profileDropZone.addEventListener('click', () => profilePicInput.click());

            // Обробка вибору фото
            profilePicInput.addEventListener('change', function() {
                if (this.files && this.files[0]) {
                    const file = this.files[0];
                    const reader = new FileReader();

                    reader.onload = function(e) {
                        const img = document.querySelector('.profile-preview');
                        if (img) {
                            img.src = e.target.result;
                        }
                    };

                    reader.readAsDataURL(file);

                    // Автоматичне відправлення форми
                    this.closest('form').submit();
                }
            });

            // Обробка перетягування фото
            profileDropZone.addEventListener('dragover', (e) => {
                e.preventDefault();
                profileDropZone.classList.add('active');
            });

            profileDropZone.addEventListener('dragleave', () => {
                profileDropZone.classList.remove('active');
            });

            profileDropZone.addEventListener('drop', (e) => {
                e.preventDefault();
                profileDropZone.classList.remove('active');

                if (e.dataTransfer.files.length > 0) {
                    const file = e.dataTransfer.files[0];

                    // Перевірка типу файлу
                    const validTypes = ['image/jpeg', 'image/png', 'image/gif'];
                    if (!validTypes.includes(file.type)) {
                        alert('Будь ласка, виберіть зображення (JPEG, PNG, GIF)');
                        return;
                    }

                    // Перевірка розміру файлу (максимум 2MB)
                    if (file.size > 2 * 1024 * 1024) {
                        alert('Розмір файлу перевищує 2MB');
                        return;
                    }

                    // Показати превью
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const img = document.querySelector('.profile-preview');
                        if (img) {
                            img.src = e.target.result;
                        }
                    };
                    reader.readAsDataURL(file);

                    // Встановити файл для форми
                    const dataTransfer = new DataTransfer();
                    dataTransfer.items.add(file);
                    profilePicInput.files = dataTransfer.files;

                    // Автоматичне відправлення форми
                    profilePicInput.closest('form').submit();
                }
            });
        }

        // Функція для обробки фільтрації замовлень
        function initFilters() {
            const filterForm = document.getElementById('filter-form');
            const statusSelect = filterForm.querySelector('[name="status"]');
            const serviceSelect = filterForm.querySelector('[name="service"]');
            const searchInput = filterForm.querySelector('[name="search"]');

            // Автоматичне відправлення форми при зміні селектів
            statusSelect.addEventListener('change', () => filterForm.submit());
            serviceSelect.addEventListener('change', () => filterForm.submit());

            // Відправлення форми при натисненні Enter у полі пошуку
            searchInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    filterForm.submit();
                }
            });
        }

        // Ініціалізація всіх компонентів при завантаженні сторінки
        document.addEventListener("DOMContentLoaded", function() {
            // Оновлення часу
            updateLocalTimes();
            setInterval(updateLocalTimes, 60000); // Оновлення кожну хвилину

            // Ініціалізація сповіщень
            initNotifications();

            // Ініціалізація інтерфейсу
            initViewMoreButtons();
            initCollapsibleSections();
            initTabs();
            initSidebarToggle();
            initThemeSelector();
            initDeleteComments();
            initFileViewer();
            initModals();
            initAjaxCommentForm();
            initFilters();

            // Ініціалізація форм
            initProfilePicForm();
            initDragAndDrop('drop-zone', 'drop-zone-input', 'file-preview-container', 'dropped_files_data');
        });
    </script>
</body>
</html>