<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Перевіряємо чи користувач авторизований
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

require_once '/home/l131113/public_html/lagodiy.com/db.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    $user_id = $_SESSION['user_id'];

    // Валідація
    if (strlen($new_password) < 8) {
        $_SESSION['error'] = "Новий пароль повинен містити мінімум 8 символів";
        header("Location: change_password.php");
        exit();
    }

    if ($new_password !== $confirm_password) {
        $_SESSION['error'] = "Паролі не співпадають";
        header("Location: change_password.php");
        exit();
    }

    try {
        // Отримуємо поточний пароль користувача
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();

        // Перевіряємо поточний пароль
        if (!password_verify($current_password, $user['password'])) {
            $_SESSION['error'] = "Неправильний поточний пароль";
            header("Location: change_password.php");
            exit();
        }

        // Хешуємо новий пароль
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

        // Оновлюємо пароль
        $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $update_stmt->bind_param("si", $hashed_password, $user_id);

        if ($update_stmt->execute()) {
            // Записуємо час зміни паролю
            $time_updated = date('Y-m-d H:i:s');
            $log_stmt = $conn->prepare("UPDATE users SET password_changed_at = ? WHERE id = ?");
            $log_stmt->bind_param("si", $time_updated, $user_id);
            $log_stmt->execute();

            $_SESSION['success'] = "Пароль успішно змінено";
        } else {
            $_SESSION['error'] = "Помилка при зміні паролю";
        }

    } catch (Exception $e) {
        error_log("Password change error: " . $e->getMessage());
        $_SESSION['error'] = "Виникла помилка. Спробуйте пізніше";
    }

    header("Location: change_password.php");
    exit();
}
?>