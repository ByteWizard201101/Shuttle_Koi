<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $shuttle_id = $_POST['shuttle_id'] ?? '';
    $veh_number = $_POST['veh_number'] ?? '';
    $capacity = $_POST['capacity'] ?? '';
    $model = $_POST['model'] ?? '';
    $status = $_POST['status'] ?? '';
    $driver_id = $_POST['driver_id'] ?? '';
    $route_id = $_POST['route_id'] ?? '';

    if (empty($shuttle_id) || empty($veh_number) || empty($capacity) || empty($status)) {
        header('Location: dashboard.php?shuttle_error=missing_fields');
        exit();
    }
    if (!is_numeric($capacity) || $capacity < 1) {
        header('Location: dashboard.php?shuttle_error=invalid_capacity');
        exit();
    }

    $database = new Database();
    $db = $database->getConnection();
    try {
        // Check for duplicate vehicle number (exclude current shuttle)
        $check_query = "SELECT COUNT(*) FROM Shuttle WHERE Veh_Number = :veh_number AND Shuttle_ID != :shuttle_id";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(':veh_number', $veh_number);
        $check_stmt->bindParam(':shuttle_id', $shuttle_id);
        $check_stmt->execute();
        if ($check_stmt->fetchColumn() > 0) {
            header('Location: dashboard.php?shuttle_error=vehicle_exists');
            exit();
        }
        // Update shuttle details
        $update_query = "UPDATE Shuttle SET Veh_Number = :veh_number, Capacity = :capacity, Model = :model, Status = :status WHERE Shuttle_ID = :shuttle_id";
        $update_stmt = $db->prepare($update_query);
        $update_stmt->bindParam(':veh_number', $veh_number);
        $update_stmt->bindParam(':capacity', $capacity);
        $update_stmt->bindParam(':model', $model);
        $update_stmt->bindParam(':status', $status);
        $update_stmt->bindParam(':shuttle_id', $shuttle_id);
        $update_stmt->execute();

        // Handle driver assignment
        // Get current assignment
        $current_driver_query = "SELECT D_ID FROM Driver_Shuttle WHERE Shuttle_ID = :shuttle_id";
        $current_driver_stmt = $db->prepare($current_driver_query);
        $current_driver_stmt->bindParam(':shuttle_id', $shuttle_id);
        $current_driver_stmt->execute();
        $current_driver = $current_driver_stmt->fetchColumn();

        if (!empty($driver_id)) {
            if ($current_driver != $driver_id) {
                // Check if new driver is already assigned to another shuttle
                $check_driver_query = "SELECT COUNT(*) FROM Driver_Shuttle WHERE D_ID = :driver_id AND Shuttle_ID != :shuttle_id";
                $check_driver_stmt = $db->prepare($check_driver_query);
                $check_driver_stmt->bindParam(':driver_id', $driver_id);
                $check_driver_stmt->bindParam(':shuttle_id', $shuttle_id);
                $check_driver_stmt->execute();
                
                if ($check_driver_stmt->fetchColumn() > 0) {
                    header('Location: dashboard.php?shuttle_error=driver_already_assigned');
                    exit();
                }
                
                // Remove previous assignment if exists
                $delete_assignment = "DELETE FROM Driver_Shuttle WHERE Shuttle_ID = :shuttle_id";
                $del_stmt = $db->prepare($delete_assignment);
                $del_stmt->bindParam(':shuttle_id', $shuttle_id);
                $del_stmt->execute();
                // Assign new driver
                $assign_query = "INSERT INTO Driver_Shuttle (D_ID, Shuttle_ID, Assigned_At) VALUES (:driver_id, :shuttle_id, NOW())";
                $assign_stmt = $db->prepare($assign_query);
                $assign_stmt->bindParam(':driver_id', $driver_id);
                $assign_stmt->bindParam(':shuttle_id', $shuttle_id);
                $assign_stmt->execute();
                // Send notification to driver
                $notification_query = "INSERT INTO Notification (Recipient_type, Type, Message, Recipient_id) VALUES ('Driver', 'SystemAlert', :message, :recipient_id)";
                $notification_stmt = $db->prepare($notification_query);
                $message = "You have been assigned to shuttle: " . $veh_number;
                $notification_stmt->bindParam(':message', $message);
                $notification_stmt->bindParam(':recipient_id', $driver_id);
                $notification_stmt->execute();
            }
        } else {
            // Unassign driver if any
            if ($current_driver) {
                $delete_assignment = "DELETE FROM Driver_Shuttle WHERE Shuttle_ID = :shuttle_id";
                $del_stmt = $db->prepare($delete_assignment);
                $del_stmt->bindParam(':shuttle_id', $shuttle_id);
                $del_stmt->execute();
            }
        }
        
        // Handle route assignment
        // Get current route assignment
        $current_route_query = "SELECT Route_ID FROM Shuttle_Route WHERE Shuttle_ID = :shuttle_id";
        $current_route_stmt = $db->prepare($current_route_query);
        $current_route_stmt->bindParam(':shuttle_id', $shuttle_id);
        $current_route_stmt->execute();
        $current_route = $current_route_stmt->fetchColumn();

        if (!empty($route_id)) {
            if ($current_route != $route_id) {
                // Remove previous route assignment if exists
                $delete_route = "DELETE FROM Shuttle_Route WHERE Shuttle_ID = :shuttle_id";
                $del_route_stmt = $db->prepare($delete_route);
                $del_route_stmt->bindParam(':shuttle_id', $shuttle_id);
                $del_route_stmt->execute();
                
                // Assign new route
                $assign_route_query = "INSERT INTO Shuttle_Route (Shuttle_ID, Route_ID) VALUES (:shuttle_id, :route_id)";
                $assign_route_stmt = $db->prepare($assign_route_query);
                $assign_route_stmt->bindParam(':shuttle_id', $shuttle_id);
                $assign_route_stmt->bindParam(':route_id', $route_id);
                $assign_route_stmt->execute();
            }
        } else {
            // Unassign route if any
            if ($current_route) {
                $delete_route = "DELETE FROM Shuttle_Route WHERE Shuttle_ID = :shuttle_id";
                $del_route_stmt = $db->prepare($delete_route);
                $del_route_stmt->bindParam(':shuttle_id', $shuttle_id);
                $del_route_stmt->execute();
            }
        }
        
        header('Location: dashboard.php?shuttle_success=edited');
    } catch (PDOException $e) {
        header('Location: dashboard.php?shuttle_error=database_error');
    }
} else {
    header('Location: dashboard.php');
}
exit(); 