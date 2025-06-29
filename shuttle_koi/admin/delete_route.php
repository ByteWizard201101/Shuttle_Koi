<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $route_id = $_POST['route_id'] ?? '';
    if (empty($route_id)) {
        header('Location: dashboard.php?route_error=missing_route_id');
        exit();
    }
    $database = new Database();
    $db = $database->getConnection();
    try {
        // Optionally, check for dependencies (e.g., Route_Stop, ActivityLog, etc.)
        $query = "DELETE FROM Route WHERE Route_ID = :route_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':route_id', $route_id);
        if ($stmt->execute()) {
            header('Location: dashboard.php?route_success=deleted');
        } else {
            header('Location: dashboard.php?route_error=delete_failed');
        }
    } catch (PDOException $e) {
        header('Location: dashboard.php?route_error=database_error');
    }
} else {
    header('Location: dashboard.php');
}
exit();
?> 