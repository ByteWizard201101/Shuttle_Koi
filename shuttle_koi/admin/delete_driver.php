<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $driver_id = $_POST['driver_id'] ?? '';
    if (empty($driver_id)) {
        header('Location: dashboard.php?driver_error=missing_driver_id');
        exit();
    }
    $database = new Database();
    $db = $database->getConnection();
    try {
        $query = "DELETE FROM Driver WHERE D_ID = :driver_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':driver_id', $driver_id);
        if ($stmt->execute()) {
            header('Location: dashboard.php?driver_success=deleted');
        } else {
            header('Location: dashboard.php?driver_error=delete_failed');
        }
    } catch (PDOException $e) {
        header('Location: dashboard.php?driver_error=database_error');
    }
} else {
    header('Location: dashboard.php');
}
exit(); 