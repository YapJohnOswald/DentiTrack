<?php
session_start();
include '../config/db_pdo.php';
include '../config/db_conn.php';

// ✅ Set timezone to Philippines
date_default_timezone_set('Asia/Manila');

// Ensure PDO throws exceptions
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Check secretary role for access control
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'secretary') {
    die("Access denied.");
}

$search = $_GET['search'] ?? '';

// --- 1. Set CSV Headers ---
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="DentiTrack_Payment_Logs_' . date('Ymd_His') . '.csv"');

$output = fopen('php://output', 'w');

// --- 2. Write CSV Column Headers ---
// **The 'Date Created' column header is here.**
$header_row = [
    'Payment ID', 'Patient Name', 'Service Name', 'Amount (Service)', 
    'Supplies Used (Details)', 'Supplies Total (Cost)', 'Discount Details', 
    'Total Amount Paid (PHP)', 'Payment Method', 'Date Created'
];
fputcsv($output, $header_row);

// --- 3. Pre-fetch Supply Prices for Efficiency ---
$supply_prices = [];
try {
    $stmt_supplies = $conn->query("SELECT supply_id, price FROM dental_supplies");
    while ($row = $stmt_supplies->fetch(PDO::FETCH_ASSOC)) {
        $supply_prices[$row['supply_id']] = floatval($row['price']);
    }
} catch (Exception $e) {
    error_log("Error fetching supply prices: " . $e->getMessage());
}

// --- 4. Fetch and Process Data ---
try {
    $sql = "
        SELECT p.payment_id, CONCAT(pt.first_name, ' ', pt.last_name) AS patient_name,
               s.service_name, p.amount, p.discount_type, p.discount_amount,
               p.total_amount, p.payment_method, p.supplies_used, p.created_at
        FROM payments p
        INNER JOIN patient pt ON p.patient_id = pt.patient_id
        LEFT JOIN services s ON p.service_id = s.service_id
    ";
    
    $params = [];
    if (!empty($search)) {
        $sql .= " WHERE pt.first_name LIKE :search OR pt.last_name LIKE :search OR s.service_name LIKE :search";
        $params[':search'] = "%$search%";
    }
    
    $sql .= " ORDER BY p.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    
    // --- 5. Loop Through Results and Write to CSV ---
    while ($p = $stmt->fetch(PDO::FETCH_ASSOC)) {
        
        $sup_total = 0;
        $supplies_list = '';
        $supplies = json_decode($p['supplies_used'], true);
        
        // Calculate supply total and list details
        if (!empty($supplies)) {
            $list = [];
            foreach ($supplies as $s) {
                $supply_id = $s['id'] ?? 0;
                $qty = intval($s['qty'] ?? 0);
                $price = $supply_prices[$supply_id] ?? 0;
                
                $sup_total += $qty * $price;
                $list[] = htmlspecialchars($s['name']) . ' x' . $qty;
            }
            $supplies_list = implode('; ', $list);
        }

        $discount_text = $p['discount_type'] === 'none' 
                         ? 'No Discount' 
                         : ucfirst($p['discount_type']) . ' (-' . number_format($p['discount_amount'], 2) . ' PHP)';

        $row_data = [
            $p['payment_id'],
            $p['patient_name'],
            $p['service_name'] ?? 'N/A',
            number_format($p['amount'], 2),
            $supplies_list ?: 'N/A',
            number_format($sup_total, 2) ?: '0.00',
            $discount_text,
            number_format($p['total_amount'], 2),
            ucfirst($p['payment_method']),
            // **The 'created_at' date is formatted and included here.**
            date('Y-m-d H:i:s', strtotime($p['created_at']))
        ];

        fputcsv($output, $row_data);
    }

} catch (Exception $e) {
    fputcsv($output, ["ERROR: Failed to fetch payments: " . $e->getMessage()]);
}

fclose($output);
exit;
?>