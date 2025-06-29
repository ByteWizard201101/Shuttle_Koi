<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $shuttle_id = $_POST['shuttle_id'] ?? '';
    if (empty($shuttle_id)) {
        header('Location: dashboard.php?shuttle_error=missing_shuttle_id');
        exit();
    }
    $database = new Database();
    $db = $database->getConnection();
    try {
        // Remove driver assignment(s)
        $del_assign = "DELETE FROM Driver_Shuttle WHERE Shuttle_ID = :shuttle_id";
        $stmt1 = $db->prepare($del_assign);
        $stmt1->bindParam(':shuttle_id', $shuttle_id);
        $stmt1->execute();
        // Delete shuttle
        $del_shuttle = "DELETE FROM Shuttle WHERE Shuttle_ID = :shuttle_id";
        $stmt2 = $db->prepare($del_shuttle);
        $stmt2->bindParam(':shuttle_id', $shuttle_id);
        if ($stmt2->execute()) {
            header('Location: dashboard.php?shuttle_success=deleted');
        } else {
            header('Location: dashboard.php?shuttle_error=delete_failed');
        }
    } catch (PDOException $e) {
        header('Location: dashboard.php?shuttle_error=database_error');
    }
} else {
    header('Location: dashboard.php');
}
exit(); 