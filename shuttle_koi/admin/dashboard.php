<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

// Get system statistics
$stats_query = "SELECT 
    (SELECT COUNT(*) FROM Student) as total_students,
    (SELECT COUNT(*) FROM Driver) as total_drivers,
    (SELECT COUNT(*) FROM Shuttle WHERE Status = 'Active') as active_shuttles,
    (SELECT COUNT(*) FROM Checkin WHERE Status = 'Waiting') as waiting_students,
    (SELECT COUNT(*) FROM Checkin WHERE Status = 'Boarded') as boarded_students,
    (SELECT AVG(Rating) FROM Feedback) as avg_rating";
$stats_stmt = $db->prepare($stats_query);
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Get recent notifications
$notifications_query = "SELECT * FROM Notification 
                       ORDER BY Timestamp DESC 
                       LIMIT 10";
$notifications_stmt = $db->prepare($notifications_query);
$notifications_stmt->execute();
$recent_notifications = $notifications_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent feedback
$feedback_query = "SELECT f.*, s.Name as StudentName, sh.Veh_Number 
                   FROM Feedback f 
                   JOIN Student s ON f.S_ID = s.S_ID 
                   LEFT JOIN Shuttle sh ON f.Shuttle_ID = sh.Shuttle_ID 
                   ORDER BY f.Timestamp DESC 
                   LIMIT 5";
$feedback_stmt = $db->prepare($feedback_query);
$feedback_stmt->execute();
$recent_feedback = $feedback_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get active shuttles with locations
$shuttles_query = "SELECT s.*, sl.Latitude, sl.Longitude, sl.Timestamp as LastUpdate,
                          d.Name as DriverName
                   FROM Shuttle s 
                   LEFT JOIN ShuttleLocation sl ON s.Shuttle_ID = sl.Shuttle_ID 
                   LEFT JOIN Driver_Shuttle ds ON s.Shuttle_ID = ds.Shuttle_ID 
                   LEFT JOIN Driver d ON ds.D_ID = d.D_ID 
                   WHERE s.Status = 'Active' 
                   AND sl.Location_ID = (
                       SELECT MAX(Location_ID) 
                       FROM ShuttleLocation 
                       WHERE Shuttle_ID = s.Shuttle_ID
                   )";
$shuttles_stmt = $db->prepare($shuttles_query);
$shuttles_stmt->execute();
$active_shuttles = $shuttles_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all routes for management
$routes_query = "SELECT * FROM Route ORDER BY Name";
$routes_stmt = $db->prepare($routes_query);
$routes_stmt->execute();
$all_routes = $routes_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch unassigned drivers for shuttle assignment
$unassigned_drivers_query = "SELECT d.D_ID, d.Name 
                            FROM Driver d 
                            WHERE d.D_ID NOT IN (
                                SELECT DISTINCT ds.D_ID 
                                FROM Driver_Shuttle ds 
                                WHERE ds.D_ID IS NOT NULL
                            )";
$unassigned_drivers_stmt = $db->prepare($unassigned_drivers_query);
$unassigned_drivers_stmt->execute();
$unassigned_drivers = $unassigned_drivers_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all stops for management
$stops_query = "SELECT * FROM Stop ORDER BY Name";
$stops_stmt = $db->prepare($stops_query);
$stops_stmt->execute();
$all_stops = $stops_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all students for management
$students_query = "SELECT * FROM Student ORDER BY Name";
$students_stmt = $db->prepare($students_query);
$students_stmt->execute();
$all_students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all drivers for management
$drivers_query = "SELECT * FROM Driver ORDER BY Name";
$drivers_stmt = $db->prepare($drivers_query);
$drivers_stmt->execute();
$all_drivers = $drivers_stmt->fetchAll(PDO::FETCH_ASSOC);

$route_success = $_GET['route_success'] ?? '';
$route_error = $_GET['route_error'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Shuttle Koi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" />
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
            --accent-color: #e74c3c;
            --success-color: #27ae60;
            --warning-color: #f39c12;
        }

        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .dashboard-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
            overflow: hidden;
        }

        .card-header {
            background: linear-gradient(45deg, var(--secondary-color), var(--primary-color));
            color: white;
            padding: 1rem;
            border: none;
        }

        .btn-custom {
            background: linear-gradient(45deg, var(--secondary-color), var(--primary-color));
            border: none;
            border-radius: 25px;
            color: white;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.4);
            color: white;
        }

        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            text-align: center;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: var(--secondary-color);
        }

        .stat-label {
            color: var(--dark-color);
            font-weight: 500;
            margin-top: 0.5rem;
        }

        .shuttle-item {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 10px;
            border-left: 4px solid var(--secondary-color);
        }

        .notification-item {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 10px;
            margin-bottom: 8px;
            border-left: 4px solid var(--warning-color);
        }

        .feedback-item {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 10px;
        }

        .rating-stars {
            color: #f39c12;
        }

        #map {
            height: 400px;
            border-radius: 15px;
            margin-bottom: 20px;
        }

        .navbar {
            background: rgba(44, 62, 80, 0.9) !important;
            backdrop-filter: blur(10px);
        }

        .welcome-section {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
            color: white;
        }

        /* Custom tooltip styling */
        .custom-tooltip {
            background: rgba(0, 0, 0, 0.8);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 12px;
            font-weight: 500;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
        }

        .custom-tooltip::before {
            border-top-color: rgba(0, 0, 0, 0.8);
        }

        /* Custom div icon styling */
        .custom-div-icon {
            background: transparent;
            border: none;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="fas fa-shuttle-van me-2"></i>Shuttle Koi - Admin
            </a>
            <div class="navbar-nav ms-auto">
                <span class="navbar-text me-3">
                    Welcome, <?php echo htmlspecialchars($_SESSION['user_name']); ?>
                </span>
                <a class="nav-link" href="../auth/logout.php">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <!-- Welcome Section -->
        <div class="welcome-section d-flex justify-content-between align-items-center">
            <div>
                <h2><i class="fas fa-user-shield me-2"></i>Admin Dashboard</h2>
                <p class="mb-0">Monitor system performance, manage users, and oversee shuttle operations.</p>
            </div>
            <div>
                <button id="notificationBell" class="btn btn-link position-relative" style="font-size: 2rem; color: #fff;">
                    <i class="fas fa-bell"></i>
                </button>
                <button id="feedbackStar" class="btn btn-link position-relative" style="font-size: 2rem; color: #fff;">
                    <i class="fas fa-star"></i>
                </button>
            </div>
        </div>

        <!-- Error/Success Messages -->
        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php 
                $error = $_GET['error'];
                switch($error) {
                    case 'driver_already_assigned':
                        echo '<i class="fas fa-exclamation-triangle me-2"></i>Cannot assign driver: Driver is already assigned to another shuttle.';
                        break;
                    case 'vehicle_exists':
                        echo '<i class="fas fa-exclamation-triangle me-2"></i>Vehicle number already exists.';
                        break;
                    case 'missing_fields':
                        echo '<i class="fas fa-exclamation-triangle me-2"></i>Please fill in all required fields.';
                        break;
                    case 'invalid_capacity':
                        echo '<i class="fas fa-exclamation-triangle me-2"></i>Invalid capacity value.';
                        break;
                    default:
                        echo '<i class="fas fa-exclamation-triangle me-2"></i>An error occurred.';
                }
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php 
                $success = $_GET['success'];
                switch($success) {
                    case 'shuttle_added':
                        echo '<i class="fas fa-check-circle me-2"></i>Shuttle added successfully!';
                        break;
                    default:
                        echo '<i class="fas fa-check-circle me-2"></i>Operation completed successfully.';
                }
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['shuttle_error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php 
                $shuttle_error = $_GET['shuttle_error'];
                switch($shuttle_error) {
                    case 'driver_already_assigned':
                        echo '<i class="fas fa-exclamation-triangle me-2"></i>Cannot assign driver: Driver is already assigned to another shuttle.';
                        break;
                    case 'vehicle_exists':
                        echo '<i class="fas fa-exclamation-triangle me-2"></i>Vehicle number already exists.';
                        break;
                    case 'missing_fields':
                        echo '<i class="fas fa-exclamation-triangle me-2"></i>Please fill in all required fields.';
                        break;
                    case 'invalid_capacity':
                        echo '<i class="fas fa-exclamation-triangle me-2"></i>Invalid capacity value.';
                        break;
                    default:
                        echo '<i class="fas fa-exclamation-triangle me-2"></i>An error occurred while updating shuttle.';
                }
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['shuttle_success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>Shuttle updated successfully!
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-lg-2 col-md-4 col-6">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['total_students']; ?></div>
                    <div class="stat-label">Students</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-6">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['total_drivers']; ?></div>
                    <div class="stat-label">Drivers</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-6">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['active_shuttles']; ?></div>
                    <div class="stat-label">Active Shuttles</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-6">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['waiting_students']; ?></div>
                    <div class="stat-label">Waiting</div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-6">
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($stats['avg_rating'], 1); ?></div>
                    <div class="stat-label">Avg Rating</div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- System Overview -->
            <div class="col-lg-8">
                <div class="dashboard-card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-map-marker-alt me-2"></i>System Overview</h5>
                    </div>
                    <div class="card-body">
                        <div id="map"></div>
                        <div class="row">
                            <?php foreach ($active_shuttles as $shuttle): ?>
                            <div class="col-md-6">
                                <div class="shuttle-item">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="mb-1"><?php echo htmlspecialchars($shuttle['Veh_Number']); ?></h6>
                                            <small class="text-muted">
                                                Driver: <?php echo htmlspecialchars($shuttle['DriverName'] ?? 'Unassigned'); ?>
                                            </small>
                                        </div>
                                        <span class="badge bg-success">Active</span>
                                    </div>
                                    <?php if ($shuttle['Latitude'] && $shuttle['Longitude']): ?>
                                    <small class="text-muted">
                                        Last update: <?php echo date('H:i', strtotime($shuttle['LastUpdate'])); ?>
                                    </small>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="col-lg-4">
                <div class="dashboard-card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-cogs me-2"></i>Quick Actions</h5>
                    </div>
                    <div class="card-body">
                        <button class="btn btn-custom w-100 mb-3" data-bs-toggle="modal" data-bs-target="#addUserModal">
                            <i class="fas fa-user-plus me-2"></i>Add User
                        </button>
                        <a href="students.php" class="btn btn-outline-primary w-100 mb-3">
                            <i class="fas fa-user-graduate me-2"></i>Manage Students
                        </a>
                        <a href="drivers.php" class="btn btn-outline-success w-100 mb-3">
                            <i class="fas fa-user-tie me-2"></i>Manage Drivers
                        </a>
                        <button class="btn btn-outline-primary w-100 mb-3" data-bs-toggle="modal" data-bs-target="#addShuttleModal">
                            <i class="fas fa-bus me-2"></i>Add Shuttle
                        </button>
                        <button class="btn btn-outline-success w-100 mb-3" data-bs-toggle="modal" data-bs-target="#addRouteModal">
                            <i class="fas fa-route me-2"></i>Add Route
                        </button>
                        <button class="btn btn-outline-info w-100 mb-3" data-bs-toggle="modal" data-bs-target="#addStopModal">
                            <i class="fas fa-map-pin me-2"></i>Add Stop
                        </button>
                        <button class="btn btn-outline-warning w-100" onclick="generateReport()">
                            <i class="fas fa-chart-bar me-2"></i>Generate Report
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Shuttle Management Section -->
        <div class="dashboard-card mt-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-bus me-2"></i>Shuttle Management</h5>
            </div>
            <div class="card-body">
                <table class="table table-bordered align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Vehicle Number</th>
                            <th>Capacity</th>
                            <th>Model</th>
                            <th>Status</th>
                            <th>Assigned Driver</th>
                            <th>Assigned Route</th>
                            <th style="width: 160px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Fetch all shuttles with driver info
                        $all_shuttles_query = "SELECT s.*, d.Name as DriverName, d.D_ID FROM Shuttle s
                            LEFT JOIN Driver_Shuttle ds ON s.Shuttle_ID = ds.Shuttle_ID
                            LEFT JOIN Driver d ON ds.D_ID = d.D_ID
                            ORDER BY s.Veh_Number";
                        $all_shuttles_stmt = $db->prepare($all_shuttles_query);
                        $all_shuttles_stmt->execute();
                        $all_shuttles = $all_shuttles_stmt->fetchAll(PDO::FETCH_ASSOC);
                        ?>
                        <?php foreach ($all_shuttles as $shuttle): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($shuttle['Veh_Number']); ?></td>
                            <td><?php echo htmlspecialchars($shuttle['Capacity']); ?></td>
                            <td><?php echo htmlspecialchars($shuttle['Model']); ?></td>
                            <td>
                                <span class="badge bg-<?php echo $shuttle['Status'] === 'Active' ? 'success' : ($shuttle['Status'] === 'Maintenance' ? 'warning' : 'secondary'); ?>">
                                    <?php echo htmlspecialchars($shuttle['Status']); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($shuttle['DriverName'] ?? 'Unassigned'); ?></td>
                            <td>
                                <?php
                                // Get assigned route for this shuttle
                                $route_query = "SELECT r.Route_ID, r.Name FROM Route r 
                                               JOIN Shuttle_Route sr ON r.Route_ID = sr.Route_ID 
                                               WHERE sr.Shuttle_ID = :shuttle_id";
                                $route_stmt = $db->prepare($route_query);
                                $route_stmt->bindParam(':shuttle_id', $shuttle['Shuttle_ID']);
                                $route_stmt->execute();
                                $assigned_route = $route_stmt->fetch(PDO::FETCH_ASSOC);
                                echo htmlspecialchars($assigned_route['Name'] ?? 'No Route Assigned');
                                ?>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-primary me-2" data-bs-toggle="modal" data-bs-target="#editShuttleModal<?php echo $shuttle['Shuttle_ID']; ?>">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <button class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#deleteShuttleModal<?php echo $shuttle['Shuttle_ID']; ?>">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </td>
                        </tr>
                        <!-- Edit Shuttle Modal -->
                        <div class="modal fade" id="editShuttleModal<?php echo $shuttle['Shuttle_ID']; ?>" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <form action="edit_shuttle.php" method="POST">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Edit Shuttle</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <input type="hidden" name="shuttle_id" value="<?php echo $shuttle['Shuttle_ID']; ?>">
                                            <div class="mb-3">
                                                <label class="form-label">Vehicle Number</label>
                                                <input type="text" class="form-control" name="veh_number" value="<?php echo htmlspecialchars($shuttle['Veh_Number']); ?>" required>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Capacity</label>
                                                <input type="number" class="form-control" name="capacity" min="1" value="<?php echo htmlspecialchars($shuttle['Capacity']); ?>" required>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Model</label>
                                                <input type="text" class="form-control" name="model" value="<?php echo htmlspecialchars($shuttle['Model']); ?>">
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Status</label>
                                                <select class="form-select" name="status" required>
                                                    <option value="Active" <?php if ($shuttle['Status'] === 'Active') echo 'selected'; ?>>Active</option>
                                                    <option value="Maintenance" <?php if ($shuttle['Status'] === 'Maintenance') echo 'selected'; ?>>Maintenance</option>
                                                    <option value="Inactive" <?php if ($shuttle['Status'] === 'Inactive') echo 'selected'; ?>>Inactive</option>
                                                </select>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Assign Driver</label>
                                                <select class="form-select" name="driver_id">
                                                    <option value="">-- Unassigned --</option>
                                                    <?php
                                                    // Get current driver for this shuttle
                                                    $current_driver_query = "SELECT D_ID FROM Driver_Shuttle WHERE Shuttle_ID = :shuttle_id";
                                                    $current_driver_stmt = $db->prepare($current_driver_query);
                                                    $current_driver_stmt->bindParam(':shuttle_id', $shuttle['Shuttle_ID']);
                                                    $current_driver_stmt->execute();
                                                    $current_driver_id = $current_driver_stmt->fetchColumn();
                                                    
                                                    // Show unassigned drivers
                                                    foreach ($unassigned_drivers as $driver): ?>
                                                        <option value="<?php echo $driver['D_ID']; ?>"><?php echo htmlspecialchars($driver['Name']); ?> (Unassigned)</option>
                                                    <?php endforeach; ?>
                                                    
                                                    <?php
                                                    // Show currently assigned driver if any
                                                    if ($current_driver_id) {
                                                        $current_driver_query = "SELECT D_ID, Name FROM Driver WHERE D_ID = :driver_id";
                                                        $current_driver_stmt = $db->prepare($current_driver_query);
                                                        $current_driver_stmt->bindParam(':driver_id', $current_driver_id);
                                                        $current_driver_stmt->execute();
                                                        $current_driver = $current_driver_stmt->fetch(PDO::FETCH_ASSOC);
                                                        if ($current_driver): ?>
                                                            <option value="<?php echo $current_driver['D_ID']; ?>" selected><?php echo htmlspecialchars($current_driver['Name']); ?> (Currently Assigned)</option>
                                                        <?php endif;
                                                    }
                                                    ?>
                                                </select>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Assign Route</label>
                                                <select class="form-select" name="route_id">
                                                    <option value="">-- No Route --</option>
                                                    <?php
                                                    // Get current route for this shuttle
                                                    $current_route_query = "SELECT Route_ID FROM Shuttle_Route WHERE Shuttle_ID = :shuttle_id";
                                                    $current_route_stmt = $db->prepare($current_route_query);
                                                    $current_route_stmt->bindParam(':shuttle_id', $shuttle['Shuttle_ID']);
                                                    $current_route_stmt->execute();
                                                    $current_route_id = $current_route_stmt->fetchColumn();
                                                    
                                                    // Show all routes
                                                    foreach ($all_routes as $route): ?>
                                                        <option value="<?php echo $route['Route_ID']; ?>" <?php if ($current_route_id == $route['Route_ID']) echo 'selected'; ?>><?php echo htmlspecialchars($route['Name']); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-primary">Save Changes</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <!-- Delete Shuttle Modal -->
                        <div class="modal fade" id="deleteShuttleModal<?php echo $shuttle['Shuttle_ID']; ?>" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <form action="delete_shuttle.php" method="POST">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Delete Shuttle</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <input type="hidden" name="shuttle_id" value="<?php echo $shuttle['Shuttle_ID']; ?>">
                                            <p>Are you sure you want to delete the shuttle <strong><?php echo htmlspecialchars($shuttle['Veh_Number']); ?></strong>?</p>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-danger">Delete</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Route Management Section -->
        <div class="dashboard-card mt-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-route me-2"></i>Route Management</h5>
            </div>
            <div class="card-body">
                <?php if ($route_success): ?>
                    <div class="alert alert-success mt-3">
                        <?php
                        switch ($route_success) {
                            case 'edited':
                                echo 'Route updated successfully!';
                                break;
                            case 'deleted':
                                echo 'Route deleted successfully!';
                                break;
                            default:
                                echo 'Success!';
                        }
                        ?>
                    </div>
                <?php endif; ?>
                <?php if ($route_error): ?>
                    <div class="alert alert-danger mt-3">
                        <?php
                        switch ($route_error) {
                            case 'missing_fields':
                                echo 'Please fill in all required fields.';
                                break;
                            case 'route_exists':
                                echo 'A route with this name already exists.';
                                break;
                            case 'edit_failed':
                                echo 'Failed to update route.';
                                break;
                            case 'delete_failed':
                                echo 'Failed to delete route.';
                                break;
                            case 'database_error':
                                echo 'A database error occurred.';
                                break;
                            default:
                                echo 'An error occurred.';
                        }
                        ?>
                    </div>
                <?php endif; ?>
                <table class="table table-bordered align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>Description</th>
                            <th>Stops</th>
                            <th style="width: 160px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($all_routes as $route): ?>
                        <?php
                        // Fetch stops for this route
                        $route_stops_query = "SELECT s.Stop_ID, s.Name FROM Route_Stop rs JOIN Stop s ON rs.Stop_ID = s.Stop_ID WHERE rs.Route_ID = :route_id ORDER BY rs.Stop_Order";
                        $rs_stmt = $db->prepare($route_stops_query);
                        $rs_stmt->bindParam(':route_id', $route['Route_ID']);
                        $rs_stmt->execute();
                        $route_stops = $rs_stmt->fetchAll(PDO::FETCH_ASSOC);
                        $stop_names = array_map(function($s) { return $s['Name']; }, $route_stops);
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($route['Name']); ?></td>
                            <td><?php echo htmlspecialchars($route['Description']); ?></td>
                            <td><?php echo htmlspecialchars(implode(', ', $stop_names)); ?></td>
                            <td>
                                <button class="btn btn-sm btn-primary me-2" data-bs-toggle="modal" data-bs-target="#editRouteModal<?php echo $route['Route_ID']; ?>">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <button class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#deleteRouteModal<?php echo $route['Route_ID']; ?>">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </td>
                        </tr>
                        <!-- Edit Route Modal -->
                        <div class="modal fade" id="editRouteModal<?php echo $route['Route_ID']; ?>" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <form action="edit_route.php" method="POST">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Edit Route</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <input type="hidden" name="route_id" value="<?php echo $route['Route_ID']; ?>">
                                            <div class="mb-3">
                                                <label class="form-label">Route Name</label>
                                                <input type="text" class="form-control" name="route_name" value="<?php echo htmlspecialchars($route['Name']); ?>" required>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Description</label>
                                                <textarea class="form-control" name="description" rows="3"><?php echo htmlspecialchars($route['Description']); ?></textarea>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Stops (Drag to reorder)</label>
                                                <select class="form-select" name="stops[]" id="stopsSelect<?php echo $route['Route_ID']; ?>" multiple required>
                                                    <?php foreach ($all_stops as $stop): ?>
                                                        <option value="<?php echo $stop['Stop_ID']; ?>" <?php if (in_array($stop['Stop_ID'], array_column($route_stops, 'Stop_ID'))) echo 'selected'; ?>><?php echo htmlspecialchars($stop['Name']); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <small class="text-muted">Hold Ctrl (Cmd on Mac) to select multiple. Drag to reorder after selecting.</small>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-primary">Save Changes</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <!-- Delete Route Modal -->
                        <div class="modal fade" id="deleteRouteModal<?php echo $route['Route_ID']; ?>" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <form action="delete_route.php" method="POST">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Delete Route</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <input type="hidden" name="route_id" value="<?php echo $route['Route_ID']; ?>">
                                            <p>Are you sure you want to delete the route <strong><?php echo htmlspecialchars($route['Name']); ?></strong>?</p>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-danger">Delete</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Stop Management Section -->
        <div class="dashboard-card mt-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-map-pin me-2"></i>Stop Management</h5>
            </div>
            <div class="card-body">
                <table class="table table-bordered align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>Latitude</th>
                            <th>Longitude</th>
                            <th style="width: 160px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($all_stops as $stop): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($stop['Name']); ?></td>
                            <td><?php echo htmlspecialchars($stop['Latitude']); ?></td>
                            <td><?php echo htmlspecialchars($stop['Longitude']); ?></td>
                            <td>
                                <button class="btn btn-sm btn-primary me-2" data-bs-toggle="modal" data-bs-target="#editStopModal<?php echo $stop['Stop_ID']; ?>">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <button class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#deleteStopModal<?php echo $stop['Stop_ID']; ?>">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </td>
                        </tr>
                        <!-- Edit Stop Modal -->
                        <div class="modal fade" id="editStopModal<?php echo $stop['Stop_ID']; ?>" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <form action="edit_stop.php" method="POST">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Edit Stop</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <input type="hidden" name="stop_id" value="<?php echo $stop['Stop_ID']; ?>">
                                            <div class="mb-3">
                                                <label class="form-label">Stop Name</label>
                                                <input type="text" class="form-control" name="name" value="<?php echo htmlspecialchars($stop['Name']); ?>" required>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Select Location on Map</label>
                                                <div id="editStopMap<?php echo $stop['Stop_ID']; ?>" style="height: 300px; border-radius: 10px;"></div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">Latitude</label>
                                                    <input type="text" class="form-control" id="editStopLat<?php echo $stop['Stop_ID']; ?>" name="latitude" value="<?php echo htmlspecialchars($stop['Latitude']); ?>" required readonly>
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">Longitude</label>
                                                    <input type="text" class="form-control" id="editStopLng<?php echo $stop['Stop_ID']; ?>" name="longitude" value="<?php echo htmlspecialchars($stop['Longitude']); ?>" required readonly>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-primary">Save Changes</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <!-- Delete Stop Modal -->
                        <div class="modal fade" id="deleteStopModal<?php echo $stop['Stop_ID']; ?>" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <form action="delete_stop.php" method="POST">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Delete Stop</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <input type="hidden" name="stop_id" value="<?php echo $stop['Stop_ID']; ?>">
                                            <p>Are you sure you want to delete the stop <strong><?php echo htmlspecialchars($stop['Name']); ?></strong>?</p>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-danger">Delete</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Add User Modal -->
        <div class="modal fade" id="addUserModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Add New User</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <form id="addUserForm" action="add_user.php" method="POST">
                            <div class="mb-3">
                                <label class="form-label">User Type</label>
                                <select class="form-select" name="user_type" required>
                                    <option value="">Select user type...</option>
                                    <option value="student">Student</option>
                                    <option value="driver">Driver</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Full Name</label>
                                <input type="text" class="form-control" name="name" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" name="email" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Phone Number</label>
                                <input type="tel" class="form-control" name="phone">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Password</label>
                                <input type="password" class="form-control" name="password" required>
                            </div>
                            <button type="submit" class="btn btn-custom w-100">Add User</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add Shuttle Modal -->
        <div class="modal fade" id="addShuttleModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Add New Shuttle</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <form id="addShuttleForm" action="add_shuttle.php" method="POST">
                            <div class="mb-3">
                                <label class="form-label">Vehicle Number</label>
                                <input type="text" class="form-control" name="veh_number" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Capacity</label>
                                <input type="number" class="form-control" name="capacity" min="1" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Model</label>
                                <input type="text" class="form-control" name="model">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Assign Driver (optional)</label>
                                <select class="form-select" name="driver_id">
                                    <option value="">-- Unassigned --</option>
                                    <?php foreach ($unassigned_drivers as $driver): ?>
                                        <option value="<?php echo $driver['D_ID']; ?>"><?php echo htmlspecialchars($driver['Name']); ?> (Unassigned)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Assign Route (optional)</label>
                                <select class="form-select" name="route_id">
                                    <option value="">-- No Route --</option>
                                    <?php foreach ($all_routes as $route): ?>
                                        <option value="<?php echo $route['Route_ID']; ?>"><?php echo htmlspecialchars($route['Name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-custom w-100">Add Shuttle</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add Route Modal -->
        <div class="modal fade" id="addRouteModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Add New Route</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <form id="addRouteForm" action="add_route.php" method="POST">
                            <div class="mb-3">
                                <label class="form-label">Route Name</label>
                                <input type="text" class="form-control" name="route_name" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Description</label>
                                <textarea class="form-control" name="description" rows="3"></textarea>
                            </div>
                            <button type="submit" class="btn btn-custom w-100">Add Route</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add Stop Modal -->
        <div class="modal fade" id="addStopModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form action="add_stop.php" method="POST">
                        <div class="modal-header">
                            <h5 class="modal-title">Add New Stop</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Stop Name</label>
                                <input type="text" class="form-control" name="name" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Select Location on Map</label>
                                <div id="addStopMap" style="height: 300px; border-radius: 10px;"></div>
                                <small class="text-muted">Click anywhere on the map to place the stop location. Red dots show existing stops.</small>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Latitude</label>
                                    <input type="text" class="form-control" id="addStopLat" name="latitude" required readonly>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Longitude</label>
                                    <input type="text" class="form-control" id="addStopLng" name="longitude" required readonly>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-success">Add Stop</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Add Notification Modal -->
        <div class="modal fade" id="notificationModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-bell me-2"></i>All Notifications</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <?php if (empty($recent_notifications)): ?>
                            <p class="text-muted">No notifications</p>
                        <?php else: ?>
                            <?php foreach ($recent_notifications as $notification): ?>
                                <div class="notification-item mb-2">
                                    <small class="fw-bold"><?php echo ucfirst($notification['Type']); ?></small><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($notification['Message']); ?></small><br>
                                    <small class="text-muted"><?php echo date('M j, H:i', strtotime($notification['Timestamp'])); ?></small>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add Feedback Modal -->
        <div class="modal fade" id="feedbackModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-star me-2"></i>All Feedback</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <?php if (empty($recent_feedback)): ?>
                            <p class="text-muted">No feedback</p>
                        <?php else: ?>
                            <?php foreach ($recent_feedback as $feedback): ?>
                                <div class="feedback-item mb-2">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h6 class="mb-1"><?php echo htmlspecialchars($feedback['StudentName']); ?></h6>
                                            <div class="rating-stars">
                                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                                    <i class="fas fa-star<?php echo $i <= $feedback['Rating'] ? '' : '-o'; ?>"></i>
                                                <?php endfor; ?>
                                            </div>
                                        </div>
                                        <small class="text-muted"><?php echo date('M j', strtotime($feedback['Timestamp'])); ?></small>
                                    </div>
                                    <?php if ($feedback['Comment']): ?>
                                        <p class="mb-0 mt-2"><small><?php echo htmlspecialchars($feedback['Comment']); ?></small></p>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Generate Report Modal -->
        <div class="modal fade" id="generateReportModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-chart-bar me-2"></i>Generate Shuttle Report</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <form id="reportForm" action="generate_report_simple.php" method="GET" target="_blank">
                            <div class="mb-3">
                                <label class="form-label">Select Shuttle (Optional)</label>
                                <select class="form-select" name="shuttle_id">
                                    <option value="">All Shuttles</option>
                                    <?php foreach ($all_shuttles as $shuttle): ?>
                                        <option value="<?php echo $shuttle['Shuttle_ID']; ?>">
                                            <?php echo htmlspecialchars($shuttle['Veh_Number']); ?> - <?php echo htmlspecialchars($shuttle['Model']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Leave empty to generate report for all shuttles</small>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Start Date</label>
                                        <input type="date" class="form-control" name="start_date" value="<?php echo date('Y-m-d', strtotime('-30 days')); ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">End Date</label>
                                        <input type="date" class="form-control" name="end_date" value="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                </div>
                            </div>
                            <div class="mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="includeTrips" checked>
                                    <label class="form-check-label" for="includeTrips">
                                        Include Trip Details
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="includeIssues" checked>
                                    <label class="form-check-label" for="includeIssues">
                                        Include Shuttle Issues
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="includeFeedback" checked>
                                    <label class="form-check-label" for="includeFeedback">
                                        Include Feedback Summary
                                    </label>
                                </div>
                            </div>
                        </form>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary" onclick="submitReport()">
                            <i class="fas fa-download me-2"></i>Generate Report
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
    <script>
    // System Overview Map (Leaflet) - Real-time shuttle locations
    let map, shuttleMarkers = {}, stopMarkers = {};
    function initShuttleMap() {
        map = L.map('map').setView([23.8103, 90.4125], 13); // Default center
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors'
        }).addTo(map);
        loadShuttleMarkers();
        setInterval(loadShuttleMarkers, 15000); // Refresh every 15s
    }
    function loadShuttleMarkers() {
        fetch('get_shuttle_locations.php')
            .then(res => res.json())
            .then(data => {
                // Remove old shuttle markers only
                for (const id in shuttleMarkers) {
                    map.removeLayer(shuttleMarkers[id]);
                }
                shuttleMarkers = {};
                // Add shuttle/driver markers (green bus icon)
                if (data.shuttles) {
                    data.shuttles.forEach(shuttle => {
                        if (shuttle.Current_Latitude && shuttle.Current_Longitude) {
                            const busIcon = L.divIcon({
                                className: 'custom-div-icon',
                                html: '<div style="background-color: #27ae60; width: 28px; height: 28px; border-radius: 50%; border: 3px solid white; box-shadow: 0 2px 8px rgba(0,0,0,0.3); display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 16px;">🚌</div>',
                                iconSize: [28, 28],
                                iconAnchor: [14, 14]
                            });
                            const marker = L.marker([shuttle.Current_Latitude, shuttle.Current_Longitude], {icon: busIcon}).addTo(map);
                            marker.bindTooltip(`<b>${shuttle.DriverName || 'Driver'}</b><br>Vehicle: ${shuttle.Veh_Number}`);
                            shuttleMarkers[shuttle.D_ID] = marker;
                        }
                    });
                }
                // Only update stop markers if needed (do not remove them here)
                if (data.stops) {
                    data.stops.forEach(stop => {
                        if (!stopMarkers[stop.Stop_ID] && stop.Latitude && stop.Longitude) {
                            const redIcon = L.divIcon({
                                className: 'custom-div-icon',
                                html: '<div style="background-color: #e74c3c; width: 22px; height: 22px; border-radius: 50%; border: 2px solid white; box-shadow: 0 2px 4px rgba(0,0,0,0.3);"></div>',
                                iconSize: [22, 22],
                                iconAnchor: [11, 11]
                            });
                            const marker = L.marker([stop.Latitude, stop.Longitude], {icon: redIcon}).addTo(map);
                            marker.bindTooltip(`<b>${stop.Name}</b><br>Queue: <b>${stop.QueueCount}</b> students`, {
                                permanent: false,
                                direction: 'top',
                                className: 'custom-tooltip'
                            });
                            marker.bindPopup(`<b>${stop.Name}</b><br>Queue Count: <b>${stop.QueueCount}</b> students`);
                            stopMarkers[stop.Stop_ID] = marker;
                        }
                    });
                }
            })
            .catch(error => {
                console.error('Error loading shuttle/stop data:', error);
            });
    }
    document.addEventListener('DOMContentLoaded', initShuttleMap);
    
    // Add Stop Map (Leaflet)
    let addStopMap, addStopMarker;
    document.getElementById('addStopModal').addEventListener('shown.bs.modal', function () {
        if (!addStopMap) {
            addStopMap = L.map('addStopMap').setView([23.8103, 90.4125], 13); // Default center (Dhaka)
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors'
            }).addTo(addStopMap);
            
            // Add click event to place marker
            addStopMap.on('click', function(e) {
                if (addStopMarker) addStopMap.removeLayer(addStopMarker);
                addStopMarker = L.marker(e.latlng).addTo(addStopMap);
                document.getElementById('addStopLat').value = e.latlng.lat.toFixed(7);
                document.getElementById('addStopLng').value = e.latlng.lng.toFixed(7);
            });
            
            // Show existing stops on the map
            fetch('get_shuttle_locations.php')
                .then(res => res.json())
                .then(data => {
                    if (data.stops) {
                        data.stops.forEach(stop => {
                            if (stop.Latitude && stop.Longitude) {
                                // Create red marker icon for existing stops
                                const redIcon = L.divIcon({
                                    className: 'custom-div-icon',
                                    html: '<div style="background-color: #e74c3c; width: 16px; height: 16px; border-radius: 50%; border: 2px solid white; box-shadow: 0 2px 4px rgba(0,0,0,0.3);"></div>',
                                    iconSize: [16, 16],
                                    iconAnchor: [8, 8]
                                });
                                
                                const marker = L.marker([stop.Latitude, stop.Longitude], {icon: redIcon}).addTo(addStopMap);
                                marker.bindTooltip(`<b>${stop.Name}</b><br>Existing Stop`, {
                                    permanent: false,
                                    direction: 'top',
                                    className: 'custom-tooltip'
                                });
                            }
                        });
                    }
                })
                .catch(error => {
                    console.error('Error loading existing stops:', error);
                });
        }
    });
    
    // Clear form when modal is hidden
    document.getElementById('addStopModal').addEventListener('hidden.bs.modal', function () {
        // Clear form fields
        document.getElementById('addStopModal').querySelector('form').reset();
        // Remove marker if exists
        if (addStopMarker && addStopMap) {
            addStopMap.removeLayer(addStopMarker);
            addStopMarker = null;
        }
    });
    
    // Edit Stop Maps (Leaflet)
    <?php foreach ($all_stops as $stop): ?>
    let editStopMap<?php echo $stop['Stop_ID']; ?>, editStopMarker<?php echo $stop['Stop_ID']; ?>;
    document.getElementById('editStopModal<?php echo $stop['Stop_ID']; ?>').addEventListener('shown.bs.modal', function () {
        if (!editStopMap<?php echo $stop['Stop_ID']; ?>) {
            editStopMap<?php echo $stop['Stop_ID']; ?> = L.map('editStopMap<?php echo $stop['Stop_ID']; ?>').setView([<?php echo floatval($stop['Latitude']); ?>, <?php echo floatval($stop['Longitude']); ?>], 15);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors'
            }).addTo(editStopMap<?php echo $stop['Stop_ID']; ?>);
            editStopMarker<?php echo $stop['Stop_ID']; ?> = L.marker([<?php echo floatval($stop['Latitude']); ?>, <?php echo floatval($stop['Longitude']); ?>]).addTo(editStopMap<?php echo $stop['Stop_ID']; ?>);
            editStopMap<?php echo $stop['Stop_ID']; ?>.on('click', function(e) {
                if (editStopMarker<?php echo $stop['Stop_ID']; ?>) editStopMap<?php echo $stop['Stop_ID']; ?>.removeLayer(editStopMarker<?php echo $stop['Stop_ID']; ?>);
                editStopMarker<?php echo $stop['Stop_ID']; ?> = L.marker(e.latlng).addTo(editStopMap<?php echo $stop['Stop_ID']; ?>);
                document.getElementById('editStopLat<?php echo $stop['Stop_ID']; ?>').value = e.latlng.lat.toFixed(7);
                document.getElementById('editStopLng<?php echo $stop['Stop_ID']; ?>').value = e.latlng.lng.toFixed(7);
            });
        }
    });
    <?php endforeach; ?>

    // Generate Report function
    function generateReport() {
        var modal = new bootstrap.Modal(document.getElementById('generateReportModal'));
        modal.show();
    }
    
    // Submit Report function
    function submitReport() {
        var form = document.getElementById('reportForm');
        var formData = new FormData(form);
        
        // Build URL with parameters
        var url = 'generate_report_simple.php?';
        var params = [];
        for (var pair of formData.entries()) {
            if (pair[1]) { // Only add non-empty values
                params.push(pair[0] + '=' + encodeURIComponent(pair[1]));
            }
        }
        url += params.join('&');
        
        // Open report in new tab
        window.open(url, '_blank');
        
        // Close modal
        var modal = bootstrap.Modal.getInstance(document.getElementById('generateReportModal'));
        modal.hide();
    }

    document.getElementById('notificationBell').addEventListener('click', function() {
        var modal = new bootstrap.Modal(document.getElementById('notificationModal'));
        modal.show();
    });

    document.getElementById('feedbackStar').addEventListener('click', function() {
        var modal = new bootstrap.Modal(document.getElementById('feedbackModal'));
        modal.show();
    });
    </script>
</body>
</html> 