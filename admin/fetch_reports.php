<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(401);
    exit("Unauthorized Access");
}

// NOTE: Ensure your database connection file path is correct
include '../config/db.php'; 

// Set header for JSON response
header('Content-Type: application/json');

// Get report parameters
$category = $_POST['report_category'] ?? 'appointments';
$global_search = trim($_POST['global_search'] ?? '');
$start_date = $_POST['start_date'] ?? ''; 
$end_date = $_POST['end_date'] ?? ''; 
$report_html = ''; 
$analytics_html_cards = ''; 
$output = []; 

// Helper function for dynamic mysqli binding
function refValues($arr){
    if (strnatcmp(phpversion(),'5.3') >= 0) { // PHP 5.3+
        $refs = array();
        foreach($arr as $key => $value)
            $refs[$key] = &$arr[$key];
        return $refs;
    }
    return $arr;
}

// --- APPOINTMENTS & SERVICES REPORT (REVISED) ---
if ($category === 'appointments') {
    $patient = $_POST['search_patient'] ?? '';
    $service = $_POST['search_service'] ?? '';
    $status = $_POST['search_status'] ?? '';

    // REVISED QUERY: Join appointments with patient and services tables
    $query = "
        SELECT 
            a.appointment_id, 
            a.appointment_date, 
            a.status, 
            p.fullname AS patient_name, 
            p.email, 
            s.service_name AS service
        FROM appointments a
        JOIN patient p ON a.patient_id = p.patient_id
        JOIN services s ON a.service_id = s.service_id
        WHERE 1=1
    ";
    
    $params = [];
    $types = "";

    // Apply Filters - Targets the correct joined columns
    if(!empty($start_date)) { $query .= " AND a.appointment_date >= ?"; $types .= "s"; $params[] = $start_date; }
    if(!empty($end_date)) { $query .= " AND a.appointment_date <= ?"; $types .= "s"; $params[] = $end_date; }
    if(!empty($patient)) { $query .= " AND p.fullname = ?"; $types .= "s"; $params[] = $patient; }
    if(!empty($service)) { $query .= " AND s.service_name = ?"; $types .= "s"; $params[] = $service; }
    if(!empty($status)) { $query .= " AND a.status = ?"; $types .= "s"; $params[] = $status; }
    
    // Global Search - Targets joined columns (fullname, email, service_name)
    if(!empty($global_search)) { 
        $search_term = "%" . $global_search . "%";
        $query .= " AND (p.fullname LIKE ? OR p.email LIKE ? OR s.service_name LIKE ?)";
        $types .= "sss"; $params[] = $search_term; $params[] = $search_term; $params[] = $search_term;
    }

    $query .= " ORDER BY a.appointment_date DESC, a.appointment_time DESC";
    
    $stmt = $conn->prepare($query);
    if($stmt){
        if(!empty($params)){ 
            call_user_func_array([$stmt, 'bind_param'], refValues(array_merge([$types], $params)));
        }
        $stmt->execute();
        $result = $stmt->get_result();

        $report_html .= '<h2>Appointments & Service Report</h2>';
        
        if ($result->num_rows > 0) {
            $data = $result->fetch_all(MYSQLI_ASSOC);
            
            // --- ANALYTICS CALCULATION: APPOINTMENTS ---
            $total_appointments = count($data);
            $completed_count = 0;
            $cancelled_count = 0;
            $service_counts = [];
            
            foreach($data as $row) {
                if ($row['status'] === 'completed') $completed_count++;
                if ($row['status'] === 'cancelled') $cancelled_count++;
                $service_counts[$row['service']] = ($service_counts[$row['service']] ?? 0) + 1;
            }
            
            arsort($service_counts);
            $top_service = key($service_counts) ?? 'N/A';
            $top_service_count = current($service_counts) ?? 0;
            
            // --- ANALYTICS HTML OUTPUT ---
            $analytics_html_cards = '
                <div class="summary-card card-blue">
                    <h4>Total Appointments Found</h4>
                    <p>' . $total_appointments . '</p>
                </div>
                <div class="summary-card card-green">
                    <h4>Completed Appointments</h4>
                    <p>' . $completed_count . '</p>
                </div>
                <div class="summary-card card-red">
                    <h4>Cancelled Appointments</h4>
                    <p>' . $cancelled_count . '</p>
                </div>
                <div class="summary-card card-orange">
                    <h4>Top Service: ' . htmlspecialchars($top_service) . '</h4>
                    <p>' . $top_service_count . '</p>
                </div>';
            
            // --- TABLE GENERATION ---
            $report_html .= '<table><thead><tr><th>ID</th><th>Patient</th><th>Service</th><th>Date</th><th>Status</th></tr></thead><tbody>';
            foreach($data as $row){
                $report_html .= '<tr>
                                        <td>'.$row['appointment_id'].'</td>
                                        <td>'.htmlspecialchars($row['patient_name']).'</td>
                                        <td>'.htmlspecialchars($row['service']).'</td>
                                        <td>'.$row['appointment_date'].'</td>
                                        <td><span class="status-'.strtolower($row['status']).'">'.htmlspecialchars($row['status']).'</span></td>
                                    </tr>';
            }
            $report_html .= '</tbody></table>';
        } else {
            $report_html .= '<p class="no-data"><i class="fas fa-info-circle"></i> No appointments found matching the current filters.</p>';
        }
        $stmt->close();
    } else {
        $report_html .= '<p class="error-msg"><i class="fas fa-exclamation-triangle"></i> Database error: ' . htmlspecialchars($conn->error) . '</p>';
    }

} 
// --- INVENTORY REPORT (UNCHANGED) ---
elseif ($category === 'dental_supplies') {
    // NOTE: This section assumes a table named 'dental_supplies' with columns: name, category, quantity, low_stock_threshold, date_added.
    $query = "SELECT * FROM dental_supplies WHERE 1=1";
    $params = [];
    $types = "";

    // Apply Filters (Date filter assumes a 'date_added' column)
    if(!empty($start_date)) { $query .= " AND date_added >= ?"; $types .= "s"; $params[] = $start_date; }
    if(!empty($end_date)) { $query .= " AND date_added <= ?"; $types .= "s"; $params[] = $end_date; }
    if(!empty($global_search)) { 
        $search_term = "%" . $global_search . "%";
        $query .= " AND (name LIKE ? OR batch_id LIKE ? OR category LIKE ?)";
        $types .= "sss"; $params[] = $search_term; $params[] = $search_term; $params[] = $search_term;
    }
    
    $query .= " ORDER BY name ASC";
    
    $stmt = $conn->prepare($query);
    if($stmt){
        if(!empty($params)){ 
            call_user_func_array([$stmt, 'bind_param'], refValues(array_merge([$types], $params)));
        }
        $stmt->execute();
        $result = $stmt->get_result();

        $report_html .= '<h2>Supplies Inventory Report</h2>';

        if ($result->num_rows > 0) {
            $data = $result->fetch_all(MYSQLI_ASSOC);

            // --- ANALYTICS CALCULATION: INVENTORY ---
            $total_items = count($data);
            $total_quantity = array_sum(array_column($data, 'quantity'));
            $low_stock_count = 0;
            $unique_categories = count(array_unique(array_column($data, 'category')));
            
            foreach($data as $row) {
                $threshold = $row['low_stock_threshold'] ?? 5; // Use 5 as a fallback
                if ($row['quantity'] <= $threshold) {
                    $low_stock_count++;
                }
            }
            
            // --- ANALYTICS HTML OUTPUT ---
            $analytics_html_cards = '
                <div class="summary-card card-blue">
                    <h4>Total Unique Items</h4>
                    <p>' . $total_items . '</p>
                </div>
                <div class="summary-card card-green">
                    <h4>Total Quantity in Stock</h4>
                    <p>' . number_format($total_quantity, 0) . '</p>
                </div>
                <div class="summary-card card-red">
                    <h4>Items Below Threshold</h4>
                    <p>' . $low_stock_count . '</p>
                </div>
                <div class="summary-card card-orange">
                    <h4>Product Categories</h4>
                    <p>' . $unique_categories . '</p>
                </div>';
            
            // --- TABLE GENERATION ---
            $report_html .= '<table><thead><tr><th>Name</th><th>Category</th><th>Quantity</th><th>Threshold</th></tr></thead><tbody>';
            foreach($data as $row){
                   $report_html .= '<tr><td>'.htmlspecialchars($row['name']).'</td><td>'.htmlspecialchars($row['category']).'</td><td>'.$row['quantity'].'</td><td>'.$row['low_stock_threshold'].'</td></tr>';
            }
            $report_html .= '</tbody></table>';
        } else {
               $report_html .= '<p class="no-data"><i class="fas fa-info-circle"></i> No inventory items found matching the current filters.</p>';
        }
        $stmt->close();
    } else {
        $report_html .= '<p class="error-msg"><i class="fas fa-exclamation-triangle"></i> Database error: ' . htmlspecialchars($conn->error) . '</p>';
    }
}
// --- USER ACTIVITY LOG REPORT (UNCHANGED) ---
elseif ($category === 'activity_log') {
    // NOTE: This section assumes a table named 'user_logs' with columns: username, action_type, timestamp, ip_address.
    $query = "SELECT log_id, username, action_type, timestamp, ip_address FROM user_logs WHERE 1=1";
    $params = [];
    $types = "";

    // Apply Filters
    if(!empty($start_date)) { $query .= " AND DATE(timestamp) >= ?"; $types .= "s"; $params[] = $start_date; }
    if(!empty($end_date)) { $query .= " AND DATE(timestamp) <= ?"; $types .= "s"; $params[] = $end_date; }
    if(!empty($global_search)) { 
        $search_term = "%" . $global_search . "%";
        $query .= " AND (username LIKE ? OR action_type LIKE ? OR ip_address LIKE ?)";
        $types .= "sss"; $params[] = $search_term; $params[] = $search_term; $params[] = $search_term;
    }

    $query .= " ORDER BY timestamp DESC";
    
    $stmt = $conn->prepare($query);
    if($stmt){
        if(!empty($params)){ 
            call_user_func_array([$stmt, 'bind_param'], refValues(array_merge([$types], $params)));
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        $report_html .= '<h2>User Activity Log</h2>';

        if ($result->num_rows > 0) {
            $data = $result->fetch_all(MYSQLI_ASSOC);
            
            // --- ANALYTICS CALCULATION: LOGS ---
            $total_logs = count($data);
            $successful_logins = 0;
            $failed_logins = 0;
            $unique_users = count(array_unique(array_column($data, 'username')));
            
            foreach($data as $row) {
                $action = strtolower($row['action_type']);
                if (strpos($action, 'successful login') !== false) {
                    $successful_logins++;
                } elseif (strpos($action, 'failed login') !== false) {
                    $failed_logins++;
                }
            }
            
            // --- ANALYTICS HTML OUTPUT ---
            $analytics_html_cards = '
                <div class="summary-card card-blue">
                    <h4>Total Log Entries</h4>
                    <p>' . $total_logs . '</p>
                </div>
                <div class="summary-card card-green">
                    <h4>Successful Logins</h4>
                    <p>' . $successful_logins . '</p>
                </div>
                <div class="summary-card card-red">
                    <h4>Failed Login Attempts</h4>
                    <p>' . $failed_logins . '</p>
                </div>
                <div class="summary-card card-orange">
                    <h4>Unique Users Logged</h4>
                    <p>' . $unique_users . '</p>
                </div>';
            
            // --- TABLE GENERATION ---
            $report_html .= '<table><thead><tr><th>ID</th><th>User</th><th>Action Type</th><th>Timestamp</th><th>IP</th></tr></thead><tbody>';
            foreach($data as $row){
                   $report_html .= '<tr><td>'.$row['log_id'].'</td><td>'.htmlspecialchars($row['username']).'</td><td>'.htmlspecialchars($row['action_type']).'</td><td>'.$row['timestamp'].'</td><td>'.htmlspecialchars($row['ip_address']).'</td></tr>';
            }
            $report_html .= '</tbody></table>';
        } else {
               $report_html .= '<p class="no-data"><i class="fas fa-info-circle"></i> No activity logs found matching the current filters.</p>';
        }
        $stmt->close();
    } else {
        $report_html .= '<p class="error-msg"><i class="fas fa-exclamation-triangle"></i> Database error: ' . htmlspecialchars($conn->error) . '</p>';
    }
}
else {
     $report_html .= '<p class="error-msg"><i class="fas fa-exclamation-triangle"></i> Invalid report category selected.</p>';
}


// Add simple CSS styles to the report HTML for the frontend to pick up
$report_html .= '
<style>
    /* Status Styles (for Appointments) */
    .status-completed { color: white; background-color: #28a745; padding: 3px 8px; border-radius: 4px; font-weight: 600; font-size: 12px; }
    .status-scheduled { color: white; background-color: #007bff; padding: 3px 8px; border-radius: 4px; font-weight: 600; font-size: 12px; }
    .status-pending { color: white; background-color: #ffc107; padding: 3px 8px; border-radius: 4px; font-weight: 600; font-size: 12px; }
    .status-cancelled { color: white; background-color: #dc3545; padding: 3px 8px; border-radius: 4px; font-weight: 600; font-size: 12px; }

    /* Message Styles */
    .no-data, .error-msg {
        text-align: center;
        padding: 20px;
        margin-top: 30px;
        border-radius: 8px;
        font-size: 1.1em;
        font-weight: 500;
        display: block;
    }
    .no-data { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
    .error-msg { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    
    h2 { 
        font-size: 1.5rem; 
        color: #f3f5f7ff; 
        margin-bottom: 15px;  
        padding-bottom: 10px;
    }
</style>';


// Final JSON output object
$output = [
    'analytics_html' => $analytics_html_cards,
    'report_html' => $report_html
];

echo json_encode($output);

?>