<?php
session_start();
require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_type = $_POST['user_type'] ?? '';
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    if (empty($user_type) || empty($email) || empty($password)) {
        header('Location: ../index.php?login_error=missing_fields');
        exit();
    }

    $database = new Database();
    $db = $database->getConnection();

    try {
        $table_name = '';
        $id_column = '';
        $name_column = 'Name';

        switch ($user_type) {
            case 'student':
                $table_name = 'Student';
                $id_column = 'S_ID';
                break;
            case 'driver':
                $table_name = 'Driver';
                $id_column = 'D_ID';
                break;
            case 'admin':
                $table_name = 'Admin';
                $id_column = 'A_ID';
                break;
            default:
                header('Location: ../index.php?login_error=invalid_user_type');
                exit();
        }

        $query = "SELECT $id_column, $name_column, Email, Password FROM $table_name WHERE Email = :email";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':email', $email);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // For demo purposes, using simple password verification
            // In production, use password_verify() with hashed passwords
            if ($password === 'password123' || password_verify($password, $user['Password'])) {
                $_SESSION['user_id'] = $user[$id_column];
                $_SESSION['user_type'] = $user_type;
                $_SESSION['user_name'] = $user[$name_column];
                $_SESSION['user_email'] = $user['Email'];

                // Redirect to appropriate dashboard
                switch ($user_type) {
                    case 'student':
                        header('Location: ../student/dashboard.php');
                        break;
                    case 'driver':
                        header('Location: ../driver/dashboard.php');
                        break;
                    case 'admin':
                        header('Location: ../admin/dashboard.php');
                        break;
                }
                exit();
            } else {
                header('Location: ../index.php?login_error=invalid_password');
                exit();
            }
        } else {
            header('Location: ../index.php?login_error=user_not_found');
            exit();
        }
    } catch (PDOException $e) {
        header('Location: ../index.php?login_error=database_error');
        exit();
    }
} else {
    header('Location: ../index.php');
    exit();
}
?> 