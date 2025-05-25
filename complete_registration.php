<?php
ob_start();
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once '/home/l131113/public_html/lagodiy.com/db.php';
require_once '/home/l131113/public_html/lagodiy.com/send_email.php';

if (!isset($_SESSION['temp_email']) || !isset($_SESSION['temp_google_id'])) {
    header('Location: login.php');
    exit();
}

function generateRandomPassword($length = 8) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $password;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim(htmlspecialchars($_POST['username'], ENT_QUOTES, 'UTF-8'));

    if (empty($username) || strlen($username) < 3) {
        $_SESSION['error'] = "Ім'я повинно містити мінімум 3 символи";
        header('Location: complete_registration.php');
        exit();
    }

    try {
        $check = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $check->bind_param("s", $username);
        $check->execute();
        $result = $check->get_result();

        if ($result->num_rows > 0) {
            $_SESSION['error'] = "Це ім'я вже зайняте";
            header('Location: complete_registration.php');
            exit();
        }

        $password = generateRandomPassword();
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("INSERT INTO users (username, email, google_id, profile_pic, password, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("sssss",
            $username,
            $_SESSION['temp_email'],
            $_SESSION['temp_google_id'],
            $_SESSION['temp_picture'],
            $hashed_password
        );

        if ($stmt->execute()) {
            $emailResult = sendEmail(
                $_SESSION['temp_email'],
                'Ваш пароль для lagodiy.com',
                "Вітаємо!\n\nВаші дані:\nЛогін: {$_SESSION['temp_email']}\nПароль: $password\n\nЗбережіть цей пароль!"
            );

            if (!$emailResult['success']) {
                throw new Exception("Помилка відправки: " . $emailResult['message']);
            }

            $_SESSION['user_id'] = $conn->insert_id;
            $_SESSION['username'] = $username;
            $_SESSION['logged_in'] = true;

            unset($_SESSION['temp_email']);
            unset($_SESSION['temp_google_id']);
            unset($_SESSION['temp_picture']);

            header('Location: /../dah/dashboard.php');
            exit();
        } else {
            throw new Exception("Помилка при створенні користувача");
        }
    } catch (Exception $e) {
        $_SESSION['error'] = "Помилка: " . $e->getMessage();
        header('Location: complete_registration.php');
        exit();
    }
}

ob_end_flush();
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Завершення реєстрації</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="text-center">Завершення реєстрації</h3>
                </div>
                <div class="card-body">
                    <div class="alert alert-info">
                        <strong>Увага!</strong> Пароль для входу буде відправлено на вашу електронну адресу
                        <?php echo htmlspecialchars($_SESSION['temp_email']); ?>.
                        Збережіть його для подальшого використання.
                    </div>

                    <?php if(isset($_SESSION['error'])): ?>
                        <div class="alert alert-danger">
                            <?php
                            echo htmlspecialchars($_SESSION['error']);
                            unset($_SESSION['error']);
                            ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="">
                        <div class="mb-3">
                            <label for="username" class="form-label">Виберіть ім'я користувача</label>
                            <input type="text"
                                   class="form-control"
                                   id="username"
                                   name="username"
                                   value="<?php echo isset($_SESSION['temp_name']) ? htmlspecialchars($_SESSION['temp_name']) : ''; ?>"
                                   required
                                   minlength="3"
                                   pattern="[a-zA-Z0-9_-]+"
                                   title="Дозволені символи: літери, цифри, дефіс та нижнє підкреслення">
                            <small class="form-text text-muted">Мінімум 3 символи. Дозволені символи: літери, цифри, дефіс та нижнє підкреслення</small>
                        </div>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary">Завершити реєстрацію</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>