<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a driver
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'driver') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$database = new Database();
$db = $database->getConnection();

// Get driver's assigned shuttle and routes
$shuttle_query = "SELECT s.Shuttle_ID FROM Shuttle s 
                  JOIN Driver_Shuttle ds ON s.Shuttle_ID = ds.Shuttle_ID 
                  WHERE ds.D_ID = :driver_id AND s.Status = 'Active'";
$shuttle_stmt = $db->prepare($shuttle_query);
$shuttle_stmt->bindParam(':driver_id', $_SESSION['user_id']);
$shuttle_stmt->execute();
$assigned_shuttle = $shuttle_stmt->fetch(PDO::FETCH_ASSOC);

$alerts = [];

if ($assigned_shuttle) {
    // Get routes assigned to the driver's shuttle
    $routes_query = "SELECT r.Route_ID FROM Route r 
                     JOIN Shuttle_Route sr ON r.Route_ID = sr.Route_ID 
                     WHERE sr.Shuttle_ID = :shuttle_id";
    $routes_stmt = $db->prepare($routes_query);
    $routes_stmt->bindParam(':shuttle_id', $assigned_shuttle['Shuttle_ID']);
    $routes_stmt->execute();
    $assigned_routes = $routes_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Check queue counts for stops on assigned routes
    foreach ($assigned_routes as $route) {
        $queue_query = "SELECT s.Stop_ID, s.Name, COUNT(c.CheckIn_ID) as QueueCount
                       FROM Stop s 
                       JOIN Route_Stop rs ON s.Stop_ID = rs.Stop_ID 
                       LEFT JOIN Checkin c ON s.Stop_ID = c.Stop_ID AND c.Status = 'Waiting'
                       WHERE rs.Route_ID = :route_id
                       GROUP BY s.Stop_ID, s.Name
                       HAVING QueueCount > 40";
        $queue_stmt = $db->prepare($queue_query);
        $queue_stmt->bindParam(':route_id', $route['Route_ID']);
        $queue_stmt->execute();
        $high_queues = $queue_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($high_queues as $queue) {
            $alerts[] = [
                'stop_name' => $queue['Name'],
                'queue_count' => $queue['QueueCount'],
                'message' => "High queue alert: {$queue['Name']} has {$queue['QueueCount']} students waiting (threshold: 40)"
            ];
        }
    }
}

header('Content-Type: application/json');
echo json_encode(['alerts' => $alerts]);
?> 