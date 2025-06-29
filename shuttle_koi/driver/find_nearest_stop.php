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

if (!$latitude || !$longitude) {
    echo json_encode(['error' => 'Location coordinates required']);
    exit();
}

$database = new Database();
$db = $database->getConnection();

try {
    // Get driver's assigned shuttle and routes
    $shuttle_query = "SELECT s.Shuttle_ID FROM Shuttle s 
                      JOIN Driver_Shuttle ds ON s.Shuttle_ID = ds.Shuttle_ID 
                      WHERE ds.D_ID = :driver_id AND s.Status = 'Active'";
    $shuttle_stmt = $db->prepare($shuttle_query);
    $shuttle_stmt->bindParam(':driver_id', $driver_id);
    $shuttle_stmt->execute();
    $assigned_shuttle = $shuttle_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$assigned_shuttle) {
        echo json_encode(['error' => 'No shuttle assigned']);
        exit();
    }

    // Get stops on assigned routes
    $stops_query = "SELECT s.Stop_ID, s.Name, s.Latitude, s.Longitude, rs.Stop_Order,
                           r.Name as RouteName,
                           (6371 * acos(cos(radians(:latitude)) * cos(radians(s.Latitude)) * 
                           cos(radians(s.Longitude) - radians(:longitude)) + 
                           sin(radians(:latitude)) * sin(radians(s.Latitude)))) AS distance
                    FROM Stop s 
                    JOIN Route_Stop rs ON s.Stop_ID = rs.Stop_ID 
                    JOIN Route r ON rs.Route_ID = r.Route_ID
                    JOIN Shuttle_Route sr ON r.Route_ID = sr.Route_ID
                    WHERE sr.Shuttle_ID = :shuttle_id
                    ORDER BY distance ASC
                    LIMIT 1";
    
    $stops_stmt = $db->prepare($stops_query);
    $stops_stmt->bindParam(':latitude', $latitude);
    $stops_stmt->bindParam(':longitude', $longitude);
    $stops_stmt->bindParam(':shuttle_id', $assigned_shuttle['Shuttle_ID']);
    $stops_stmt->execute();
    $nearest_stop = $stops_stmt->fetch(PDO::FETCH_ASSOC);

    if ($nearest_stop && $nearest_stop['distance'] <= 0.5) { // Within 500 meters
        echo json_encode([
            'success' => true,
            'stop' => [
                'Stop_ID' => $nearest_stop['Stop_ID'],
                'Name' => $nearest_stop['Name'],
                'RouteName' => $nearest_stop['RouteName'],
                'Stop_Order' => $nearest_stop['Stop_Order'],
                'distance' => round($nearest_stop['distance'] * 1000, 0) // Convert to meters
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No nearby stops found']);
    }

} catch (Exception $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?> 