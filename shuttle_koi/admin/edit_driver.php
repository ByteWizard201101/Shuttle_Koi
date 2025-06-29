<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $driver_id = $_POST['driver_id'] ?? '';
    $name = $_POST['name'] ?? '';
    $email = $_POST['email'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $license_number = $_POST['license_number'] ?? '';

    if (empty($driver_id) || empty($name) || empty($email)) {
        header('Location: dashboard.php?driver_error=missing_fields');
        exit();
    }

    $database = new Database();
    $db = $database->getConnection();
    try {
        $query = "UPDATE Driver SET Name = :name, Email = :email, Phone_Number = :phone, License_Number = :license_number WHERE D_ID = :driver_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':phone', $phone);
        $stmt->bindParam(':license_number', $license_number);
        $stmt->bindParam(':driver_id', $driver_id);
        if ($stmt->execute()) {
            header('Location: dashboard.php?driver_success=edited');
        } else {
            header('Location: dashboard.php?driver_error=edit_failed');
        }
    } catch (PDOException $e) {
        header('Location: dashboard.php?driver_error=database_error');
    }
} else {
    header('Location: dashboard.php');
}
exit(); 