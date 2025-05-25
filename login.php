<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require 'db.php';


$error = '';


if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $identifier = $_POST['username'];
    $password = $_POST['password'];

    $query = "SELECT * FROM users WHERE username=? OR email=?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $identifier, $identifier);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if ($user) {
        // Перевірка підтвердження email (якщо використовується)
        if (isset($user['email_verified']) && $user['email_verified'] != 1) {
            $error = "Будь ласка, підтвердьте вашу електронну пошту перед входом.";
        }
        // Перевірка пароля
        elseif (password_verify($password, $user['password'])) {
            // Встановлення сесії
            $_SESSION['username'] = $user['username'];
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role']; // Додаємо роль у сесію

            // Перенаправлення за роллю
            if ($user['role'] === 'admin'|| $user['role'] == 'junior_admin') {
                header("Location: /../admin_panel/admin_dashboard.php" );
            } else {
                header("Location: /../dah/dashboard.php");
            }
            exit();
        } else {
            $error = "Невірний пароль";
        }
    } else {
        $error = "Користувача з таким логіном/email не знайдено";
    }
}
?>

<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Логін</title>
    <link rel="stylesheet" href="login1.css">
</head>
<body>
<div class="wrapper">
    <h1 class="title">Авторизація</h1>
    <form method="post" action="login.php" class="form" id="loginForm">
        <div class="input-box">
            <label for="username">Логін або Email</label>
            <input type="text" id="username" name="username" required autocomplete="username">
        </div>
        <div class="input-box">
            <label for="password">Пароль</label>
            <input type="password" id="password" name="password" required autocomplete="current-password">
        </div>
        <div class="remember-forgot">
            <label>
                <input type="checkbox" id="rememberMe" name="rememberMe">
                <span>Запам'ятати мене</span>
            </label>
            <a href="request_reset.php" class="sell">Забули пароль?</a>
        </div>
        <button type="submit" class="btn">Увійти</button>
    </form>

    <div class="social-login">
        <a href="google_login.php" class="btn-google">
            <img src="image/google-logo.png" alt="Google Logo" class="google-logo">
            <span>Увійти через Google</span>
        </a>
    </div>

    <?php if (!empty($error)): ?>
        <p class="error"><?php echo htmlspecialchars($error); ?></p>
    <?php endif; ?>

    <div class="register-link">
        <p>Немає облікового запису? <a href="register.php">Зареєструватися</a></p>
    </div>
</div>

<script>
    document.addEventListener("DOMContentLoaded", function() {
        const rememberedUsername = localStorage.getItem('username');
        const rememberedPassword = localStorage.getItem('password');
        const loginForm = document.getElementById('loginForm');
        const rememberMeCheckbox = document.getElementById('rememberMe');

        if (rememberedUsername && rememberedPassword) {
            document.getElementById('username').value = rememberedUsername;
            document.getElementById('password').value = rememberedPassword;
            rememberMeCheckbox.checked = true;
        }

        loginForm.addEventListener('submit', function() {
            if (rememberMeCheckbox.checked) {
                localStorage.setItem('username', document.getElementById('username').value);
                localStorage.setItem('password', document.getElementById('password').value);
            } else {
                localStorage.removeItem('username');
                localStorage.removeItem('password');
            }
        });
    });
</script>
</body>
</html>