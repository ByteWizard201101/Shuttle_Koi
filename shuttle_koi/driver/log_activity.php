<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a driver
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'driver') {
    header('Location: ../index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $route_id = $_POST['route_id'] ?? null;
    $notes = $_POST['notes'] ?? '';
    
    if (empty($action)) {
        echo json_encode(['success' => false, 'message' => 'Action is required']);
        exit();
    }

    $database = new Database();
    $db = $database->getConnection();

    try {
        // Get driver's assigned shuttle
        $shuttle_query = "SELECT s.Shuttle_ID FROM Shuttle s 
                         JOIN Driver_Shuttle ds ON s.Shuttle_ID = ds.Shuttle_ID 
                         WHERE ds.D_ID = :driver_id AND s.Status = 'Active'";
        $shuttle_stmt = $db->prepare($shuttle_query);
        $shuttle_stmt->bindParam(':driver_id', $_SESSION['user_id']);
        $shuttle_stmt->execute();
        $shuttle = $shuttle_stmt->fetch(PDO::FETCH_ASSOC);

        // Insert activity log
        $activity_query = "INSERT INTO ActivityLog (Action, D_ID, Route_ID, Shuttle_ID, A_ID, Notes) 
                           VALUES (:action, :driver_id, :route_id, :shuttle_id, 1, :notes)";
        $activity_stmt = $db->prepare($activity_query);
        $activity_stmt->bindParam(':action', $action);
        $activity_stmt->bindParam(':driver_id', $_SESSION['user_id']);
        $activity_stmt->bindParam(':route_id', $route_id);
        $activity_stmt->bindParam(':shuttle_id', $shuttle['Shuttle_ID'] ?? null);
        $activity_stmt->bindParam(':notes', $notes);
        
        if ($activity_stmt->execute()) {
            // Update driver status if starting/ending trip
            if ($action === 'StartTrip') {
                $status_query = "UPDATE Driver SET Status = 'On_Trip' WHERE D_ID = :driver_id";
                $status_stmt = $db->prepare($status_query);
                $status_stmt->bindParam(':driver_id', $_SESSION['user_id']);
                $status_stmt->execute();
            } elseif ($action === 'EndTrip') {
                $status_query = "UPDATE Driver SET Status = 'Active' WHERE D_ID = :driver_id";
                $status_stmt = $db->prepare($status_query);
                $status_stmt->bindParam(':driver_id', $_SESSION['user_id']);
                $status_stmt->execute();
            }

            // Create notification for admin
            $notification_query = "INSERT INTO Notification (Recipient_type, Type, Message, Recipient_id) 
                                  VALUES ('Admin', 'SystemAlert', :message, NULL)";
            $notification_stmt = $db->prepare($notification_query);
            $message = "Driver " . $_SESSION['user_name'] . " performed action: " . $action;
            $notification_stmt->bindParam(':message', $message);
            $notification_stmt->execute();
            
            echo json_encode(['success' => true, 'message' => 'Activity logged successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to log activity']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?> 