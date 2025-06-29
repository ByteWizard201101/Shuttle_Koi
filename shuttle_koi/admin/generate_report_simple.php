<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Get filter parameters
    $shuttle_id = $_GET['shuttle_id'] ?? null;
    $start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
    $end_date = $_GET['end_date'] ?? date('Y-m-d');
    
    // Build the query based on filters
    $where_conditions = [];
    $params = [];
    
    if ($shuttle_id) {
        $where_conditions[] = "s.Shuttle_ID = :shuttle_id";
        $params[':shuttle_id'] = $shuttle_id;
    }
    
    $where_conditions[] = "DATE(t.Start_Time) BETWEEN :start_date AND :end_date";
    $params[':start_date'] = $start_date;
    $params[':end_date'] = $end_date;
    
    $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
    
    // Get shuttle trip data
    $trip_query = "
        SELECT 
            s.Veh_Number,
            s.Model,
            s.Capacity,
            d.Name as DriverName,
            d.License_Number,
            r.Name as RouteName,
            t.Trip_ID,
            t.Start_Time,
            t.End_Time,
            t.Distance_Km,
            t.Duration_Seconds,
            t.Status,
            t.Passenger_Count,
            t.Notes
        FROM Trip t
        JOIN Shuttle s ON t.Shuttle_ID = s.Shuttle_ID
        JOIN Driver d ON t.D_ID = d.D_ID
        JOIN Route r ON t.Route_ID = r.Route_ID
        $where_clause
        ORDER BY s.Veh_Number, t.Start_Time DESC
    ";
    
    $trip_stmt = $db->prepare($trip_query);
    $trip_stmt->execute($params);
    $trips = $trip_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get shuttle issues
    $issue_query = "
        SELECT 
            s.Veh_Number,
            si.Issue_Type,
            si.Description,
            si.Severity,
            si.Status,
            si.Reported_At,
            si.Resolved_At,
            si.Estimated_Cost,
            si.Actual_Cost,
            d.Name as DriverName
        FROM ShuttleIssue si
        JOIN Shuttle s ON si.Shuttle_ID = s.Shuttle_ID
        LEFT JOIN Driver d ON si.D_ID = d.D_ID
        WHERE DATE(si.Reported_At) BETWEEN :start_date AND :end_date
        " . ($shuttle_id ? "AND si.Shuttle_ID = :shuttle_id" : "") . "
        ORDER BY si.Reported_At DESC
    ";
    
    $issue_stmt = $db->prepare($issue_query);
    $issue_params = [':start_date' => $start_date, ':end_date' => $end_date];
    if ($shuttle_id) {
        $issue_params[':shuttle_id'] = $shuttle_id;
    }
    $issue_stmt->execute($issue_params);
    $issues = $issue_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get feedback data
    $feedback_query = "
        SELECT 
            s.Veh_Number,
            f.Rating,
            f.Comment,
            f.Timestamp,
            st.Name as StudentName,
            d.Name as DriverName
        FROM Feedback f
        JOIN Shuttle s ON f.Shuttle_ID = s.Shuttle_ID
        LEFT JOIN Student st ON f.S_ID = st.S_ID
        LEFT JOIN Driver d ON f.D_ID = d.D_ID
        WHERE DATE(f.Timestamp) BETWEEN :start_date AND :end_date
        " . ($shuttle_id ? "AND f.Shuttle_ID = :shuttle_id" : "") . "
        ORDER BY f.Timestamp DESC
    ";
    
    $feedback_stmt = $db->prepare($feedback_query);
    $feedback_params = [':start_date' => $start_date, ':end_date' => $end_date];
    if ($shuttle_id) {
        $feedback_params[':shuttle_id'] = $shuttle_id;
    }
    $feedback_stmt->execute($feedback_params);
    $feedbacks = $feedback_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate summary statistics
    $total_trips = count($trips);
    $total_distance = array_sum(array_column($trips, 'Distance_Km'));
    $total_duration = array_sum(array_column($trips, 'Duration_Seconds'));
    $total_passengers = array_sum(array_column($trips, 'Passenger_Count'));
    $completed_trips = count(array_filter($trips, function($trip) { return $trip['Status'] === 'Completed'; }));
    
    // Get shuttle name for title if specific shuttle selected
    $shuttle_name = '';
    if ($shuttle_id) {
        $shuttle_query = "SELECT Veh_Number FROM Shuttle WHERE Shuttle_ID = :shuttle_id";
        $shuttle_stmt = $db->prepare($shuttle_query);
        $shuttle_stmt->bindParam(':shuttle_id', $shuttle_id);
        $shuttle_stmt->execute();
        $shuttle_result = $shuttle_stmt->fetch(PDO::FETCH_ASSOC);
        $shuttle_name = $shuttle_result ? $shuttle_result['Veh_Number'] : '';
    }
    
} catch (Exception $e) {
    echo "Error generating report: " . $e->getMessage();
    exit();
}

// Helper function to format duration
function format_duration($seconds) {
    $h = floor($seconds / 3600);
    $m = floor(($seconds % 3600) / 60);
    $s = $seconds % 60;
    return sprintf('%d:%02d:%02d', $h, $m, $s);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shuttle Report - Shuttle Koi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        @media print {
            .no-print { display: none !important; }
            .print-break { page-break-before: always; }
        }
        .report-header { background: linear-gradient(45deg, #3498db, #2c3e50); color: white; padding: 20px; }
        .stats-card { background: #f8f9fa; border-radius: 10px; padding: 15px; margin: 10px 0; }
        .table-responsive { margin: 20px 0; }
        .issue-critical { background-color: #ffe6e6; }
        .issue-high { background-color: #fff2e6; }
        .issue-medium { background-color: #fff9e6; }
        .issue-low { background-color: #f0f8ff; }
    </style>
</head>
<body>
    <div class="container-fluid">
        <!-- Print/Back buttons -->
        <div class="no-print mt-3 mb-3">
            <button onclick="window.print()" class="btn btn-primary me-2">
                <i class="fas fa-print me-2"></i>Print Report
            </button>
            <a href="dashboard.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
            </a>
        </div>
        
        <!-- Report Header -->
        <div class="report-header text-center">
            <h1><i class="fas fa-bus me-3"></i>Shuttle Koi - Shuttle Report</h1>
            <p class="mb-0">Generated on: <?php echo date('Y-m-d H:i:s'); ?></p>
            <p class="mb-0">Report Period: <?php echo date('M d, Y', strtotime($start_date)); ?> to <?php echo date('M d, Y', strtotime($end_date)); ?></p>
            <?php if ($shuttle_name): ?>
                <p class="mb-0">Shuttle: <?php echo htmlspecialchars($shuttle_name); ?></p>
            <?php endif; ?>
        </div>
        
        <!-- Summary Statistics -->
        <div class="row mt-4">
            <div class="col-md-2">
                <div class="stats-card text-center">
                    <h3 class="text-primary"><?php echo $total_trips; ?></h3>
                    <p class="mb-0">Total Trips</p>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stats-card text-center">
                    <h3 class="text-success"><?php echo $completed_trips; ?></h3>
                    <p class="mb-0">Completed</p>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stats-card text-center">
                    <h3 class="text-info"><?php echo number_format($total_distance, 1); ?></h3>
                    <p class="mb-0">Total Distance (km)</p>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stats-card text-center">
                    <h3 class="text-warning"><?php echo format_duration($total_duration); ?></h3>
                    <p class="mb-0">Total Duration</p>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stats-card text-center">
                    <h3 class="text-secondary"><?php echo $total_passengers; ?></h3>
                    <p class="mb-0">Passengers</p>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stats-card text-center">
                    <h3 class="text-danger"><?php echo count($issues); ?></h3>
                    <p class="mb-0">Issues</p>
                </div>
            </div>
        </div>
        
        <!-- Trip Details -->
        <?php if (!empty($trips)): ?>
        <div class="print-break">
            <h2 class="mt-4 mb-3"><i class="fas fa-route me-2"></i>Trip Details</h2>
            <div class="table-responsive">
                <table class="table table-striped table-bordered">
                    <thead class="table-dark">
                        <tr>
                            <th>Vehicle</th>
                            <th>Driver</th>
                            <th>Route</th>
                            <th>Start Time</th>
                            <th>End Time</th>
                            <th>Distance (km)</th>
                            <th>Duration</th>
                            <th>Passengers</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($trips as $trip): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($trip['Veh_Number']); ?></strong></td>
                            <td><?php echo htmlspecialchars($trip['DriverName']); ?></td>
                            <td><?php echo htmlspecialchars($trip['RouteName']); ?></td>
                            <td><?php echo date('H:i', strtotime($trip['Start_Time'])); ?></td>
                            <td><?php echo $trip['End_Time'] ? date('H:i', strtotime($trip['End_Time'])) : '-'; ?></td>
                            <td><?php echo number_format($trip['Distance_Km'], 1); ?></td>
                            <td><?php echo format_duration($trip['Duration_Seconds']); ?></td>
                            <td><?php echo $trip['Passenger_Count']; ?></td>
                            <td>
                                <span class="badge bg-<?php echo $trip['Status'] === 'Completed' ? 'success' : ($trip['Status'] === 'In_Progress' ? 'warning' : 'secondary'); ?>">
                                    <?php echo $trip['Status']; ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Shuttle Issues -->
        <?php if (!empty($issues)): ?>
        <div class="print-break">
            <h2 class="mt-4 mb-3"><i class="fas fa-exclamation-triangle me-2"></i>Shuttle Issues</h2>
            <div class="table-responsive">
                <table class="table table-striped table-bordered">
                    <thead class="table-dark">
                        <tr>
                            <th>Vehicle</th>
                            <th>Issue Type</th>
                            <th>Description</th>
                            <th>Severity</th>
                            <th>Status</th>
                            <th>Reported</th>
                            <th>Resolved</th>
                            <th>Cost ($)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($issues as $issue): ?>
                        <tr class="issue-<?php echo strtolower($issue['Severity']); ?>">
                            <td><strong><?php echo htmlspecialchars($issue['Veh_Number']); ?></strong></td>
                            <td><?php echo htmlspecialchars($issue['Issue_Type']); ?></td>
                            <td><?php echo htmlspecialchars($issue['Description']); ?></td>
                            <td>
                                <span class="badge bg-<?php echo $issue['Severity'] === 'Critical' ? 'danger' : ($issue['Severity'] === 'High' ? 'warning' : ($issue['Severity'] === 'Medium' ? 'info' : 'success')); ?>">
                                    <?php echo $issue['Severity']; ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-<?php echo $issue['Status'] === 'Resolved' ? 'success' : ($issue['Status'] === 'Being_Fixed' ? 'warning' : 'secondary'); ?>">
                                    <?php echo $issue['Status']; ?>
                                </span>
                            </td>
                            <td><?php echo date('M d, Y', strtotime($issue['Reported_At'])); ?></td>
                            <td><?php echo $issue['Resolved_At'] ? date('M d, Y', strtotime($issue['Resolved_At'])) : '-'; ?></td>
                            <td><?php echo number_format($issue['Actual_Cost'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Feedback Summary -->
        <?php if (!empty($feedbacks)): ?>
        <div class="print-break">
            <h2 class="mt-4 mb-3"><i class="fas fa-star me-2"></i>Feedback Summary</h2>
            <?php 
            $avg_rating = array_sum(array_column($feedbacks, 'Rating')) / count($feedbacks);
            ?>
            <div class="row mb-3">
                <div class="col-md-6">
                    <div class="stats-card">
                        <h4>Average Rating: <?php echo number_format($avg_rating, 1); ?>/5.0</h4>
                        <div class="text-warning">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <i class="fas fa-star<?php echo $i <= $avg_rating ? '' : '-o'; ?>"></i>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="stats-card">
                        <h4>Total Feedback: <?php echo count($feedbacks); ?></h4>
                    </div>
                </div>
            </div>
            
            <div class="table-responsive">
                <table class="table table-striped table-bordered">
                    <thead class="table-dark">
                        <tr>
                            <th>Vehicle</th>
                            <th>Rating</th>
                            <th>Student</th>
                            <th>Driver</th>
                            <th>Date</th>
                            <th>Comment</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($feedbacks as $feedback): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($feedback['Veh_Number']); ?></strong></td>
                            <td>
                                <div class="text-warning">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <i class="fas fa-star<?php echo $i <= $feedback['Rating'] ? '' : '-o'; ?>"></i>
                                    <?php endfor; ?>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($feedback['StudentName']); ?></td>
                            <td><?php echo htmlspecialchars($feedback['DriverName']); ?></td>
                            <td><?php echo date('M d, Y', strtotime($feedback['Timestamp'])); ?></td>
                            <td><?php echo htmlspecialchars($feedback['Comment']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if (empty($trips) && empty($issues) && empty($feedbacks)): ?>
        <div class="text-center mt-5">
            <i class="fas fa-info-circle fa-3x text-muted mb-3"></i>
            <h3 class="text-muted">No data found for the selected period</h3>
            <p class="text-muted">Try adjusting the date range or shuttle selection.</p>
        </div>
        <?php endif; ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 