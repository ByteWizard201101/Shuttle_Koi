<?php
// Start output buffering to prevent any unwanted output
ob_start();

session_start();

// Fix the database include path to work from any context
$config_path = __DIR__ . '/../config/database.php';
require_once $config_path;

// Set error reporting to prevent HTML error messages
error_reporting(0);
ini_set('display_errors', 0);

// Check if user is logged in and is a driver
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'driver') {
    ob_end_clean(); // Clear any output buffer
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

$database = new Database();
$db = $database->getConnection();

$response = ['success' => false, 'message' => ''];

try {
    $driver_id = $_SESSION['user_id'];
    
    // Get driver's assigned shuttle
    $shuttle_query = "SELECT s.Shuttle_ID, s.Veh_Number FROM Shuttle s 
                      JOIN Driver_Shuttle ds ON s.Shuttle_ID = ds.Shuttle_ID 
                      WHERE ds.D_ID = :driver_id 
                      AND s.Status = 'Active'";
    $shuttle_stmt = $db->prepare($shuttle_query);
    $shuttle_stmt->bindParam(':driver_id', $driver_id);
    $shuttle_stmt->execute();
    $assigned_shuttle = $shuttle_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$assigned_shuttle) {
        throw new Exception('No shuttle assigned to driver');
    }
    
    // Get current active trip if any
    $trip_query = "SELECT Trip_ID FROM Trip 
                   WHERE D_ID = :driver_id AND Status = 'In_Progress'";
    $trip_stmt = $db->prepare($trip_query);
    $trip_stmt->bindParam(':driver_id', $driver_id);
    $trip_stmt->execute();
    $active_trip = $trip_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Validate input
    $issue_type = $_POST['issue_type'] ?? '';
    $description = $_POST['description'] ?? '';
    $severity = $_POST['severity'] ?? 'Medium';
    $estimated_cost = $_POST['estimated_cost'] ?? 0;
    
    if (empty($issue_type) || empty($description)) {
        throw new Exception('Please fill in all required fields');
    }
    
    // Validate issue type
    $valid_types = ['Mechanical', 'Electrical', 'Tire', 'Fuel', 'Other'];
    if (!in_array($issue_type, $valid_types)) {
        throw new Exception('Invalid issue type');
    }
    
    // Validate severity
    $valid_severities = ['Low', 'Medium', 'High', 'Critical'];
    if (!in_array($severity, $valid_severities)) {
        throw new Exception('Invalid severity level');
    }
    
    // Insert issue report
    $insert_query = "INSERT INTO ShuttleIssue (Shuttle_ID, D_ID, Trip_ID, Issue_Type, Description, 
                                              Severity, Status, Estimated_Cost) 
                     VALUES (:shuttle_id, :driver_id, :trip_id, :issue_type, :description, 
                             :severity, 'Reported', :estimated_cost)";
    $insert_stmt = $db->prepare($insert_query);
    $insert_stmt->bindParam(':shuttle_id', $assigned_shuttle['Shuttle_ID']);
    $insert_stmt->bindParam(':driver_id', $driver_id);
    $insert_stmt->bindParam(':trip_id', $active_trip['Trip_ID'] ?? null);
    $insert_stmt->bindParam(':issue_type', $issue_type);
    $insert_stmt->bindParam(':description', $description);
    $insert_stmt->bindParam(':severity', $severity);
    $insert_stmt->bindParam(':estimated_cost', $estimated_cost);
    $insert_stmt->execute();
    
    $issue_id = $db->lastInsertId();
    
    // Create notification for admin
    $notification_query = "INSERT INTO Notification (Recipient_type, Type, Message, Status) 
                          VALUES ('Admin', 'SystemAlert', :message, 'Pending')";
    $notification_stmt = $db->prepare($notification_query);
    $message = "Shuttle issue reported: " . $assigned_shuttle['Veh_Number'] . " - " . $issue_type . " (" . $severity . ")";
    $notification_stmt->bindParam(':message', $message);
    $notification_stmt->execute();
    
    // Log activity
    $activity_query = "INSERT INTO ActivityLog (Action, D_ID, Shuttle_ID, Trip_ID, Notes) 
                      VALUES ('ReportIssue', :driver_id, :shuttle_id, :trip_id, :notes)";
    $activity_stmt = $db->prepare($activity_query);
    $activity_stmt->bindParam(':driver_id', $driver_id);
    $activity_stmt->bindParam(':shuttle_id', $assigned_shuttle['Shuttle_ID']);
    $activity_stmt->bindParam(':trip_id', $active_trip['Trip_ID'] ?? null);
    $notes = "Issue reported: " . $issue_type . " - " . $description;
    $activity_stmt->bindParam(':notes', $notes);
    $activity_stmt->execute();
    
    $response = [
        'success' => true,
        'message' => 'Issue reported successfully',
        'issue_id' => $issue_id
    ];
    
} catch (Exception $e) {
    $response = [
        'success' => false,
        'message' => $e->getMessage()
    ];
}

// Clear any output buffer and send JSON response
ob_end_clean();
header('Content-Type: application/json');
echo json_encode($response);
?> 