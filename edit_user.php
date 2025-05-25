<?php
session_start();
require 'db.php';

// Перевірка прав
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Отримання ID користувача
$user_id = $_GET['id'] ?? null;

// Отримання даних користувача
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// Обробка форми
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_role = $_POST['role'];
    $stmt = $conn->prepare("UPDATE users SET role = ? WHERE id = ?");
    $stmt->bind_param("si", $new_role, $user_id);
    $stmt->execute();
    header("Location: admin_dashboard.php");
}
?>

<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <title>Редагування користувача</title>
    <link rel="stylesheet" href="style/dash.css">
</head>
<body>
<div class="container">
    <h1>Редагування користувача <?= htmlspecialchars($user['username']) ?></h1>
    <form method="POST">
        <label>Роль:</label>
        <select name="role">
            <option value="user" <?= $user['role'] === 'user' ? 'selected' : '' ?>>Користувач</option>
            <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Адміністратор</option>
        </select>
        <button type="submit" class="btn">Зберегти</button>
    </form>
</div>
</body>
</html>