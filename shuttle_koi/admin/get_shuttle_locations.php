<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
require_once '../config/database.php';
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}
$database = new Database();
$db = $database->getConnection();
// Shuttles
$query = "SELECT s.Shuttle_ID, s.Veh_Number, sl.Latitude, sl.Longitude, d.Name as DriverName, d.Status as DriverStatus, d.D_ID, d.Current_Latitude, d.Current_Longitude
          FROM Shuttle s
          LEFT JOIN ShuttleLocation sl ON s.Shuttle_ID = sl.Shuttle_ID AND sl.Location_ID = (
              SELECT MAX(Location_ID) FROM ShuttleLocation WHERE Shuttle_ID = s.Shuttle_ID
          )
          LEFT JOIN Driver_Shuttle ds ON s.Shuttle_ID = ds.Shuttle_ID
          LEFT JOIN Driver d ON ds.D_ID = d.D_ID
          WHERE s.Status = 'Active' AND d.Status = 'On_Trip' AND d.Current_Latitude IS NOT NULL AND d.Current_Longitude IS NOT NULL";
$stmt = $db->prepare($query);
$stmt->execute();
$shuttles = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Stops with queue count
$stop_query = "SELECT s.Stop_ID, s.Name, s.Latitude, s.Longitude, COUNT(c.CheckIn_ID) as QueueCount
               FROM Stop s
               LEFT JOIN Checkin c ON s.Stop_ID = c.Stop_ID AND c.Status = 'Waiting'
               GROUP BY s.Stop_ID, s.Name, s.Latitude, s.Longitude
               ORDER BY s.Name";
$stop_stmt = $db->prepare($stop_query);
$stop_stmt->execute();
$stops = $stop_stmt->fetchAll(PDO::FETCH_ASSOC);
header('Content-Type: application/json');
echo json_encode(['shuttles' => $shuttles, 'stops' => $stops]); 