<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $route_id = $_POST['route_id'] ?? '';
    $route_name = $_POST['route_name'] ?? '';
    $description = $_POST['description'] ?? '';
    $stops = $_POST['stops'] ?? [];

    if (empty($route_id) || empty($route_name) || empty($stops)) {
        header('Location: dashboard.php?route_error=missing_fields');
        exit();
    }

    $database = new Database();
    $db = $database->getConnection();

    try {
        // Check if another route with the same name exists
        $check_query = "SELECT COUNT(*) FROM Route WHERE Name = :route_name AND Route_ID != :route_id";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(':route_name', $route_name);
        $check_stmt->bindParam(':route_id', $route_id);
        $check_stmt->execute();
        if ($check_stmt->fetchColumn() > 0) {
            header('Location: dashboard.php?route_error=route_exists');
            exit();
        }
        $query = "UPDATE Route SET Name = :route_name, Description = :description WHERE Route_ID = :route_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':route_name', $route_name);
        $stmt->bindParam(':description', $description);
        $stmt->bindParam(':route_id', $route_id);
        if ($stmt->execute()) {
            // Update stops for this route
            $del = $db->prepare("DELETE FROM Route_Stop WHERE Route_ID = :route_id");
            $del->bindParam(':route_id', $route_id);
            $del->execute();
            $ins = $db->prepare("INSERT INTO Route_Stop (Route_ID, Stop_ID, Stop_Order) VALUES (:route_id, :stop_id, :stop_order)");
            foreach ($stops as $order => $stop_id) {
                $ins->bindParam(':route_id', $route_id);
                $ins->bindParam(':stop_id', $stop_id);
                $ins->bindValue(':stop_order', $order + 1, PDO::PARAM_INT);
                $ins->execute();
            }
            header('Location: dashboard.php?route_success=edited');
        } else {
            header('Location: dashboard.php?route_error=edit_failed');
        }
    } catch (PDOException $e) {
        header('Location: dashboard.php?route_error=database_error');
    }
} else {
    header('Location: dashboard.php');
}
exit();
?> 