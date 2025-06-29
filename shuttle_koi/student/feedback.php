<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a student
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    header('Location: ../index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rating = $_POST['rating'] ?? '';
    $comment = $_POST['comment'] ?? '';
    
    if (empty($rating) || $rating < 1 || $rating > 5) {
        header('Location: dashboard.php?error=invalid_rating');
        exit();
    }

    $database = new Database();
    $db = $database->getConnection();

    try {
        // Get student's most recent completed trip
        $trip_query = "SELECT c.Shuttle_ID, ds.D_ID 
                       FROM Checkin c 
                       LEFT JOIN Driver_Shuttle ds ON c.Shuttle_ID = ds.Shuttle_ID 
                       WHERE c.S_ID = :student_id 
                       AND c.Status IN ('Boarded', 'Completed') 
                       ORDER BY c.Timestamp DESC 
                       LIMIT 1";
        $trip_stmt = $db->prepare($trip_query);
        $trip_stmt->bindParam(':student_id', $_SESSION['user_id']);
        $trip_stmt->execute();
        $trip = $trip_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Insert feedback
        $feedback_query = "INSERT INTO Feedback (Rating, Comment, S_ID, Shuttle_ID, D_ID) 
                           VALUES (:rating, :comment, :student_id, :shuttle_id, :driver_id)";
        $feedback_stmt = $db->prepare($feedback_query);
        $feedback_stmt->bindParam(':rating', $rating);
        $feedback_stmt->bindParam(':comment', $comment);
        $feedback_stmt->bindParam(':student_id', $_SESSION['user_id']);
        $shuttle_id = $trip['Shuttle_ID'] ?? null;
        $driver_id = $trip['D_ID'] ?? null;
        $feedback_stmt->bindParam(':shuttle_id', $shuttle_id);
        $feedback_stmt->bindParam(':driver_id', $driver_id);
        
        if ($feedback_stmt->execute()) {
            // Create notification for admin
            $notification_query = "INSERT INTO Notification (Recipient_type, Type, Message, Recipient_id) 
                                  VALUES ('Admin', 'FeedbackAlert', :message, NULL)";
            $notification_stmt = $db->prepare($notification_query);
            $message = "New feedback received: " . $rating . " stars";
            $notification_stmt->bindParam(':message', $message);
            $notification_stmt->execute();
            
            header('Location: dashboard.php?success=feedback_submitted');
        } else {
            header('Location: dashboard.php?error=feedback_failed');
        }
    } catch (PDOException $e) {
        header('Location: dashboard.php?error=database_error');
    }
} else {
    header('Location: dashboard.php');
}
exit();
?> 