<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
/**
 * Клас Mailer - розроблено для сервісу ремонту Lagodi
 * Спрощена версія без зовнішніх залежностей
 *
 * @version 2.0.0
 * @date 2025-04-06
 */

// Запобігання прямому доступу до файлу
if (!defined('SECURITY_CHECK')) {
    die('Прямий доступ до цього файлу заборонений');
}

/**
 * Клас для відправки електронних листів
 * Використовує нативну функцію mail() PHP без зовнішніх залежностей
 */
class Mailer {
    /**
     * @var bool $available Чи доступна функціональність відправки листів
     */
    private $available;

    /**
     * Конструктор класу
     * Перевіряє, чи доступна нативна функція відправки пошти
     */
    public function __construct() {
        $this->available = function_exists('mail');

        if (!$this->available) {
            logError("Mailer: Mail function is not available, email functionality will be disabled");
        }
    }

    /**
     * Перевіряє, чи доступна функціональність відправки листів
     * @return bool Доступність функції відправки
     */
    public function isAvailable() {
        return $this->available;
    }

    /**
     * Відправка листа для підтвердження реєстрації
     * @param string $email Email отримувача
     * @param string $displayName Ім'я отримувача
     * @param string $token Токен для підтвердження
     * @return bool Результат відправки
     */
    public function sendRegistrationConfirmation($email, $displayName, $token) {
        if (!$this->available) {
            logError("Mailer: Attempted to send registration confirmation but mail functionality is disabled");
            return false;
        }

        $verificationLink = APP_URL . '/verify.php?token=' . $token;

        $subject = 'Підтвердження реєстрації в сервісі "Lagodi"';

        // Формуємо HTML-версію листа
        $htmlMessage = '
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <title>Підтвердження реєстрації</title>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; }
                    .container { border: 1px solid #ddd; border-radius: 5px; padding: 20px; }
                    .header { background-color: #2a71b9; padding: 15px; text-align: center; color: white; border-radius: 5px 5px 0 0; }
                    .content { background-color: #f7f9fc; padding: 20px; border-radius: 0 0 5px 5px; }
                    .button { display: inline-block; padding: 10px 20px; background-color: #2a71b9; color: white; text-decoration: none; border-radius: 5px; }
                    .footer { margin-top: 20px; font-size: 12px; color: #666; text-align: center; }
                </style>
            </head>
            <body>
                <div class="container">
                    <div class="header">
                        <h1>Вітаємо у сервісі ремонту Lagodi!</h1>
                    </div>
                    <div class="content">
                        <p>Шановний(а) ' . htmlspecialchars($displayName) . ',</p>
                        <p>Дякуємо за реєстрацію в нашому сервісі ремонту!</p>
                        <p>Для завершення реєстрації, будь ласка, підтвердіть свою електронну пошту, натиснувши кнопку нижче:</p>
                        <p style="text-align: center;">
                            <a href="' . $verificationLink . '" class="button">Підтвердити email</a>
                        </p>
                        <p>Або ви можете скопіювати та вставити наступне посилання у ваш браузер:</p>
                        <p>' . $verificationLink . '</p>
                        <p>Якщо ви не реєструвалися в нашому сервісі, проігноруйте цей лист.</p>
                    </div>
                    <div class="footer">
                        <p>&copy; ' . date('Y') . ' Lagodi - Сервіс ремонту. Всі права захищені.</p>
                    </div>
                </div>
            </body>
            </html>
        ';

        // Формуємо текстову версію листа (для клієнтів, які не підтримують HTML)
        $textMessage = "Вітаємо у сервісі ремонту Lagodi!\r\n\r\n"
            . "Шановний(а) " . $displayName . ",\r\n\r\n"
            . "Дякуємо за реєстрацію в нашому сервісі ремонту!\r\n"
            . "Для завершення реєстрації, будь ласка, перейдіть за посиланням нижче:\r\n"
            . $verificationLink . "\r\n\r\n"
            . "Якщо ви не реєструвалися в нашому сервісі, проігноруйте цей лист.\r\n\r\n"
            . "© " . date('Y') . " Lagodi - Сервіс ремонту. Всі права захищені.";

        // Відправляємо лист
        return $this->sendMultipartMail($email, $subject, $textMessage, $htmlMessage, $displayName);
    }

    /**
     * Відправка листа для відновлення пароля
     * @param string $email Email отримувача
     * @param string $displayName Ім'я отримувача
     * @param string $token Токен для відновлення пароля
     * @return bool Результат відправки
     */
    public function sendPasswordReset($email, $displayName, $token) {
        if (!$this->available) {
            logError("Mailer: Attempted to send password reset but mail functionality is disabled");
            return false;
        }

        $resetLink = APP_URL . '/reset-password.php?token=' . $token;

        // Переконаємося, що є коректне ім'я
        $displayNameFinal = $displayName;
        if (empty($displayNameFinal)) {
            $displayNameFinal = "Шановний користувач";
        }

        $subject = 'Відновлення пароля в сервісі "Lagodi"';

        // Формуємо HTML-версію листа
        $htmlMessage = '
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <title>Відновлення пароля</title>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; }
                    .container { border: 1px solid #ddd; border-radius: 5px; padding: 20px; }
                    .header { background-color: #2a71b9; padding: 15px; text-align: center; color: white; border-radius: 5px 5px 0 0; }
                    .content { background-color: #f7f9fc; padding: 20px; border-radius: 0 0 5px 5px; }
                    .button { display: inline-block; padding: 10px 20px; background-color: #2a71b9; color: white; text-decoration: none; border-radius: 5px; }
                    .footer { margin-top: 20px; font-size: 12px; color: #666; text-align: center; }
                </style>
            </head>
            <body>
                <div class="container">
                    <div class="header">
                        <h1>Відновлення пароля</h1>
                    </div>
                    <div class="content">
                        <p>Шановний(а) ' . htmlspecialchars($displayNameFinal) . ',</p>
                        <p>Ми отримали запит на відновлення пароля для вашого облікового запису.</p>
                        <p>Для встановлення нового пароля, будь ласка, натисніть кнопку нижче:</p>
                        <p style="text-align: center;">
                            <a href="' . $resetLink . '" class="button">Відновити пароль</a>
                        </p>
                        <p>Або ви можете скопіювати та вставити наступне посилання у ваш браузер:</p>
                        <p>' . $resetLink . '</p>
                        <p>Посилання дійсне протягом 24 годин.</p>
                        <p>Якщо ви не запитували відновлення пароля, проігноруйте цей лист і ваш пароль залишиться незмінним.</p>
                    </div>
                    <div class="footer">
                        <p>&copy; ' . date('Y') . ' Lagodi - Сервіс ремонту. Всі права захищені.</p>
                    </div>
                </div>
            </body>
            </html>
        ';

        // Формуємо текстову версію листа (для клієнтів, які не підтримують HTML)
        $textMessage = "Відновлення пароля\r\n\r\n"
            . "Шановний(а) " . $displayNameFinal . ",\r\n\r\n"
            . "Ми отримали запит на відновлення пароля для вашого облікового запису.\r\n\r\n"
            . "Для встановлення нового пароля, будь ласка, перейдіть за посиланням нижче:\r\n"
            . $resetLink . "\r\n\r\n"
            . "Посилання дійсне протягом 24 годин.\r\n\r\n"
            . "Якщо ви не запитували відновлення пароля, проігноруйте цей лист і ваш пароль залишиться незмінним.\r\n\r\n"
            . "© " . date('Y') . " Lagodi - Сервіс ремонту. Всі права захищені.";

        // Відправляємо лист
        return $this->sendMultipartMail($email, $subject, $textMessage, $htmlMessage, $displayNameFinal);
    }

    /**
     * Відправка сповіщення про створення нового замовлення
     * @param array $user Дані користувача
     * @param array $order Дані замовлення
     * @return bool Результат відправки
     */
    public function sendNewOrderNotification($user, $order) {
        if (!$this->available) {
            logError("Mailer: Attempted to send new order notification but mail functionality is disabled");
            return false;
        }

        $orderLink = APP_URL . '/dashboard.php?tab=orders&order=' . $order['id'];

        // Отримуємо ім'я користувача
        $userName = isset($user['display_name']) && !empty($user['display_name']) ? $user['display_name'] : $user['username'];

        $subject = 'Нове замовлення створено';

        // Формуємо HTML-версію листа
        $htmlMessage = '
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <title>Нове замовлення створено</title>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; }
                    .container { border: 1px solid #ddd; border-radius: 5px; padding: 20px; }
                    .header { background-color: #2a71b9; padding: 15px; text-align: center; color: white; border-radius: 5px 5px 0 0; }
                    .content { background-color: #f7f9fc; padding: 20px; border-radius: 0 0 5px 5px; }
                    .details { background-color: #ffffff; padding: 15px; border-radius: 5px; margin: 15px 0; }
                    .details-row { margin-bottom: 10px; }
                    .details-label { font-weight: bold; }
                    .button { display: inline-block; padding: 10px 20px; background-color: #2a71b9; color: white; text-decoration: none; border-radius: 5px; }
                    .footer { margin-top: 20px; font-size: 12px; color: #666; text-align: center; }
                </style>
            </head>
            <body>
                <div class="container">
                    <div class="header">
                        <h1>Нове замовлення створено</h1>
                    </div>
                    <div class="content">
                        <p>Шановний(а) ' . htmlspecialchars($userName) . ',</p>
                        <p>Ваше замовлення успішно створено в системі.</p>
                        <div class="details">
                            <div class="details-row">
                                <span class="details-label">Номер замовлення:</span> #' . $order['id'] . '
                            </div>
                            <div class="details-row">
                                <span class="details-label">Послуга:</span> ' . htmlspecialchars($order['service']) . '
                            </div>
                            <div class="details-row">
                                <span class="details-label">Пристрій:</span> ' . htmlspecialchars($order['device_type']) . '
                            </div>
                            <div class="details-row">
                                <span class="details-label">Статус:</span> ' . htmlspecialchars($order['status']) . '
                            </div>
                        </div>
                        <p>Ви можете відстежувати статус замовлення в особистому кабінеті:</p>
                        <p style="text-align: center;">
                            <a href="' . $orderLink . '" class="button">Перейти до замовлення</a>
                        </p>
                        <p>Дякуємо за довіру до нашого сервісу!</p>
                    </div>
                    <div class="footer">
                        <p>&copy; ' . date('Y') . ' Lagodi - Сервіс ремонту. Всі права захищені.</p>
                    </div>
                </div>
            </body>
            </html>
        ';

        // Формуємо текстову версію листа (для клієнтів, які не підтримують HTML)
        $textMessage = "Нове замовлення створено\r\n\r\n"
            . "Шановний(а) " . $userName . ",\r\n\r\n"
            . "Ваше замовлення успішно створено в системі.\r\n\r\n"
            . "Номер замовлення: #" . $order['id'] . "\r\n"
            . "Послуга: " . $order['service'] . "\r\n"
            . "Пристрій: " . $order['device_type'] . "\r\n"
            . "Статус: " . $order['status'] . "\r\n\r\n"
            . "Ви можете відстежувати статус замовлення в особистому кабінеті:\r\n"
            . $orderLink . "\r\n\r\n"
            . "Дякуємо за довіру до нашого сервісу!\r\n\r\n"
            . "© " . date('Y') . " Lagodi - Сервіс ремонту. Всі права захищені.";

        // Відправляємо лист
        return $this->sendMultipartMail($user['email'], $subject, $textMessage, $htmlMessage, $userName);
    }

    /**
     * Відправка сповіщення про зміну статусу замовлення
     * @param array $user Дані користувача
     * @param array $order Оновлені дані замовлення
     * @param string $oldStatus Попередній статус замовлення
     * @return bool Результат відправки
     */
    public function sendOrderStatusChangedNotification($user, $order, $oldStatus) {
        if (!$this->available) {
            logError("Mailer: Attempted to send status change notification but mail functionality is disabled");
            return false;
        }

        $orderLink = APP_URL . '/dashboard.php?tab=orders&order=' . $order['id'];

        // Отримуємо ім'я користувача
        $userName = isset($user['display_name']) && !empty($user['display_name']) ? $user['display_name'] : $user['username'];

        $subject = 'Статус вашого замовлення оновлено';

        // Формуємо HTML-версію листа
        $htmlMessage = '
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <title>Статус замовлення оновлено</title>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; }
                    .container { border: 1px solid #ddd; border-radius: 5px; padding: 20px; }
                    .header { background-color: #2a71b9; padding: 15px; text-align: center; color: white; border-radius: 5px 5px 0 0; }
                    .content { background-color: #f7f9fc; padding: 20px; border-radius: 0 0 5px 5px; }
                    .details { background-color: #ffffff; padding: 15px; border-radius: 5px; margin: 15px 0; }
                    .details-row { margin-bottom: 10px; }
                    .details-label { font-weight: bold; }
                    .status { display: inline-block; padding: 5px 10px; border-radius: 3px; font-size: 14px; }
                    .status-old { background-color: #f8d7da; color: #721c24; }
                    .status-new { background-color: #d4edda; color: #155724; }
                    .button { display: inline-block; padding: 10px 20px; background-color: #2a71b9; color: white; text-decoration: none; border-radius: 5px; }
                    .footer { margin-top: 20px; font-size: 12px; color: #666; text-align: center; }
                </style>
            </head>
            <body>
                <div class="container">
                    <div class="header">
                        <h1>Статус замовлення оновлено</h1>
                    </div>
                    <div class="content">
                        <p>Шановний(а) ' . htmlspecialchars($userName) . ',</p>
                        <p>Статус вашого замовлення №' . $order['id'] . ' було оновлено.</p>
                        <div class="details">
                            <div class="details-row">
                                <span class="details-label">Номер замовлення:</span> #' . $order['id'] . '
                            </div>
                            <div class="details-row">
                                <span class="details-label">Послуга:</span> ' . htmlspecialchars($order['service']) . '
                            </div>
                            <div class="details-row">
                                <span class="details-label">Попередній статус:</span> <span class="status status-old">' . htmlspecialchars($oldStatus) . '</span>
                            </div>
                            <div class="details-row">
                                <span class="details-label">Новий статус:</span> <span class="status status-new">' . htmlspecialchars($order['status']) . '</span>
                            </div>
                        </div>
                        <p>Для перегляду деталей замовлення перейдіть за посиланням:</p>
                        <p style="text-align: center;">
                            <a href="' . $orderLink . '" class="button">Перейти до замовлення</a>
                        </p>
                    </div>
                    <div class="footer">
                        <p>&copy; ' . date('Y') . ' Lagodi - Сервіс ремонту. Всі права захищені.</p>
                    </div>
                </div>
            </body>
            </html>
        ';

        // Формуємо текстову версію листа (для клієнтів, які не підтримують HTML)
        $textMessage = "Статус замовлення оновлено\r\n\r\n"
            . "Шановний(а) " . $userName . ",\r\n\r\n"
            . "Статус вашого замовлення №" . $order['id'] . " було оновлено.\r\n\r\n"
            . "Номер замовлення: #" . $order['id'] . "\r\n"
            . "Послуга: " . $order['service'] . "\r\n"
            . "Попередній статус: " . $oldStatus . "\r\n"
            . "Новий статус: " . $order['status'] . "\r\n\r\n"
            . "Для перегляду деталей замовлення перейдіть за посиланням:\r\n"
            . $orderLink . "\r\n\r\n"
            . "© " . date('Y') . " Lagodi - Сервіс ремонту. Всі права захищені.";

        // Відправляємо лист
        return $this->sendMultipartMail($user['email'], $subject, $textMessage, $htmlMessage, $userName);
    }

    /**
     * Відправка сповіщення про зміну пароля
     * @param array $user Дані користувача
     * @return bool Результат відправки
     */
    public function sendPasswordChangedNotification($user) {
        if (!$this->available) {
            logError("Mailer: Attempted to send password change notification but mail functionality is disabled");
            return false;
        }

        // Отримуємо ім'я користувача
        $userName = isset($user['display_name']) && !empty($user['display_name']) ? $user['display_name'] : $user['username'];

        $subject = 'Пароль змінено';

        // Формуємо HTML-версію листа
        $htmlMessage = '
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <title>Пароль змінено</title>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; }
                    .container { border: 1px solid #ddd; border-radius: 5px; padding: 20px; }
                    .header { background-color: #2a71b9; padding: 15px; text-align: center; color: white; border-radius: 5px 5px 0 0; }
                    .content { background-color: #f7f9fc; padding: 20px; border-radius: 0 0 5px 5px; }
                    .warning { background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 10px; margin: 15px 0; }
                    .footer { margin-top: 20px; font-size: 12px; color: #666; text-align: center; }
                </style>
            </head>
            <body>
                <div class="container">
                    <div class="header">
                        <h1>Пароль змінено</h1>
                    </div>
                    <div class="content">
                        <p>Шановний(а) ' . htmlspecialchars($userName) . ',</p>
                        <p>Пароль для вашого облікового запису було успішно змінено.</p>
                        <div class="warning">
                            <p><strong>Увага!</strong> Якщо ви не змінювали свій пароль, негайно зверніться до служби підтримки за адресою <a href="mailto:support@lagodi.com">support@lagodi.com</a>.</p>
                        </div>
                    </div>
                    <div class="footer">
                        <p>&copy; ' . date('Y') . ' Lagodi - Сервіс ремонту. Всі права захищені.</p>
                    </div>
                </div>
            </body>
            </html>
        ';

        // Формуємо текстову версію листа (для клієнтів, які не підтримують HTML)
        $textMessage = "Пароль змінено\r\n\r\n"
            . "Шановний(а) " . $userName . ",\r\n\r\n"
            . "Пароль для вашого облікового запису було успішно змінено.\r\n\r\n"
            . "Увага! Якщо ви не змінювали свій пароль, негайно зверніться до служби підтримки за адресою support@lagodi.com.\r\n\r\n"
            . "© " . date('Y') . " Lagodi - Сервіс ремонту. Всі права захищені.";

        // Відправляємо лист
        return $this->sendMultipartMail($user['email'], $subject, $textMessage, $htmlMessage, $userName);
    }

    /**
     * Відправка листа для підтвердження зміни email
     * @param string $email Новий email
     * @param string $displayName Ім'я користувача
     * @param string $token Токен для підтвердження
     * @return bool Результат відправки
     */
    public function sendEmailVerification($email, $displayName, $token) {
        if (!$this->available) {
            logError("Mailer: Attempted to send email verification but mail functionality is disabled");
            return false;
        }

        $verificationLink = APP_URL . '/verify-email.php?token=' . $token;

        $subject = 'Підтвердження нової електронної пошти';

        // Формуємо HTML-версію листа
        $htmlMessage = '
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <title>Підтвердження нової електронної пошти</title>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; }
                    .container { border: 1px solid #ddd; border-radius: 5px; padding: 20px; }
                    .header { background-color: #2a71b9; padding: 15px; text-align: center; color: white; border-radius: 5px 5px 0 0; }
                    .content { background-color: #f7f9fc; padding: 20px; border-radius: 0 0 5px 5px; }
                    .button { display: inline-block; padding: 10px 20px; background-color: #2a71b9; color: white; text-decoration: none; border-radius: 5px; }
                    .footer { margin-top: 20px; font-size: 12px; color: #666; text-align: center; }
                </style>
            </head>
            <body>
                <div class="container">
                    <div class="header">
                        <h1>Підтвердження нової електронної пошти</h1>
                    </div>
                    <div class="content">
                        <p>Шановний(а) ' . htmlspecialchars($displayName) . ',</p>
                        <p>Ви змінили електронну пошту для вашого облікового запису в сервісі Lagodi.</p>
                        <p>Для підтвердження нової електронної пошти, будь ласка, натисніть кнопку нижче:</p>
                        <p style="text-align: center;">
                            <a href="' . $verificationLink . '" class="button">Підтвердити електронну пошту</a>
                        </p>
                        <p>Або ви можете скопіювати та вставити наступне посилання у ваш браузер:</p>
                        <p>' . $verificationLink . '</p>
                        <p>Якщо ви не змінювали свою електронну пошту, проігноруйте цей лист.</p>
                    </div>
                    <div class="footer">
                        <p>&copy; ' . date('Y') . ' Lagodi - Сервіс ремонту. Всі права захищені.</p>
                    </div>
                </div>
            </body>
            </html>
        ';

        // Формуємо текстову версію листа (для клієнтів, які не підтримують HTML)
        $textMessage = "Підтвердження нової електронної пошти\r\n\r\n"
            . "Шановний(а) " . $displayName . ",\r\n\r\n"
            . "Ви змінили електронну пошту для вашого облікового запису в сервісі Lagodi.\r\n\r\n"
            . "Для підтвердження нової електронної пошти, будь ласка, перейдіть за посиланням нижче:\r\n"
            . $verificationLink . "\r\n\r\n"
            . "Якщо ви не змінювали свою електронну пошту, проігноруйте цей лист.\r\n\r\n"
            . "© " . date('Y') . " Lagodi - Сервіс ремонту. Всі права захищені.";

        // Відправляємо лист
        return $this->sendMultipartMail($email, $subject, $textMessage, $htmlMessage, $displayName);
    }

    /**
     * Відправка сповіщення про зміну email
     * @param string $oldEmail Старий email
     * @param string $displayName Ім'я користувача
     * @param string $newEmail Новий email
     * @return bool Результат відправки
     */
    public function sendEmailChangeNotification($oldEmail, $displayName, $newEmail) {
        if (!$this->available) {
            logError("Mailer: Attempted to send email change notification but mail functionality is disabled");
            return false;
        }

        $subject = 'Зміна електронної пошти в сервісі "Lagodi"';

        // Формуємо HTML-версію листа
        $htmlMessage = '
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <title>Зміна електронної пошти</title>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; }
                    .container { border: 1px solid #ddd; border-radius: 5px; padding: 20px; }
                    .header { background-color: #2a71b9; padding: 15px; text-align: center; color: white; border-radius: 5px 5px 0 0; }
                    .content { background-color: #f7f9fc; padding: 20px; border-radius: 0 0 5px 5px; }
                    .warning { background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 10px; margin: 15px 0; }
                    .footer { margin-top: 20px; font-size: 12px; color: #666; text-align: center; }
                </style>
            </head>
            <body>
                <div class="container">
                    <div class="header">
                        <h1>Зміна електронної пошти</h1>
                    </div>
                    <div class="content">
                        <p>Шановний(а) ' . htmlspecialchars($displayName) . ',</p>
                        <p>Електронну пошту вашого облікового запису було змінено з <strong>' . htmlspecialchars($oldEmail) . '</strong> на <strong>' . htmlspecialchars($newEmail) . '</strong>.</p>
                        <div class="warning">
                            <p><strong>Увага!</strong> Якщо ви не змінювали свою електронну пошту, негайно зверніться до служби підтримки за адресою <a href="mailto:support@lagodi.com">support@lagodi.com</a>.</p>
                        </div>
                    </div>
                    <div class="footer">
                        <p>&copy; ' . date('Y') . ' Lagodi - Сервіс ремонту. Всі права захищені.</p>
                    </div>
                </div>
            </body>
            </html>
        ';

        // Формуємо текстову версію листа (для клієнтів, які не підтримують HTML)
        $textMessage = "Зміна електронної пошти\r\n\r\n"
            . "Шановний(а) " . $displayName . ",\r\n\r\n"
            . "Електронну пошту вашого облікового запису було змінено з " . $oldEmail . " на " . $newEmail . ".\r\n\r\n"
            . "Увага! Якщо ви не змінювали свою електронну пошту, негайно зверніться до служби підтримки за адресою support@lagodi.com.\r\n\r\n"
            . "© " . date('Y') . " Lagodi - Сервіс ремонту. Всі права захищені.";

        // Відправляємо лист
        return $this->sendMultipartMail($oldEmail, $subject, $textMessage, $htmlMessage, $displayName);
    }

    /**
     * Відправка MIME листа з HTML та текстовою версіями
     * Метод використовує звичайну функцію mail() з MIME кодуванням
     *
     * @param string $to Email отримувача
     * @param string $subject Тема листа
     * @param string $textMessage Текстова частина листа
     * @param string $htmlMessage HTML частина листа
     * @param string $toName Ім'я отримувача
     * @return bool Результат відправки
     */
    private function sendMultipartMail($to, $subject, $textMessage, $htmlMessage, $toName = '') {
        try {
            // Якщо функція mail() недоступна, просто логуємо лист
            if (!$this->available) {
                logError("Mailer: Mail would have been sent to: " . $to . " Subject: " . $subject);
                return false;
            }

            // Генеруємо унікальний ідентифікатор (boundary) для розділення частин листа
            $boundary = md5(time()) . rand(0, 9999);

            // Заголовки листа
            $headers = [
                'From' => SMTP_FROM_NAME . ' <' . SMTP_FROM_EMAIL . '>',
                'Reply-To' => SMTP_FROM_EMAIL,
                'MIME-Version' => '1.0',
                'Content-Type' => 'multipart/alternative; boundary=' . $boundary,
                'X-Mailer' => 'PHP/' . phpversion()
            ];

            // Формуємо структуру MIME-повідомлення
            $message = "--" . $boundary . "\r\n";
            $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $message .= $textMessage . "\r\n\r\n";

            // Додаємо HTML-частину
            $message .= "--" . $boundary . "\r\n";
            $message .= "Content-Type: text/html; charset=UTF-8\r\n";
            $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $message .= $htmlMessage . "\r\n\r\n";

            // Завершуємо повідомлення
            $message .= "--" . $boundary . "--";

            // Перетворюємо заголовки в рядок
            $headerString = '';
            foreach ($headers as $key => $value) {
                $headerString .= $key . ': ' . $value . "\r\n";
            }

            // Спроба відправки листа
            $result = mail(
                $to,
                '=?UTF-8?B?' . base64_encode($subject) . '?=',
                $message,
                $headerString
            );

            if (!$result) {
                logError("Mailer: Failed to send mail to " . $to . " Subject: " . $subject);
            }

            return $result;
        } catch (Exception $e) {
            logError("Mailer: Error sending mail: " . $e->getMessage());
            return false;
        }
    }
}