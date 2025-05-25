<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once '/home/l131113/public_html/lagodiy.com/vendor/autoload.php';
include 'db.php';
require 'mail-config.php';

function writeLog($message) {
    error_log(date('Y-m-d H:i:s') . ": " . $message . "\n", 3, "google_auth.log");
}

function sendWelcomeEmail($email, $username) {
    try {
        $mail = createMailer();
        $mail->addAddress($email);
        $mail->Subject = 'Ласкаво просимо до Lagodiy!';
        $mail->Body = "
            <h1>Вітаємо, $username!</h1>
            <p>Дякуємо за реєстрацію через Google.</p>
            <p>Ваші дані для входу:</p>
            <ul>
                <li>Логін: $email</li>
                <li>Спосіб входу: Google</li>
            </ul>
        ";
        $mail->send();
    } catch (Exception $e) {
        writeLog("Помилка відправки вітання: {$mail->ErrorInfo}");
    }
}

writeLog("Початок процесу автентифікації");

$client = new Google_Client();
$client->setClientId('629262427335-rep71pn47c65jbvgh6f8ho65112ii21k.apps.googleusercontent.com');
$client->setClientSecret('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');
$client->setRedirectUri('https://lagodiy.com/google_login.php');
$client->addScope('email');
$client->addScope('profile');

if (isset($_GET['code'])) {
    writeLog("Отримано код авторизації");

    try {
        $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
        writeLog("Токен: " . json_encode($token));

        if (isset($token['access_token'])) {
            $client->setAccessToken($token);
            $oauth2 = new Google_Service_Oauth2($client);
            $google_account_info = $oauth2->userinfo->get();

            $email = $google_account_info->email;
            $google_id = $google_account_info->id;
            $name = $google_account_info->name;
            $picture = $google_account_info->picture;

            writeLog("Отримано інформацію про користувача: $email");

            // Валідація email
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Некоректний email");
            }

            $stmt = $conn->prepare("SELECT * FROM users WHERE google_id = ? OR email = ?");
            $stmt->bind_param("ss", $google_id, $email);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();

            if ($user) {
                writeLog("Існуючий користувач: " . $user['id']);

                if (empty($user['google_id'])) {
                    $update = $conn->prepare("UPDATE users SET google_id = ? WHERE id = ?");
                    $update->bind_param("si", $google_id, $user['id']);
                    $update->execute();
                    sendWelcomeEmail($user['email'], $user['username']);
                }

                $_SESSION['user_id'] = $user['id'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['logged_in'] = true;

                writeLog("Перенаправлення в кабінет");
                header('Location: /../dah/dashboard.php');
                exit();
            } else {
                writeLog("Новий користувач, перенаправлення на реєстрацію");
                $_SESSION['temp_google_id'] = $google_id;
                $_SESSION['temp_email'] = $email;
                $_SESSION['temp_name'] = $name;
                $_SESSION['temp_picture'] = $picture;

                header('Location: complete_registration.php');
                exit();
            }
        }
    } catch (Exception $e) {
        writeLog("Помилка: " . $e->getMessage());
        $_SESSION['error'] = "Помилка автентифікації: " . $e->getMessage();
        header('Location: login.php');
        exit();
    }
} elseif (!isset($_SESSION['user_id'])) {
    $auth_url = $client->createAuthUrl();
    header('Location: ' . filter_var($auth_url, FILTER_SANITIZE_URL));
    exit();
} else {
    header('Location: /../dah/dashboard.php');
    exit();
}
?>