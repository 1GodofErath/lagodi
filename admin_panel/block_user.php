<?php
session_start();
require __DIR__ . '/../db.php';

// Only admin can block users
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die("Доступ заборонено");
}

// Verify CSRF token
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    die("Невірний CSRF токен");
}



// Check if 'blocked_until' is provided and not empty
if (!isset($_POST['blocked_until']) || empty(trim($_POST['blocked_until']))) {
    die("Не вказано дату чи час блокування");
}

$user_id = (int)$_POST['user_id'];
$blocked_until_raw = trim($_POST['blocked_until']);
$reason = $_POST['block_reason'] ?? '';

// Convert the blocked_until value from HTML datetime-local format to MySQL datetime format
$date_time = DateTime::createFromFormat('Y-m-d\TH:i', $blocked_until_raw);
if (!$date_time) {
    die("Невірний формат дати чи часу");
}
$blocked_until = $date_time->format('Y-m-d H:i:s');

try {
    // Update the user's blocked_until and block_reason fields
    $stmt = $conn->prepare("UPDATE users SET blocked_until = ?, block_reason = ? WHERE id = ?");
    if (!$stmt) {
        die("Помилка підготовки запиту: " . $conn->error);
    }
    $stmt->bind_param("ssi", $blocked_until, $reason, $user_id);
    $stmt->execute();

    // Log the blocking action
    $action = "Блокування користувача $user_id до $blocked_until з причини: $reason";
    $log_stmt = $conn->prepare("INSERT INTO logs (user_id, action) VALUES (?, ?)");
    if (!$log_stmt) {
        die("Помилка підготовки лог-запиту: " . $conn->error);
    }
    $log_stmt->bind_param("is", $_SESSION['user_id'], $action);
    $log_stmt->execute();

    header("Location: /admin_panel/admin_dashboard.php?section=users");
    exit();
} catch (Exception $e) {
    die("Помилка: " . $e->getMessage());
}
?>