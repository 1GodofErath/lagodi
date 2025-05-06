<?php
session_start();
require __DIR__ . '/../db.php';

if ($_SESSION['role'] !== 'admin') {
    die("Доступ заборонено");
}

if (!isset($_GET['csrf_token']) || $_GET['csrf_token'] !== $_SESSION['csrf_token']) {
    die("CSRF помилка");
}

$user_id = $_GET['id'];

// Отримання інформації про користувача перед видаленням для логу
$stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

$del_stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
$del_stmt->bind_param("i", $user_id);
$del_stmt->execute();

// Логування
$log_action = "Видалено користувача #$user_id: " . $user['username'];
$log_stmt = $conn->prepare("INSERT INTO logs (user_id, action) VALUES (?, ?)");
$log_stmt->bind_param("is", $_SESSION['user_id'], $log_action);
$log_stmt->execute();

header("Location: /admin_panel/admin_dashboard.php#users");