<?php
session_start();
require_once '../db.php';
require_once '../vendor/autoload.php'; // For PHPMailer

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Перевірка авторизації
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

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
$stmt = $conn->prepare("SELECT email, first_name, last_name, middle_name, profile_pic FROM users WHERE id = ?");
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

// Функція для відправки email
function sendNotificationEmail($to, $subject, $message) {
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

                header("Location:  /../admin_panel/dashboard.php");
                exit();
            } else {
                $_SESSION['error'] = "Помилка завантаження файлу";
            }
        } else {
            $_SESSION['error'] = "Недозволений тип файлу. Дозволено тільки: " . implode(', ', $allowed);
        }

        header("Location:  /../admin_panel/dashboard.php");
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

    $stmt = $conn->prepare("UPDATE users SET first_name = ?, last_name = ?, middle_name = ? WHERE id = ?");
    $stmt->bind_param("sssi", $first_name, $last_name, $middle_name, $user_id);

    if ($stmt->execute()) {
        $_SESSION['success'] = "Профіль успішно оновлено!";
        $user_data['first_name'] = $first_name;
        $user_data['last_name'] = $last_name;
        $user_data['middle_name'] = $middle_name;
        logUserAction($conn, $user_id, 'update_profile', 'Оновлено персональні дані');
    } else {
        $_SESSION['error'] = "Помилка оновлення профілю: " . $conn->error;
    }

    header("Location:  /../admin_panel/dashboard.php");
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
        header("Location:  /../admin_panel/dashboard.php");
        exit();
    }

    // Перевірка валідності email
    if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = "Невалідний формат email!";
        header("Location:  /../admin_panel/dashboard.php");
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
        header("Location:  /../admin_panel/dashboard.php");
        exit();
    }

    // Перевірка унікальності email
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $stmt->bind_param("si", $new_email, $user_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $_SESSION['error'] = "Цей email вже використовується!";
        header("Location:  /../admin_panel/dashboard.php");
        exit();
    }

    // Оновлення email
    $stmt = $conn->prepare("UPDATE users SET email = ? WHERE id = ?");
    $stmt->bind_param("si", $new_email, $user_id);

    if ($stmt->execute()) {
        $user_data['email'] = $new_email;
        $_SESSION['success'] = "Email успішно оновлено!";
        logUserAction($conn, $user_id, 'update_email', 'Змінено email з ' . $user_data['email'] . ' на ' . $new_email);
    } else {
        $_SESSION['error'] = "Помилка оновлення email: " . $conn->error;
    }

    header("Location:  /../admin_panel/dashboard.php");
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
        header("Location:  /../admin_panel/dashboard.php");
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
        header("Location:  /../admin_panel/dashboard.php");
        exit();
    }

    // Перевірка унікальності логіна
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
    $stmt->bind_param("si", $new_username, $user_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $_SESSION['error'] = "Цей логін вже використовується!";
        header("Location:  /../admin_panel/dashboard.php");
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

    header("Location:  /../admin_panel/dashboard.php");
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
        header("Location:  /../admin_panel/dashboard.php");
        exit();
    }

    if ($new_password !== $confirm_password) {
        $_SESSION['error'] = "Паролі не співпадають!";
        header("Location:  /../admin_panel/dashboard.php");
        exit();
    }

    if (strlen($new_password) < 8) {
        $_SESSION['error'] = "Пароль повинен містити не менше 8 символів!";
        header("Location:  /../admin_panel/dashboard.php");
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
        header("Location: /../admin_panel/dashboard.php");
        exit();
    }

    // Хешування та оновлення пароля
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE users SET password = ?, password_changed_at = NOW() WHERE id = ?");
    $stmt->bind_param("si", $hashed_password, $user_id);

    if ($stmt->execute()) {
        $_SESSION['success'] = "Пароль успішно оновлено!";
        logUserAction($conn, $user_id, 'update_password', 'Змінено пароль');
    } else {
        $_SESSION['error'] = "Помилка оновлення пароля: " . $conn->error;
    }

    header("Location: /../admin_panel/dashboard.php");
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

    if (empty($service) || empty($details) || empty($device_type) || empty($phone)) {
        $_SESSION['error'] = "Заповніть всі обов'язкові поля!";
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

    if (empty($service) || empty($details) || empty($device_type) || empty($phone)) {
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
            <a href="/../logout.php">
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
            <div class="current-time" id="current-time"></div>
            <button id="theme-toggle" class="theme-toggle">
                <i class="fas fa-moon"></i>
            </button>
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
            ?>
            <div class="order-card">
                <div class="order-header">
                    <h3 class="order-id">Замовлення #<?= $order['id'] ?></h3>
                    <div class="order-meta">
                            <span class="status-badge status-<?= strtolower(str_replace(' ', '-', $order['status'])) ?>">
                                <?= htmlspecialchars($order['status']) ?>
                            </span>
                        <span class="order-date">
                                <i class="far fa-calendar-alt"></i>
                                <span class="local-time" data-utc="<?= $order['created_at'] ?>">
                                    <?= date('d.m.Y H:i', strtotime($order['created_at'])) ?>                                </span>
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
                                        <button class="btn btn-sm view-file" data-path="../<?= htmlspecialchars($file['file_path']) ?>" data-filename="<?= htmlspecialchars($file['file_name']) ?>">
                                            <i class="fas fa-eye"></i>
                                        </button>
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
                            </div>
                        </div>
                    <?php endif; ?>

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
                    <?php foreach ($orders as $order): ?>
                        <div class="order-card">
                            <div class="order-header">
                                <h3 class="order-id">Замовлення #<?= $order['id'] ?></h3>
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
                                                    <button class="btn btn-sm view-file" data-path="../<?= htmlspecialchars($file['file_path']) ?>" data-filename="<?= htmlspecialchars($file['file_name']) ?>">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
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
                                        </div>
                                    </div>
                                <?php endif; ?>

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

                    <div class="form-group">
                        <label for="service">Послуга*:</label>
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
                        <label for="device_type">Тип пристрою*:</label>
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
                        <label for="details">Деталі замовлення*:</label>
                        <textarea name="details" id="details" class="form-control" rows="5" required></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="first_name">Ім'я*:</label>
                            <input type="text" name="first_name" id="first_name" class="form-control" required value="<?= htmlspecialchars($user_data['first_name'] ?? '') ?>">
                        </div>

                        <div class="form-group">
                            <label for="last_name">Прізвище*:</label>
                            <input type="text" name="last_name" id="last_name" class="form-control" required value="<?= htmlspecialchars($user_data['last_name'] ?? '') ?>">
                        </div>

                        <div class="form-group">
                            <label for="middle_name">По батькові:</label>
                            <input type="text" name="middle_name" id="middle_name" class="form-control" value="<?= htmlspecialchars($user_data['middle_name'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="phone">Контактний телефон*:</label>
                        <input type="tel" name="phone" id="phone" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label for="address">Адреса:</label>
                        <textarea name="address" id="address" class="form-control" rows="2"></textarea>
                    </div>

                    <div class="form-group">
                        <label for="delivery_method">Спосіб доставки:</label>
                        <select name="delivery_method" id="delivery_method" class="form-control">
                            <option value="">Виберіть спосіб доставки</option>
                            <option value="Самовивіз">Самовивіз</option>
                            <option value="Кур'єр">Кур'єр</option>
                            <option value="Нова пошта">Нова пошта</option>
                            <option value="Укрпошта">Укрпошта</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="order_files">Прикріпити файли (фото, відео, текстові файли):</label>
                        <input type="file" name="order_files[]" id="order_files" class="form-control" multiple>
                        <small>Максимальний розмір файлу: 10 МБ. Дозволені формати: jpg, jpeg, png, gif, mp4, avi, mov, pdf, doc, docx, txt</small>
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
                <div class="tab" data-target="change-password">Зміна пароля</div>
                <div class="tab" data-target="change-email">Зміна email</div>
                <div class="tab" data-target="change-username">Зміна логіну</div>
            </div>

            <div class="tab-content active" id="profile-info-content">
                <form method="post" enctype="multipart/form-data" class="profile-form">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="update_profile" value="1">

                    <div class="form-group">
                        <label>Фото профілю:</label>
                        <div class="profile-pic-container">
                            <?php if (!empty($user_data['profile_pic']) && file_exists('../' . $user_data['profile_pic'])): ?>
                                <img src="../<?= htmlspecialchars($user_data['profile_pic']) ?>" alt="Фото профілю" class="profile-preview" style="max-width: 150px; border-radius: 5px;">
                            <?php else: ?>
                                <img src="../assets/images/default_avatar.png" alt="Фото профілю за замовчуванням" class="profile-preview" style="max-width: 150px; border-radius: 5px;">
                            <?php endif; ?>
                        </div>

                        <form method="post" enctype="multipart/form-data" class="profile-pic-form">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="update_profile_pic" value="1">
                            <div class="form-group">
                                <input type="file" name="profile_pic" id="profile_pic" class="form-control" accept="image/*">
                                <small>Максимальний розмір: 2 МБ. Формати: jpg, jpeg, png, gif</small>
                            </div>
                            <button type="submit" class="btn">Оновити фото</button>
                        </form>
                    </div>

                    <div class="form-group">
                        <label for="first_name">Ім'я:</label>
                        <input type="text" name="first_name" id="first_name" class="form-control" value="<?= htmlspecialchars($user_data['first_name'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label for="last_name">Прізвище:</label>
                        <input type="text" name="last_name" id="last_name" class="form-control" value="<?= htmlspecialchars($user_data['last_name'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label for="middle_name">По батькові:</label>
                        <input type="text" name="middle_name" id="middle_name" class="form-control" value="<?= htmlspecialchars($user_data['middle_name'] ?? '') ?>">
                    </div>

                    <button type="submit" class="btn">Зберегти зміни</button>
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
                        <small>Мінімум 8 символів</small>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Підтвердження нового пароля:</label>
                        <input type="password" name="confirm_password" id="confirm_password" class="form-control" required>
                    </div>

                    <button type="submit" class="btn">Змінити пароль</button>
                </form>
            </div>

            <div class="tab-content" id="change-email-content">
                <form method="post" class="email-form">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="update_email" value="1">

                    <div class="form-group">
                        <label for="current_email">Поточний email:</label>
                        <input type="email" id="current_email" class="form-control" value="<?= htmlspecialchars($user_data['email']) ?>" readonly>
                    </div>

                    <div class="form-group">
                        <label for="new_email">Новий email:</label>
                        <input type="email" name="new_email" id="new_email" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label for="password">Введіть пароль для підтвердження:</label>
                        <input type="password" name="password" id="password" class="form-control" required>
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
                        <label for="password">Введіть пароль для підтвердження:</label>
                        <input type="password" name="password" id="password" class="form-control" required>
                    </div>

                    <button type="submit" class="btn">Змінити логін</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Модальне вікно для перегляду файлів -->
<div class="modal" id="file-modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title" id="file-modal-title">Перегляд файлу</h3>
            <button class="close-modal">&times;</button>
        </div>
        <div class="modal-body" id="file-modal-body">
            <!-- Вміст буде додано через JavaScript -->
        </div>
    </div>
</div>

<!-- Модальне вікно для редагування замовлення -->
<div class="modal" id="edit-order-modal">
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

                <div class="form-group">
                    <label for="edit-service">Послуга*:</label>
                    <select name="service" id="edit-service" class="form-control" required>
                        <option value="Ремонт комп'ютера">Ремонт комп'ютера</option>
                        <option value="Ремонт ноутбука">Ремонт ноутбука</option>
                        <option value="Ремонт телефона">Ремонт телефона</option>
                        <option value="Ремонт МФУ">Ремонт МФУ</option>
                        <option value="Заправка картриджів">Заправка картриджів</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="edit-device-type">Тип пристрою*:</label>
                    <select name="device_type" id="edit-device-type" class="form-control" required>
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
                    <label for="edit-details">Деталі замовлення*:</label>
                    <textarea name="details" id="edit-details" class="form-control" rows="5" required></textarea>
                </div>

                <div class="form-group">
                    <label for="edit-phone">Контактний телефон*:</label>
                    <input type="tel" name="phone" id="edit-phone" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="edit-address">Адреса:</label>
                    <textarea name="address" id="edit-address" class="form-control" rows="2"></textarea>
                </div>

                <div class="form-group">
                    <label for="edit-delivery-method">Спосіб доставки:</label>
                    <select name="delivery_method" id="edit-delivery-method" class="form-control">
                        <option value="">Виберіть спосіб доставки</option>
                        <option value="Самовивіз">Самовивіз</option>
                        <option value="Кур'єр">Кур'єр</option>
                        <option value="Нова пошта">Нова пошта</option>
                        <option value="Укрпошта">Укрпошта</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="edit-user-comment">Ваш коментар:</label>
                    <textarea name="user_comment" id="edit-user-comment" class="form-control" rows="3"></textarea>
                </div>

                <div class="form-group">
                    <label for="edit-order-files">Додати файли:</label>
                    <input type="file" name="order_files[]" id="edit-order-files" class="form-control" multiple>
                    <small>Максимальний розмір: 10 МБ. Дозволені формати: jpg, jpeg, png, gif, mp4, avi, mov, pdf, doc, docx, txt</small>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn">
                        <i class="fas fa-save"></i> Зберегти зміни
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Модальне вікно для додавання коментаря -->
<div class="modal" id="comment-modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Додати коментар</h3>
            <button class="close-modal">&times;</button>
        </div>
        <div class="modal-body">
            <form method="post" id="comment-form">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="add_comment" value="1">
                <input type="hidden" name="order_id" id="comment-order-id">

                <div class="form-group">
                    <label for="comment">Ваш коментар:</label>
                    <textarea name="comment" id="comment" class="form-control" rows="5" required></textarea>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn">
                        <i class="fas fa-paper-plane"></i> Надіслати коментар
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Поточний час
        function updateLocalTime() {
            const now = new Date();
            const formattedDate = now.toLocaleDateString('uk-UA', {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric'
            });
            const formattedTime = now.toLocaleTimeString('uk-UA', {
                hour: '2-digit',
                minute: '2-digit'
            });
            document.getElementById('current-time').textContent = `${formattedDate} ${formattedTime}`;

            // Оновлення часу для всіх елементів з класом local-time
            document.querySelectorAll('.local-time').forEach(element => {
                const utcDate = element.getAttribute('data-utc');
                if (utcDate) {
                    const localDate = new Date(utcDate);
                    element.textContent = localDate.toLocaleDateString('uk-UA', {
                        day: '2-digit',
                        month: '2-digit',
                        year: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                    });
                }
            });
        }

        updateLocalTime();
        setInterval(updateLocalTime, 60000); // Оновлювати кожну хвилину

        // Тема сайту
        const themeToggle = document.getElementById('theme-toggle');
        const currentTheme = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', currentTheme);

        if (currentTheme === 'dark') {
            themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
        }

        themeToggle.addEventListener('click', function() {
            const currentTheme = document.documentElement.getAttribute('data-theme');
            const newTheme = currentTheme === 'light' ? 'dark' : 'light';
            document.documentElement.setAttribute('data-theme', newTheme);
            localStorage.setItem('theme', newTheme);

            if (newTheme === 'dark') {
                this.innerHTML = '<i class="fas fa-sun"></i>';
            } else {
                this.innerHTML = '<i class="fas fa-moon"></i>';
            }
        });

        // Переключення бокової панелі
        const toggleSidebar = document.getElementById('toggle-sidebar');
        const sidebar = document.getElementById('sidebar');

        toggleSidebar.addEventListener('click', function() {
            sidebar.classList.toggle('collapsed');
            localStorage.setItem('sidebar_collapsed', sidebar.classList.contains('collapsed'));
        });

        // Перевірити стан бокової панелі з localStorage
        if (localStorage.getItem('sidebar_collapsed') === 'true') {
            sidebar.classList.add('collapsed');
        }

        // На мобільних відображати згорнуту бокову панель
        if (window.innerWidth <= 768) {
            sidebar.classList.add('collapsed');
        }

        // Переключення між вкладками
        document.querySelectorAll('.sidebar-menu a').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const tabId = this.getAttribute('data-tab');

                // Видалити активний клас з усіх вкладок
                document.querySelectorAll('.sidebar-menu a').forEach(tab => {
                    tab.classList.remove('active');
                });

                // Приховати всі контейнери контенту вкладок
                document.querySelectorAll('.tab-content').forEach(content => {
                    content.classList.remove('active');
                });

                // Активувати вибрану вкладку та її контент
                this.classList.add('active');
                document.getElementById(tabId + '-content').classList.add('active');

                // На мобільних пристроях автоматично згортати бічну панель після вибору вкладки
                if (window.innerWidth <= 768) {
                    sidebar.classList.add('collapsed');
                }

                // Зберегти активну вкладку в localStorage
                localStorage.setItem('active_tab', tabId);
            });
        });

        // Помічання активного пункту меню при натисканні на кнопки перегляду всіх замовлень
        document.querySelectorAll('.view-all').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const tabId = this.getAttribute('data-tab');

                // Імітуємо натискання на відповідний пункт меню
                document.querySelector(`.sidebar-menu a[data-tab="${tabId}"]`).click();
            });
        });

        // Відновлення активної вкладки з localStorage
        const activeTab = localStorage.getItem('active_tab');
        if (activeTab) {
            const tabLink = document.querySelector(`.sidebar-menu a[data-tab="${activeTab}"]`);
            if (tabLink) {
                tabLink.click();
            }
        }

        // Переключення між вкладками в налаштуваннях
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', function() {
                const targetId = this.getAttribute('data-target');

                // Видалити активний клас з усіх вкладок
                document.querySelectorAll('.tab').forEach(tab => {
                    tab.classList.remove('active');
                });

                // Сховати всі контейнери контенту вкладок
                document.querySelectorAll('.settings-content .tab-content').forEach(content => {
                    content.classList.remove('active');
                });

                // Активувати вибрану вкладку та її контент
                this.classList.add('active');
                document.getElementById(targetId + '-content').classList.add('active');
            });
        });

        // Робота з модальними вікнами
        const modals = document.querySelectorAll('.modal');
        const closeButtons = document.querySelectorAll('.close-modal');

        // Закриття модальних вікон
        closeButtons.forEach(button => {
            button.addEventListener('click', function() {
                const modal = this.closest('.modal');
                modal.style.display = 'none';
            });
        });

        window.addEventListener('click', function(event) {
            modals.forEach(modal => {
                if (event.target === modal) {
                    modal.style.display = 'none';
                }
            });
        });

        // Перегляд файлів
        const fileButtons = document.querySelectorAll('.view-file');
        const fileModal = document.getElementById('file-modal');
        const fileModalTitle = document.getElementById('file-modal-title');
        const fileModalBody = document.getElementById('file-modal-body');

        fileButtons.forEach(button => {
            button.addEventListener('click', function() {
                const filePath = this.getAttribute('data-path');
                const fileName = this.getAttribute('data-filename');
                const fileExt = fileName.split('.').pop().toLowerCase();

                fileModalTitle.textContent = fileName;
                fileModalBody.innerHTML = '';

                if (['jpg', 'jpeg', 'png', 'gif'].includes(fileExt)) {
                    // Зображення
                    const img = document.createElement('img');
                    img.src = filePath;
                    img.alt = fileName;
                    img.className = 'media-viewer';
                    fileModalBody.appendChild(img);
                } else if (['mp4', 'mov', 'avi'].includes(fileExt)) {
                    // Відео
                    const video = document.createElement('video');
                    video.src = filePath;
                    video.controls = true;
                    video.className = 'media-viewer';
                    fileModalBody.appendChild(video);
                } else if (fileExt === 'txt') {
                    // Текстовий файл
                    fetch(filePath)
                        .then(response => response.text())
                        .then(text => {
                            const pre = document.createElement('pre');
                            pre.className = 'file-viewer';
                            pre.textContent = text;
                            fileModalBody.appendChild(pre);
                        })
                        .catch(error => {
                            fileModalBody.innerHTML = `<p class="error">Помилка завантаження файлу: ${error.message}</p>`;
                        });
                } else {
                    // Інші типи файлів
                    fileModalBody.innerHTML = `
                        <p>Неможливо переглянути файл в браузері. <a href="${filePath}" download="${fileName}">Завантажити файл</a></p>
                    `;
                }

                fileModal.style.display = 'block';
            });
        });

        // Редагування замовлення
        const editButtons = document.querySelectorAll('.edit-order');
        const editOrderModal = document.getElementById('edit-order-modal');
        const editOrderForm = document.getElementById('edit-order-form');
        const editOrderId = document.getElementById('edit-order-id');

        editButtons.forEach(button => {
            button.addEventListener('click', function() {
                const orderId = this.getAttribute('data-id');
                const orderCard = this.closest('.order-card');

                // Заповнюємо форму даними замовлення
                editOrderId.value = orderId;

                const service = orderCard.querySelector('.order-body div:nth-child(1) div:last-child').textContent;
                const deviceType = orderCard.querySelector('.order-body div:nth-child(2) div:last-child').textContent;
                const details = orderCard.querySelector('.order-body div:nth-child(3) div:last-child').textContent;
                const phone = orderCard.querySelector('.order-body div:nth-child(4) div:last-child')?.textContent || '';
                const address = orderCard.querySelector('.order-body div:nth-child(5) div:last-child')?.textContent || '';
                const deliveryMethod = orderCard.querySelector('.order-body div:nth-child(6) div:last-child')?.textContent || '';
                const userComment = orderCard.querySelector('.user-comment-section .comment')?.textContent || '';

                document.querySelector('#edit-service').value = service.trim();
                document.querySelector('#edit-device-type').value = deviceType.trim();
                document.querySelector('#edit-details').value = details.trim();
                document.querySelector('#edit-phone').value = phone.trim();
                document.querySelector('#edit-address').value = address.trim();
                document.querySelector('#edit-delivery-method').value = deliveryMethod.trim();
                document.querySelector('#edit-user-comment').value = userComment.trim();

                editOrderModal.style.display = 'block';
            });
        });

        // Додавання коментаря
        const commentButtons = document.querySelectorAll('.add-comment');
        const commentModal = document.getElementById('comment-modal');
        const commentForm = document.getElementById('comment-form');
        const commentOrderId = document.getElementById('comment-order-id');

        commentButtons.forEach(button => {
            button.addEventListener('click', function() {
                const orderId = this.getAttribute('data-id');
                commentOrderId.value = orderId;
                commentModal.style.display = 'block';
            });
        });

        // Обробка форми коментаря з автоматичним зникненням повідомлення
        commentForm.addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(this);

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
                .then(response => {
                    commentModal.style.display = 'none';

                    // Створюємо тимчасове повідомлення
                    const tempMessage = document.createElement('div');
                    tempMessage.className = 'temp-message';
                    tempMessage.textContent = 'Коментар успішно додано!';
                    document.body.appendChild(tempMessage);

                    // Коментар автоматично зникає через 2 секунди
                    setTimeout(() => {
                        // Перезавантажуємо сторінку після зникнення повідомлення для відображення оновлених даних
                        location.reload();
                    }, 2000);
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Помилка при відправці коментаря. Спробуйте ще раз.');
                });
        });

        // Фільтрація замовлень
        const filterForm = document.getElementById('filter-form');
        if (filterForm) {
            filterForm.addEventListener('submit', function(e) {
                // Форма відправляється стандартно через GET запит
            });
        }

        // Попередній перегляд зображення профілю
        const profilePic = document.getElementById('profile_pic');
        if (profilePic) {
            profilePic.addEventListener('change', function() {
                const file = this.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const preview = document.querySelector('.profile-preview');
                        if (preview) {
                            preview.src = e.target.result;
                        }
                    }
                    reader.readAsDataURL(file);
                }
            });
        }
    });
</script>
</body>
</html>