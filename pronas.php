<?php
session_start();

if (isset($_SESSION['username'])) {
    header("Location: dashboard.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IT-Lagodiy | Про нас</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<!-- Навігаційне меню -->
<nav class="navbar">
    <div class="container">
        <a href="index.php" class="logo">IT-Lagodiy</a>
        <div class="nav-links">
            <a href="index.php">Головна</a>
            <a href="index.php#services">Послуги</a>
            <a href="index.php#contact">Контакти</a>
            <a href="login.php" class="btn">Мій кабінет</a>
        </div>
        <div class="mobile-menu">
            <i class="fas fa-bars"></i>
        </div>
    </div>
</nav>

<!-- Секція "Про нас" -->
<section class="about" id="about">
    <div class="container">
        <h2>Про нас</h2>
        <div class="about-grid">
            <div class="about-content">
                <div class="about-text">
                    <h3>IT-Lagodiy - професійний сервіс з 2010 року</h3>
                    <p>Ми спеціалізуємося на комплексному обслуговуванні техніки та пропонуємо:</p>

                    <div class="about-features">
                        <div class="feature-item">
                            <i class="fas fa-users-cog fa-2x"></i>
                            <div>
                                <h4>Досвідчені майстри</h4>
                                <p>Команда з 10+ сертифікованих фахівців</p>
                            </div>
                        </div>

                        <div class="feature-item">
                            <i class="fas fa-certificate fa-2x"></i>
                            <div>
                                <h4>Гарантія якості</h4>
                                <p>12 місяців гарантії на всі види робіт</p>
                            </div>
                        </div>

                        <div class="feature-item">
                            <i class="fas fa-tools fa-2x"></i>
                            <div>
                                <h4>Сучасне обладнання</h4>
                                <p>Використовуємо професійні інструменти та технології</p>
                            </div>
                        </div>
                    </div>

                    <p class="about-cta">Звертайтесь до нас і переконайтесь у нашій професійності особисто!</p>
                    <a href="index.php#contact" class="cta-button">Зв'язатися з нами</a>
                </div>
            </div>

            <div class="about-image">
                <img src="foto/foto.avif" alt="Наша команда" loading="lazy">
            </div>
        </div>
    </div>
</section>

<!-- Футер -->
<footer class="footer">
    <div class="container">
        <p>&copy; 2025 IT-Lagodiy. Всі права захищені</p>
        <div class="social-links">
            <a href="#"><i class="fab fa-facebook"></i></a>
            <a href="#"><i class="fab fa-instagram"></i></a>
            <a href="#"><i class="fab fa-telegram"></i></a>
        </div>
    </div>
</footer>

<script src="script.js"></script>
</body>
</html>