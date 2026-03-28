<?php
// clinic_schedule_admin.php
// Schedule-only admin UI (separated from services management)
// Sidebar and design preserved from original combined page.
// Added: calendar date picker (flatpickr) for Specific Date Overrides & Leave

session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: admin_login.php');
    exit();
}

include '../../config/db.php'; // mysqli $conn
include '../../config/settings_loader.php';

$admin_username = $_SESSION['username'];
$message = '';
$doctor_load_error = null;

date_default_timezone_set(getSetting('default_timezone', 'Asia/Manila'));
$days_of_week_full = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];

function parse_date_input_to_sql($input) {
    $val = trim((string)$input);
    if ($val === '') return null;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) return $val;
    if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $val)) {
        list($m,$d,$y) = explode('/',$val);
        if (checkdate((int)$m,(int)$d,(int)$y)) {
            return sprintf('%04d-%02d-%02d',$y,$m,$d);
        }
    }
    $t = strtotime($val);
    if ($t !== false) return date('Y-m-d',$t);
    return null;
}

// safe prepare helper (throws exception on prepare failure)
function prepare_or_throw($sql) {
    global $conn;
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        throw new Exception("MySQL prepare() failed: " . $conn->error . " -- SQL: " . $sql);
    }
    return $stmt;
}

// helper: check table existence to avoid fatal prepare failures
function table_exists($table) {
    global $conn;
    $t = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '{$t}'");
    return ($res && $res->num_rows > 0);
}

// --- Load doctors ---
$default_doctor_id = 1;
$doctor_id = $_REQUEST['doctor_id'] ?? $default_doctor_id;
$doctor_id = filter_var($doctor_id, FILTER_VALIDATE_INT);

$doctors = [];
try {
    $stmt = prepare_or_throw("SELECT user_id, username FROM users WHERE role = 'doctor' ORDER BY username ASC");
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $doctors[$r['user_id']] = htmlspecialchars($r['username']);
    $stmt->close();
} catch (Exception $e) {
    $doctor_load_error = $e->getMessage();
    $doctors = [0 => 'ERROR LOADING DOCTORS'];
}
if (!array_key_exists($doctor_id, $doctors) && !empty($doctors)) $doctor_id = key($doctors);
elseif (empty($doctors)) $doctor_id = 0;

// Load master services (for assignment list) if table exists
$services_master = [];
if (table_exists('services')) {
    try {
        $stmt = prepare_or_throw("SELECT service_id, service_name, price FROM services ORDER BY created_at DESC");
        $stmt->execute();
        $res = $stmt->get_result();
        while ($s = $res->fetch_assoc()) $services_master[] = $s;
        $stmt->close();
    } catch (Exception $e) {
        // non-fatal
    }
}

// doctor_services mapping (if table exists)
$doctor_services_map = [];
if ($doctor_id > 0 && table_exists('doctor_services')) {
    try {
        $stmt = prepare_or_throw("SELECT service_id FROM doctor_services WHERE doctor_id = ?");
        $stmt->bind_param("i",$doctor_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $doctor_services_map[(int)$r['service_id']] = true;
        $stmt->close();
    } catch (Exception $e) {
        // ignore mapping load failure
    }
}

// --- POST handling (schedule-related only) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1) Delete schedule row
    if (isset($_POST['delete_schedule_id'])) {
        $delete_schedule_id = filter_input(INPUT_POST,'delete_schedule_id',FILTER_VALIDATE_INT);
        $current_doctor_id = filter_input(INPUT_POST,'doctor_to_schedule',FILTER_VALIDATE_INT);
        try {
            $stmt = prepare_or_throw("DELETE FROM doctor_schedules WHERE schedule_id = ? AND doctor_id = ?");
            $stmt->bind_param("ii",$delete_schedule_id,$current_doctor_id);
            $stmt->execute();
            $stmt->close();
            $message = "✅ Schedule row deleted.";
            header("Location: clinic_schedule_admin.php?doctor_id=".$current_doctor_id);
            exit();
        } catch (Exception $e) { $message = "❌ Error deleting schedule row: ".htmlspecialchars($e->getMessage()); }
    }

    // 2) Delete exception
    if (isset($_POST['delete_exception_id'])) {
        $delete_id = filter_input(INPUT_POST,'delete_exception_id',FILTER_VALIDATE_INT);
        $current_doctor_id = filter_input(INPUT_POST,'doctor_to_schedule',FILTER_VALIDATE_INT);
        try {
            $stmt = prepare_or_throw("DELETE FROM doctor_availability WHERE availability_id = ? AND doctor_id = ?");
            $stmt->bind_param("ii",$delete_id,$current_doctor_id);
            $stmt->execute();
            $stmt->close();
            $message = "✅ Exception deleted.";
            header("Location: clinic_schedule_admin.php?doctor_id=".$current_doctor_id);
            exit();
        } catch (Exception $e) { $message = "❌ Error deleting exception: ".htmlspecialchars($e->getMessage()); }
    }

    // 3) Main schedule save
    if (isset($_POST['save_schedule'])) {
        $doctor_id = filter_input(INPUT_POST,'doctor_to_schedule',FILTER_VALIDATE_INT);
        try {
            $conn->begin_transaction();

            // A: doctor_config (KEEP THIS SECTION TO UPDATE DOCTOR CONFIG)
            $consult_duration = filter_input(INPUT_POST,'consult_duration',FILTER_VALIDATE_INT) ?: 30;
            $consult_cost = filter_input(INPUT_POST,'consult_cost',FILTER_VALIDATE_FLOAT) ?: 0.0;
            $service_duration = filter_input(INPUT_POST,'service_duration',FILTER_VALIDATE_INT) ?: 60;
            $service_cost = filter_input(INPUT_POST,'service_cost',FILTER_VALIDATE_FLOAT) ?: 0.0;

            $stmt = prepare_or_throw("
                INSERT INTO doctor_config (doctor_id, consult_duration, consult_cost, service_duration, service_cost)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE consult_duration=VALUES(consult_duration), consult_cost=VALUES(consult_cost), service_duration=VALUES(service_duration), service_cost=VALUES(service_cost)
            ");
            $stmt->bind_param("iidid",$doctor_id,$consult_duration,$consult_cost,$service_duration,$service_cost);
            $stmt->execute();
            $stmt->close();

            // B: schedules (delete then insert)
            $stmt = prepare_or_throw("DELETE FROM doctor_schedules WHERE doctor_id = ?");
            $stmt->bind_param("i",$doctor_id);
            $stmt->execute();
            $stmt->close();

            $stmt_ins = prepare_or_throw("INSERT INTO doctor_schedules (doctor_id, day_of_week, start_time, end_time, schedule_type) VALUES (?, ?, ?, ?, 'individual')");
            $working_days_post = $_POST['day_working'] ?? [];

            foreach ($days_of_week_full as $day) {
                $day_key = strtolower($day);
                if (!isset($working_days_post[$day_key])) continue;
                $start_times = $_POST[$day_key.'_start_time'] ?? [];
                $end_times = $_POST[$day_key.'_end_time'] ?? [];
                if (!is_array($start_times)) $start_times = [$start_times];
                if (!is_array($end_times)) $end_times = [$end_times];
                $count = max(count($start_times),count($end_times));
                for ($i=0;$i<$count;$i++){
                    $s = trim($start_times[$i] ?? '');
                    $e = trim($end_times[$i] ?? '');
                    if ($s === '' || $e === '') continue;
                    $s_sql = date('H:i:s', strtotime($s));
                    $e_sql = date('H:i:s', strtotime($e));
                    $stmt_ins->bind_param("isss",$doctor_id,$day_key,$s_sql,$e_sql);
                    $stmt_ins->execute();
                }
            }
            $stmt_ins->close();

            // C: exception
            $edit_avail_id = filter_input(INPUT_POST,'edit_avail_id',FILTER_VALIDATE_INT);
            $raw_date = $_POST['new_avail_date'] ?? '';
            $new_avail_date_sql = parse_date_input_to_sql($raw_date);
            if (!empty($new_avail_date_sql)) {
                $new_avail_start = filter_input(INPUT_POST,'new_avail_start',FILTER_SANITIZE_STRING) ?: '09:00';
                $new_avail_end = filter_input(INPUT_POST,'new_avail_end',FILTER_SANITIZE_STRING) ?: '17:00';
                $new_avail_start = date('H:i:s', strtotime($new_avail_start));
                $new_avail_end = date('H:i:s', strtotime($new_avail_end));
                $new_is_available = filter_input(INPUT_POST,'new_is_available',FILTER_VALIDATE_INT);
                if ($new_is_available === null) $new_is_available = 1;
                $new_reason = filter_input(INPUT_POST,'new_reason',FILTER_SANITIZE_STRING) ?? null;

                if ($edit_avail_id) {
                    $stmt = prepare_or_throw("UPDATE doctor_availability SET available_date=?, start_time=?, end_time=?, is_available=?, reason=? WHERE availability_id=? AND doctor_id=?");
                    $stmt->bind_param("sssisii",$new_avail_date_sql,$new_avail_start,$new_avail_end,$new_is_available,$new_reason,$edit_avail_id,$doctor_id);
                    $stmt->execute();
                    $stmt->close();
                } else {
                    $stmt = prepare_or_throw("
                        INSERT INTO doctor_availability (doctor_id, available_date, start_time, end_time, is_available, reason)
                        VALUES (?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE start_time = VALUES(start_time), end_time = VALUES(end_time), is_available = VALUES(is_available), reason = VALUES(reason)
                    ");
                    $stmt->bind_param("isssis",$doctor_id,$new_avail_date_sql,$new_avail_start,$new_avail_end,$new_is_available,$new_reason);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            // D: doctor_services from schedule form (optional - only if table exists)
            if (table_exists('doctor_services')) {
                $selected_services = $_POST['services_assignment'] ?? [];
                if (!is_array($selected_services)) $selected_services = [$selected_services];
                $clean_ids = [];
                foreach ($selected_services as $sid) { $sid = (int)$sid; if ($sid>0) $clean_ids[$sid] = true; }
                $clean_ids = array_keys($clean_ids);

                $stmt = prepare_or_throw("DELETE FROM doctor_services WHERE doctor_id = ?");
                $stmt->bind_param("i",$doctor_id);
                $stmt->execute();
                $stmt->close();

                if (!empty($clean_ids)) {
                    $stmt = prepare_or_throw("INSERT INTO doctor_services (doctor_id, service_id) VALUES (?, ?)");
                    foreach ($clean_ids as $sid) {
                        $stmt->bind_param("ii",$doctor_id,$sid);
                        $stmt->execute();
                    }
                    $stmt->close();
                }
            }

            $conn->commit();
            $message = "✅ Schedule saved for Dr. " . htmlspecialchars($doctors[$doctor_id] ?? 'N/A') . ".";
            header("Location: clinic_schedule_admin.php?doctor_id=".$doctor_id);
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $message = "❌ Error saving schedule: ".htmlspecialchars($e->getMessage());
        }
    }
}

// --- Load existing schedule/config/availability for display ---
$loaded_schedules_map = [];
$loaded_consult_duration = 30;
$loaded_consult_cost = 50.00;
$loaded_service_duration = 60;
$loaded_service_cost = 100.00;
$date_exceptions = [];
$date_exceptions_map = [];

if ($doctor_id > 0) {
    try {
        $stmt = prepare_or_throw("SELECT consult_duration, consult_cost, service_duration, service_cost FROM doctor_config WHERE doctor_id = ?");
        $stmt->bind_param("i",$doctor_id);
        $stmt->execute();
        $cfg = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($cfg) {
            $loaded_consult_duration = $cfg['consult_duration'] ?? $loaded_consult_duration;
            $loaded_consult_cost = $cfg['consult_cost'] ?? $loaded_consult_cost;
            $loaded_service_duration = $cfg['service_duration'] ?? $loaded_service_duration;
            $loaded_service_cost = $cfg['service_cost'] ?? $loaded_service_cost;
        }
    } catch (Exception $e) {
        // ignore
    }

    // schedules
    try {
        $stmt = prepare_or_throw("SELECT schedule_id, day_of_week, start_time, end_time FROM doctor_schedules WHERE doctor_id = ? ORDER BY FIELD(day_of_week,'monday','tuesday','wednesday','thursday','friday','saturday','sunday'), start_time ASC");
        $stmt->bind_param("i",$doctor_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($rows as $r) {
            $d = $r['day_of_week'];
            if (!isset($loaded_schedules_map[$d])) $loaded_schedules_map[$d] = [];
            $loaded_schedules_map[$d][] = ['schedule_id'=>$r['schedule_id'],'start'=>$r['start_time'],'end'=>$r['end_time']];
        }
    } catch (Exception $e) {
        // ignore
    }

    // exceptions
    try {
        $stmt = prepare_or_throw("SELECT availability_id, available_date, start_time, end_time, is_available, reason FROM doctor_availability WHERE doctor_id = ? ORDER BY available_date ASC");
        $stmt->bind_param("i",$doctor_id);
        $stmt->execute();
        $date_exceptions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($date_exceptions as $ex) $date_exceptions_map[$ex['available_date']] = $ex;
    } catch (Exception $e) {
        // ignore
    }

    // doctor_services mapping (reload) - optional
    $doctor_services_map = [];
    if (table_exists('doctor_services')) {
        try {
            $stmt = prepare_or_throw("SELECT service_id FROM doctor_services WHERE doctor_id = ?");
            $stmt->bind_param("i",$doctor_id);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) $doctor_services_map[(int)$r['service_id']] = true;
            $stmt->close();
        } catch (Exception $e) { /* ignore */ }
    }
}

function get_day_schedule_status($date,$weekly_schedule_map,$exceptions_map) {
    $date_formatted = date('Y-m-d',strtotime($date));
    $day_of_week = strtolower(date('l',strtotime($date)));
    if (isset($exceptions_map[$date_formatted])) {
        $ex = $exceptions_map[$date_formatted];
        if ($ex['is_available'] == 0) return ['status'=>'DAY OFF','start'=>null,'end'=>null,'reason'=>htmlspecialchars($ex['reason'] ?? 'Unavailable')];
        return ['status'=>'Override','start'=>date('H:i',strtotime($ex['start_time'])),'end'=>date('H:i',strtotime($ex['end_time'])),'reason'=>htmlspecialchars($ex['reason'] ?? 'Override Schedule')];
    }
    if (isset($weekly_schedule_map[$day_of_week]) && count($weekly_schedule_map[$day_of_week])>0) {
        $ranges = $weekly_schedule_map[$day_of_week];
        $earliest = null; $latest = null;
        foreach ($ranges as $r) { $s = strtotime($r['start']); $e = strtotime($r['end']); if ($earliest===null || $s<$earliest) $earliest=$s; if ($latest===null||$e>$latest) $latest=$e; }
        return ['status'=>'Working','start'=>date('H:i',$earliest),'end'=>date('H:i',$latest),'reason'=>null];
    }
    return ['status'=>'Rest Day','start'=>null,'end'=>null,'reason'=>null];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Admin: Clinic Schedule - DentiTrack</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<style>
/* --- CSS STYLES FOR SIDEBAR AND MOBILE HEADER --- */
:root {
    --primary-blue: #007bff;
    --secondary-blue: #0056b3;
    --text-dark: #343a40;
    --text-light: #6c757d;
    --bg-page: #eef2f8; 
    --widget-bg: #ffffff;
    --alert-red: #dc3545;
    --success-green: #28a745;
    --accent-orange: #ffc107;
    --radius: 12px;
    --sidebar-width: 250px;
    --mobile-header-height: 60px;
}
* { box-sizing: border-box; }
html { scroll-behavior: smooth; }
body {
    margin: 0;
    font-family: 'Inter', sans-serif;
    background: var(--bg-page);
    color: var(--text-dark);
    min-height: 100vh;
    display: flex;
    overflow-x: hidden;
}

/* --- Sidebar Styling --- */
.sidebar {
    width: var(--sidebar-width);
    background: var(--widget-bg); 
    padding: 0;
    color: var(--text-dark);
    box-shadow: 2px 0 10px rgba(0,0,0,0.05);
    display: flex;
    flex-direction: column;
    position: fixed; 
    height: 100vh;
    z-index: 1000;
    transition: transform 0.3s ease;
}
.sidebar-header {
    padding: 25px;
    text-align: center;
    margin-bottom: 20px;
    font-size: 22px;
    font-weight: 700;
    color: var(--primary-blue);
    border-bottom: 1px solid #e9ecef;
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 10px;
}
.sidebar-nav {
    flex-grow: 1; 
    padding: 0 15px;
    /* Hidden Scrollbar */
    -ms-overflow-style: none;
    scrollbar-width: none;
    overflow-y: auto;
}
.sidebar-nav::-webkit-scrollbar { display: none; }

.sidebar a {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 15px;
    margin: 8px 0;
    color: var(--text-dark);
    text-decoration: none;
    font-weight: 500;
    border-radius: 8px;
    transition: all 0.3s ease;
}
.sidebar a:hover {
    background: rgba(0, 123, 255, 0.08);
    color: var(--primary-blue);
}
.sidebar a.active {
    background: var(--primary-blue);
    color: white;
    font-weight: 600;
    box-shadow: 0 4px 8px rgba(0, 123, 255, 0.2);
}
.sidebar a i {
    font-size: 18px;
    color: var(--text-light);
    transition: color 0.3s ease;
}
.sidebar a.active i { color: white; }
.sidebar a:hover i { color: var(--primary-blue); }

/* Specific style for Logout button */
.sidebar a[href="logout.php"] {
    margin-top: auto; 
    border-top: 1px solid #e9ecef;
    padding: 12px 15px;
    margin: 8px 15px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    gap: 12px;
    color: var(--text-dark);
    font-weight: 500;
}
.sidebar a[href="logout.php"]:hover {
    background-color: #fce8e8;
    color: var(--alert-red);
}
.sidebar a[href="logout.php"]:hover i {
    color: var(--alert-red);
}

/* --- Main Content Positioning (Crucial for layout) --- */
main.main-content {
    flex: 1;
    margin-left: var(--sidebar-width); /* Offset for the fixed sidebar */
    padding: 40px 60px;
    background: var(--bg-page); 
    overflow-y: auto;
    box-sizing: border-box;
    max-width: 100%;
}

/* Original Header/Content styles retained, adapted to new variables */
header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}
header h1 {
    font-size: 1.8rem;
    color: var(--secondary-blue);
    display: flex;
    align-items: center;
    gap: 10px; 
    border-bottom: 2px solid #e0e0e0;
    padding-bottom: 10px;
    margin-bottom: 20px;
    font-weight: 700;
}

.welcome { 
    font-size: 1.1rem; 
    margin-bottom: 30px; 
    color: var(--text-dark); 
    font-weight: 500; 
}
.welcome strong { color: var(--primary-blue); }

/* --- Alert Message Styling --- */
.alert {
    margin: 15px 0;
    padding: 12px 16px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 600;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    border: 1px solid;
}
.alert.success { 
    background: #e6ffed; 
    color: var(--success-green); 
    border-color: #b8e9c6; 
}
.alert.error { 
    background: #fff3e6; 
    color: var(--accent-orange); 
    border-color: #ffcc99; 
}

/* --- Controls/Form Styling --- */
.schedule-controls {
    display: flex;
    gap: 20px;
    align-items: center;
    margin-bottom: 30px;
    padding: 20px;
    background: var(--widget-bg);
    border-radius: var(--radius);
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
}
.schedule-controls label {
    font-weight: 600;
    color: var(--text-dark);
}
.schedule-controls select {
    padding: 8px 12px;
    border-radius: 6px;
    border: 1px solid #ced4da;
    font-size: 1rem;
    cursor: pointer;
}

/* --- Main Schedule Management Sections --- */
.schedule-sections {
    display: grid;
    grid-template-columns: 2fr 1fr; /* Weekly schedule wide, exceptions narrow */
    gap: 30px;
}
.section-box {
    background: var(--widget-bg);
    padding: 25px;
    border-radius: var(--radius);
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
}
.section-box h3 {
    color: var(--secondary-blue);
    margin-top: 0;
    padding-bottom: 10px;
    border-bottom: 1px dashed #e9ecef;
    margin-bottom: 20px;
    font-size: 1.3rem;
}

/* Weekly Schedule Grid */
.weekly-schedule {
    display: grid;
    grid-template-columns: 100px 1fr;
    gap: 15px 10px;
    align-items: center;
}
.day-label {
    font-weight: 700;
    color: var(--text-dark);
    padding: 10px 0;
    border-bottom: 1px solid #f0f0f0;
}
.time-slots {
    padding: 10px 0;
    border-bottom: 1px solid #f0f0f0;
}
.time-slot-group {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 5px;
}
.time-slot-group input[type="time"], 
.time-slot-group button {
    padding: 8px;
    border-radius: 6px;
    border: 1px solid #ced4da;
    font-size: 0.95rem;
}
.time-slot-group button {
    background: var(--alert-red);
    color: white;
    cursor: pointer;
    transition: background 0.2s;
    border: none;
}
.time-slot-group button:hover { background: #cc0000; }
.add-slot-btn {
    background: var(--primary-blue);
    color: white;
    padding: 5px 10px;
    font-size: 0.9rem;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    margin-top: 10px;
    transition: background 0.2s;
}
.add-slot-btn:hover { background: var(--secondary-blue); }

/* Exception Form */
#exceptionForm input, #exceptionForm select, #exceptionForm textarea {
    width: 100%;
    padding: 10px;
    border-radius: 6px;
    border: 1px solid #ced4da;
    margin-bottom: 15px;
    box-sizing: border-box;
}
#exceptionForm button {
    background: var(--success-green);
    color: white;
    padding: 10px 20px;
    border: none;
    border-radius: 6px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.2s;
}
#exceptionForm button:hover { background: #1e7e34; }

/* Exception/Leave List */
.exception-list ul {
    list-style: none;
    padding: 0;
    margin: 0;
}
.exception-list li {
    background: #fff8e1; /* Light yellow for notice */
    border-left: 4px solid var(--accent-orange);
    padding: 10px;
    margin-bottom: 8px;
    border-radius: 4px;
    font-size: 0.9rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.exception-list li.off {
    background: #ffebeb; /* Light red for day off */
    border-left: 4px solid var(--alert-red);
}
.exception-actions button {
    background: none;
    border: none;
    color: var(--alert-red);
    cursor: pointer;
    font-size: 0.8rem;
    transition: color 0.2s;
}

/* --- MOBILE SPECIFIC HEADER (ALIGNED) --- */
.mobile-header { 
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: var(--mobile-header-height);
    background: var(--widget-bg);
    z-index: 1000;
    justify-content: space-between;
    align-items: center;
    padding: 0 20px;
}
.mobile-logo {
    font-size: 24px;
    font-weight: 700;
    color: var(--primary-blue);
}
#menu-toggle-open {
    background: none;
    border: none;
    font-size: 24px;
    color: var(--primary-blue);
    cursor: pointer;
}
.sidebar-header button {
    display: none !important; /* Hide close button on desktop sidebar */
}


/* --- Responsive Design --- */
@media (max-width: 992px) {
    .mobile-header { display: flex; }
    main.main-content { 
        margin-left: 0; 
        padding: 20px; 
        padding-top: calc(20px + var(--mobile-header-height)); 
    }
    
    /* Sidebar Overlay */
    .sidebar { width: 80%; max-width: 300px; transform: translateX(-100%); position: fixed; height: 100vh; z-index: 1050; }
    .sidebar.open { transform: translateX(0); box-shadow: 5px 0 15px rgba(0,0,0,0.2); }
    
    .sidebar-header { justify-content: space-between; display: flex; align-items: center; }
    .sidebar-header button { display: block !important; }
    .sidebar-nav { padding: 0; overflow-y: auto !important; }
    .sidebar a { margin: 0; border-radius: 0; padding: 15px 30px; border-bottom: 1px solid #f0f0f0; }
    .sidebar a[href="logout.php"] { padding: 15px 30px; margin: 0; margin-top: 10px; border-radius: 0; }
    
    /* Schedule sections stacking */
    .schedule-sections { grid-template-columns: 1fr; gap: 20px; }
    .schedule-controls { flex-direction: column; align-items: stretch; gap: 15px; }
}
</style>
</head>

<body>

<div class="mobile-header" id="mobileHeader">
    <button id="menu-toggle-open"><i class="fas fa-bars"></i></button>
    <div class="mobile-logo">DentiTrack</div>
    <div style="width: 24px;"></div> 
</div>

<nav class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <i class="fas fa-tooth"></i> DentiTrack
        <button id="menu-toggle-close" style="display: none;"><i class="fas fa-times"></i></button>
    </div>
    <div class="sidebar-nav">
        <a href="../Dashboard/admin_dashboard.php" ><i class="fas fa-home"></i> Dashboard</a>
        <a href="../Manage_accounts/Manage_accounts.php"><i class="fas fa-users-cog"></i> Manage Accounts</a>
        <a href="../Clinic_Services/clinic_services_admin.php"><i class="fas fa-tools"></i> Clinic Services</a>
        <a href="../Generate_Reports/generate_reports.php"><i class="fas fa-chart-line"></i> Generate Reports</a>
        <a href="../Payment_Module/payment_module.php"><i class="fas fa-money-check-dollar"></i> Payment Module</a>
        <a href="../Clinic_Scedule/clinic_schedule_admin.php" class="active"><i class="fas fa-calendar-check"></i> Clinic Schedule</a>
        <a href="../System_Settings/admin_settings.php"><i class="fas fa-gear"></i> System Settings</a>
    </div>
    <a href="../logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
</nav>

<main class="main-content" role="main">
    <header>
        <h1><i class="fas fa-calendar-check"></i> Appointments</h1>
    </header>

    <?php if ($message): ?>
        <div class="alert <?= (strpos($message, '✅') !== false) ? 'success' : 'error' ?>">
            <i class="fas <?= (strpos($message, '✅') !== false) ? 'fa-check-circle' : 'fa-exclamation-triangle' ?>"></i>
            <?= str_replace(['✅', '❌'], '', $message) ?>
        </div>
    <?php endif; ?>
    
    <div class="schedule-controls" style="display:none;">
        <select id="doctorSelect" onchange="window.location.href = '?doctor_id=' + this.value">
            <?php foreach ($doctors as $id => $name): ?>
                <option value="<?= $id ?>" <?= ($id == $doctor_id) ? 'selected' : '' ?>>
                    Dr. <?= $name ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if ($doctor_load_error): ?>
            <p style="color: var(--alert-red); margin: 0; font-weight: 600;">Error: <?= htmlspecialchars($doctor_load_error) ?></p>
        <?php endif; ?>
    </div>

    <?php if ($doctor_id > 0): ?>
    <div class="schedule-sections" style="display:none;">
        
        <div class="section-box">
            <h3><i class="fas fa-calendar-alt"></i> Weekly Schedule & Configurations</h3>
            <form method="POST" onsubmit="return validateForm(this)">
                <input type="hidden" name="doctor_to_schedule" value="<?= $doctor_id ?>">
                <input type="hidden" name="save_schedule" value="1">

                <div class="config-settings" style="margin-bottom: 30px; padding: 15px; border: 1px solid #eee; border-radius: 8px;">
                    <h4><i class="fas fa-wrench"></i> Default Appointment Durations/Fees</h4>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                        <div>
                            <label>Consultation Duration (mins):</label>
                            <input type="number" name="consult_duration" value="<?= $loaded_consult_duration ?>" min="15" required style="width:100%;">
                        </div>
                        <div>
                            <label>Consultation Fee (₱):</label>
                            <input type="number" name="consult_cost" value="<?= $loaded_consult_cost ?>" min="0" step="0.01" required style="width:100%;">
                        </div>
                        <div>
                            <label>Service Duration (mins):</label>
                            <input type="number" name="service_duration" value="<?= $loaded_service_duration ?>" min="30" required style="width:100%;">
                        </div>
                        <div>
                            <label>Service Fee (₱):</label>
                            <input type="number" name="service_cost" value="<?= $loaded_service_cost ?>" min="0" step="0.01" required style="width:100%;">
                        </div>
                    </div>
                </div>

                <h4><i class="fas fa-clock"></i> Doctor's Weekly Availability</h4>
                <div class="weekly-schedule">
                    <div class="day-label">Day</div>
                    <div class="day-label">Times (Start - End)</div>
                    
                    <?php foreach ($days_of_week_full as $day): 
                        $day_key = strtolower($day);
                        $current_slots = $loaded_schedules_map[$day_key] ?? [];
                        $is_working = !empty($current_slots);
                    ?>
                        <div class="day-label">
                            <input type="checkbox" name="day_working[<?= $day_key ?>]" id="day_<?= $day_key ?>" <?= $is_working ? 'checked' : '' ?> onchange="toggleDay(this, '<?= $day_key ?>')">
                            <label for="day_<?= $day_key ?>"><?= $day ?></label>
                        </div>
                        <div class="time-slots" id="slots_<?= $day_key ?>" style="display: <?= $is_working ? 'block' : 'none' ?>;">
                            <?php if (empty($current_slots)): ?>
                                <div class="time-slot-group">
                                    <input type="time" name="<?= $day_key ?>_start_time[]" value="09:00">
                                    to
                                    <input type="time" name="<?= $day_key ?>_end_time[]" value="17:00">
                                    <button type="button" onclick="removeSlot(this)"><i class="fas fa-trash-alt"></i></button>
                                </div>
                            <?php else: ?>
                                <?php foreach ($current_slots as $slot): ?>
                                    <div class="time-slot-group">
                                        <input type="time" name="<?= $day_key ?>_start_time[]" value="<?= date('H:i', strtotime($slot['start'])) ?>">
                                        to
                                        <input type="time" name="<?= $day_key ?>_end_time[]" value="<?= date('H:i', strtotime($slot['end'])) ?>">
                                        <button type="button" onclick="removeSlot(this)"><i class="fas fa-trash-alt"></i></button>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <button type="button" class="add-slot-btn" onclick="addSlot('<?= $day_key ?>')"><i class="fas fa-plus"></i> Add Slot</button>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div style="margin-top: 30px; padding-top: 20px; border-top: 1px dashed #e9ecef;">
                    <h4><i class="fas fa-tasks"></i> Assign Services</h4>
                    <p style="color: var(--text-light); font-size: 0.9rem;">Select the services this doctor is qualified to perform.</p>
                    <div style="max-height: 200px; overflow-y: auto; display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                        <?php if (!empty($services_master)): ?>
                            <?php foreach ($services_master as $service): ?>
                                <div style="display:flex; align-items: center;">
                                    <input type="checkbox" name="services_assignment[]" value="<?= $service['service_id'] ?>" 
                                        id="service_<?= $service['service_id'] ?>" 
                                        <?= isset($doctor_services_map[$service['service_id']]) ? 'checked' : '' ?> style="margin-right: 8px;">
                                    <label for="service_<?= $service['service_id'] ?>" style="margin: 0; font-weight: normal; color: var(--text-dark);">
                                        <?= htmlspecialchars($service['service_name']) ?> (₱<?= number_format($service['price'], 2) ?>)
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p style="grid-column: 1 / -1; color: var(--alert-red);">No master services found.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div style="text-align: right; margin-top: 25px;">
                    <button type="submit" class="add-slot-btn"><i class="fas fa-save"></i> Save Weekly Schedule & Config</button>
                </div>
            </form>
        </div>


        <div class="section-box" style="grid-column: 1 / -1; display: none;"> 
            <h3><i class="fas fa-ban"></i> Date Exceptions & Leave</h3>
            
            <form method="POST" id="exceptionForm">
                <input type="hidden" name="doctor_to_schedule" value="<?= $doctor_id ?>">
                <input type="hidden" name="save_schedule" value="1">
                <input type="hidden" name="edit_avail_id" id="edit_avail_id_field">

                <label for="new_avail_date">Date:</label>
                <input type="text" name="new_avail_date" id="new_avail_date" required placeholder="YYYY-MM-DD or select date">
                
                <div style="display: flex; gap: 10px; margin-bottom: 15px;">
                    <div style="flex:1;">
                        <label for="new_avail_start">Start Time:</label>
                        <input type="time" name="new_avail_start" id="new_avail_start" value="09:00">
                    </div>
                    <div style="flex:1;">
                        <label for="new_avail_end">End Time:</label>
                        <input type="time" name="new_avail_end" id="new_avail_end" value="17:00">
                    </div>
                </div>
                
                <label for="new_is_available">Status:</label>
                <select name="new_is_available" id="new_is_available" onchange="toggleReason(this.value)">
                    <option value="1">Available (Override Weekly Schedule)</option>
                    <option value="0">Unavailable (Day Off/Leave)</option>
                </select>

                <label for="new_reason">Reason (Optional):</label>
                <textarea name="new_reason" id="new_reason" placeholder="Reason for change or leave..." rows="2"></textarea>
                
                <div style="text-align: right;">
                    <button type="submit">Save Exception/Leave</button>
                </div>
            </form>

            <h4 style="margin-top: 30px; color: var(--primary-blue); border-bottom: 1px dashed #e9ecef; padding-bottom: 5px;"><i class="fas fa-list-alt"></i> Existing Exceptions</h4>
            <div class="exception-list">
                <ul>
                    <?php if (!empty($date_exceptions)): ?>
                        <?php foreach ($date_exceptions as $ex): 
                            $is_off = $ex['is_available'] == 0;
                            $style_class = $is_off ? 'off' : '';
                        ?>
                            <li class="<?= $style_class ?>" data-id="<?= $ex['availability_id'] ?>" 
                                data-date="<?= $ex['available_date'] ?>" 
                                data-start="<?= date('H:i', strtotime($ex['start_time'])) ?>"
                                data-end="<?= date('H:i', strtotime($ex['end_time'])) ?>"
                                data-available="<?= $ex['is_available'] ?>"
                                data-reason="<?= htmlspecialchars($ex['reason'] ?? '') ?>">
                                
                                <span>
                                    <strong><?= date('M d, Y', strtotime($ex['available_date'])) ?>:</strong> 
                                    <?php if ($is_off): ?>
                                        Day Off (<?= htmlspecialchars($ex['reason'] ?? 'N/A') ?>)
                                    <?php else: ?>
                                        Override: <?= date('g:i A', strtotime($ex['start_time'])) ?> - <?= date('g:i A', strtotime($ex['end_time'])) ?>
                                    <?php endif; ?>
                                </span>
                                <span class="exception-actions">
                                    <button onclick="editException(this)"><i class="fas fa-edit"></i> Edit</button>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Confirm delete?')">
                                        <input type="hidden" name="doctor_to_schedule" value="<?= $doctor_id ?>">
                                        <input type="hidden" name="delete_exception_id" value="<?= $ex['availability_id'] ?>">
                                        <button type="submit"><i class="fas fa-trash"></i> Delete</button>
                                    </form>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <li>No scheduled exceptions or leaves.</li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php include '../../calendar_function/admin_calendar.php'; ?>
</main>

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize Flatpickr for the date selector
    flatpickr("#new_avail_date", {
        dateFormat: "Y-m-d",
        minDate: "today" // Only allow scheduling/exceptions for today onwards
    });
    
    // --- WEEKLY SCHEDULE LOGIC (Needed to keep JS functions defined) ---
    window.toggleDay = function(checkbox, dayKey) {
        const slotsDiv = document.getElementById('slots_' + dayKey);
        if (checkbox.checked) {
            slotsDiv.style.display = 'block';
            if (slotsDiv.querySelectorAll('.time-slot-group').length === 0) {
                addSlot(dayKey);
            }
        } else {
            slotsDiv.style.display = 'none';
        }
    };

    window.addSlot = function(dayKey) {
        const slotsDiv = document.getElementById('slots_' + dayKey);
        const newGroup = document.createElement('div');
        newGroup.className = 'time-slot-group';
        newGroup.innerHTML = `
            <input type="time" name="${dayKey}_start_time[]" value="09:00">
            to
            <input type="time" name="${dayKey}_end_time[]" value="17:00">
            <button type="button" onclick="removeSlot(this)"><i class="fas fa-trash-alt"></i></button>
        `;
        slotsDiv.insertBefore(newGroup, slotsDiv.querySelector('.add-slot-btn'));
    };

    window.removeSlot = function(button) {
        const group = button.closest('.time-slot-group');
        if (group) {
            group.remove();
        }
    };
    
    window.validateForm = function(form) {
        return true;
    };


    // --- DATE EXCEPTION LOGIC (Needed to keep JS functions defined) ---
    window.toggleReason = function(isAvailable) {
        const reasonField = document.getElementById('new_reason');
        reasonField.placeholder = (isAvailable === '0') 
            ? 'Reason for day off or leave (e.g., Seminar, Vacation)' 
            : 'Reason for time override (Optional)';
    };
    
    window.editException = function(button) {
        const listItem = button.closest('li');
        const id = listItem.dataset.id;
        const date = listItem.dataset.date;
        const start = listItem.dataset.start;
        const end = listItem.dataset.end;
        const available = listItem.dataset.available;
        const reason = listItem.dataset.reason;

        document.getElementById('edit_avail_id_field').value = id;
        document.getElementById('new_avail_date').value = date;
        document.getElementById('new_avail_start').value = start;
        document.getElementById('new_avail_end').value = end;
        document.getElementById('new_is_available').value = available;
        document.getElementById('new_reason').value = reason;
        
        document.querySelector('#exceptionForm button[type="submit"]').textContent = 'Update Exception/Leave';
        
        window.scrollTo({ top: 0, behavior: 'smooth' }); // Scroll to top for visibility
    };
    
    toggleReason(document.getElementById('new_is_available').value);


    // --- MOBILE MENU TOGGLE LOGIC ---
    document.addEventListener('DOMContentLoaded', function() {
        if (window.innerWidth < 992) {
            const sidebar = document.getElementById('sidebar');
            const menuToggleOpen = document.getElementById('menu-toggle-open');
            const menuToggleClose = document.getElementById('menu-toggle-close');

            if (menuToggleOpen && sidebar && menuToggleClose) {
                menuToggleClose.style.display = 'none';

                menuToggleOpen.addEventListener('click', function() {
                    sidebar.classList.add('open');
                    menuToggleClose.style.display = 'block';
                    document.body.style.overflow = 'hidden'; 
                });
                
                menuToggleClose.addEventListener('click', function() {
                    sidebar.classList.remove('open');
                    menuToggleClose.style.display = 'none';
                    document.body.style.overflow = ''; 
                });

                document.querySelectorAll('.sidebar a').forEach(link => {
                    link.addEventListener('click', function() {
                        setTimeout(() => {
                            sidebar.classList.remove('open');
                            document.body.style.overflow = '';
                            menuToggleClose.style.display = 'none';
                        }, 300);
                    });
                });
            }
        }
    });
});
</script>
</body>
</html>