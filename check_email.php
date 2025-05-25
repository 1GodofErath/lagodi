<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <title>Перевірте вашу пошту</title>
    <link rel="stylesheet" href="verify_email.css">
</head>
<body>
<div class="wrapper">
    <h1 class="title">Лист з підтвердженням відправлено!</h1>

    <div class="verification-content">
        <div class="success-message">
            <!-- Іконка успіху (додайте SVG або зображення) -->
            <svg class="icon-success" viewBox="0 0 24 24" fill="none">
                <path d="M20 6L9 17l-5-5"/>
            </svg>
            <p>Перевірте вашу електронну пошту та перейдіть за посиланням для завершення реєстрації.</p>
            <p class="redirect-info">Якщо лист не надійшов, перевірте папку "Спам".</p>
        </div>

        <div class="action-buttons">
            <a href="login.php" class="btn login-btn">До входу</a>
            <a href="index.php" class="btn home-btn">На головну</a>
        </div>
    </div>
</div>
</body>
</html>