<?php
require 'db.php';

if (isset($_GET['token'])) {
    $token = $_GET['token'];

    $query = "SELECT id FROM users WHERE verification_token = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        $updateQuery = "UPDATE users SET email_verified = TRUE, verification_token = NULL WHERE id = ?";
        $stmt = $conn->prepare($updateQuery);
        $stmt->bind_param("i", $user['id']);

        if ($stmt->execute()) {
            $message = "Email успішно підтверджено!";
            $type = "success";
        } else {
            $message = "Помилка оновлення: " . $conn->error;
            $type = "error";
        }
    } else {
        $message = "email вже підтверджено";
        $type = "error";
    }
} else {
    $message = "Токен не надано";
    $type = "error";
}
?>

<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <title>Підтвердження Email</title>
    <link rel="stylesheet" href="verify_email.css">
</head>
<body>
<div class="wrapper">
    <h1 class="title">Підтвердження Email</h1>

    <div class="verification-box">
        <?php if($type === 'success'): ?>
            <div class="verification-success">
                <svg viewBox="0 0 24 24" width="50" height="50">
                    <path fill="currentColor" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                </svg>
                <p><?php echo $message; ?></p>
                <div class="redirect-notice">Перенаправлення на сторінку входу через <span id="countdown">3</span> секунди...</div>
            </div>
        <?php else: ?>
            <div class="verification-error">
                <svg viewBox="0 0 24 24" width="50" height="50">
                    <path fill="currentColor" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
                </svg>
                <p><?php echo $message; ?></p>
            </div>
        <?php endif; ?>
        <div class="action-buttons">
            <a href="login.php" class="btn login-btn">Увійти</a>
            <a href="index.php" class="btn home-btn">На головну</a>
        </div>
        </div>
    </div>
</div>

<script>
    // Таймер перенаправлення
    <?php if($type === 'success'): ?>
    let seconds =100;
    const countdownElement = document.getElementById('countdown');

    const interval = setInterval(() => {
        seconds--;
        countdownElement.textContent = seconds;
        if(seconds <= 0) {
            clearInterval(interval);
            window.location.href = 'login.php';
        }
    }, 1000);
    <?php endif; ?>
</script>
</body>
</html>