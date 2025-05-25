 <?php
session_start();

if (isset($_SESSION['username'])) {
    header("Location: /../dah/dashboard.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IT-lagodiy | Ремонт техніки та заправка картриджів</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<nav class="navbar">
    <div class="container">
        <a href="#home" class="logo">IT-lagodiy</a>
        <div class="nav-links">
            <a href="#home">Головна</a>
            <a href="#services">Послуги</a>
            <a href="#contact">Контакти</a>
            <a href="pronas.php" class="btn">Про нас</a>
            <a href="login.php" class="btn">Мій кабінет</a>
        </div>
        <div class="mobile-menu">
            <i class="fas fa-bars"></i>
        </div>
    </div>
</nav>

<section class="hero" id="home">
    <div class="hero-slider">
        <div class="slide active" style="background-image: url('img/slide1.jpg')"></div>
        <div class="slide" style="background-image: url('image/Designer.jpg')"></div>
        <div class="slide" style="background-image: url('img/slide3.jpg')"></div>
    </div>

    <div class="container">
        <div class="hero-content">
            <h1>Професійний ремонт техніки та заправка картриджів</h1>
            <p>Швидко, якісно, з гарантією!</p>
            <a href="#contact" class="cta-button">Зв'язатися з нами</a>
        </div>
    </div>

    <div class="slider-dots">
        <div class="dot active"></div>
        <div class="dot"></div>
        <div class="dot"></div>
    </div>
</section>

<section class="services" id="services">
    <div class="container">
        <h2>Наші послуги</h2>
        <div class="services-grid">
            <!-- Заправка картриджів -->
            <div class="service-card">
                <i class="fas fa-print fa-3x"></i>
                <h3>Заправка картриджів</h3>
                <p>Професійна заправка всіх типів картриджів з гарантією якості</p>
            </div>

            <div class="service-card">
                <!-- Використовуємо правильний клас для іконки принтера -->
                <i class="fas fa-print fa-3x"></i>
                <h3>Ремонт МФУ</h3>
                <p>Усунення несправностей багатофункціональних пристроїв:</p>
                <ul class="service-list">
                    <li>Відновлення роботи сканера</li>
                    <li>Ремонт механізму подачі паперу</li>
                    <li>Налаштування мережевої печаті</li>
                    <li>Усунення програмних помилок</li>
                </ul>
            </div>

            <!-- НОВА ПОСЛУГА: Ремонт телефонів -->
            <div class="service-card">
                <i class="fas fa-mobile-alt fa-3x"></i>
                <h3>Ремонт телефонів</h3>
                <p>Комплексний ремонт мобільних пристроїв:</p>
                <ul class="service-list">
                    <li>Заміна дисплеїв та тачскрінів</li>
                    <li>Ремонт акумуляторів</li>
                    <li>Усунення проблем із зарядкою</li>
                    <li>Прошивка та налаштування</li>
                </ul>
            </div>

            <!-- Ремонт ноутбуків -->
            <div class="service-card">
                <i class="fas fa-laptop fa-3x"></i>
                <h3>Ремонт ноутбуків</h3>
                <p>Усунення будь-яких несправностей за короткий термін</p>
            </div>

            <!-- Ремонт ПК -->
            <div class="service-card">
                <i class="fas fa-desktop fa-3x"></i>
                <h3>Ремонт ПК</h3>
                <p>Професійний ремонт та модернізація стаціонарних комп'ютерів</p>
            </div>
        </div>
    </div>
</section>

<section class="advantages">
    <div class="container">
        <h2>Чому обирають нас?</h2>
        <div class="advantages-grid">
            <div class="advantage-card">
                <i class="fas fa-clock fa-2x"></i>
                <h3>Швидкий сервіс</h3>
                <p>Середній час виконання замовлення - 2 години</p>
            </div>
            <div class="advantage-card">
                <i class="fas fa-certificate fa-2x"></i>
                <h3>Гарантія якості</h3>
                <p>На всі послуги надаємо гарантію до 1 року</p>
            </div>
            <div class="advantage-card">
                <i class="fas fa-dollar-sign fa-2x"></i>
                <h3>Доступні ціни</h3>
                <p>Працюємо без посередників, тому ціни нижчі на 30%</p>
            </div>
        </div>
    </div>
</section>

<section class="contact" id="contact">
    <div class="container">
        <h2>Контактна інформація</h2>
        <div class="contact-grid">
            <div class="contact-info">
                <p><i class="fas fa-map-marker-alt"></i> м. Ніжин, вул. Носівський шлях 46</p>
                <p><i class="fas fa-phone"></i> +380 12 345 6789</p>
                <p><i class="fas fa-envelope"></i>lagodiy.service@lagodiy.com</p>
            </div>
            <div class="contact-info">
                <p class="contact-instruction">
                    <i class="fas fa-info-circle"></i>
                    Щоб зв'язатися, ви можете:<br>
                    1. Зателефонувати за вказаним номером +380 12 345 6789<br>
                    2. Створити та увійти в свій кабінет<br>
                    3. Написати на нашу електронну пошту lagodiy.service@lagodiy.com
                </p>
            </div>
        </div>
    </div>
</section>

<footer class="footer">
    <div class="container">
        <p>&copy; 2025 IT-lagodiy. Всі права захищені</p>
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


