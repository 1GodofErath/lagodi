<?php
session_start();
require_once __DIR__ . '/../db.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Доступ заборонено']);
    exit;
}

// Validate CSRF token
if (!isset($_GET['csrf_token']) || $_GET['csrf_token'] !== $_SESSION['csrf_token']) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Недійсний CSRF-токен']);
    exit;
}

// Validate user ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Неправильний ID користувача']);
    exit;
}

$userId = (int)$_GET['id'];
$adminId = $_SESSION['user_id'];
$currentDateTime = date('Y-m-d H:i:s');

try {
    // Start transaction
    $conn->begin_transaction();

    // First, get the username of the user being unblocked
    $userStmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
    $userStmt->bind_param("i", $userId);
    $userStmt->execute();
    $userResult = $userStmt->get_result();

    if ($userResult->num_rows === 0) {
        throw new Exception("Користувача не знайдено");
    }

    $userData = $userResult->fetch_assoc();
    $username = $userData['username'];

    // Update user's blocked status
    $stmt = $conn->prepare("
        UPDATE users 
        SET 
            blocked_until = NULL,
            block_reason = NULL
        WHERE id = ?
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        // Log the unblock action with username
        $logStmt = $conn->prepare("
            INSERT INTO logs 
            (user_id, action, created_at) 
            VALUES 
            (?, ?, ?)
        ");

        $action = "Розблоковано користувача {$username} (ID: {$userId})";
        $logStmt->bind_param("iss", $adminId, $action, $currentDateTime);
        $logStmt->execute();

        // Commit transaction
        $conn->commit();

        // Redirect back to admin dashboard with success message
        $redirectUrl = "/admin_panel/admin_dashboard.php?section=users&message=" .
            urlencode("Користувача {$username} успішно розблоковано") .
            "&status=success";
        header("Location: " . $redirectUrl);
        exit;
    } else {
        throw new Exception("Користувач {$username} вже розблокований");
    }

} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollback();

    // Redirect back with error message
    $redirectUrl = "/admin_panel/admin_dashboard.php?section=users&message=" .
        urlencode("Помилка: " . $e->getMessage()) .
        "&status=error";
    header("Location: " . $redirectUrl);
    exit;
} finally {
    // Close prepared statements
    if (isset($userStmt)) $userStmt->close();
    if (isset($stmt)) $stmt->close();
    if (isset($logStmt)) $logStmt->close();
    $conn->close();
}
?>