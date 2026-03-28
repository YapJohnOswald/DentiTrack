<?php
session_start();
// Use require_once to ensure execution stops if the database connection fails
require_once '../config/db_pdo.php'; 
// The db_conn.php include is kept as you included it, but the main logic uses PDO ($conn)
include '../config/db_conn.php'; 

// Check secretary role
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'secretary') {
    header('Location: secretary_login.php');
    exit();
}

$message = '';
$error = '';

// --- Normalization function ---
function normalizeName($name) {
    $name = trim($name);
    $name = strtolower($name);
    $name = ucwords($name); 
    return $name;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_or_update') {
        // Core Item Details
        $name = normalizeName($_POST['name_input'] ?? '');
        $supply_id = intval($_POST['supply_id'] ?? 0); 
        $description = trim($_POST['description'] ?? '');
        $unit = trim($_POST['unit'] ?? 'pcs');
        $low_stock_threshold = intval($_POST['low_stock_threshold'] ?? 5);
        $lot_number = trim($_POST['lot_number'] ?? ''); 
        
        // Batch Details 
        $quantity = intval($_POST['quantity'] ?? 0);
        $expiration_date = $_POST['expiration_date'] ?: null; 
        
        $item_id_placeholder = $supply_id; 

        if ($name === '' || $quantity < 0 || $low_stock_threshold < 0) {
            $error = "Please fill out all required fields correctly (especially Item Name and Quantity)."; 
        } else {
            try {
                $conn->beginTransaction();

                if ($supply_id > 0) {
                    // Item/Record already exists, update its details
                    $sql_supply = "UPDATE dental_supplies 
                                     SET name = :name,
                                         description = :description,
                                         unit = :unit,
                                         low_stock_threshold = :low_stock_threshold,
                                         quantity = :quantity,
                                         expiration_date = :expiration_date,
                                         lot_number = :lot_number,
                                         last_updated = NOW()
                                     WHERE supply_id = :supply_id";
                    $stmt_supply = $conn->prepare($sql_supply);
                    $stmt_supply->bindValue(':name', $name);
                    $stmt_supply->bindValue(':description', $description);
                    $stmt_supply->bindValue(':unit', $unit);
                    $stmt_supply->bindValue(':low_stock_threshold', $low_stock_threshold, PDO::PARAM_INT);
                    $stmt_supply->bindValue(':quantity', $quantity, PDO::PARAM_INT);
                    $stmt_supply->bindValue(':expiration_date', $expiration_date, $expiration_date ? PDO::PARAM_STR : PDO::PARAM_NULL);
       
                    $stmt_supply->bindValue(':lot_number', $lot_number);
                    $stmt_supply->bindValue(':supply_id', $supply_id, PDO::PARAM_INT);
                    $stmt_supply->execute();
                    $message = "Supply record (ID: **{$supply_id}**) updated successfully.";
                    
                } else {
                    
                    // Check for existing batch before insertion to prevent duplicate error
                    $sql_check = "SELECT supply_id, quantity 
                                     FROM dental_supplies 
                                     WHERE name = :name AND expiration_date = :expiration_date";
                    $stmt_check = $conn->prepare($sql_check);
                    $stmt_check->bindValue(':name', $name);
                    $stmt_check->bindValue(':expiration_date', $expiration_date, $expiration_date ? PDO::PARAM_STR : PDO::PARAM_NULL);
                    $stmt_check->execute();
                    $existing_record = $stmt_check->fetch(PDO::FETCH_ASSOC);

                    if ($existing_record) {
                        // Existing Record Found: Merge the new quantity into the old record
                        $existing_supply_id = $existing_record['supply_id'];
                        $new_quantity = $existing_record['quantity'] + $quantity;
                        
                        $sql_merge = "UPDATE dental_supplies 
                                      SET quantity = :quantity,
                                          low_stock_threshold = :low_stock_threshold,
                                          description = :description,
                                          unit = :unit,
                                          last_updated = NOW() 
                                      WHERE supply_id = :supply_id";
                        
                        $stmt_merge = $conn->prepare($sql_merge);
                        $stmt_merge->bindValue(':quantity', $new_quantity, PDO::PARAM_INT);
       
                        $stmt_merge->bindValue(':low_stock_threshold', $low_stock_threshold, PDO::PARAM_INT);
                        $stmt_merge->bindValue(':description', $description);
                        $stmt_merge->bindValue(':unit', $unit);
                        $stmt_merge->bindValue(':supply_id', $existing_supply_id, PDO::PARAM_INT);
                        $stmt_merge->execute();
                        
                        $message = "Existing batch (ID: **{$existing_supply_id}**) of **{$name}** (Exp: {$expiration_date}) was found. Quantity updated/merged to **{$new_quantity}**.";
                        $supply_id = $existing_supply_id; 
                        
                    } else {
                        // Record Not Found: Proceed with Insertion.
                        
                        if (empty($lot_number)) {
                            $lot_number = 'LOT-' . date('Ymd') . '-' . substr(md5(microtime()), 0, 6);
                        }

                        // --- STEP 1: Insert the new record ---
                        $sql_supply = "INSERT INTO dental_supplies 
                                         (name, description, unit, low_stock_threshold, quantity, expiration_date, lot_number, last_updated)
                                         VALUES (:name, :description, :unit, :low_stock_threshold, :quantity, :expiration_date, :lot_number, NOW())";
                        
                        $stmt_supply = $conn->prepare($sql_supply);
                        
                        $stmt_supply->bindValue(':name', $name);
                        $stmt_supply->bindValue(':description', $description);
                        $stmt_supply->bindValue(':unit', $unit);
                        $stmt_supply->bindValue(':low_stock_threshold', $low_stock_threshold, PDO::PARAM_INT);
                        $stmt_supply->bindValue(':quantity', $quantity, PDO::PARAM_INT);
                        $stmt_supply->bindValue(':expiration_date', $expiration_date, $expiration_date ? PDO::PARAM_STR : PDO::PARAM_NULL);
                        
                        $stmt_supply->bindValue(':lot_number', $lot_number); 
                        
                        $stmt_supply->execute();
                        
                        $supply_id = $conn->lastInsertId();

                        // --- STEP 2: Generate and update the batch_id (Optional/Legacy logic kept) ---
                        if ($supply_id > 0 && !empty($expiration_date)) {
                            $date_part = date('Y-m-d', strtotime($expiration_date));
                            $generated_batch_id = $date_part . '-' . $supply_id;
                            
                            $sql_update_batch = "UPDATE dental_supplies SET batch_id = :batch_id WHERE supply_id = :supply_id";
                            $stmt_update = $conn->prepare($sql_update_batch);
                            $stmt_update->bindValue(':batch_id', $generated_batch_id);
                            $stmt_update->bindValue(':supply_id', $supply_id, PDO::PARAM_INT);
                            $stmt_update->execute();
                        }
                        
                        $message = "New supply record **{$name}** added (ID: **{$supply_id}**). Lot Number: **{$lot_number}**";
                    }
                }

                $conn->commit();
            } catch (PDOException $e) {
                $conn->rollBack();
                if ($e->getCode() === '23000' && strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    $error = "Database Error: Cannot add duplicate batch. A record for **{$name}** with expiration date **{$expiration_date}** already exists. The application should have merged the quantities. Please try again or check the database record manually.";
                } else {
                    $error = "Database Error: " . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'delete_item') {
        // Deletes all records associated with this item name
        $name_to_delete = $_POST['name'] ?? '';
        if ($name_to_delete !== '') {
            try {
                $sql = "DELETE FROM dental_supplies WHERE name = :name";
                $stmt = $conn->prepare($sql);
                $stmt->bindValue(':name', $name_to_delete);
                $stmt->execute();
                $message = "All records for supply item **{$name_to_delete}** deleted successfully.";
            } catch (PDOException $e) {
                $error = "Failed to delete item group: " . $e->getMessage();
            }
        } else {
            $error = "Invalid item name for deletion.";
        }
    } elseif ($action === 'delete_batch') {
        // Deletes a specific supply record (batch)
        $supply_id = intval($_POST['supply_id'] ?? 0);
        if ($supply_id > 0) {
            $sql = "DELETE FROM dental_supplies WHERE supply_id = :supply_id";
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(':supply_id', $supply_id, PDO::PARAM_INT);
            if ($stmt->execute()) {
                $message = "Supply Record (Batch) ID: **{$supply_id}** deleted successfully.";
            } else {
                $error = "Failed to delete supply record.";
            }
        } else {
            $error = "Invalid supply ID for deletion.";
        }
    }
}

// Helper functions for status
function isLowStock($quantity, $threshold) {
    return $quantity <= $threshold;
}
function isExpired($expiration_date) {
    if (!$expiration_date) return false;
    return strtotime($expiration_date) < time(); 
}

// --- Fetch all normalized supplies for display (Single Table) ---
$sql = "SELECT 
            supply_id, name, description, unit, low_stock_threshold, quantity, expiration_date,batch_id, lot_number 
        FROM 
            dental_supplies
        ORDER BY name ASC, expiration_date ASC";

$supplies_raw = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC); 

// Group supplies by Item Name and calculate totals
$supplies_grouped = [];

foreach ($supplies_raw as $row) {
    $item_name = $row['name'];
    
    if (!isset($supplies_grouped[$item_name])) {
        $supplies_grouped[$item_name] = [
            'item_id' => $row['supply_id'], 
            'name' => $item_name,
            'description' => $row['description'],
            'unit' => $row['unit'],
            'low_stock_threshold' => $row['low_stock_threshold'],
            'needs_batch' => 1, 
            'total_quantity' => 0,
            'status_low' => false, 
            'status_expired' => false, 
            'batches' => []
        ];
    }

    $supplies_grouped[$item_name]['total_quantity'] += $row['quantity'];
        
    $is_exp_batch = isExpired($row['expiration_date']);

    if ($is_exp_batch) {
        $supplies_grouped[$item_name]['status_expired'] = true;
    }

    // Store batch data (which is the individual row)
    $supplies_grouped[$item_name]['batches'][] = [
        'supply_id' => $row['supply_id'], 
        'batch_id_external' => $row['batch_id'], 
        'lot_number' => $row['lot_number'],
        'quantity' => $row['quantity'],
        'expiration_date' => $row['expiration_date'],

        'is_expired' => $is_exp_batch,
    ];
}

// 3. Final check for Low Stock (based on TOTAL quantity)
foreach ($supplies_grouped as $item_name => $item) {
    if (isLowStock($item['total_quantity'], $item['low_stock_threshold'])) {
        $supplies_grouped[$item_name]['status_low'] = true;
    }
}


// Totals for widgets
$total_supplies_items = count($supplies_grouped);
$total_low_stock_items = count(array_filter($supplies_grouped, fn($s) => $s['status_low']));
$total_expired_items = count(array_filter($supplies_grouped, fn($s) => $s['status_expired']));
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Dental Inventory Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
<style>
/* CSS Variables for a clean, consistent color scheme */
:root {
    --sidebar-width: 240px;
    --primary-blue: #007bff;
    --secondary-blue: #0056b3;
    --text-dark: #343a40;
    --text-light: #6c757d;
    --bg-page: #eef2f8;
    --widget-bg: #ffffff;
    --alert-red: #dc3545;
    --success-green: #28a745;
    --accent-orange: #ffc107;
}

/* Base Styles */
*, *::before, *::after { box-sizing: border-box; }
body { 
    margin: 0; padding: 0; height: 100%; 
    font-family: 'Inter', sans-serif; 
    background: var(--bg-page); color: var(--text-dark); 
    min-height: 100vh; 
    display: flex; 
    overflow-x: hidden; 
    scroll-behavior: smooth;
}
a { text-decoration: none; color: inherit; }
button { cursor: pointer; }

/* --- Sidebar Styling (Fixed & Aligned) --- */
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
    /* --- AUTOMATIC SCROLL FUNCTIONALITY --- */
    overflow-y: auto; 
}
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
    transition: background-color 0.3s ease, color 0.3s ease;
}
.sidebar a:hover { 
    background-color: rgba(0, 123, 255, 0.08); 
    color: var(--primary-blue);
}
.sidebar a.active { 
    background-color: var(--primary-blue);
    color: white; 
    font-weight: 600;
    box-shadow: 0 4px 8px rgba(0, 123, 255, 0.2);
}
.sidebar a i {
    font-size: 18px;
    color: var(--text-light);
    transition: color 0.3s ease;
}
.sidebar a.active i {
    color: white; 
}
.sidebar .logout {
    margin-top: auto; 
    border-top: 1px solid #e9ecef; 
    margin: 0;
    padding: 15px 30px; 
    border-radius: 0;
}
.sidebar .logout:hover {
    background-color: #fce8e8;
    color: var(--alert-red);
}

/* --- Main Content Styling --- */
main.main-content { 
    flex: 1; 
    margin-left: var(--sidebar-width); 
    background: var(--bg-page); 
    padding: 40px; 
    overflow-y: auto; 
    display: flex; flex-direction: column; 
    gap: 30px; 
}
header h1 { 
    font-size: 1.8rem; 
    color: var(--text-dark); 
    font-weight: 700; 
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}
header h1 i { font-size: 2rem; color: var(--secondary-blue); }

/* Flash Messages */
.flash-message { 
    max-width: 100%; 
    margin: 0; 
    padding: 16px 20px; 
    border-radius: 8px; 
    font-weight: 600; 
    font-size: 1rem; 
    text-align: left; 
    display: flex; align-items: center; gap: 10px; 
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}
.flash-success { background-color: #d4edda; color: var(--success-green); }
.flash-error { background-color: #f8d7da; color: var(--alert-red); }

/* Widgets */
.widgets { 
    display: grid; 
    grid-template-columns: repeat(auto-fit,minmax(220px,1fr)); 
    gap: 20px; 
}
.widget { 
    background: var(--widget-bg); 
    padding: 25px; 
    border-radius: 12px; 
    box-shadow: 0 4px 15px rgba(0,0,0,0.08); 
    display: flex; 
    flex-direction: column; 
    gap: 8px; 
    border-left: 5px solid var(--primary-blue);
}
.widget i { font-size: 2rem; color: var(--primary-blue); }
.widget span { font-weight: 800; font-size: 2.2rem; color: var(--text-dark); }
.widget small { font-weight: 600; color: var(--text-light); opacity: 0.9; }

/* Add New Item Button */
#add-new-supply-btn {
    align-self: flex-start;
    padding: 12px 25px;
    font-size: 1rem;
    font-weight: 700;
    border-radius: 8px;
    border: none;
    background: var(--success-green); 
    color: white;
    box-shadow: 0 4px 10px rgba(40, 167, 69, 0.3);
    transition: background-color 0.3s ease;
}
#add-new-supply-btn:hover {
    background: #1e7e34;
}

/* Table */
section table { 
    width: 100%; 
    border-collapse: separate; 
    border-spacing: 0;
    border-radius: 10px; 
    overflow: hidden; 
    box-shadow: 0 4px 15px rgba(0,0,0,0.05); 
    background-color: var(--widget-bg); 
    font-size: 0.95rem; 
    color: var(--text-dark); 
}
table thead { background: var(--primary-blue); color: #fff; font-weight: 600; }
table thead tr th { padding: 15px 18px; text-align: left; text-transform: uppercase; font-size: 0.8rem; }
table thead th:first-child { border-top-left-radius: 10px; }
table thead th:last-child { border-top-right-radius: 10px; }

table tbody tr { transition: background-color 0.2s ease; border-bottom: 1px solid #f0f0f0; }
table tbody tr:last-child { border-bottom: none; }
table tbody tr.main-item { cursor: pointer; font-weight: 600; }
table tbody tr.main-item:hover { background-color: #f5f8fc; }

/* Status colors for MAIN ITEM ROW */
table tbody tr.low-stock { background-color: #fffbe6; } /* Light yellow */
table tbody tr.expired { background-color: #fce8e8; } /* Light pink */
table tbody tr.low-stock.expired { background-color: #f7e0e5; }

table tbody tr td { padding: 12px 18px; vertical-align: middle; }

table tbody tr td.actions { text-align: right; white-space: nowrap; }
table tbody tr td.actions button { 
    border: none; background: none; 
    font-size: 0.9rem; 
    color: var(--text-light); 
    padding: 6px 10px; 
    border-radius: 6px; 
    transition: color 0.25s ease, background-color 0.25s ease;
}
table tbody tr td.actions button:hover { background-color: #eee; color: var(--primary-blue); }

/* Batch Details */
.batch-row { display: none; background: var(--bg-light); }
.batch-row td { padding: 0; }
.batch-table { 
    width: 95%; margin: 10px auto; 
    background: #e6f0ff; 
    box-shadow: none; 
    border: 1px solid #cce0ff; 
    border-radius: 8px; 
    overflow: hidden;
}
.batch-table th { background: var(--secondary-blue); color: white; padding: 8px 15px; font-size: 0.75rem; }
.batch-table td { padding: 6px 15px; }
.batch-row .batch-expired { background-color: #f8d7da; color: var(--alert-red); }
.batch-toggle { 
    margin-left: 10px; 
    font-size: 0.8rem; font-weight: 700; 
    padding: 4px 8px; 
    border-radius: 4px; 
    background: var(--secondary-blue); 
    color: white; 
    border: none; 
}
.batch-toggle:hover { background: var(--primary-blue); }
.batch-toggle.open::after { content: " ▲"; }
.batch-toggle:not(.open)::after { content: " ▼"; }
.batch-row .actions button { color: var(--secondary-blue); }
.batch-row .actions button:hover { background: #cce0ff; }
.batch-row .actions form button { color: var(--alert-red); }
.batch-row .actions form button:hover { background: #f8d7da; }


/* Modal Styles */
.modal {
    position: fixed; top: 0; left: 0;
    width: 100%; height: 100%;
    background-color: rgba(0, 0, 0, 0.6); 
    display: none; 
    justify-content: center;
    align-items: center;
    z-index: 1000;
    overflow-y: auto;
    padding: 20px;
}
.modal-content {
    background: var(--widget-bg);
    padding: 30px;
    border-radius: 12px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4);
    position: relative;
    max-width: 650px;
    width: 100%;
    animation: fadeIn 0.3s ease-out;
}
.close-btn {
    position: absolute;
    top: 15px; right: 25px;
    font-size: 2rem;
    font-weight: bold;
    color: var(--text-light);
    transition: color 0.3s;
    border: none; background: none;
}
.close-btn:hover, .close-btn:focus {
    color: var(--alert-red);
}
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-50px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Add Supply Form inside Modal */
form#add-supply h2 { 
    margin: 0 0 20px 0; 
    color: var(--primary-blue); 
    font-weight: 700; 
    font-size: 1.8rem; 
    text-align: center; 
    border-bottom: 1px solid #eee; 
    padding-bottom: 10px;
}
form#add-supply h3 { 
    color: var(--secondary-blue); 
    font-weight: 600; 
    font-size: 1.1rem;
    margin-top: 20px;
}
form#add-supply hr { display: none; }
form#add-supply .form-row { display: flex; flex-wrap: wrap; gap: 20px; margin-bottom: 15px; align-items: center;}
form#add-supply label { flex: 1 0 150px; font-weight: 600; color: var(--text-dark); display: flex; align-items: center; gap: 6px; font-size: 0.95rem; }
form#add-supply input, form#add-supply select, form#add-supply textarea { 
    flex: 2 1 250px; 
    padding: 10px 12px; 
    font-size: 1rem; 
    border-radius: 8px; 
    border: 1px solid #ced4da; 
    font-family: inherit;
    transition: border-color 0.3s;
}
form#add-supply input:focus, form#add-supply select:focus, form#add-supply textarea:focus {
    border-color: var(--primary-blue);
    outline: none;
    box-shadow: 0 0 0 2px rgba(0, 123, 255, 0.1);
}
form#add-supply button[type="submit"] { 
    display: block; margin: 30px auto 0; 
    padding: 12px 30px; 
    font-weight: 700; 
    font-size: 1rem; 
    border-radius: 8px; 
    border: none; 
    background: var(--primary-blue); 
    color: white; 
    box-shadow: 0 4px 10px rgba(0, 123, 255, 0.3); 
    transition: background-color 0.3s ease; 
}
form#add-supply button[type="submit"]:hover { background: var(--secondary-blue); }

/* MOBILE RESPONSIVENESS */
@media (max-width: 992px) {
    .sidebar { width: 80%; max-width: 300px; transform: translateX(-100%); z-index: 1050; }
    .sidebar.open { transform: translateX(0); box-shadow: 5px 0 15px rgba(0,0,0,0.2); }
    .sidebar-nav { padding: 0; }
    .sidebar a { margin: 0; border-radius: 0; padding: 15px 30px; border-bottom: 1px solid #f0f0f0; }
    .sidebar .logout { padding: 15px 30px; margin-top: 10px; }
    
    main.main-content { margin-left: 0; padding: 20px; padding-top: 80px; }
    
    .widgets { gap: 15px; }
    .widget span { font-size: 1.8rem; }

    #add-new-supply-btn { width: 100%; }

    /* Modal Mobile */
    .modal-content { max-width: 100%; margin: 5vh auto; padding: 20px; }
    form#add-supply .form-row { flex-direction: column; gap: 10px; margin-bottom: 10px; }
    form#add-supply label, form#add-supply input, form#add-supply select, form#add-supply textarea { flex: none; width: 100%; }
    
    /* Table Responsive */
    section table { display: block; overflow-x: auto; white-space: nowrap; border-spacing: 0; }
    section table thead, section table tbody { display: table; width: 100%; }
    section table th, section table td { min-width: 120px; }
    .batch-table { width: 100%; }

    /* Mobile Header */
    .mobile-header { display: flex; }
    .sidebar-header { justify-content: space-between; border-bottom: 1px solid #e9ecef; }
    .sidebar-header button { display: block !important; background: none; border: none; color: var(--text-light); font-size: 24px; }
}

/* --- ADDED CSS FOR INVISIBLE AUTOMATIC SCROLLBAR (Webkit/Chrome/Safari/Edge) --- */
.sidebar-nav::-webkit-scrollbar {
    width: 6px; 
    height: 6px;
}
.sidebar-nav::-webkit-scrollbar-track {
    background: transparent;
}
.sidebar-nav::-webkit-scrollbar-thumb {
    background-color: transparent; /* Makes it invisible normally */
}
/* Scrollbar appears subtly on hover (optional enhancement) */
.sidebar-nav:hover::-webkit-scrollbar-thumb {
    background-color: rgba(0, 0, 0, 0.2); /* Faint gray on hover */
    border-radius: 10px;
}
</style>
</head>
<body>

<div class="mobile-header" id="mobileHeader">
    <button id="menu-toggle-open"><i class="fas fa-bars"></i></button>
    <div class="mobile-logo">DentiTrack</div>
    <div style="width: 24px;"></div> </div>

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <i class="fas fa-tooth"></i> DentiTrack
        <button id="menu-toggle-close" style="display: none;"><i class="fas fa-times"></i></button>
    </div>
    <nav class="sidebar-nav">
        <a href="secretary_dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
        <a href="find_patient.php" ><i class="fas fa-search"></i> Find Patient</a>
        <a href="view_patients.php"><i class="fas fa-users"></i> Patients List</a>
        <a href="online_bookings.php" ><i class="fas fa-calendar-check"></i> Booking Mgmt</a>
        <a href="appointments.php"><i class="fas fa-calendar-alt"></i> Consultations</a>
        <a href="services_list.php"><i class="fas fa-briefcase-medical"></i> Services</a>
        <a href="inventory.php" class="active"><i class="fas fa-boxes"></i> Inventory Stock</a>
        <a href="payments.php"><i class="fas fa-money-bill-wave"></i> Process Payments</a>
        <a href="pending_installments.php"><i class="fas fa-credit-card"></i> Pending Installments</a>
        <a href="pending_payments.php"><i class="fas fa-credit-card"></i> Pending Payments</a>
        <a href="payment_logs.php"><i class="fas fa-file-invoice-dollar"></i> Payments Log</a>
        <a href="create_announcements.php"><i class="fas fa-bullhorn"></i> Announcements</a>
    </nav>
    <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Log Out</a>
</div>

<main class="main-content">
<header><h1><i class="fas fa-boxes"></i> Dental Supplies Inventory</h1></header>

<?php if ($message): ?>
    <div class="flash-message flash-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($message) ?></div>
<?php elseif ($error): ?>
    <div class="flash-message flash-error"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="widgets">
    <div class="widget"><i class="fas fa-cubes"></i><span><?= $total_supplies_items ?></span><small>Total Unique Items</small></div>
    <div class="widget"><i class="fas fa-exclamation-triangle"></i><span><?= $total_low_stock_items ?></span><small>Items Low Stock</small></div>
    <div class="widget"><i class="fas fa-calendar-xmark"></i><span><?= $total_expired_items ?></span><small>Items Expired</small></div>
</div>

<button type="button" id="add-new-supply-btn" onclick="openModalForNewItem()">
    <i class="fas fa-plus-circle"></i> Add New Supply / Batch
</button>

<section>
<table>
<thead>
    <tr>
        <th>Item Name</th>
        <th>Total Quantity</th>
        <th>Unit</th>
        <th>Threshold</th>
        <th>Description / Status</th>
        <th>Actions</th>
    </tr>
</thead>
<tbody>
<?php if (empty($supplies_grouped)): ?>
<tr><td colspan="6" style="text-align:center; color: var(--text-light);">No supplies found.</td></tr>
<?php else: ?>
<?php foreach ($supplies_grouped as $item): 
    $itemNameKey = htmlspecialchars($item['name']); 
    $rowClass = ($item['status_low'] ? 'low-stock ' : '') . ($item['status_expired'] ? 'expired' : '');
    $isBatched = $item['needs_batch']; 
    $statusText = htmlspecialchars($item['description']) ?: '<span style="opacity:0.7;">N/A</span>';

    if ($item['status_expired']) $statusText = '<i class="fas fa-circle-exclamation" style="color:#dc3545;"></i> **Expired Batch Exists**';
    if ($item['status_low']) $statusText .= '<i class="fas fa-triangle-exclamation" style="color:#ffc107; margin-left: 10px;"></i> **LOW STOCK**';
    
?>
<tr class="main-item <?= $rowClass ?>" data-item-name="<?= $itemNameKey ?>">
    <td><?= $itemNameKey ?></td>
    <td><?= htmlspecialchars($item['total_quantity']) ?></td>
    <td><?= htmlspecialchars($item['unit']) ?></td>
    <td><?= htmlspecialchars($item['low_stock_threshold']) ?></td>
    <td>
        <?= $statusText ?>
        <?php if ($isBatched && count($item['batches']) > 0): ?>
            <button type="button" class="batch-toggle" id="toggle-btn-<?= $itemNameKey ?>" onclick="toggleBatches('<?= $itemNameKey ?>')">View Batches</button>
        <?php else: ?> 
            <span style="opacity:0.6;">(No stock records)</span>
        <?php endif; ?>
    </td>
    <td class="actions">
        <button type="button" class="edit" onclick="loadItemForNewBatch('<?= $itemNameKey ?>')" title="Add New Batch / Edit Item"><i class="fas fa-plus-circle"></i></button>

        <form method="post" onsubmit="return confirm('Delete item \'<?= $itemNameKey ?>\' and ALL its records?');" style="display:inline;">
            <input type="hidden" name="action" value="delete_item">
            <input type="hidden" name="name" value="<?= $itemNameKey ?>">
            <button type="submit" title="Delete Item Group"><i class="fas fa-trash"></i></button>
        </form>
    </td>
</tr>

<?php if ($isBatched && !empty($item['batches'])): ?>
<tr class="batch-row" id="batches-<?= $itemNameKey ?>">
    <td colspan="6">
        <table class="batch-table">
            <thead>
                <tr>
                    <th>Record ID (supply_id)</th>
                    <th>Lot Number</th> 
                    <th>Quantity</th>
                    <th>Expiration Date</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($item['batches'] as $batch): 
                    $batchClass = ($batch['is_expired'] ? 'batch-expired' : '');
                ?>
                <tr class="<?= $batchClass ?>">
                    <td><?= htmlspecialchars($batch['supply_id']) ?></td>
                    <td><?= htmlspecialchars($batch['lot_number']) ?: 'N/A' ?></td> 
                    <td><?= htmlspecialchars($batch['quantity']) ?></td>
                    <td><?= $batch['expiration_date'] ? date('M d, Y', strtotime($batch['expiration_date'])) : 'N/A' ?></td>
                    <td>
                        <?= $batch['is_expired'] ? '<span style="color:#dc3545; font-weight:700;">EXPIRED</span>' : 'OK' ?>
                    </td>
                    <td class="actions">
                        <button type="button" class="edit" onclick="loadBatch('<?= $itemNameKey ?>', <?= $batch['supply_id'] ?>)" title="Edit Batch Record"><i class="fas fa-edit"></i></button>
                        <form method="post" action="inventory.php" onsubmit="return confirm('Delete this specific batch record (ID: <?= $batch['supply_id'] ?>)?');" style="display:inline;">
                            <input type="hidden" name="action" value="delete_batch">
                            <input type="hidden" name="supply_id" value="<?= $batch['supply_id'] ?>">
                            <button type="submit" title="Delete Batch Record"><i class="fas fa-minus-circle"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </td>
</tr>
<?php endif; ?>

<?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>
</section>


<div id="supplyModal" class="modal">
    <div class="modal-content">
        <button type="button" class="close-btn" onclick="closeModal()">&times;</button>
        <form id="add-supply" method="post" action="inventory.php" novalidate>
            <h2>Add New Record / Update Existing</h2>
            <input type="hidden" name="action" value="add_or_update">
            <input type="hidden" name="supply_id" id="supply_id" value="0"> 
            <input type="hidden" name="batch_id" id="batch_id" value="0"> 
            
            <div class="form-row">
                <label for="item_select">Select Existing Item Name</label>
                <select id="item_select" onchange="loadItemDetails(this.value)">
                    <option value="">-- Add New Item Name --</option>
                    <?php foreach ($supplies_grouped as $item): ?>
                        <option value="<?= htmlspecialchars($item['name']) ?>"><?= htmlspecialchars($item['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-row">
                <label for="name_input">Item Name <sup style="color:red">*</sup></label>
                <input type="text" name="name_input" id="name_input" required placeholder="e.g., Composite Resin">
            </div>

            <div class="form-row">
                <label for="description">Description</label>
                <textarea name="description" id="description" placeholder="Manufacturer, model, shade..."></textarea>
            </div>
            
            <div class="form-row">
                <label for="unit">Unit</label>
                <select name="unit" id="unit" required>
                    <option value="pcs">pcs</option>
                    <option value="boxes">boxes</option>
                    <option value="packs">packs</option>
                    <option value="ml">ml</option>
                    <option value="grams">grams</option>
                </select>
            </div>

            <div class="form-row">
                <label for="low_stock_threshold">Low Stock Threshold (for item group)</label>
                <input type="number" name="low_stock_threshold" id="low_stock_threshold" min="0" value="5">
            </div>
            
            <input type="hidden" name="needs_batch" id="needs_batch" value="1"> 


            <h3>Stock Details <span id="batch-status-label" style="font-weight:400; font-size:0.9em;">(New Record)</span></h3>
            
            <div class="form-row">
                <label for="lot_number">Lot Number</label>
                <input type="text" name="lot_number" id="lot_number" placeholder="Optional (e.g., BATCH-123)">
            </div>
            <div class="form-row">
                <label for="quantity">Quantity <sup style="color:red">*</sup></label>
                <input type="number" name="quantity" id="quantity" min="0" value="0" required>
            </div>
            <div class="form-row">
                <label for="expiration_date">Expiration Date</label>
                <input type="date" name="expiration_date" id="expiration_date">
            </div>

            <button type="submit">Save Record</button>
        </form>
    </div>
</div>

</main>

<script>
// Array of item objects, flattened for easy search
const suppliesGrouped = <?= json_encode(array_values($supplies_grouped)) ?>;
const modal = document.getElementById('supplyModal');

// --- MODAL FUNCTIONS ---
function openModal() {
    modal.style.display = 'flex';
}

function closeModal() {
    modal.style.display = 'none';
    resetForm(); // Always reset form when closing the modal
}

function openModalForNewItem() {
    resetForm();
    openModal();
}
// --- END MODAL FUNCTIONS ---

function resetForm() {
    document.getElementById('supply_id').value = 0; 
    document.getElementById('batch_id').value = 0; 
    document.getElementById('name_input').value = '';
    document.getElementById('description').value = '';
    document.getElementById('unit').value = 'pcs';
    document.getElementById('low_stock_threshold').value = 5;
 
    document.getElementById('lot_number').value = ''; 
    document.getElementById('quantity').value = 0;
    document.getElementById('expiration_date').value = '';
    document.getElementById('item_select').value = ''; 
    document.getElementById('name_input').readOnly = false;
    document.getElementById('batch-status-label').textContent = '(New Record)';
}

// Toggle Batches now uses the Item Name as the ID
function toggleBatches(itemName) {
    // Escape single quotes in item name to safely use as an ID fragment
    const safeItemName = itemName.replace(/'/g, '\\\''); 
    
    const batchRow = document.getElementById('batches-' + safeItemName);
    const toggleBtn = document.getElementById('toggle-btn-' + safeItemName);
    
    if (!batchRow || !toggleBtn) return;

    // Find the immediate next sibling which is the batch row
    const actualBatchRow = document.getElementById('batches-' + itemName);

    if (actualBatchRow.style.display === 'none' || actualBatchRow.style.display === '') {
        actualBatchRow.style.display = 'table-row';
        toggleBtn.classList.add('open');
        toggleBtn.textContent = 'Hide Batches';
    } else {
        actualBatchRow.style.display = 'none';
        toggleBtn.classList.remove('open');
        toggleBtn.textContent = 'View Batches';
    }
}

// 1. Load item details (for a new record) when selecting from dropdown or clicking "+"
function loadItemDetails(itemName) {
    resetForm();

    if (itemName === '') return;
    document.getElementById('item_select').value = itemName;

    const item = suppliesGrouped.find(s => s.name === itemName);

    if (item) {
        document.getElementById('name_input').value = item.name;
        document.getElementById('description').value = item.description || '';
        document.getElementById('unit').value = item.unit;
        document.getElementById('low_stock_threshold').value = item.low_stock_threshold;
        document.getElementById('name_input').readOnly = true;

        document.getElementById('supply_id').value = 0;
        document.getElementById('quantity').value = 0;
        document.getElementById('expiration_date').value = '';
        document.getElementById('lot_number').value = ''; 
        
        document.getElementById('batch-status-label').textContent = '(New Record for Existing Item)';
        
        openModal();
    }
}

// 2. Used when clicking the "Add New Batch / Edit Item" button on the table row
function loadItemForNewBatch(itemName) {
    loadItemDetails(itemName);
}

// 3. Used when clicking the "Edit" button on a specific batch row
function loadBatch(itemName, supplyId) {
    const item = suppliesGrouped.find(s => s.name === itemName);

    if (item) {
        // Load the general item details first
        loadItemDetails(itemName); 
        document.getElementById('name_input').readOnly = false; 

        // Find and apply specific batch record details (using supplyId)
        const batchRecord = item.batches.find(b => b.supply_id == supplyId);
        
        if (batchRecord) {
            document.getElementById('supply_id').value = batchRecord.supply_id;
            document.getElementById('quantity').value = batchRecord.quantity;
            
            document.getElementById('lot_number').value = batchRecord.lot_number || '';
            document.getElementById('expiration_date').value = batchRecord.expiration_date ? batchRecord.expiration_date.substring(0, 10) : ''; 
            document.getElementById('batch-status-label').textContent = `(Updating Record ID: ${supplyId})`;
        }
    }
    openModal();
}

// Initial form setup 
document.addEventListener('DOMContentLoaded', () => {
    resetForm();
    
    // Explicit client-side validation for Item Name
    const form = document.getElementById('add-supply');
    form.addEventListener('submit', function(e) {
        const nameInput = document.getElementById('name_input');
        if (nameInput.value.trim() === '') {
            e.preventDefault();
            alert('Item Name is required. Please enter a name for the new item.');
            nameInput.focus();
            return false;
        }
    });
    
    // --- MOBILE MENU TOGGLE ---
    const sidebar = document.getElementById('sidebar');
    const menuToggleOpen = document.getElementById('menu-toggle-open');
    const menuToggleClose = document.getElementById('menu-toggle-close');

    if (window.innerWidth < 992) {
        menuToggleClose.style.display = 'none';

        if (menuToggleOpen) {
            menuToggleOpen.addEventListener('click', function() {
                sidebar.classList.add('open');
                menuToggleClose.style.display = 'block';
                document.body.style.overflow = 'hidden'; 
            });
        }
        
        if (menuToggleClose) {
             menuToggleClose.addEventListener('click', function() {
                sidebar.classList.remove('open');
                menuToggleClose.style.display = 'none';
                document.body.style.overflow = ''; 
            });
        }
        
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
});

// Close modal when clicking outside of it
window.onclick = function(event) {
    if (event.target == modal) {
        closeModal();
    }
}
</script>
</body>
</html>