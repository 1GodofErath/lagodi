<?php
require 'db.php';
require 'send_email.php'; // Підключення функції відправки листів

error_reporting(E_ALL);
ini_set('display_errors', 1);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $email = $_POST['email'];

    if (empty($username) || empty($_POST['password']) || empty($email)) {
        $error = "Будь ласка, заповніть всі поля.";
    } else {
        // Перевірка наявності email
        $query = "SELECT * FROM users WHERE email = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $error = "Користувач з такою електронною поштою вже існує.";
        } else {
            // Генерація токену
            $verification_token = bin2hex(random_bytes(32));

            // Додавання користувача з токеном
            $query = "INSERT INTO users (username, password, email, verification_token) VALUES (?, ?, ?, ?)";
            $stmt = $conn->prepare($query);
            if ($stmt === false) {
                $error = "Помилка підготовки запиту: " . $conn->error;
            } else {
                $stmt->bind_param("ssss", $username, $password, $email, $verification_token);
                if ($stmt->execute()) {
                    // Відправка листа з підтвердженням
                    $subject = "Підтвердження електронної пошти";
                    $verification_link = "https://lagodiy.com/verify_email.php?token=$verification_token";
                    $body = "Будь ласка, підтвердьте вашу електронну пошту: $verification_link";

                    $emailResult = sendEmail($email, $subject, $body);

                    if ($emailResult['success']) {
                        header("Location: check_email.php"); // Сторінка з інструкціями
                        exit();
                    } else {
                        $error = "Помилка відправки листа: " . $emailResult['message'];
                    }
                } else {
                    $error = "Помилка реєстрації: " . $stmt->error;
                }
                $stmt->close();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <title>Реєстрація</title>
    <link rel="stylesheet" href="login1.css"> <!-- Використовуємо той самий CSS -->
</head>
<body>
<div class="wrapper">
    <h1 class="title">Реєстрація</h1>
    <form method="post" action="register.php" class="form" id="registerForm">
        <div class="input-box">
            <label for="username">Ім'я користувача</label>
            <input type="text" id="username" name="username" required>
        </div>
        <div class="input-box">
            <label for="password">Пароль</label>
            <input type="password" id="password" name="password" required>
        </div>
        <div class="input-box">
            <label for="email">Електронна пошта</label>
            <input type="email" id="email" name="email" required>
        </div>

        <button type="submit" class="btn">Зареєструватися</button>
    </form>
    <div class="social-login">
        <a href="google_login.php" class="btn-google">
            <img src="image/google-logo.png" alt="Google Logo" class="google-logo">
            Зареєструватися через Google
        </a>
    </div>
    <?php if (isset($error)) echo "<p class='error'>$error</p>"; ?>
    <div class="register-link">
        <p>Вже маєте обліковий запис? <a href="login.php">Увійти</a></p>
    </div>
</div>
</body>
</html>