<?php
session_start();
require __DIR__ . '/../db.php';

if ($_SESSION['role'] !== 'admin') {
    die("Доступ заборонено");
}

if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    die("CSRF помилка");
}

$user_id = $_POST['user_id'];
$username = $_POST['username'];
$role = $_POST['role'];

$stmt = $conn->prepare("UPDATE users SET username = ?, role = ? WHERE id = ?");
$stmt->bind_param("ssi", $username, $role, $user_id);
$stmt->execute();

// Логування
$log_action = "Оновлено користувача #$user_id: новий логін '$username', роль '$role'";
$log_stmt = $conn->prepare("INSERT INTO logs (user_id, action) VALUES (?, ?)");
$log_stmt->bind_param("is", $_SESSION['user_id'], $log_action);
$log_stmt->execute();

header("Location: /admin_panel/admin_dashboard.php?section=users");
