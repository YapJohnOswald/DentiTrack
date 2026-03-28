<?php
// dashboard_chart_data.php
// Accepts JSON POST { start_date, end_date } -> returns JSON { labels:[], data:[] }
// If no start/end provided returns monthly counts for last 12 months.

header('Content-Type: application/json; charset=utf-8');
require_once '../config/db.php'; // expects $conn (mysqli)

$raw = file_get_contents('php://input');
$input = json_decode($raw, true) ?? [];

$start = trim($input['start_date'] ?? '');
$end = trim($input['end_date'] ?? '');

function is_valid_date($d) {
    if (!$d) return false;
    $parts = explode('-', $d);
    if (count($parts) !== 3) return false;
    return checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0]);
}

try {
    if (!$conn) throw new Exception('DB connection not available');

    if ($start && $end) {
        if (!is_valid_date($start) || !is_valid_date($end)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid date format. Use YYYY-MM-DD.']);
            exit;
        }
        if (strtotime($start) > strtotime($end)) {
            http_response_code(400);
            echo json_encode(['error' => 'Start date must be before or equal to end date.']);
            exit;
        }

        $sql = "SELECT DATE(appointment_date) AS d, COUNT(*) AS cnt
                FROM appointments
                WHERE appointment_date BETWEEN ? AND ?
                GROUP BY d
                ORDER BY d ASC";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ss', $start, $end);
        $stmt->execute();
        $res = $stmt->get_result();

        $map = [];
        while ($row = $res->fetch_assoc()) {
            $map[$row['d']] = (int)$row['cnt'];
        }
        $stmt->close();

        $labels = [];
        $data = [];
        $cur = strtotime($start);
        $end_ts = strtotime($end);
        while ($cur <= $end_ts) {
            $d = date('Y-m-d', $cur);
            $labels[] = $d;
            $data[] = $map[$d] ?? 0;
            $cur = strtotime('+1 day', $cur);
        }

        echo json_encode(['labels' => $labels, 'data' => $data]);
        exit;
    } else {
        // monthly for last 12 months
        $sql = "SELECT DATE_FORMAT(appointment_date, '%Y-%m') AS ym, COUNT(*) AS cnt
                FROM appointments
                WHERE appointment_date >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
                GROUP BY ym
                ORDER BY ym ASC";

        $res = $conn->query($sql);
        $map = [];
        while ($row = $res->fetch_assoc()) {
            $map[$row['ym']] = (int)$row['cnt'];
        }

        $labels = [];
        $data = [];
        for ($i = 11; $i >= 0; $i--) {
            $m = date('Y-m', strtotime("-{$i} months"));
            $labels[] = $m;
            $data[] = $map[$m] ?? 0;
        }

        echo json_encode(['labels' => $labels, 'data' => $data]);
        exit;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error', 'details' => $e->getMessage()]);
    exit;
}