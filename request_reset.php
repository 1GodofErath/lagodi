<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '/home/l131113/public_html/lagodiy.com/db.php';
require_once '/home/l131113/public_html/lagodiy.com/send_email.php';

if (!isset($conn) || $conn->connect_error) {
    die("Помилка з'єднання з базою даних");
}

function generateToken($length = 32) {
    return bin2hex(random_bytes($length));
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = "Невірний формат email";
        header("Location: request_reset.php");
        exit();
    }

    try {
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();

        if ($user) {
            $token = generateToken();
            $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));

            $update_stmt = $conn->prepare("UPDATE users SET reset_token = ?, reset_token_expiry = ? WHERE email = ?");
            $update_stmt->bind_param("sss", $token, $expiry, $email);
            $update_stmt->execute();

            $resetLink = "https://lagodiy.com/process_reset.php?token=" . $token;

            $emailResult = sendEmail(
                $email,
                "Скидання паролю",
                "Для скидання паролю перейдіть за посиланням:\n$resetLink\n\nПосилання дійсне 1 годину."
            );

            if ($emailResult['success']) {
                $_SESSION['success'] = "Інструкції відправлено на вашу пошту";
            } else {
                $_SESSION['error'] = "Помилка відправки. Спробуйте пізніше.";
            }
        } else {
            $_SESSION['success'] = "Якщо email існує, ви отримаєте інструкції";
        }
    } catch (Exception $e) {
        error_log("Reset error: " . $e->getMessage());
        $_SESSION['error'] = "Виникла помилка. Спробуйте пізніше";
    }

    header("Location: request_reset.php");
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
                            echo htmlspecialchars($_SESSION['error']);
                            unset($_SESSION['error']);
                            ?>
                        </div>
                    <?php endif; ?>

                    <?php if(isset($_SESSION['success'])): ?>
                        <div class="alert alert-success">
                            <?php
                            echo htmlspecialchars($_SESSION['success']);
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
                    <div class="mt-3 text-center">
                        <a href="login.php">Повернутися до входу</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>