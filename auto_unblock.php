<?php

require __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Шлях до файлу логування
$logFile = __DIR__ . '/auto_unblock_result.log';

/**
 * Записує повідомлення у лог файл.
 *
 * @param string $message
 */
function writeResultLog($message) {
    global $logFile;
    $logMessage = date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL;
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

/**
 * Надсилає повідомлення електронною поштою за допомогою PHPMailer.
 *
 * @param string $subject Тема листа.
 * @param string $body    Тіло листа.
 */
function sendNotificationEmail($subject, $body) {
    $mail = new PHPMailer(true);
    try {
        // Налаштування SMTP (змініть параметри під свої дані)
        $mail->isSMTP();
        $mail->Host       = 'hostch02.fornex.host';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'lagodiy.info@lagodiy.com';
        $mail->Password   = '3zIDVnH#tu?2&uIn';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->CharSet    = 'UTF-8';

        // Налаштування відправника та отримувача
        $mail->setFrom('lagodiy.info@lagodiy.com', 'завдання Cron');
        $mail->addAddress('admin@lagodiy.com');            // Ваша електронна адреса

        // Вміст листа
        $mail->isHTML(false);
        $mail->Subject = $subject;
        $mail->Body    = $body;

        $mail->send();
    } catch (Exception $e) {
        error_log("Повідомлення не надіслано. Помилка PHPMailer: {$mail->ErrorInfo}");
    }
}

// Підключення до бази даних (файл db.php повинен бути у тому ж каталозі або вкажіть коректний шлях)
require __DIR__ . '/db.php';

// Отримуємо поточний час у форматі MySQL (YYYY-MM-DD HH:MM:SS)
$currentTime = date('Y-m-d H:i:s');
error_log("auto_unblock.php запущено в: " . $currentTime);

// Підготовка запиту для оновлення користувачів, у яких час блокування закінчився
$query = "UPDATE users SET blocked_until = NULL, block_reason = NULL WHERE blocked_until IS NOT NULL AND blocked_until <= ?";
$stmt = $conn->prepare($query);

if (!$stmt) {
    $error = "Помилка підготовки запиту: " . $conn->error;
    error_log($error);
    writeResultLog($error);
    sendNotificationEmail("Cron Task Error", $error);
    die("Помилка: " . $conn->error);
}

$stmt->bind_param("s", $currentTime);

if (!$stmt->execute()) {
    $error = "Помилка виконання запиту: " . $stmt->error;
    error_log($error);
    writeResultLog($error);
    sendNotificationEmail("Cron Task Error", $error);
    die("Помилка виконання запиту: " . $stmt->error);
}

$affectedRows = $stmt->affected_rows;
if ($affectedRows > 0) {
    $message = "Розблоковано " . $affectedRows . " користувач(ів).";
} else {
    $message = "Немає користувачів для розблокування.";
}

error_log($message);
writeResultLog($message);
sendNotificationEmail("Cron Task Completed", $message);
echo $message;

$stmt->close();
$conn->close();
?>