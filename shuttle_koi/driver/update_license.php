<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'driver') {
    header('Location: ../index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $driver_id = $_POST['driver_id'] ?? '';
    $license_number = trim($_POST['license_number'] ?? '');
    if (empty($driver_id) || empty($license_number)) {
        header('Location: dashboard.php?license_error=missing');
        exit();
    }
    $database = new Database();
    $db = $database->getConnection();
    try {
        $update_query = "UPDATE Driver SET License_Number = :license WHERE D_ID = :driver_id";
        $stmt = $db->prepare($update_query);
        $stmt->bindParam(':license', $license_number);
        $stmt->bindParam(':driver_id', $driver_id);
        if ($stmt->execute()) {
            header('Location: dashboard.php?license_success=1');
        } else {
            header('Location: dashboard.php?license_error=update_failed');
        }
    } catch (PDOException $e) {
        header('Location: dashboard.php?license_error=database_error');
    }
} else {
    header('Location: dashboard.php');
}
exit(); 