<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once '/home/l131113/public_html/lagodiy.com/vendor/autoload.php';
include 'db.php';

// Функція для генерації випадкового токена
function generateToken($length = 32) {
    return bin2hex(random_bytes($length));
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = "Невірний формат email";
        header("Location: reset_password.php");
        exit();
    }

    try {
        // Перевіряємо чи існує користувач
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            // Генеруємо токен
            $token = generateToken();
            $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));

            // Зберігаємо токен в базі даних
            $stmt = $pdo->prepare("UPDATE users SET reset_token = ?, reset_token_expiry = ? WHERE email = ?");
            $stmt->execute([$token, $expiry, $email]);

            // Формуємо посилання для скидання
            $resetLink = "https://lagodiy.com/process_reset.php?token=" . $token;

            // Відправляємо email
            $to = $email;
            $subject = "Скидання паролю";
            $message = "Для скидання паролю перейдіть за посиланням: \n" . $resetLink . "\n\nПосилання дійсне протягом 1 години.";
            $headers = "From: noreply@lagodiy.com";

            mail($to, $subject, $message, $headers);

            $_SESSION['success'] = "Інструкції щодо скидання паролю відправлені на вашу електронну пошту";
        } else {
            // Для безпеки не повідомляємо, що email не знайдено
            $_SESSION['success'] = "Якщо email існує в системі, ви отримаєте інструкції щодо скидання паролю";
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Виникла помилка. Спробуйте пізніше";
    }

    header("Location: reset_password.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Скидання паролю</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="text-center">Скидання паролю</h3>
                </div>
                <div class="card-body">
                    <?php if(isset($_SESSION['error'])): ?>
                        <div class="alert alert-danger">
                            <?php
                            echo $_SESSION['error'];
                            unset($_SESSION['error']);
                            ?>
                        </div>
                    <?php endif; ?>

                    <?php if(isset($_SESSION['success'])): ?>
                        <div class="alert alert-success">
                            <?php
                            echo $_SESSION['success'];
                            unset($_SESSION['success']);
                            ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="">
                        <div class="mb-3">
                            <label for="email" class="form-label">Email адреса</label>
                            <input type="email" class="form-control" id="email" name="email" required>
                        </div>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary">Скинути пароль</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>