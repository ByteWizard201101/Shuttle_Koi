<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a driver
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'driver') {
    header('Location: ../index.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

// Get driver's assigned shuttle
$shuttle_query = "SELECT s.*, ds.Assigned_At 
                  FROM Shuttle s 
                  JOIN Driver_Shuttle ds ON s.Shuttle_ID = ds.Shuttle_ID 
                  WHERE ds.D_ID = :driver_id 
                  AND s.Status = 'Active'";
$shuttle_stmt = $db->prepare($shuttle_query);
$shuttle_stmt->bindParam(':driver_id', $_SESSION['user_id']);
$shuttle_stmt->execute();
$assigned_shuttle = $shuttle_stmt->fetch(PDO::FETCH_ASSOC);

// Fetch route(s) for the assigned shuttle
$assigned_routes = [];
if ($assigned_shuttle) {
    $route_query = "SELECT r.* FROM Route r JOIN Shuttle_Route sr ON r.Route_ID = sr.Route_ID WHERE sr.Shuttle_ID = :shuttle_id";
    $route_stmt = $db->prepare($route_query);
    $route_stmt->bindParam(':shuttle_id', $assigned_shuttle['Shuttle_ID']);
    $route_stmt->execute();
    $assigned_routes = $route_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get current queue counts at stops
$queue_query = "SELECT s.Stop_ID, s.Name, s.Latitude, s.Longitude, 
                       COUNT(c.CheckIn_ID) as QueueCount
                FROM Stop s 
                LEFT JOIN Checkin c ON s.Stop_ID = c.Stop_ID 
                AND c.Status = 'Waiting'
                GROUP BY s.Stop_ID, s.Name, s.Latitude, s.Longitude
                HAVING QueueCount > 0
                ORDER BY QueueCount DESC";
$queue_stmt = $db->prepare($queue_query);
$queue_stmt->execute();
$queue_data = $queue_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get route-specific queue data for assigned routes
$route_queue_data = [];
if (!empty($assigned_routes)) {
    foreach ($assigned_routes as $route) {
        $route_stops_query = "SELECT s.Stop_ID, s.Name, s.Latitude, s.Longitude, 
                                    COUNT(c.CheckIn_ID) as QueueCount,
                                    rs.Stop_Order
                             FROM Stop s 
                             JOIN Route_Stop rs ON s.Stop_ID = rs.Stop_ID 
                             LEFT JOIN Checkin c ON s.Stop_ID = c.Stop_ID AND c.Status = 'Waiting'
                             WHERE rs.Route_ID = :route_id
                             GROUP BY s.Stop_ID, s.Name, s.Latitude, s.Longitude, rs.Stop_Order
                             ORDER BY rs.Stop_Order";
        $route_stops_stmt = $db->prepare($route_stops_query);
        $route_stops_stmt->bindParam(':route_id', $route['Route_ID']);
        $route_stops_stmt->execute();
        $route_queue_data[$route['Route_ID']] = [
            'route_name' => $route['Name'],
            'stops' => $route_stops_stmt->fetchAll(PDO::FETCH_ASSOC)
        ];
    }
}

// Check for queue alerts (queue count > 40) and create notifications
$alert_threshold = 40;
foreach ($queue_data as $queue) {
    if ($queue['QueueCount'] > $alert_threshold) {
        // Check if alert already exists for this stop and driver
        $existing_alert_query = "SELECT Alert_ID FROM QueueAlert 
                                WHERE Stop_ID = :stop_id AND D_ID = :driver_id AND Status = 'Active'";
        $existing_alert_stmt = $db->prepare($existing_alert_query);
        $existing_alert_stmt->bindParam(':stop_id', $queue['Stop_ID']);
        $existing_alert_stmt->bindParam(':driver_id', $_SESSION['user_id']);
        $existing_alert_stmt->execute();
        
        if (!$existing_alert_stmt->fetch()) {
            // Create new alert
            $alert_query = "INSERT INTO QueueAlert (QueueCount, Threshold, Stop_ID, D_ID, Status) 
                           VALUES (:queue_count, :threshold, :stop_id, :driver_id, 'Active')";
            $alert_stmt = $db->prepare($alert_query);
            $alert_stmt->bindParam(':queue_count', $queue['QueueCount']);
            $alert_stmt->bindParam(':threshold', $alert_threshold);
            $alert_stmt->bindParam(':stop_id', $queue['Stop_ID']);
            $alert_stmt->bindParam(':driver_id', $_SESSION['user_id']);
            $alert_stmt->execute();
            
            // Create notification
            $notification_query = "INSERT INTO Notification (Recipient_type, Type, Message, Recipient_id) 
                                  VALUES ('Driver', 'QueueAlert', :message, :driver_id)";
            $notification_stmt = $db->prepare($notification_query);
            $message = "High queue alert: " . $queue['Name'] . " has " . $queue['QueueCount'] . " students waiting (threshold: " . $alert_threshold . ")";
            $notification_stmt->bindParam(':message', $message);
            $notification_stmt->bindParam(':driver_id', $_SESSION['user_id']);
            $notification_stmt->execute();
        }
    }
}

// Get driver's recent notifications
$notifications_query = "SELECT * FROM Notification 
                       WHERE Recipient_type = 'Driver' AND Recipient_id = :driver_id 
                       ORDER BY Timestamp DESC 
                       LIMIT 5";
$notifications_stmt = $db->prepare($notifications_query);
$notifications_stmt->bindParam(':driver_id', $_SESSION['user_id']);
$notifications_stmt->execute();
$recent_notifications = $notifications_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get driver's recent activity
$activity_query = "SELECT al.*, s.Name as StopName, r.Name as RouteName 
                   FROM ActivityLog al 
                   LEFT JOIN Stop s ON al.Stop_ID = s.Stop_ID 
                   LEFT JOIN Route r ON al.Route_ID = r.Route_ID 
                   WHERE al.D_ID = :driver_id 
                   ORDER BY al.Timestamp DESC 
                   LIMIT 10";
$activity_stmt = $db->prepare($activity_query);
$activity_stmt->bindParam(':driver_id', $_SESSION['user_id']);
$activity_stmt->execute();
$recent_activity = $activity_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get routes
$routes_query = "SELECT * FROM Route ORDER BY Name";
$routes_stmt = $db->prepare($routes_query);
$routes_stmt->execute();
$routes = $routes_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get driver's profile
$profile_query = "SELECT * FROM Driver WHERE D_ID = :driver_id";
$profile_stmt = $db->prepare($profile_query);
$profile_stmt->bindParam(':driver_id', $_SESSION['user_id']);
$profile_stmt->execute();
$driver_profile = $profile_stmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Driver Dashboard - Shuttle Koi</title>
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

        .queue-item {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 10px;
            border-left: 4px solid var(--warning-color);
        }

        .queue-high {
            border-left-color: var(--accent-color);
        }

        .queue-medium {
            border-left-color: var(--warning-color);
        }

        .status-badge {
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .status-active { background: var(--success-color); color: white; }
        .status-inactive { background: var(--primary-color); color: white; }
        .status-on-trip { background: var(--warning-color); color: white; }

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

        .shuttle-info {
            background: linear-gradient(45deg, var(--success-color), var(--secondary-color));
            color: white;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="fas fa-shuttle-van me-2"></i>Shuttle Koi
            </a>
            <div class="navbar-nav ms-auto">
                <span class="navbar-text me-3">
                    Welcome, <?php echo htmlspecialchars($_SESSION['user_name']); ?>
                </span>
                <a class="nav-link" href="#" data-bs-toggle="modal" data-bs-target="#changePasswordModal">
                    <i class="fas fa-key"></i> Change Password
                </a>
                <a class="nav-link" href="../auth/logout.php">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <?php if (isset($_GET['pw_success'])): ?>
            <div class="alert alert-success">Password changed successfully!</div>
        <?php elseif (isset($_GET['pw_error'])): ?>
            <?php
            $msg = 'An error occurred.';
            switch ($_GET['pw_error']) {
                case 'missing_fields': $msg = 'Please fill in all fields.'; break;
                case 'nomatch': $msg = 'New passwords do not match.'; break;
                case 'short': $msg = 'New password must be at least 6 characters.'; break;
                case 'wrong': $msg = 'Current password is incorrect.'; break;
                case 'updatefail': $msg = 'Failed to update password.'; break;
            }
            ?>
            <div class="alert alert-danger"><?php echo $msg; ?></div>
        <?php endif; ?>
        <!-- License Number Modal -->
        <?php if (empty($driver_profile['License_Number'])): ?>
        <div class="modal fade show" id="licenseModal" tabindex="-1" style="display:block; background:rgba(0,0,0,0.5);" aria-modal="true" role="dialog">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form action="update_license.php" method="POST">
                        <div class="modal-header">
                            <h5 class="modal-title">Enter Your Driving License Number</h5>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Driving License Number</label>
                                <input type="text" class="form-control" name="license_number" required maxlength="50">
                                <input type="hidden" name="driver_id" value="<?php echo $_SESSION['user_id']; ?>">
                            </div>
                            <div class="alert alert-warning">You must provide your license number to use the dashboard.</div>
                        </div>
                        <div class="modal-footer">
                            <button type="submit" class="btn btn-primary">Save</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <script>
        document.body.classList.add('modal-open');
        </script>
        <?php endif; ?>
        <!-- Welcome Section -->
        <div class="welcome-section">
            <h2><i class="fas fa-user-tie me-2"></i>Driver Dashboard</h2>
            <p class="mb-0">Manage your route, track queue counts, and log activities.</p>
        </div>

        <!-- Change Password Modal -->
        <div class="modal fade" id="changePasswordModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form action="change_password.php" method="POST">
                        <div class="modal-header">
                            <h5 class="modal-title">Change Password</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Current Password</label>
                                <input type="password" class="form-control" name="current_password" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">New Password</label>
                                <input type="password" class="form-control" name="new_password" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Confirm New Password</label>
                                <input type="password" class="form-control" name="confirm_password" required>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Change Password</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Shuttle Information -->
        <?php if ($assigned_shuttle): ?>
        <div class="shuttle-info">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h4><i class="fas fa-bus me-2"></i><?php echo htmlspecialchars($assigned_shuttle['Veh_Number']); ?></h4>
                    <p class="mb-1">Model: <?php echo htmlspecialchars($assigned_shuttle['Model']); ?></p>
                    <p class="mb-0">Capacity: <?php echo $assigned_shuttle['Capacity']; ?> passengers</p>
                </div>
                <div class="col-md-6 text-end">
                    <div class="row">
                        <div class="col-6">
                            <button class="btn btn-success w-100 mb-2" id="tripToggleBtn" onclick="toggleTrip()">
                                <i class="fas fa-play me-2"></i>Start Trip
                            </button>
                        </div>
                        <div class="col-6">
                            <button class="btn btn-warning w-100 mb-2" onclick="reportIssue()">
                                <i class="fas fa-exclamation-triangle me-2"></i>Report Issue
                            </button>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-12">
                            <div id="tripStatus" class="alert alert-info mt-2" style="display: none;">
                                <i class="fas fa-info-circle me-2"></i>
                                <span id="tripStatusText">Trip Status</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php if (!empty($assigned_routes)): ?>
            <hr>
            <h5>Assigned Route(s):</h5>
            <ul>
                <?php foreach ($assigned_routes as $route): ?>
                    <li><strong><?php echo htmlspecialchars($route['Name']); ?></strong>: <?php echo htmlspecialchars($route['Description']); ?></li>
                <?php endforeach; ?>
            </ul>
            <?php else: ?>
            <p class="mt-3"><em>No route assigned to your shuttle yet.</em></p>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>
            No shuttle assigned. Please contact administrator.
        </div>
        <?php endif; ?>

        <!-- Add map container at the top of the main content area -->
        <div class="dashboard-card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-map-marker-alt me-2"></i>Stop Queue Map</h5>
            </div>
            <div class="card-body">
                <div id="driverMap" style="height: 400px; border-radius: 15px; width: 100%; min-width: 100%; min-height: 400px;"></div>
            </div>
        </div>

        <div class="row">
            <!-- Queue Management -->
            <div class="col-lg-8">
                <div class="dashboard-card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-users me-2"></i>Route Queue Management</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($route_queue_data)): ?>
                            <?php foreach ($route_queue_data as $route_id => $route_info): ?>
                            <div class="mb-4">
                                <h6 class="text-primary mb-3">
                                    <i class="fas fa-route me-2"></i><?php echo htmlspecialchars($route_info['route_name']); ?>
                                </h6>
                                <div class="row">
                                    <?php foreach ($route_info['stops'] as $stop): ?>
                                    <div class="col-md-6 mb-3">
                                        <div class="queue-item <?php echo $stop['QueueCount'] > 40 ? 'queue-high' : ($stop['QueueCount'] > 20 ? 'queue-medium' : ''); ?>">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <h6 class="mb-1">
                                                        <span class="badge bg-secondary me-2"><?php echo $stop['Stop_Order']; ?></span>
                                                        <?php echo htmlspecialchars($stop['Name']); ?>
                                                    </h6>
                                                    <small class="text-muted">Stop #<?php echo $stop['Stop_Order']; ?> on route</small>
                                                </div>
                                                <div class="text-end">
                                                    <span class="badge <?php echo $stop['QueueCount'] > 40 ? 'bg-danger' : ($stop['QueueCount'] > 20 ? 'bg-warning' : 'bg-primary'); ?> fs-6">
                                                        <?php echo $stop['QueueCount']; ?>
                                                    </span>
                                                    <?php if ($stop['QueueCount'] > 40): ?>
                                                    <div class="mt-1">
                                                        <small class="text-danger">
                                                            <i class="fas fa-exclamation-triangle"></i> Alert!
                                                        </small>
                                                    </div>
                                                    <?php elseif ($stop['QueueCount'] > 20): ?>
                                                    <div class="mt-1">
                                                        <small class="text-warning">
                                                            <i class="fas fa-exclamation-circle"></i> Growing
                                                        </small>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="fas fa-route fa-3x text-muted mb-3"></i>
                                <p class="text-muted">No routes assigned to your shuttle yet.</p>
                            </div>
                        <?php endif; ?>
                        
                        <!-- All Stops Queue Summary -->
                        <?php if (!empty($queue_data)): ?>
                        <hr>
                        <h6 class="text-secondary mb-3">
                            <i class="fas fa-globe me-2"></i>All Stops Queue Summary
                        </h6>
                        <div class="row">
                            <?php foreach ($queue_data as $queue): ?>
                            <div class="col-md-6">
                                <div class="queue-item <?php echo $queue['QueueCount'] > 40 ? 'queue-high' : ''; ?>">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="mb-1"><?php echo htmlspecialchars($queue['Name']); ?></h6>
                                            <small class="text-muted">Queue Count</small>
                                        </div>
                                        <div class="text-end">
                                            <span class="badge bg-primary fs-6"><?php echo $queue['QueueCount']; ?></span>
                                            <?php if ($queue['QueueCount'] > 40): ?>
                                            <div class="mt-1">
                                                <small class="text-danger">
                                                    <i class="fas fa-exclamation-triangle"></i> High Queue
                                                </small>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="col-lg-4">
                <!-- Recent Activity -->
                <div class="dashboard-card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-history me-2"></i>Recent Activity</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($recent_activity)): ?>
                            <p class="text-muted">No recent activity</p>
                        <?php else: ?>
                            <?php foreach ($recent_activity as $activity): ?>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div>
                                    <small class="fw-bold"><?php echo ucfirst(str_replace('_', ' ', $activity['Action'])); ?></small><br>
                                    <small class="text-muted">
                                        <?php echo $activity['StopName'] ?: $activity['RouteName'] ?: 'General'; ?>
                                    </small>
                                </div>
                                <small class="text-muted"><?php echo date('H:i', strtotime($activity['Timestamp'])); ?></small>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Notifications -->
                <div class="dashboard-card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-bell me-2"></i>Recent Notifications</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($recent_notifications)): ?>
                            <p class="text-muted">No recent notifications</p>
                        <?php else: ?>
                            <?php foreach ($recent_notifications as $notification): ?>
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div class="flex-grow-1">
                                    <div class="d-flex align-items-center mb-1">
                                        <i class="fas fa-<?php echo $notification['Type'] === 'QueueAlert' ? 'exclamation-triangle text-danger' : 'info-circle text-primary'; ?> me-2"></i>
                                        <small class="fw-bold text-uppercase"><?php echo $notification['Type']; ?></small>
                                    </div>
                                    <small class="text-muted"><?php echo htmlspecialchars($notification['Message']); ?></small>
                                </div>
                                <small class="text-muted"><?php echo date('H:i', strtotime($notification['Timestamp'])); ?></small>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Report Issue Modal -->
    <div class="modal fade" id="reportIssueModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i>Report Shuttle Issue</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="issueForm">
                        <div class="mb-3">
                            <label class="form-label">Issue Type *</label>
                            <select class="form-select" name="issue_type" required>
                                <option value="">Select Issue Type</option>
                                <option value="Mechanical">Mechanical</option>
                                <option value="Electrical">Electrical</option>
                                <option value="Tire">Tire</option>
                                <option value="Fuel">Fuel</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description *</label>
                            <textarea class="form-control" name="description" rows="3" placeholder="Describe the issue in detail..." required></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Severity</label>
                            <select class="form-select" name="severity">
                                <option value="Low">Low - Minor issue, can continue operation</option>
                                <option value="Medium" selected>Medium - Moderate issue, needs attention</option>
                                <option value="High">High - Serious issue, may affect operation</option>
                                <option value="Critical">Critical - Major issue, immediate attention required</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Estimated Cost ($)</label>
                            <input type="number" class="form-control" name="estimated_cost" min="0" step="0.01" placeholder="0.00">
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-warning" onclick="submitIssue()">
                        <i class="fas fa-paper-plane me-2"></i>Submit Report
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
    <script>
        let currentTripStatus = 'idle', currentLocation = null, locationUpdateIntervalId;
        let atStop = false, isPaused = false;

        function toggleTrip() {
            if (currentTripStatus === 'idle') {
                startTrip();
            } else {
                endTrip();
            }
        }

        function startTrip() {
            fetch('trip_management.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=start_trip'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    currentTripStatus = 'active';
                    updateTripButtons();
                    startLocationTracking();
                    showNotification('Trip started successfully!', 'success');
                } else {
                    showNotification(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error starting trip:', error);
                showNotification('Failed to start trip', 'error');
            });
        }

        function endTrip() {
            fetch('trip_management.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=end_trip'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    currentTripStatus = 'idle';
                    updateTripButtons();
                    stopLocationTracking();
                    showNotification(`Trip ended successfully! Duration: ${data.duration} minutes, Passengers: ${data.passengers}`, 'success');
                } else {
                    showNotification(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error ending trip:', error);
                showNotification('Failed to end trip', 'error');
            });
        }

        function updateTripButtons() {
            const btn = document.getElementById('tripToggleBtn');
            const statusDiv = document.getElementById('tripStatus');
            const statusText = document.getElementById('tripStatusText');
            
            if (currentTripStatus === 'active') {
                btn.innerHTML = '<i class="fas fa-stop me-2"></i>End Trip';
                btn.className = 'btn btn-danger w-100 mb-2';
                statusDiv.style.display = 'block';
                statusText.textContent = 'Trip in progress...';
                statusDiv.className = 'alert alert-success mt-2';
            } else {
                btn.innerHTML = '<i class="fas fa-play me-2"></i>Start Trip';
                btn.className = 'btn btn-success w-100 mb-2';
                statusDiv.style.display = 'none';
            }
        }

        function reportIssue() {
            var modal = new bootstrap.Modal(document.getElementById('reportIssueModal'));
            modal.show();
        }

        function submitIssue() {
            const form = document.getElementById('issueForm');
            const formData = new FormData(form);
            
            fetch('report_issue.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Issue reported successfully!', 'success');
                    form.reset();
                    var modal = bootstrap.Modal.getInstance(document.getElementById('reportIssueModal'));
                    modal.hide();
                } else {
                    showNotification(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error reporting issue:', error);
                showNotification('Failed to report issue', 'error');
            });
        }

        function showNotification(message, type) {
            const alertClass = type === 'success' ? 'alert-success' : 
                             type === 'error' ? 'alert-danger' : 
                             type === 'warning' ? 'alert-warning' : 'alert-info';
            
            const notification = document.createElement('div');
            notification.className = `alert ${alertClass} alert-dismissible fade show position-fixed`;
            notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
            notification.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-triangle' : 'info-circle'} me-2"></i>
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.remove();
                }
            }, 5000);
        }
        // Real-time queue monitoring
        function checkQueueAlerts() {
            fetch('check_queue_alerts.php')
                .then(response => response.json())
                .then(data => {
                    if (data.alerts && data.alerts.length > 0) {
                        data.alerts.forEach(alert => {
                            showAlertNotification(alert.message);
                        });
                    }
                })
                .catch(error => {
                    console.error('Error checking queue alerts:', error);
                });
        }
        function showAlertNotification(message) {
            const notification = document.createElement('div');
            notification.className = 'alert alert-danger alert-dismissible fade show position-fixed';
            notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
            notification.innerHTML = `
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>Queue Alert!</strong><br>
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.remove();
                }
            }, 10000);
        }
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            updateTripButtons();
            
            // Check for alerts every 30 seconds
            setInterval(checkQueueAlerts, 30000);
            checkQueueAlerts();
        });

        // --- Driver Map: Show all stops with queue counts (real-time) ---
        let driverMap, shuttleMarkers = {}, stopMarkers = {};
        function initDriverMap() {
            driverMap = L.map('driverMap').setView([23.8103, 90.4125], 13);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors'
            }).addTo(driverMap);
            loadShuttleMarkers();
            loadStopMarkers();
            setInterval(loadShuttleMarkers, 15000);
            setInterval(loadStopMarkers, 15000);
        }
        function loadShuttleMarkers() {
            fetch('../admin/get_shuttle_locations.php')
                .then(res => res.json())
                .then(data => {
                    // Remove old shuttle markers only
                    for (const id in shuttleMarkers) {
                        driverMap.removeLayer(shuttleMarkers[id]);
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
                                const marker = L.marker([shuttle.Current_Latitude, shuttle.Current_Longitude], {icon: busIcon}).addTo(driverMap);
                                marker.bindTooltip(`<b>${shuttle.DriverName || 'Driver'}</b><br>Vehicle: ${shuttle.Veh_Number}`);
                                shuttleMarkers[shuttle.D_ID] = marker;
                            }
                        });
                    }
                })
                .catch(error => {
                    console.error('Error loading shuttle locations:', error);
                });
        }
        function loadStopMarkers() {
            fetch('../get_stops_with_queue.php')
                .then(res => res.json())
                .then(data => {
                    // Only add new stop markers (do not remove existing)
                    if (data.stops) {
                        data.stops.forEach(stop => {
                            if (!stopMarkers[stop.Stop_ID] && stop.Latitude && stop.Longitude) {
                                const redIcon = L.divIcon({
                                    className: 'custom-div-icon',
                                    html: '<div style="background-color: #e74c3c; width: 22px; height: 22px; border-radius: 50%; border: 2px solid white; box-shadow: 0 2px 4px rgba(0,0,0,0.3);"></div>',
                                    iconSize: [22, 22],
                                    iconAnchor: [11, 11]
                                });
                                const marker = L.marker([stop.Latitude, stop.Longitude], {icon: redIcon}).addTo(driverMap);
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
                    console.error('Error loading stop data:', error);
                });
        }
        document.addEventListener('DOMContentLoaded', initDriverMap);

        // Add this function before or after the other JS functions
        function startLocationTracking() {
            if (navigator.geolocation) {
                if (locationUpdateIntervalId) clearInterval(locationUpdateIntervalId);
                // Get and send initial position
                navigator.geolocation.getCurrentPosition(function(position) {
                    currentLocation = {
                        latitude: position.coords.latitude,
                        longitude: position.coords.longitude
                    };
                    sendLocationUpdate('active');
                });
                // Send location every 10 seconds while trip is active
                locationUpdateIntervalId = setInterval(function() {
                    navigator.geolocation.getCurrentPosition(function(position) {
                        currentLocation = {
                            latitude: position.coords.latitude,
                            longitude: position.coords.longitude
                        };
                        sendLocationUpdate('active');
                    });
                }, 10000);
            } else {
                showNotification('Geolocation is not supported by this browser.', 'error');
            }
        }
        function stopLocationTracking() {
            if (locationUpdateIntervalId) clearInterval(locationUpdateIntervalId);
            sendLocationUpdate('idle'); // Clear location in DB
        }
        function sendLocationUpdate(tripStatus) {
            if (!currentLocation) return;
            fetch('update_location.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    latitude: currentLocation.latitude,
                    longitude: currentLocation.longitude,
                    driver_id: <?php echo $_SESSION['user_id']; ?>,
                    shuttle_id: <?php echo $assigned_shuttle ? $assigned_shuttle['Shuttle_ID'] : 'null'; ?>,
                    trip_status: tripStatus
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Location updated successfully', 'success');
                } else {
                    showNotification('Failed to update location', 'error');
                }
            })
            .catch(error => {
                console.error('Error sending location update:', error);
                showNotification('An error occurred while updating location', 'error');
            });
        }
    </script>
</body>
</html> 