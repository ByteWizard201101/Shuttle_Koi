<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

// Include TCPDF library
require_once '../vendor/autoload.php';

use TCPDF as TCPDF;

class ShuttleReport extends TCPDF {
    public function Header() {
        $this->SetFont('helvetica', 'B', 16);
        $this->Cell(0, 15, 'Shuttle Koi - Shuttle Report', 0, false, 'C', 0, '', 0, false, 'M', 'M');
        $this->Ln();
        $this->SetFont('helvetica', '', 10);
        $this->Cell(0, 10, 'Generated on: ' . date('Y-m-d H:i:s'), 0, false, 'C', 0, '', 0, false, 'M', 'M');
        $this->Ln(20);
    }
    
    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        $this->Cell(0, 10, 'Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, false, 'C', 0, '', 0, false, 'T', 'M');
    }
}

// Helper function to format duration
function format_duration($seconds) {
    $h = floor($seconds / 3600);
    $m = floor(($seconds % 3600) / 60);
    $s = $seconds % 60;
    return sprintf('%d:%02d:%02d', $h, $m, $s);
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Get filter parameters
    $shuttle_id = $_GET['shuttle_id'] ?? null;
    $start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
    $end_date = $_GET['end_date'] ?? date('Y-m-d');
    
    // Create PDF
    $pdf = new ShuttleReport(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    
    // Set document information
    $pdf->SetCreator('Shuttle Koi System');
    $pdf->SetAuthor('Admin');
    $pdf->SetTitle('Shuttle Report - ' . date('Y-m-d'));
    
    // Set default header data
    $pdf->SetHeaderData(PDF_HEADER_LOGO, PDF_HEADER_LOGO_WIDTH, PDF_HEADER_TITLE, PDF_HEADER_STRING);
    
    // Set header and footer fonts
    $pdf->setHeaderFont(Array(PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN));
    $pdf->setFooterFont(Array(PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA));
    
    // Set default monospaced font
    $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
    
    // Set margins
    $pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);
    $pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
    $pdf->SetFooterMargin(PDF_MARGIN_FOOTER);
    
    // Set auto page breaks
    $pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);
    
    // Set image scale factor
    $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
    
    // Add a page
    $pdf->AddPage();
    
    // Set font
    $pdf->SetFont('helvetica', '', 10);
    
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
    
    // Generate report content
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 10, 'Shuttle Trip Report', 0, 1, 'L');
    $pdf->Ln(5);
    
    // Report period
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 8, 'Report Period: ' . date('M d, Y', strtotime($start_date)) . ' to ' . date('M d, Y', strtotime($end_date)), 0, 1, 'L');
    $pdf->Ln(5);
    
    // Summary statistics
    $total_trips = count($trips);
    $total_distance = array_sum(array_column($trips, 'Distance_Km'));
    $total_duration = array_sum(array_column($trips, 'Duration_Seconds'));
    $total_passengers = array_sum(array_column($trips, 'Passenger_Count'));
    $completed_trips = count(array_filter($trips, function($trip) { return $trip['Status'] === 'Completed'; }));
    
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 10, 'Summary Statistics', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 8, 'Total Trips: ' . $total_trips, 0, 1, 'L');
    $pdf->Cell(0, 8, 'Completed Trips: ' . $completed_trips, 0, 1, 'L');
    $pdf->Cell(0, 8, 'Total Distance: ' . number_format($total_distance, 2) . ' km', 0, 1, 'L');
    $pdf->Cell(0, 8, 'Total Duration: ' . format_duration($total_duration), 0, 1, 'L');
    $pdf->Cell(0, 8, 'Total Passengers: ' . $total_passengers, 0, 1, 'L');
    $pdf->Ln(10);
    
    // Trip details table
    if (!empty($trips)) {
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 10, 'Trip Details', 0, 1, 'L');
        $pdf->Ln(5);
        
        // Table header
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetFillColor(240, 240, 240);
        $pdf->Cell(25, 8, 'Vehicle', 1, 0, 'C', true);
        $pdf->Cell(25, 8, 'Driver', 1, 0, 'C', true);
        $pdf->Cell(25, 8, 'Route', 1, 0, 'C', true);
        $pdf->Cell(25, 8, 'Start Time', 1, 0, 'C', true);
        $pdf->Cell(25, 8, 'End Time', 1, 0, 'C', true);
        $pdf->Cell(20, 8, 'Distance', 1, 0, 'C', true);
        $pdf->Cell(20, 8, 'Duration', 1, 0, 'C', true);
        $pdf->Cell(15, 8, 'Status', 1, 1, 'C', true);
        
        // Table data
        $pdf->SetFont('helvetica', '', 7);
        foreach ($trips as $trip) {
            $pdf->Cell(25, 6, $trip['Veh_Number'], 1, 0, 'L');
            $pdf->Cell(25, 6, substr($trip['DriverName'], 0, 12), 1, 0, 'L');
            $pdf->Cell(25, 6, substr($trip['RouteName'], 0, 12), 1, 0, 'L');
            $pdf->Cell(25, 6, date('H:i', strtotime($trip['Start_Time'])), 1, 0, 'C');
            $pdf->Cell(25, 6, $trip['End_Time'] ? date('H:i', strtotime($trip['End_Time'])) : '-', 1, 0, 'C');
            $pdf->Cell(20, 6, number_format($trip['Distance_Km'], 1) . 'km', 1, 0, 'C');
            $pdf->Cell(20, 6, format_duration($trip['Duration_Seconds']), 1, 0, 'C');
            $pdf->Cell(15, 6, $trip['Status'], 1, 1, 'C');
        }
        $pdf->Ln(10);
    }
    
    // Shuttle issues
    if (!empty($issues)) {
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 10, 'Shuttle Issues', 0, 1, 'L');
        $pdf->Ln(5);
        
        // Table header
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetFillColor(240, 240, 240);
        $pdf->Cell(25, 8, 'Vehicle', 1, 0, 'C', true);
        $pdf->Cell(25, 8, 'Issue Type', 1, 0, 'C', true);
        $pdf->Cell(25, 8, 'Severity', 1, 0, 'C', true);
        $pdf->Cell(25, 8, 'Status', 1, 0, 'C', true);
        $pdf->Cell(25, 8, 'Reported', 1, 0, 'C', true);
        $pdf->Cell(25, 8, 'Resolved', 1, 0, 'C', true);
        $pdf->Cell(25, 8, 'Cost', 1, 1, 'C', true);
        
        // Table data
        $pdf->SetFont('helvetica', '', 7);
        foreach ($issues as $issue) {
            $pdf->Cell(25, 6, $issue['Veh_Number'], 1, 0, 'L');
            $pdf->Cell(25, 6, $issue['Issue_Type'], 1, 0, 'L');
            $pdf->Cell(25, 6, $issue['Severity'], 1, 0, 'C');
            $pdf->Cell(25, 6, $issue['Status'], 1, 0, 'C');
            $pdf->Cell(25, 6, date('M d', strtotime($issue['Reported_At'])), 1, 0, 'C');
            $pdf->Cell(25, 6, $issue['Resolved_At'] ? date('M d', strtotime($issue['Resolved_At'])) : '-', 1, 0, 'C');
            $pdf->Cell(25, 6, '$' . number_format($issue['Actual_Cost'], 2), 1, 1, 'C');
        }
        $pdf->Ln(10);
    }
    
    // Feedback summary
    if (!empty($feedbacks)) {
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 10, 'Feedback Summary', 0, 1, 'L');
        $pdf->Ln(5);
        
        // Calculate average rating
        $avg_rating = array_sum(array_column($feedbacks, 'Rating')) / count($feedbacks);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 8, 'Average Rating: ' . number_format($avg_rating, 1) . '/5.0', 0, 1, 'L');
        $pdf->Cell(0, 8, 'Total Feedback: ' . count($feedbacks), 0, 1, 'L');
        $pdf->Ln(5);
        
        // Table header
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetFillColor(240, 240, 240);
        $pdf->Cell(25, 8, 'Vehicle', 1, 0, 'C', true);
        $pdf->Cell(25, 8, 'Rating', 1, 0, 'C', true);
        $pdf->Cell(25, 8, 'Student', 1, 0, 'C', true);
        $pdf->Cell(25, 8, 'Driver', 1, 0, 'C', true);
        $pdf->Cell(25, 8, 'Date', 1, 0, 'C', true);
        $pdf->Cell(50, 8, 'Comment', 1, 1, 'C', true);
        
        // Table data
        $pdf->SetFont('helvetica', '', 7);
        foreach ($feedbacks as $feedback) {
            $pdf->Cell(25, 6, $feedback['Veh_Number'], 1, 0, 'L');
            $pdf->Cell(25, 6, $feedback['Rating'] . '/5', 1, 0, 'C');
            $pdf->Cell(25, 6, substr($feedback['StudentName'], 0, 12), 1, 0, 'L');
            $pdf->Cell(25, 6, substr($feedback['DriverName'], 0, 12), 1, 0, 'L');
            $pdf->Cell(25, 6, date('M d', strtotime($feedback['Timestamp'])), 1, 0, 'C');
            $pdf->Cell(50, 6, substr($feedback['Comment'], 0, 30), 1, 1, 'L');
        }
    }
    
    // Output PDF
    $filename = 'shuttle_report_' . date('Y-m-d_H-i-s') . '.pdf';
    $pdf->Output($filename, 'D');
    
} catch (Exception $e) {
    echo "Error generating report: " . $e->getMessage();
}
?> 