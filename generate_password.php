<?php
function generateRandomPassword($length = 8) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $password = '';
    $charsLength = strlen($chars);

    // Переконаємося, що пароль містить хоча б одну цифру
    $password .= $chars[rand(52, 61)]; // Індекси 52-61 - це цифри

    // Додаємо хоча б одну велику літеру
    $password .= $chars[rand(26, 51)]; // Індекси 26-51 - великі літери

    // Додаємо хоча б одну малу літеру
    $password .= $chars[rand(0, 25)]; // Індекси 0-25 - малі літери

    // Додаємо решту символів
    for ($i = strlen($password); $i < $length; $i++) {
        $password .= $chars[rand(0, $charsLength - 1)];
    }

    // Перемішуємо символи
    return str_shuffle($password);
}
?>