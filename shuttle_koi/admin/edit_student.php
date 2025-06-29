<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_id = $_POST['student_id'] ?? '';
    $name = $_POST['name'] ?? '';
    $email = $_POST['email'] ?? '';
    $phone = $_POST['phone'] ?? '';

    if (empty($student_id) || empty($name) || empty($email)) {
        header('Location: dashboard.php?student_error=missing_fields');
        exit();
    }

    $database = new Database();
    $db = $database->getConnection();
    try {
        $query = "UPDATE Student SET Name = :name, Email = :email, Phone_Number = :phone WHERE S_ID = :student_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':phone', $phone);
        $stmt->bindParam(':student_id', $student_id);
        if ($stmt->execute()) {
            header('Location: dashboard.php?student_success=edited');
        } else {
            header('Location: dashboard.php?student_error=edit_failed');
        }
    } catch (PDOException $e) {
        header('Location: dashboard.php?student_error=database_error');
    }
} else {
    header('Location: dashboard.php');
}
exit(); 