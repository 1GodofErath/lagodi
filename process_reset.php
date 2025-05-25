<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once '/home/l131113/public_html/lagodiy.com/db.php';

// Перевіряємо наявність токена
if (!isset($_GET['token'])) {
    $_SESSION['error'] = "Відсутній токен для скидання паролю";
    header("Location: login.php");
    exit();
}

$token = $_GET['token'];

// Перевіряємо токен в базі даних
$stmt = $conn->prepare("SELECT id, email FROM users WHERE reset_token = ? AND reset_token_expiry > NOW()");
$stmt->bind_param("s", $token);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

// Якщо токен недійсний або застарів
if (!$user) {
    $_SESSION['error'] = "Недійсний або застарілий токен для скидання паролю";
    header("Location: login.php");
    exit();
}

// Обробка форми скидання паролю
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    // Валідація паролю
    if (strlen($new_password) < 8) {
        $_SESSION['error'] = "Пароль повинен містити мінімум 8 символів";
        header("Location: process_reset.php?token=" . $token);
        exit();
    }

    if ($new_password !== $confirm_password) {
        $_SESSION['error'] = "Паролі не співпадають";
        header("Location: process_reset.php?token=" . $token);
        exit();
    }

    try {
        // Хешуємо новий пароль
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

        // Оновлюємо пароль та очищаємо токен
        $update_stmt = $conn->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_token_expiry = NULL WHERE id = ?");
        $update_stmt->bind_param("si", $hashed_password, $user['id']);

        if ($update_stmt->execute()) {
            $_SESSION['success'] = "Пароль успішно змінено. Тепер ви можете увійти з новим паролем.";
            header("Location: login.php");
            exit();
        } else {
            $_SESSION['error'] = "Помилка при зміні паролю";
            header("Location: process_reset.php?token=" . $token);
            exit();
        }
    } catch (Exception $e) {
        error_log("Password reset error: " . $e->getMessage());
        $_SESSION['error'] = "Виникла помилка. Спробуйте пізніше";
        header("Location: process_reset.php?token=" . $token);
        exit();
    }
}
?>

    <!DOCTYPE html>
    <html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Встановлення нового паролю</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="text-center">Встановлення нового паролю</h3>
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

                    <form method="POST" action="process_reset.php?token=<?php echo htmlspecialchars($token); ?>">
                        <div class="mb-3">
                            <label for="new_password" class="form-label">Новий пароль</label>
                            <input type="password" class="form-control" id="new_password" name="new_password" required minlength="8">
                            <small class="form-text text-muted">Мінімум 8 символів</small>
                        </div>
                        <div class="mb-3">
                            <label for="confirm_password" class="form-label">Підтвердіть новий пароль</label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="8">
                        </div>
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary">Зберегти новий пароль</button>
                            <a href="login.php" class="btn btn-secondary">Повернутися до входу</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // Перевірка співпадіння паролів
    document.getElementById('confirm_password').addEventListener('input', function() {
        var new_password = document.getElementById('new_password').value;
        var confirm_password = this.value;

        if (new_password !== confirm_password) {
            this.setCustomValidity('Паролі не співпадають');
        } else {
            this.setCustomValidity('');
        }
    });

    // Перевірка довжини нового паролю
    document.getElementById('new_password').addEventListener('input', function() {
        if (this.value.length < 8) {
            this.setCustomValidity('Пароль повинен містити мінімум 8 символів');
        } else {
            this.setCustomValidity('');
            // Також перевіряємо співпадіння паролів при зміні нового паролю
            var confirm_password = document.getElementById('confirm_password');
            if (confirm_password.value !== '') {
                if (this.value !== confirm_password.value) {
                    confirm_password.setCustomValidity('Паролі не співпадають');
                } else {
                    confirm_password.setCustomValidity('');
                }
            }
        }
    });
</script>
</body>
    </html>
