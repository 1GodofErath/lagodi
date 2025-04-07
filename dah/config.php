<?php
// Константа для перевірки прямого доступу
if (!defined('SECURITY_CHECK')) {
    define('SECURITY_CHECK', true);
}

// Основні налаштування
define('APP_NAME', 'Lagodi - Сервіс ремонту');
define('APP_URL', 'https://lagodiy.com');
define('APP_VERSION', '2.0.0');

// Налаштування бази даних
define('DB_HOST', 'localhost');
define('DB_USER', 'l131113_login_us');
define('DB_PASS', 'ki4x03a91wlzdz0zgv');
define('DB_NAME', 'l131113_login');
define('DB_CHARSET', 'utf8mb4');

// Налаштування SMTP для відправки листів
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_SECURE', 'tls');
define('SMTP_USERNAME', 'your_email@gmail.com');
define('SMTP_PASSWORD', 'your_email_password');
define('SMTP_FROM_EMAIL', 'noreply@lagodi.com');
define('SMTP_FROM_NAME', 'Сервіс ремонту Lagodi');

// Налаштування безпеки
define('SECRET_KEY', 'secureRandomKeyHere');
define('PASSWORD_ALGORITHM', PASSWORD_BCRYPT);
define('PASSWORD_OPTIONS', ['cost' => 12]);
define('SESSION_LIFETIME', 1800); // 30 хвилин

// Обмеження завантаження файлів
define('MAX_FILE_SIZE', 10485760); // 10MB
define('ALLOWED_FILE_TYPES', 'jpg,jpeg,png,gif,mp4,pdf,doc,docx,txt,log');
define('UPLOAD_DIR', __DIR__ . '/uploads/');

// Інші налаштування
define('DEBUG_MODE', true);
define('LOGS_DIR', __DIR__ . '/logs/');
define('TIMEZONE', 'Europe/Kiev');

// Ініціалізація часового поясу
date_default_timezone_set(TIMEZONE);

// Функція для логування помилок
function logError($message, $severity = 'error') {
    if (!is_dir(LOGS_DIR)) {
        mkdir(LOGS_DIR, 0755, true);
    }

    $logFile = LOGS_DIR . 'app_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$severity] $message" . PHP_EOL;

    return error_log($logMessage, 3, $logFile);
}

// Налаштування показу помилок залежно від режиму налагодження
if (DEBUG_MODE) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(0);
}