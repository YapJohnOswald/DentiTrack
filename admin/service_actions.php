<?php
// services_actions.php
// Handles services CRUD and assignments via AJAX to isolate from schedule POSTs.
// Returns JSON { ok: bool, message: string, ... }

session_start();
header('Content-Type: application/json');
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['ok'=>false,'message'=>'Unauthorized']);
    exit();
}

include '../config/db.php'; // $conn (mysqli)

// helper
function j($v){ echo json_encode($v); exit(); }

$action = $_POST['service_action'] ?? $_POST['action'] ?? '';

if ($action === 'save_master_service') {
    $sid = isset($_POST['master_service_id']) ? (int)$_POST['master_service_id'] : 0;
    $name = trim($_POST['master_service_name'] ?? '');
    $category = trim($_POST['master_category'] ?? '');
    $description = trim($_POST['master_description'] ?? '');
    $price = isset($_POST['master_price']) ? (float)$_POST['master_price'] : 0.0;
    $duration = trim($_POST['master_duration'] ?? '');
    $status = (($_POST['master_status'] ?? 'active') === 'active') ? 'active' : 'inactive';

    if ($name === '') j(['ok'=>false,'message'=>'Service name required.']);

    if ($sid > 0) {
        $stmt = $conn->prepare("UPDATE services SET service_name=?, category=?, description=?, price=?, duration=?, status=? WHERE service_id=?");
        if (!$stmt) j(['ok'=>false,'message'=>'DB prepare failed: '.$conn->error]);
        $stmt->bind_param("sssdssi",$name,$category,$description,$price,$duration,$status,$sid);
        if ($stmt->execute()) j(['ok'=>true,'message'=>'Service updated']);
        j(['ok'=>false,'message'=>'Update failed: '.$stmt->error]);
    } else {
        $stmt = $conn->prepare("INSERT INTO services (service_name, category, description, price, duration, status, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
        if (!$stmt) j(['ok'=>false,'message'=>'DB prepare failed: '.$conn->error]);
        $stmt->bind_param("sssdss",$name,$category,$description,$price,$duration,$status);
        if ($stmt->execute()) j(['ok'=>true,'message'=>'Service created']);
        j(['ok'=>false,'message'=>'Insert failed: '.$stmt->error]);
    }
}

// Delete service
if ($action === 'delete_master_service') {
    $sid = isset($_POST['master_service_id']) ? (int)$_POST['master_service_id'] : 0;
    if ($sid <= 0) j(['ok'=>false,'message'=>'Invalid service id']);
    $stmt = $conn->prepare("DELETE FROM services WHERE service_id = ?");
    if (!$stmt) j(['ok'=>false,'message'=>'DB prepare failed: '.$conn->error]);
    $stmt->bind_param("i",$sid);
    if ($stmt->execute()) {
        // clean mappings
        $stmt2 = $conn->prepare("DELETE FROM doctor_services WHERE service_id = ?");
        if ($stmt2) { $stmt2->bind_param("i",$sid); $stmt2->execute(); $stmt2->close(); }
        j(['ok'=>true,'message'=>'Deleted']);
    }
    j(['ok'=>false,'message'=>'Delete failed: '.$stmt->error]);
}

// Assign services to a doctor (from services tab)
if ($action === 'assign_services') {
    $doctor_to_assign = isset($_POST['doctor_to_assign']) ? (int)$_POST['doctor_to_assign'] : 0;
    $selected = $_POST['assign_services'] ?? [];
    if (!is_array($selected)) $selected = [$selected];
    $clean = [];
    foreach ($selected as $s) { $s = (int)$s; if ($s>0) $clean[$s]=true; }
    $ids = array_keys($clean);

    try {
        $conn->begin_transaction();
        $stmt = $conn->prepare("DELETE FROM doctor_services WHERE doctor_id = ?");
        $stmt->bind_param("i",$doctor_to_assign);
        $stmt->execute();
        $stmt->close();
        if (!empty($ids)) {
            $stmt = $conn->prepare("INSERT INTO doctor_services (doctor_id, service_id) VALUES (?, ?)");
            foreach ($ids as $sid) { $stmt->bind_param("ii",$doctor_to_assign,$sid); $stmt->execute(); }
            $stmt->close();
        }
        $conn->commit();
        j(['ok'=>true,'message'=>'Assignments saved']);
    } catch (Exception $e) {
        $conn->rollback();
        j(['ok'=>false,'message'=>'Error: '.$e->getMessage()]);
    }
}

// If action not recognized
j(['ok'=>false,'message'=>'Unknown action']);