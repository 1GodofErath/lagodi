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
?>

    <!DOCTYPE html>
    <html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Зміна паролю</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="text-center">Зміна паролю</h3>
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

                    <form method="POST" action="process_change_password.php">
                        <div class="mb-3">
                            <label for="current_password" class="form-label">Поточний пароль</label>
                            <input type="password" class="form-control" id="current_password" name="current_password" required>
                        </div>
                        <div class="mb-3">
                            <label for="new_password" class="form-label">Новий пароль</label>
                            <input type="password" class="form-control" id="new_password" name="new_password" required>
                            <small class="form-text text-muted">Пароль повинен містити мінімум 8 символів</small>
                        </div>
                        <div class="mb-3">
                            <label for="confirm_password" class="form-label">Підтвердження нового паролю</label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                        </div>
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary">Змінити пароль</button>
                            <a href="/../dah/dashboard.php" class="btn btn-secondary">Повернутися на головну</a>
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
        }
    });
</script>
</body>
    </html>
