<?php
// schedule_preview.php
// Accepts JSON { doctor_id, date } and returns JSON with effective schedule for that date
// { ok: true, date: 'YYYY-MM-DD', date_display: 'M d, Y', status:'Working'|'DAY OFF'|'Override'|'Rest Day', start:'HH:MM', end:'HH:MM', reason:'' }

session_start();
header('Content-Type: application/json');
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['ok'=>false,'message'=>'Unauthorized']);
    exit();
}

include '../config/db.php'; // mysqli $conn

$input = json_decode(file_get_contents('php://input'), true);
$doctor_id = isset($input['doctor_id']) ? (int)$input['doctor_id'] : 0;
$date_raw = $input['date'] ?? '';

if ($doctor_id <= 0 || !$date_raw) {
    echo json_encode(['ok'=>false,'message'=>'Missing parameters']);
    exit();
}

// normalize date
function to_sql_date($v) {
    $v = trim($v);
    if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/',$v)) {
        list($m,$d,$y) = explode('/',$v); return sprintf('%04d-%02d-%02d',$y,$m,$d);
    }
    $t = strtotime($v);
    if ($t === false) return null;
    return date('Y-m-d',$t);
}
$date = to_sql_date($date_raw);
if (!$date) { echo json_encode(['ok'=>false,'message'=>'Invalid date']); exit(); }

// load exceptions
$exceptions_map = [];
$stmt = $conn->prepare("SELECT available_date, start_time, end_time, is_available, reason FROM doctor_availability WHERE doctor_id = ? AND available_date = ?");
if ($stmt) {
    $stmt->bind_param("is",$doctor_id,$date);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $exceptions_map[$r['available_date']] = $r;
    $stmt->close();
}

// load weekly schedule for the doctor
$day_of_week = strtolower(date('l', strtotime($date)));
$weekly_map = [];
$stmt = $conn->prepare("SELECT day_of_week, start_time, end_time FROM doctor_schedules WHERE doctor_id = ?");
if ($stmt) {
    $stmt->bind_param("i",$doctor_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $d = strtolower($r['day_of_week']);
        if (!isset($weekly_map[$d])) $weekly_map[$d] = [];
        $weekly_map[$d][] = $r;
    }
    $stmt->close();
}

// determine result (same logic as in main)
if (isset($exceptions_map[$date])) {
    $ex = $exceptions_map[$date];
    if ($ex['is_available'] == 0) {
        echo json_encode([
            'ok'=>true, 'date'=>$date, 'date_display'=>date('M d, Y',strtotime($date)),
            'status'=>'DAY OFF','status_class'=>'bg-danger','start'=>null,'end'=>null,'reason'=>$ex['reason'] ?? 'Unavailable'
        ]);
        exit();
    } else {
        echo json_encode([
            'ok'=>true, 'date'=>$date, 'date_display'=>date('M d, Y',strtotime($date)),
            'status'=>'Override','status_class'=>'bg-warning text-dark','start'=>date('H:i',strtotime($ex['start_time'])),'end'=>date('H:i',strtotime($ex['end_time'])),'reason'=>$ex['reason'] ?? ''
        ]);
        exit();
    }
}

if (isset($weekly_map[$day_of_week]) && count($weekly_map[$day_of_week])>0) {
    $ranges = $weekly_map[$day_of_week];
    $earliest = null; $latest = null;
    foreach ($ranges as $r) { $s = strtotime($r['start_time']); $e = strtotime($r['end_time']); if ($earliest===null||$s<$earliest) $earliest=$s; if ($latest===null||$e>$latest) $latest=$e; }
    echo json_encode([
        'ok'=>true, 'date'=>$date, 'date_display'=>date('M d, Y',strtotime($date)),
        'status'=>'Working','status_class'=>'bg-success','start'=>date('H:i',$earliest),'end'=>date('H:i',$latest),'reason'=>''
    ]);
    exit();
}

echo json_encode(['ok'=>true,'date'=>$date,'date_display'=>date('M d, Y',strtotime($date)),'status'=>'Rest Day','status_class'=>'bg-secondary','start'=>null,'end'=>null,'reason'=>'']);
exit();