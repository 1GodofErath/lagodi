<?php
$servername = "localhost";
$username = "l131113_login_us";
$password = "ki4x03a91wlzdz0zgv";
$dbname = "l131113_login";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Помилка з'єднання: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");
?>
