<?php
require_once 'calendar_conn.php';

$doctor_id = 4; // temporary
$date = $_GET['date'] ?? '';

if (!$date) {
  echo json_encode(['isRestDay' => false]);
  exit;
}

$stmt = $pdo->prepare("SELECT reason FROM doctor_availability WHERE doctor_id = ? AND available_date = ? AND is_available = 0");
$stmt->execute([$doctor_id, $date]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($row) {
  echo json_encode(['isRestDay' => true, 'reason' => $row['reason']]);
} else {
  echo json_encode(['isRestDay' => false]);
}
?>
