<?php
// light_blue_dentrack_module.php
// A clean, light-blue themed graph module designed for safe inclusion.

// Define the available graphs: [URL_View_Key => [File_Name, Display_Label]]
$graphs = [
    'appointments'      => ['../analytics_graph/appointments_graph_secretary.php', 'Appointments Trend'],
    'payments'          => ['../analytics_graph/payments_graph_secretary.php', 'Monthly Revenue'],
    'services'          => ['../analytics_graph/services_graph_secretary.php', 'Service Usage Trend'],
    'stock_threshold'   => ['../analytics_graph/stock_threshold_graph_secretary.php', 'Stock Thresholds'],
    // REMOVED: 'low_stock' => ['../analytics_graph/supply_graph.php', 'Low Stock Items'],
    // REMOVED: 'user_logins' => ['../analytics_graph/userlog_graph.php', 'User Logins']
];

// --- 1. Get User Selection and Parameters ---
// array_key_first() safely gets the first key, even if $graphs has only one or zero entries.
$default_view = array_key_first($graphs); 
// Ensure $view is a valid graph key, otherwise use the (new) default view.
$view = isset($_GET['view']) && array_key_exists($_GET['view'], $graphs) ? $_GET['view'] : $default_view;

// Only proceed if a graph is actually defined/selected
$selected_graph_file = $view ? $graphs[$view][0] : null;

// --- 2. Pass Parameters to Included Files (Essential for Functionality) ---
// Set global $_GET variables that the included graph files will use for filtering.
// MODIFICATION: Default service_id is now 0 for "All Services (Ranking Report)"
$_GET['service_id'] = isset($_GET['service_id']) ? (int)$_GET['service_id'] : 0; 
$_GET['start_date'] = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-30 days'));
$_GET['end_date'] = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
<style>
    /* Unique CSS for the Dashboard Module (dentrack-) */
    :root {
        --dentrack-light-blue: #e9f5ff; /* Very Light Blue for background accents */
        --dentrack-primary-blue: #007bff; /* Standard Link/Active Blue */
        --dentrack-dark-blue: #0056b3; /* Darker Blue for Contrast */
        --dentrack-text-color: #495057;
        --dentrack-white: #ffffff;
    }
    
    .dentrack-header {
        background-color: var(--dentrack-white);
        color: var(--dentrack-text-color);
        padding: 10px 0 0 0; 
        text-align: center;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05); 
        margin-bottom: 0; 
        border-bottom: 1px solid #dee2e6;
    }
    .dentrack-header h1 {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        margin-top: 0;
        margin-bottom: 15px;
        font-size: 1.8em;
        font-weight: 500;
        color: var(--dentrack-dark-blue);
    }
    
    .dentrack-nav-bar {
        display: flex;
        justify-content: center;
        flex-wrap: wrap;
        gap: 0;
        margin-top: 0;
        background-color: var(--dentrack-light-blue); 
        border-bottom: 2px solid var(--dentrack-primary-blue);
    }
    .dentrack-nav-bar a {
        padding: 12px 20px;
        text-decoration: none;
        color: var(--dentrack-text-color);
        background-color: transparent;
        border-bottom: 3px solid transparent;
        transition: all 0.2s ease-in-out;
        font-weight: 500;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        border-right: 1px solid #e0e0e0;
    }
    .dentrack-nav-bar a:first-child { border-left: 1px solid #e0e0e0; }

    .dentrack-nav-bar a:hover {
        background-color: var(--dentrack-white); 
        color: var(--dentrack-primary-blue);
    }
    .dentrack-nav-bar a.dentrack-active {
        background-color: var(--dentrack-white); 
        color: var(--dentrack-primary-blue);
        border-bottom-color: var(--dentrack-primary-blue);
        font-weight: 600;
    }

    .dentrack-main-container {
        max-width: 1200px;
        margin: 25px auto; 
        padding: 30px; 
        background-color: var(--dentrack-white);
        border-radius: 8px; 
        box-shadow: 0 0 15px rgba(0, 0, 0, 0.08); 
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        color: var(--dentrack-text-color);
    }
    
    .dentrack-graph-area {
        min-height: 400px;
        display: flex;
        justify-content: center;
        flex-direction: column; 
        align-items: center;
        padding-top: 10px; 
    }
    .dentrack-graph-area > div {
        width: 100%; 
        max-width: 900px; 
        padding: 10px;
    }
</style>
<body class="analytics-body">

<div class="dentrack-header">
    <h1>Analytics Dashboard</h1>
</div>

<div class="dentrack-nav-bar">
    <?php foreach ($graphs as $key => $details): ?>
        <a href="?view=<?php echo htmlspecialchars($key); ?>" 
           class="<?php echo ($view === $key) ? 'dentrack-active' : ''; ?>">
            <?php echo htmlspecialchars($details[1]); ?>
        </a>
    <?php endforeach; ?>
</div>

<div class="dentrack-main-container">
    <div class="dentrack-graph-area">
        <div>
            <?php
            // THE CORE STEP: Include the PHP file of the selected graph.
            if ($selected_graph_file && file_exists($selected_graph_file)) {
                include $selected_graph_file;
            } else if ($selected_graph_file === null) {
                // This case handles when the $graphs array is empty.
                echo "<p style='color: #007bff; font-weight: bold;'>No analytics graphs are currently configured for display.</p>";
            } else {
                echo "<p style='color: red; font-weight: bold;'>Error: Graph file '{$selected_graph_file}' not found. Please ensure all graph files are in the same folder.</p>";
            }
            ?>
        </div>
    </div>
</div>
</body>