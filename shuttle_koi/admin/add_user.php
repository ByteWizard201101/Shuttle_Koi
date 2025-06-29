<?php
session_start();
require_once '../config/database.php';

// Only allow admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_type = $_POST['user_type'] ?? '';
    $name = $_POST['name'] ?? '';
    $email = $_POST['email'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $password = $_POST['password'] ?? '';

    // Validation
    if (empty($user_type) || empty($name) || empty($email) || empty($password)) {
        header('Location: dashboard.php?add_user_error=missing_fields');
        exit();
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        header('Location: dashboard.php?add_user_error=invalid_email');
        exit();
    }
    if (strlen($password) < 6) {
        header('Location: dashboard.php?add_user_error=password_too_short');
        exit();
    }

    $database = new Database();
    $db = $database->getConnection();

    try {
        $table_name = ($user_type === 'student') ? 'Student' : 'Driver';
        $check_query = "SELECT COUNT(*) FROM $table_name WHERE Email = :email";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(':email', $email);
        $check_stmt->execute();
        if ($check_stmt->fetchColumn() > 0) {
            header('Location: dashboard.php?add_user_error=email_exists');
            exit();
        }
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        if ($user_type === 'student') {
            $query = "INSERT INTO Student (Name, Email, Phone_Number, Password, A_ID) VALUES (:name, :email, :phone, :password, :admin_id)";
        } else {
            $query = "INSERT INTO Driver (Name, Email, Phone_Number, Password, A_ID) VALUES (:name, :email, :phone, :password, :admin_id)";
        }
        $stmt = $db->prepare($query);
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':phone', $phone);
        $stmt->bindParam(':password', $hashed_password);
        $stmt->bindParam(':admin_id', $_SESSION['user_id']);
        if ($stmt->execute()) {
            header('Location: dashboard.php?add_user_success=1');
        } else {
            header('Location: dashboard.php?add_user_error=creation_failed');
        }
    } catch (PDOException $e) {
        header('Location: dashboard.php?add_user_error=database_error');
    }
} else {
    header('Location: dashboard.php');
}
exit();
?> 