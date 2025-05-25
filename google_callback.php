<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once '/home/l131113/public_html/lagodiy.com/vendor/autoload.php';
include 'db.php';

session_start();

$client = new Google_Client();
$client->setClientId('629262427335-rep71pn47c65jbvgh6f8ho65112ii21k.apps.googleusercontent.com');
$client->setClientSecret('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');
$client->setRedirectUri('https://lagodiy.com/google_login.php');

if (!isset($_GET['code'])) {
    exit('Помилка при авторизації з Google.');
}

$token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
if (!isset($token['access_token'])) {
    exit('Не вдалося отримати токен доступу.');
}

$client->setAccessToken($token);
$oauth2 = new Google_Service_Oauth2($client);

try {
    $google_account_info = $oauth2->userinfo->get();
    $email = $google_account_info->email;
    $google_id = $google_account_info->id;
    $picture = $google_account_info->picture;

    // Перевірка, чи існує користувач
    $stmt = $conn->prepare("SELECT * FROM users WHERE google_id = ? OR email = ?");
    $stmt->bind_param("ss", $google_id, $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if ($user) {
        // Якщо користувач існує, але google_id не прив'язаний, оновлюємо його в базі даних
        if (empty($user['google_id'])) {
            $updateStmt = $conn->prepare("UPDATE users SET google_id = ? WHERE id = ?");
            $updateStmt->bind_param("si", $google_id, $user['id']);
            $updateStmt->execute();
        }
        // Логін користувача
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['profile_pic'] = $user['profile_pic'];
        header('Location: dashboard.php');
        exit();
    } else {
        // Якщо користувача з таким е-мейлом не існує, зберігаємо тимчасові дані та переходимо до створення логіна
        $_SESSION['temp_email'] = $email;
        $_SESSION['google_id'] = $google_id;
        $_SESSION['profile_pic'] = $picture;
        header('Location: create_username.php');
        exit();
    }
} catch (Exception $e) {
    exit('Помилка під час отримання інформації про користувача: ' . $e->getMessage());
}
?>