<?php
require_once 'calendar_conn.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $doctor_id = $_POST['doctor_id'] ?? 4;
    $date = $_POST['date'] ?? null;
    $reason = $_POST['reason'] ?? 'Personal leave';
    $action = $_POST['action'] ?? 'set';

    header('Content-Type: application/json');

    if (!$date) {
        echo json_encode(['status' => 'error', 'message' => 'No date provided']);
        exit;
    }

    try {
        if ($action === 'remove') {
            $stmt = $pdo->prepare("UPDATE doctor_availability SET is_available = 1, updated_at = NOW() WHERE doctor_id = ? AND available_date = ?");
            $stmt->execute([$doctor_id, $date]);
            echo json_encode(['status' => 'removed', 'message' => 'Rest day removed successfully.']);
        } else {
            $stmt = $pdo->prepare("INSERT INTO doctor_availability (doctor_id, available_date, start_time, end_time, is_available, reason, created_at, updated_at)
                VALUES (?, ?, '00:00:00', '23:59:59', 0, ?, NOW(), NOW())");
            $stmt->execute([$doctor_id, $date, $reason]);
            echo json_encode(['status' => 'added', 'message' => 'Rest day added successfully.']);
        }
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}
?>
