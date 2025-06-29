<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a driver
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'driver') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$latitude = $input['latitude'] ?? null;
$longitude = $input['longitude'] ?? null;
$driver_id = $input['driver_id'] ?? $_SESSION['user_id'];
$shuttle_id = $input['shuttle_id'] ?? null;
$trip_status = $input['trip_status'] ?? 'idle';

if (!$latitude || !$longitude || !$shuttle_id) {
    echo json_encode(['success' => false, 'message' => 'Missing required data']);
    exit();
}

$database = new Database();
$db = $database->getConnection();

// Debug logging
file_put_contents(__DIR__ . '/update_location.log', date('Y-m-d H:i:s') . ' INPUT: ' . json_encode($input) . "\n", FILE_APPEND);

try {
    // Insert location update
    $query = "INSERT INTO ShuttleLocation (Shuttle_ID, Latitude, Longitude, Timestamp) 
              VALUES (:shuttle_id, :latitude, :longitude, NOW())";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':shuttle_id', $shuttle_id);
    $stmt->bindParam(':latitude', $latitude);
    $stmt->bindParam(':longitude', $longitude);
    
    if ($stmt->execute()) {
        // Update driver's current location for real-time tracking
        $update_query = "UPDATE Driver SET 
                        Current_Latitude = :latitude, 
                        Current_Longitude = :longitude,
                        Last_Location_Update = NOW()
                        WHERE D_ID = :driver_id";
        $update_stmt = $db->prepare($update_query);
        $update_stmt->bindParam(':latitude', $latitude);
        $update_stmt->bindParam(':longitude', $longitude);
        $update_stmt->bindParam(':driver_id', $driver_id);
        $update_stmt->execute();
        
        if ($trip_status === 'idle') {
            // Clear driver's location when trip ends
            $update_query = "UPDATE Driver SET Current_Latitude = NULL, Current_Longitude = NULL, Last_Location_Update = NULL WHERE D_ID = :driver_id";
            $update_stmt = $db->prepare($update_query);
            $update_stmt->bindParam(':driver_id', $driver_id);
            $update_stmt->execute();
            echo json_encode(['success' => true, 'message' => 'Location cleared']);
            exit();
        }
        
        echo json_encode(['success' => true, 'message' => 'Location updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update location']);
    }
} catch (Exception $e) {
    file_put_contents(__DIR__ . '/update_location.log', date('Y-m-d H:i:s') . ' ERROR: ' . $e->getMessage() . "\n", FILE_APPEND);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?> 