<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a student
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    header('Location: ../index.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

// Get student's recent check-ins
$checkin_query = "SELECT c.*, s.Name as StopName, sh.Veh_Number 
                  FROM Checkin c 
                  JOIN Stop s ON c.Stop_ID = s.Stop_ID 
                  LEFT JOIN Shuttle sh ON c.Shuttle_ID = sh.Shuttle_ID 
                  WHERE c.S_ID = :student_id 
                  ORDER BY c.Timestamp DESC 
                  LIMIT 5";
$checkin_stmt = $db->prepare($checkin_query);
$checkin_stmt->bindParam(':student_id', $_SESSION['user_id']);
$checkin_stmt->execute();
$recent_checkins = $checkin_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get available stops
$stops_query = "SELECT * FROM Stop ORDER BY Name";
$stops_stmt = $db->prepare($stops_query);
$stops_stmt->execute();
$stops = $stops_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get active shuttles
$shuttles_query = "SELECT s.*, sl.Latitude, sl.Longitude, sl.Timestamp as LastUpdate 
                   FROM Shuttle s 
                   LEFT JOIN ShuttleLocation sl ON s.Shuttle_ID = sl.Shuttle_ID 
                   WHERE s.Status = 'Active' 
                   AND sl.Location_ID = (
                       SELECT MAX(Location_ID) 
                       FROM ShuttleLocation 
                       WHERE Shuttle_ID = s.Shuttle_ID
                   )";
$shuttles_stmt = $db->prepare($shuttles_query);
$shuttles_stmt->execute();
$active_shuttles = $shuttles_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - Shuttle Koi</title>
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

        .shuttle-item {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 10px;
            border-left: 4px solid var(--secondary-color);
        }

        .status-badge {
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .status-waiting { background: var(--warning-color); color: white; }
        .status-boarded { background: var(--success-color); color: white; }
        .status-completed { background: var(--primary-color); color: white; }

        #shuttleMap {
            height: 400px;
            border-radius: 15px;
            margin-bottom: 20px;
        }

        .custom-div-icon {
            background: transparent;
            border: none;
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

        .rating {
            display: flex;
            flex-direction: row-reverse;
            justify-content: flex-end;
            position: relative;
        }
        .rating input {
            opacity: 0;
            position: absolute;
            width: 2rem;
            height: 2rem;
            z-index: 2;
            cursor: pointer;
        }
        .rating label {
            font-size: 2.5rem;
            color: #ddd;
            cursor: pointer;
            transition: color 0.2s;
            z-index: 1;
            padding: 0 0.1em;
        }
        .rating input:checked ~ label,
        .rating label:hover,
        .rating label:hover ~ label {
            color: #f39c12;
        }
        .rating label::before {
            content: '\2605'; /* Unicode filled star */
            color: inherit;
        }
        .rating label {
            font-family: inherit;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <?php if (isset($_GET['error']) && $_GET['error'] === 'already_checked_in'): ?>
    <div class="alert alert-warning alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-triangle me-2"></i>
        You already have a pending check-in. Please board your shuttle before checking in again.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
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
        <!-- Welcome Section with Check-in Icon and Text -->
        <div class="welcome-section d-flex justify-content-between align-items-center">
            <div>
                <h2><i class="fas fa-user-graduate me-2"></i>Student Dashboard</h2>
                <p class="mb-0">Track shuttles, check in, and give feedback.</p>
            </div>
            <button id="checkinIcon" class="btn btn-link d-flex align-items-center position-relative" style="font-size: 2rem; color: #fff; text-decoration: none;" title="Check In">
                <i class="fas fa-check-circle me-2"></i>
                <span style="font-size: 1.1rem; font-weight: 600; color: #fff; text-decoration: none;">Check In</span>
            </button>
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

        <div class="row">
            <!-- Shuttle Tracking -->
            <div class="col-lg-8">
                <div class="dashboard-card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-map-marker-alt me-2"></i>Live Shuttle Tracking</h5>
                    </div>
                    <div class="card-body">
                        <div id="shuttleMap" style="height: 400px; border-radius: 15px;"></div>
                        <div class="mt-3">
                            <small class="text-muted">
                                <i class="fas fa-info-circle me-1"></i>
                                Map shows real-time locations of all active shuttles. Green markers indicate shuttles currently on trips.
                            </small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="col-lg-4">
                <div class="dashboard-card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-history me-2"></i>Recent Activity</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($recent_checkins)): ?>
                            <p class="text-muted">No recent activity</p>
                        <?php else: ?>
                            <?php foreach ($recent_checkins as $checkin): ?>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div>
                                    <small class="fw-bold"><?php echo htmlspecialchars($checkin['StopName']); ?></small><br>
                                    <small class="text-muted"><?php echo date('M j, H:i', strtotime($checkin['Timestamp'])); ?></small>
                                </div>
                                <span class="status-badge status-<?php echo strtolower($checkin['Status']); ?>">
                                    <?php echo ucfirst($checkin['Status']); ?>
                                </span>
                                <?php if ($checkin['Status'] === 'Waiting'): ?>
                                    <button class="btn btn-success btn-sm ms-2" onclick="markBoarded(<?php echo $checkin['CheckIn_ID']; ?>, this)">Boarded</button>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Check-in Modal -->
    <div class="modal fade" id="checkinModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Check In at Stop</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="checkinForm" action="checkin.php" method="POST">
                        <div class="mb-3">
                            <label class="form-label">Select Stop</label>
                            <select class="form-select" name="stop_id" required>
                                <option value="">Choose a stop...</option>
                                <?php foreach ($stops as $stop): ?>
                                <option value="<?php echo $stop['Stop_ID']; ?>">
                                    <?php echo htmlspecialchars($stop['Name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-custom w-100">Check In</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Feedback Modal -->
    <div class="modal fade" id="feedbackModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Rate Your Ride</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="feedbackForm" action="feedback.php" method="POST">
                        <div class="mb-3">
                            <label class="form-label">Rating</label>
                            <div class="rating">
                                <input type="radio" name="rating" value="5" id="star5" required><label for="star5"></label>
                                <input type="radio" name="rating" value="4" id="star4"><label for="star4"></label>
                                <input type="radio" name="rating" value="3" id="star3"><label for="star3"></label>
                                <input type="radio" name="rating" value="2" id="star2"><label for="star2"></label>
                                <input type="radio" name="rating" value="1" id="star1"><label for="star1"></label>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Comment</label>
                            <textarea class="form-control" name="comment" rows="3" placeholder="Share your experience..."></textarea>
                        </div>
                        <button type="submit" class="btn btn-custom w-100">Submit Feedback</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
    <script>
        let shuttleMap, shuttleMarkers = {}, stopMarkers = {};
        
        function initShuttleMap() {
            shuttleMap = L.map('shuttleMap').setView([23.8103, 90.4125], 13); // Default center (Dhaka)
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors'
            }).addTo(shuttleMap);
            
            loadShuttleLocations();
            loadStopMarkers();
            setInterval(loadShuttleLocations, 15000);
            setInterval(loadStopMarkers, 15000);
        }
        
        function loadShuttleLocations() {
            fetch('../admin/get_shuttle_locations.php')
                .then(res => res.json())
                .then(data => {
                    // Remove old shuttle markers only
                    for (const id in shuttleMarkers) {
                        shuttleMap.removeLayer(shuttleMarkers[id]);
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
                                const marker = L.marker([shuttle.Current_Latitude, shuttle.Current_Longitude], {icon: busIcon}).addTo(shuttleMap);
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
                                const marker = L.marker([stop.Latitude, stop.Longitude], {icon: redIcon}).addTo(shuttleMap);
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
        
        // Initialize map when page loads
        document.addEventListener('DOMContentLoaded', function() {
            initShuttleMap();
        });

        function markBoarded(checkinId, btn) {
            btn.disabled = true;
            fetch('checkin.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=boarded&checkin_id=' + encodeURIComponent(checkinId)
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    // Open feedback modal automatically
                    var feedbackModal = new bootstrap.Modal(document.getElementById('feedbackModal'));
                    feedbackModal.show();
                    // Optionally, reset the check-in form so the user can check in again
                    var checkinForm = document.getElementById('checkinForm');
                    if (checkinForm) checkinForm.reset();
                } else {
                    alert(data.message || 'Failed to update status.');
                    btn.disabled = false;
                }
            })
            .catch(() => {
                alert('Network error.');
                btn.disabled = false;
            });
        }

        document.getElementById('checkinIcon').addEventListener('click', function() {
            var modal = new bootstrap.Modal(document.getElementById('checkinModal'));
            modal.show();
        });

        // Disable check-in button after click
        const checkinForm = document.getElementById('checkinForm');
        if (checkinForm) {
            checkinForm.addEventListener('submit', function(e) {
                const btn = checkinForm.querySelector('button[type="submit"]');
                if (btn) btn.disabled = true;
            });
        }
    </script>
</body>
</html> 