<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a student
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    header('Location: ../index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'boarded') {
        $checkin_id = $_POST['checkin_id'] ?? '';
        if (empty($checkin_id)) {
            echo json_encode(['success' => false, 'message' => 'Missing checkin_id']);
            exit();
        }
        $database = new Database();
        $db = $database->getConnection();
        try {
            $update_query = "UPDATE Checkin SET Status = 'Boarded' WHERE CheckIn_ID = :checkin_id AND S_ID = :student_id AND Status = 'Waiting'";
            $update_stmt = $db->prepare($update_query);
            $update_stmt->bindParam(':checkin_id', $checkin_id);
            $update_stmt->bindParam(':student_id', $_SESSION['user_id']);
            if ($update_stmt->execute() && $update_stmt->rowCount() > 0) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Update failed or already boarded']);
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error']);
        }
        exit();
    }
    $stop_id = $_POST['stop_id'] ?? '';
    
    if (empty($stop_id)) {
        header('Location: dashboard.php?error=missing_stop');
        exit();
    }

    $database = new Database();
    $db = $database->getConnection();

    try {
        // Check if student already has an active check-in
        $active_checkin_query = "SELECT CheckIn_ID FROM Checkin 
                                WHERE S_ID = :student_id 
                                AND Status = 'Waiting' 
                                ORDER BY Timestamp DESC 
                                LIMIT 1";
        $active_stmt = $db->prepare($active_checkin_query);
        $active_stmt->bindParam(':student_id', $_SESSION['user_id']);
        $active_stmt->execute();
        
        if ($active_stmt->rowCount() > 0) {
            header('Location: dashboard.php?error=already_checked_in');
            exit();
        }

        // Create new check-in
        $checkin_query = "INSERT INTO Checkin (S_ID, Stop_ID, Status) VALUES (:student_id, :stop_id, 'Waiting')";
        $checkin_stmt = $db->prepare($checkin_query);
        $checkin_stmt->bindParam(':student_id', $_SESSION['user_id']);
        $checkin_stmt->bindParam(':stop_id', $stop_id);
        
        if ($checkin_stmt->execute()) {
            // Get stop information for notification
            $stop_query = "SELECT Name FROM Stop WHERE Stop_ID = :stop_id";
            $stop_stmt = $db->prepare($stop_query);
            $stop_stmt->bindParam(':stop_id', $stop_id);
            $stop_stmt->execute();
            $stop = $stop_stmt->fetch(PDO::FETCH_ASSOC);
            
            // Create notification for drivers
            $notification_query = "INSERT INTO Notification (Recipient_type, Type, Message, Recipient_id) 
                                  VALUES ('Driver', 'QueueAlert', :message, NULL)";
            $notification_stmt = $db->prepare($notification_query);
            $message = "New student checked in at " . $stop['Name'];
            $notification_stmt->bindParam(':message', $message);
            $notification_stmt->execute();
            
            header('Location: dashboard.php?success=checked_in');
        } else {
            header('Location: dashboard.php?error=checkin_failed');
        }
    } catch (PDOException $e) {
        header('Location: dashboard.php?error=database_error');
    }
} else {
    header('Location: dashboard.php');
}
exit();
?> 