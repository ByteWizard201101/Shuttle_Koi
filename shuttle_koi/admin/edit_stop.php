<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stop_id = $_POST['stop_id'] ?? '';
    $name = $_POST['name'] ?? '';
    $latitude = $_POST['latitude'] ?? '';
    $longitude = $_POST['longitude'] ?? '';
    $description = $_POST['description'] ?? '';

    if (empty($stop_id) || empty($name) || empty($latitude) || empty($longitude)) {
        header('Location: dashboard.php?stop_error=missing_fields');
        exit();
    }

    $database = new Database();
    $db = $database->getConnection();
    try {
        $query = "UPDATE Stop SET Name = :name, Latitude = :latitude, Longitude = :longitude, Description = :description WHERE Stop_ID = :stop_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':latitude', $latitude);
        $stmt->bindParam(':longitude', $longitude);
        $stmt->bindParam(':description', $description);
        $stmt->bindParam(':stop_id', $stop_id);
        if ($stmt->execute()) {
            header('Location: dashboard.php?stop_success=edited');
        } else {
            header('Location: dashboard.php?stop_error=edit_failed');
        }
    } catch (PDOException $e) {
        header('Location: dashboard.php?stop_error=database_error');
    }
} else {
    header('Location: dashboard.php');
}
exit(); 