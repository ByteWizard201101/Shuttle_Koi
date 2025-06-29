<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_id = $_POST['student_id'] ?? '';
    if (empty($student_id)) {
        header('Location: dashboard.php?student_error=missing_student_id');
        exit();
    }
    $database = new Database();
    $db = $database->getConnection();
    try {
        $query = "DELETE FROM Student WHERE S_ID = :student_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':student_id', $student_id);
        if ($stmt->execute()) {
            header('Location: dashboard.php?student_success=deleted');
        } else {
            header('Location: dashboard.php?student_error=delete_failed');
        }
    } catch (PDOException $e) {
        header('Location: dashboard.php?student_error=database_error');
    }
} else {
    header('Location: dashboard.php');
}
exit(); 