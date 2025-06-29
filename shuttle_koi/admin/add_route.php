<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $route_name = $_POST['route_name'] ?? '';
    $description = $_POST['description'] ?? '';

    // Validation
    if (empty($route_name)) {
        header('Location: dashboard.php?error=missing_route_name');
        exit();
    }

    $database = new Database();
    $db = $database->getConnection();

    try {
        // Check if route name already exists
        $check_query = "SELECT COUNT(*) FROM Route WHERE Name = :route_name";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(':route_name', $route_name);
        $check_stmt->execute();

        if ($check_stmt->fetchColumn() > 0) {
            header('Location: dashboard.php?error=route_exists');
            exit();
        }

        // Insert new route
        $query = "INSERT INTO Route (Name, Description, A_ID) VALUES (:route_name, :description, :admin_id)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':route_name', $route_name);
        $stmt->bindParam(':description', $description);
        $stmt->bindParam(':admin_id', $_SESSION['user_id']);

        if ($stmt->execute()) {
            // Create notification
            $notification_query = "INSERT INTO Notification (Recipient_type, Type, Message, Recipient_id) 
                                  VALUES ('Admin', 'SystemAlert', :message, NULL)";
            $notification_stmt = $db->prepare($notification_query);
            $message = "New route added: " . $route_name;
            $notification_stmt->bindParam(':message', $message);
            $notification_stmt->execute();
            
            header('Location: dashboard.php?success=route_added');
        } else {
            header('Location: dashboard.php?error=route_creation_failed');
        }
    } catch (PDOException $e) {
        header('Location: dashboard.php?error=database_error');
    }
} else {
    header('Location: dashboard.php');
}
exit();
?> 