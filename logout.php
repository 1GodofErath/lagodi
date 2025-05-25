<?php
session_start();
require_once __DIR__.'/db.php';

try {
    if (isset($_GET['timeout'])) {
        cleanupIncompleteRegistration();
    }
} catch (Exception $e) {
    error_log("Logout error: " . $e->getMessage());
}

session_unset();
session_destroy();
header('Location: index.php');
exit();

function cleanupIncompleteRegistration() {
    global $conn;
    if (!empty($_SESSION['temp_email'])) {
        $conn->begin_transaction();
        $stmt = $conn->prepare("DELETE FROM users WHERE email = ? AND email_verified = 0");
        $stmt->bind_param("s", $_SESSION['temp_email']);
        $stmt->execute();
        $conn->commit();
    }
}
?>