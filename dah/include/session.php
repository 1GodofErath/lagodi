<?php
/**
 * Lagodi Service - Керування сесіями
 * Версія: 1.0.0
 * Дата останнього оновлення: 2025-04-27 12:26:01
 * Автор: 1GodofErath
 */

// Підключення файлу з базою даних
require_once $_SERVER['DOCUMENT_ROOT'] . '/dah/confi/database.php'; // Виправлено шлях до папки confi

// Початок сесії тільки якщо вона ще не активна
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Встановлення часу життя сесії (в секундах)
// 2 години = 7200 секунд
$session_lifetime = 7200;

// Перевірка, чи потрібно оновити сесію
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $session_lifetime)) {
    // Сесія застаріла, виконуємо вихід
    session_unset();
    session_destroy();

    // Перенаправлення на сторінку входу здійснюємо через JavaScript, щоб уникнути проблем з headers already sent
    echo '<script>window.location.href = "/login.php?session_expired=1";</script>';
    exit;
}

// Оновлення часу останньої активності
$_SESSION['last_activity'] = time();

// Перевірка, чи потрібно оновити ідентифікатор сесії
if (!isset($_SESSION['created'])) {
    $_SESSION['created'] = time();
} else if (time() - $_SESSION['created'] > 1800) {
    // Оновлення ідентифікатора сесії кожні 30 хвилин
    session_regenerate_id(true);
    $_SESSION['created'] = time();
}

// Функція для оновлення часу останньої активності користувача в базі даних
function updateUserLastActive($user_id) {
    $database = new Database();
    $db = $database->getConnection();

    $query = "UPDATE users SET last_active = NOW() WHERE id = :user_id";
    $stmt = $db->prepare($query);

    $stmt->bindParam(':user_id', $user_id);

    return $stmt->execute();
}

// Функція для примусового завершення сесії (без перенаправлення)
function forceSessionLogout() {
    session_unset();
    session_destroy();

    return true;
}

// Оновлення часу останньої активності для авторизованого користувача
if (isset($_SESSION['user_id'])) {
    updateUserLastActive($_SESSION['user_id']);

    // Перевірка, чи не заблоковано користувача
    $database = new Database();
    $db = $database->getConnection();

    $query = "SELECT is_blocked, blocked_until FROM users WHERE id = :user_id";
    $stmt = $db->prepare($query);

    $stmt->bindParam(':user_id', $_SESSION['user_id']);
    $stmt->execute();

    $user = $stmt->fetch();

    if ($user && $user['is_blocked'] == 1) {
        // Користувач заблокований, виконуємо вихід
        forceSessionLogout();
        // Перенаправлення здійснюємо через JavaScript
        echo '<script>window.location.href = "/login.php?blocked=1";</script>';
        exit;
    }

    if ($user && $user['blocked_until'] !== null) {
        $now = new DateTime();
        $blockedUntil = new DateTime($user['blocked_until']);

        if ($now < $blockedUntil) {
            // Користувач тимчасово заблокований, виконуємо вихід
            forceSessionLogout();
            // Перенаправлення здійснюємо через JavaScript
            echo '<script>window.location.href = "/login.php?temp_blocked=1";</script>';
            exit;
        }
    }
}
?>