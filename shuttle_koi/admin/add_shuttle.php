<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $veh_number = $_POST['veh_number'] ?? '';
    $capacity = $_POST['capacity'] ?? '';
    $model = $_POST['model'] ?? '';
    $driver_id = $_POST['driver_id'] ?? '';
    $route_id = $_POST['route_id'] ?? '';

    // Validation
    if (empty($veh_number) || empty($capacity)) {
        header('Location: dashboard.php?error=missing_fields');
        exit();
    }

    if (!is_numeric($capacity) || $capacity < 1) {
        header('Location: dashboard.php?error=invalid_capacity');
        exit();
    }

    $database = new Database();
    $db = $database->getConnection();

    try {
        // Check if vehicle number already exists
        $check_query = "SELECT COUNT(*) FROM Shuttle WHERE Veh_Number = :veh_number";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(':veh_number', $veh_number);
        $check_stmt->execute();

        if ($check_stmt->fetchColumn() > 0) {
            header('Location: dashboard.php?error=vehicle_exists');
            exit();
        }

        // Insert new shuttle
        $query = "INSERT INTO Shuttle (Veh_Number, Capacity, Model, A_ID) VALUES (:veh_number, :capacity, :model, :admin_id)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':veh_number', $veh_number);
        $stmt->bindParam(':capacity', $capacity);
        $stmt->bindParam(':model', $model);
        $stmt->bindParam(':admin_id', $_SESSION['user_id']);

        if ($stmt->execute()) {
            $shuttle_id = $db->lastInsertId();
            // Assign driver if selected
            if (!empty($driver_id)) {
                // Check if driver is already assigned to another shuttle
                $check_driver_query = "SELECT COUNT(*) FROM Driver_Shuttle WHERE D_ID = :driver_id";
                $check_driver_stmt = $db->prepare($check_driver_query);
                $check_driver_stmt->bindParam(':driver_id', $driver_id);
                $check_driver_stmt->execute();
                
                if ($check_driver_stmt->fetchColumn() > 0) {
                    // Driver is already assigned, rollback shuttle creation
                    $delete_shuttle = "DELETE FROM Shuttle WHERE Shuttle_ID = :shuttle_id";
                    $delete_stmt = $db->prepare($delete_shuttle);
                    $delete_stmt->bindParam(':shuttle_id', $shuttle_id);
                    $delete_stmt->execute();
                    
                    header('Location: dashboard.php?error=driver_already_assigned');
                    exit();
                }
                
                $assign_query = "INSERT INTO Driver_Shuttle (D_ID, Shuttle_ID, Assigned_At) VALUES (:driver_id, :shuttle_id, NOW())";
                $assign_stmt = $db->prepare($assign_query);
                $assign_stmt->bindParam(':driver_id', $driver_id);
                $assign_stmt->bindParam(':shuttle_id', $shuttle_id);
                $assign_stmt->execute();
            }
            
            // Assign route if selected
            if (!empty($route_id)) {
                $assign_route_query = "INSERT INTO Shuttle_Route (Shuttle_ID, Route_ID) VALUES (:shuttle_id, :route_id)";
                $assign_route_stmt = $db->prepare($assign_route_query);
                $assign_route_stmt->bindParam(':shuttle_id', $shuttle_id);
                $assign_route_stmt->bindParam(':route_id', $route_id);
                $assign_route_stmt->execute();
            }
            
            // Create notification
            $notification_query = "INSERT INTO Notification (Recipient_type, Type, Message, Recipient_id) 
                                  VALUES ('Admin', 'SystemAlert', :message, NULL)";
            $notification_stmt = $db->prepare($notification_query);
            $message = "New shuttle added: " . $veh_number;
            $notification_stmt->bindParam(':message', $message);
            $notification_stmt->execute();
            
            header('Location: dashboard.php?success=shuttle_added');
        } else {
            header('Location: dashboard.php?error=shuttle_creation_failed');
        }
    } catch (PDOException $e) {
        header('Location: dashboard.php?error=database_error');
    }
} else {
    header('Location: dashboard.php');
}
exit();
?> 