<?php
session_start();
require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_type = $_POST['user_type'] ?? '';
    $name = $_POST['name'] ?? '';
    $email = $_POST['email'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Validation
    if (empty($user_type) || empty($name) || empty($email) || empty($password)) {
        header('Location: ../index.php?signup_error=missing_fields');
        exit();
    }

    if ($password !== $confirm_password) {
        header('Location: ../index.php?signup_error=password_mismatch');
        exit();
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        header('Location: ../index.php?signup_error=invalid_email');
        exit();
    }

    if (strlen($password) < 6) {
        header('Location: ../index.php?signup_error=password_too_short');
        exit();
    }

    $database = new Database();
    $db = $database->getConnection();

    try {
        // Check if email already exists
        $table_name = ($user_type === 'student') ? 'Student' : 'Driver';
        $id_column = ($user_type === 'student') ? 'S_ID' : 'D_ID';
        $check_query = "SELECT COUNT(*) FROM $table_name WHERE Email = :email";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(':email', $email);
        $check_stmt->execute();

        if ($check_stmt->fetchColumn() > 0) {
            header('Location: ../index.php?signup_error=email_exists');
            exit();
        }

        // Hash password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        // Insert new user
        if ($user_type === 'student') {
            $query = "INSERT INTO Student (Name, Email, Phone_Number, Password, A_ID) VALUES (:name, :email, :phone, :password, 1)";
        } else {
            $query = "INSERT INTO Driver (Name, Email, Phone_Number, Password, A_ID) VALUES (:name, :email, :phone, :password, 1)";
        }

        $stmt = $db->prepare($query);
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':phone', $phone);
        $stmt->bindParam(':password', $hashed_password);

        if ($stmt->execute()) {
            // Auto-login after signup
            $user_id = $db->lastInsertId();
            $_SESSION['user_id'] = $user_id;
            $_SESSION['user_type'] = $user_type;
            $_SESSION['user_name'] = $name;
            $_SESSION['user_email'] = $email;

            // Redirect to dashboard
            if ($user_type === 'student') {
                header('Location: ../student/dashboard.php');
            } else {
                header('Location: ../driver/dashboard.php');
            }
            exit();
        } else {
            header('Location: ../index.php?signup_error=registration_failed');
        }
    } catch (PDOException $e) {
        header('Location: ../index.php?signup_error=database_error');
    }
} else {
    header('Location: ../index.php');
}
exit();
?> 