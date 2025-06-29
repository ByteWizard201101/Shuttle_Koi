<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'driver') {
    header('Location: ../index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        header('Location: dashboard.php?pw_error=missing_fields');
        exit();
    }
    if ($new_password !== $confirm_password) {
        header('Location: dashboard.php?pw_error=nomatch');
        exit();
    }
    if (strlen($new_password) < 6) {
        header('Location: dashboard.php?pw_error=short');
        exit();
    }

    $database = new Database();
    $db = $database->getConnection();
    $query = "SELECT Password FROM Driver WHERE D_ID = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $_SESSION['user_id']);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !password_verify($current_password, $row['Password'])) {
        header('Location: dashboard.php?pw_error=wrong');
        exit();
    }
    $hashed = password_hash($new_password, PASSWORD_DEFAULT);
    $update = $db->prepare("UPDATE Driver SET Password = :pw WHERE D_ID = :id");
    $update->bindParam(':pw', $hashed);
    $update->bindParam(':id', $_SESSION['user_id']);
    if ($update->execute()) {
        header('Location: dashboard.php?pw_success=1');
    } else {
        header('Location: dashboard.php?pw_error=updatefail');
    }
    exit();
} else {
    header('Location: dashboard.php');
    exit();
} 