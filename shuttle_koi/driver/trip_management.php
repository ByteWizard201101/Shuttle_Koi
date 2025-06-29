<?php
// Start output buffering to prevent any unwanted output
ob_start();

// Enable error logging to a file for debugging
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/trip_management_error.log');
error_reporting(E_ALL);
ini_set('display_errors', 0);

session_start();

// Fix the database include path to work from any context
$config_path = __DIR__ . '/../config/database.php';
require_once $config_path;

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

date_default_timezone_set('Asia/Dhaka'); // or your local timezone

try {
    $action = $_POST['action'] ?? '';
    $driver_id = $_SESSION['user_id'];
    
    // Get driver's assigned shuttle
    $shuttle_query = "SELECT s.Shuttle_ID, s.Veh_Number, ds.Assigned_At 
                      FROM Shuttle s 
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
    
    // Get assigned route
    $route_query = "SELECT r.Route_ID, r.Name FROM Route r 
                    JOIN Shuttle_Route sr ON r.Route_ID = sr.Route_ID 
                    WHERE sr.Shuttle_ID = :shuttle_id 
                    LIMIT 1";
    $route_stmt = $db->prepare($route_query);
    $route_stmt->bindParam(':shuttle_id', $assigned_shuttle['Shuttle_ID']);
    $route_stmt->execute();
    $assigned_route = $route_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$assigned_route) {
        throw new Exception('No route assigned to shuttle');
    }
    
    switch ($action) {
        case 'start_trip':
            // Check if there's already an active trip
            $active_trip_query = "SELECT Trip_ID FROM Trip 
                                 WHERE D_ID = :driver_id AND Status = 'In_Progress'";
            $active_trip_stmt = $db->prepare($active_trip_query);
            $active_trip_stmt->bindParam(':driver_id', $driver_id);
            $active_trip_stmt->execute();
            
            if ($active_trip_stmt->fetch()) {
                throw new Exception('You already have an active trip');
            }
            
            // Get current location from ShuttleLocation table
            $location_query = "SELECT Latitude, Longitude FROM ShuttleLocation 
                              WHERE Shuttle_ID = :shuttle_id 
                              ORDER BY Timestamp DESC LIMIT 1";
            $location_stmt = $db->prepare($location_query);
            $location_stmt->bindParam(':shuttle_id', $assigned_shuttle['Shuttle_ID']);
            $location_stmt->execute();
            $current_location = $location_stmt->fetch(PDO::FETCH_ASSOC);
            $start_lat = $current_location['Latitude'] ?? null;
            $start_lng = $current_location['Longitude'] ?? null;
            
            // Start new trip
            $start_trip_query = "INSERT INTO Trip (Shuttle_ID, D_ID, Route_ID, Start_Time, 
                                                 Start_Location_Lat, Start_Location_Lng, Status) 
                                VALUES (:shuttle_id, :driver_id, :route_id, NOW(), 
                                        :start_lat, :start_lng, 'In_Progress')";
            $start_trip_stmt = $db->prepare($start_trip_query);
            $start_trip_stmt->bindParam(':shuttle_id', $assigned_shuttle['Shuttle_ID']);
            $start_trip_stmt->bindParam(':driver_id', $driver_id);
            $start_trip_stmt->bindParam(':route_id', $assigned_route['Route_ID']);
            $start_trip_stmt->bindParam(':start_lat', $start_lat);
            $start_trip_stmt->bindParam(':start_lng', $start_lng);
            $start_trip_stmt->execute();
            
            $trip_id = $db->lastInsertId();
            
            // Log activity
            $activity_query = "INSERT INTO ActivityLog (Action, D_ID, Shuttle_ID, Route_ID, Trip_ID, Notes) 
                              VALUES ('StartTrip', :driver_id, :shuttle_id, :route_id, :trip_id, 'Trip started')";
            $activity_stmt = $db->prepare($activity_query);
            $activity_stmt->bindParam(':driver_id', $driver_id);
            $activity_stmt->bindParam(':shuttle_id', $assigned_shuttle['Shuttle_ID']);
            $activity_stmt->bindParam(':route_id', $assigned_route['Route_ID']);
            $activity_stmt->bindParam(':trip_id', $trip_id);
            $activity_stmt->execute();
            
            // Update driver status
            $update_driver_query = "UPDATE Driver SET Status = 'On_Trip' WHERE D_ID = :driver_id";
            $update_driver_stmt = $db->prepare($update_driver_query);
            $update_driver_stmt->bindParam(':driver_id', $driver_id);
            $update_driver_stmt->execute();
            
            $response = [
                'success' => true, 
                'message' => 'Trip started successfully',
                'trip_id' => $trip_id,
                'action' => 'started'
            ];
            break;
            
        case 'end_trip':
            // Get current active trip
            $active_trip_query = "SELECT Trip_ID, Start_Time FROM Trip 
                                 WHERE D_ID = :driver_id AND Status = 'In_Progress'";
            $active_trip_stmt = $db->prepare($active_trip_query);
            $active_trip_stmt->bindParam(':driver_id', $driver_id);
            $active_trip_stmt->execute();
            $active_trip = $active_trip_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$active_trip) {
                throw new Exception('No active trip found');
            }
            
            // Get current location
            $location_query = "SELECT Latitude, Longitude FROM ShuttleLocation 
                              WHERE Shuttle_ID = :shuttle_id 
                              ORDER BY Timestamp DESC LIMIT 1";
            $location_stmt = $db->prepare($location_query);
            $location_stmt->bindParam(':shuttle_id', $assigned_shuttle['Shuttle_ID']);
            $location_stmt->execute();
            $current_location = $location_stmt->fetch(PDO::FETCH_ASSOC);
            $end_lat = $current_location['Latitude'] ?? null;
            $end_lng = $current_location['Longitude'] ?? null;
            
            // Calculate distance and duration
            $stmt = $db->query("SELECT UNIX_TIMESTAMP() as now");
            $now = $stmt->fetch(PDO::FETCH_ASSOC)['now'];
            $start_timestamp = strtotime($active_trip['Start_Time']);
            $duration_seconds = $now - $start_timestamp;
            $duration_minutes = max(1, round($duration_seconds / 60));
            
            // Get passenger count from checkins during this trip
            $passenger_query = "SELECT COUNT(*) as passenger_count FROM Checkin 
                               WHERE Trip_ID = :trip_id AND Status = 'Boarded'";
            $passenger_stmt = $db->prepare($passenger_query);
            $passenger_stmt->bindParam(':trip_id', $active_trip['Trip_ID']);
            $passenger_stmt->execute();
            $passenger_count = $passenger_stmt->fetch(PDO::FETCH_ASSOC)['passenger_count'];
            
            // Calculate distance (simplified - you can implement more sophisticated distance calculation)
            $distance_km = 0; // This would be calculated based on GPS coordinates
            
            // End trip
            $end_trip_query = "UPDATE Trip SET 
                              End_Time = NOW(),
                              End_Location_Lat = :end_lat,
                              End_Location_Lng = :end_lng,
                              Distance_Km = :distance,
                              Duration_Minutes = :duration,
                              Duration_Seconds = :duration_seconds,
                              Passenger_Count = :passengers,
                              Status = 'Completed'
                              WHERE Trip_ID = :trip_id";
            $end_trip_stmt = $db->prepare($end_trip_query);
            $end_trip_stmt->bindParam(':end_lat', $end_lat);
            $end_trip_stmt->bindParam(':end_lng', $end_lng);
            $end_trip_stmt->bindParam(':distance', $distance_km);
            $end_trip_stmt->bindParam(':duration', $duration_minutes);
            $end_trip_stmt->bindParam(':duration_seconds', $duration_seconds);
            $end_trip_stmt->bindParam(':passengers', $passenger_count);
            $end_trip_stmt->bindParam(':trip_id', $active_trip['Trip_ID']);
            $end_trip_stmt->execute();
            
            // Log activity
            $activity_query = "INSERT INTO ActivityLog (Action, D_ID, Shuttle_ID, Route_ID, Trip_ID, Notes) 
                              VALUES ('EndTrip', :driver_id, :shuttle_id, :route_id, :trip_id, 'Trip completed')";
            $activity_stmt = $db->prepare($activity_query);
            $activity_stmt->bindParam(':driver_id', $driver_id);
            $activity_stmt->bindParam(':shuttle_id', $assigned_shuttle['Shuttle_ID']);
            $activity_stmt->bindParam(':route_id', $assigned_route['Route_ID']);
            $activity_stmt->bindParam(':trip_id', $active_trip['Trip_ID']);
            $activity_stmt->execute();
            
            // Update driver status
            $update_driver_query = "UPDATE Driver SET Status = 'Active' WHERE D_ID = :driver_id";
            $update_driver_stmt = $db->prepare($update_driver_query);
            $update_driver_stmt->bindParam(':driver_id', $driver_id);
            $update_driver_stmt->execute();
            
            $response = [
                'success' => true, 
                'message' => 'Trip ended successfully',
                'trip_id' => $active_trip['Trip_ID'],
                'action' => 'ended',
                'duration' => $duration_minutes,
                'passengers' => $passenger_count
            ];
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    error_log('Trip Management Error: ' . $e->getMessage());
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