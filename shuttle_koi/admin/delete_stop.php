<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stop_id = $_POST['stop_id'] ?? '';
    if (empty($stop_id)) {
        header('Location: dashboard.php?stop_error=missing_stop_id');
        exit();
    }
    $database = new Database();
    $db = $database->getConnection();
    try {
        // Optionally, check for dependencies (e.g., Route_Stop, Checkin, etc.)
        $query = "DELETE FROM Stop WHERE Stop_ID = :stop_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':stop_id', $stop_id);
        if ($stmt->execute()) {
            header('Location: dashboard.php?stop_success=deleted');
        } else {
            header('Location: dashboard.php?stop_error=delete_failed');
        }
    } catch (PDOException $e) {
        header('Location: dashboard.php?stop_error=database_error');
    }
} else {
    header('Location: dashboard.php');
}
exit(); 