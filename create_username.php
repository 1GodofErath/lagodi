<?php
session_start();
require 'db.php';
require 'mail-config.php';

function sendAdminNotification($email, $username) {
    try {
        $mail = createMailer();
        $mail->addAddress('admin@lagodiy.com');
        $mail->Subject = 'Нова реєстрація';
        $mail->Body = "
            <h2>Новий користувач</h2>
            <p>Email: $email</p>
            <p>Логін: $username</p>
            <p>Дата: " . date('Y-m-d H:i:s') . "</p>
        ";
        $mail->send();
    } catch (Exception $e) {
        error_log("Помилка відправки адміну: {$mail->ErrorInfo}");
    }
}

function sendWelcomeEmail($email, $username) {
    try {
        $mail = createMailer();
        $mail->addAddress($email);
        $mail->Subject = 'Вітаємо у Lagodiy!';
        $mail->Body = "
            <h1>Вітаємо, $username!</h1>
            <p>Ваш обліковий запис успішно створено.</p>
            <p>Тепер ви можете використовувати всі можливості нашого сервісу.</p>
        ";
        $mail->send();
    } catch (Exception $e) {
        error_log("Помилка відправки вітання: {$mail->ErrorInfo}");
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $email = $_SESSION['temp_email'];
    $google_id = $_SESSION['temp_google_id'];
    $profile_pic = $_SESSION['temp_picture'];

    // Валідація логіна
    if (empty($username) || !preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
        $error = "Некоректний логін (допустимі символи: літери, цифри, підкреслення)";
    } else {
        $check = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $check->bind_param("s", $username);
        $check->execute();
        $result = $check->get_result();

        if ($result->num_rows > 0) {
            $error = "Цей логін вже зайнятий";
        } else {
            $stmt = $conn->prepare("INSERT INTO users (username, email, google_id, profile_pic) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssss", $username, $email, $google_id, $profile_pic);

            if ($stmt->execute()) {
                // Відправка листів
                sendAdminNotification($email, $username);
                sendWelcomeEmail($email, $username);

                // Оновлення сесії
                $_SESSION['user_id'] = $conn->insert_id;
                $_SESSION['username'] = $username;
                $_SESSION['email'] = $email;
                $_SESSION['logged_in'] = true;

                // Очистка тимчасових даних
                unset(
                    $_SESSION['temp_email'],
                    $_SESSION['temp_google_id'],
                    $_SESSION['temp_name'],
                    $_SESSION['temp_picture']
                );

                header('Location: /../dah/dashboard.php');
                exit();
            } else {
                $error = "Помилка створення облікового запису";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <title>Завершення реєстрації</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<div class="container">
    <?php if (isset($error)): ?>
        <div class="alert alert-error"><?= $error ?></div>
    <?php endif; ?>

    <form method="post">
        <h2>Завершення реєстрації</h2>
        <label>Оберіть логін:</label>
        <input type="text" name="username" required
               pattern="[a-zA-Z0-9_]{3,20}"
               title="3-20 символів (літери, цифри, підкреслення)">
        <button type="submit">Зареєструватись</button>
    </form>
</div>
</body>
</html>