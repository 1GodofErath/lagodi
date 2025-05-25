<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';

function createMailer() {
    $mail = new PHPMailer(true);

    // Налаштування SMTP
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'admin@lagodiy.com';
    $mail->Password = '*k#D.yE.B8snSu<c';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;

    // Налаштування відправника
    $mail->setFrom('no-reply@lagodiy.com', 'Lagodiy Service');
    $mail->isHTML(true);

    return $mail;
}
?>