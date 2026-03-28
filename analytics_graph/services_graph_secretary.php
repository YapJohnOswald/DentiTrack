<?php
// services_graph.php
// Plots the monthly usage trend for a specific service or the ranking of all services within a date range.

// --- 0. Include Database Connection ---
// NOTE: This file now RELIES on '../analytics_graph/graph_conn.php' to define $conn (a mysqli object).
include '../analytics_graph/graph_conn.php'; 

$error_message = null; // Initialize error message for display

// Helper function for robust date conversion from display format (M d, Y) to DB format (Y-m-d)
function convert_display_to_db_date($display_date) {
    if (empty($display_date)) return null;
    $timestamp = strtotime($display_date);
    if ($timestamp === false) {
        return null;
    }
    return date('Y-m-d', $timestamp);
}


// --- 1. Get User Inputs and Format Dates ---

// Determine the start date for the database query (Y-m-d format)
if (isset($_GET['start_date']) && ($db_date = convert_display_to_db_date($_GET['start_date'])) !== null) {
    $start_date_db = $db_date;
} else {
    // Default to 6 months ago
    $start_date_db = date('Y-m-d', strtotime('-6 months'));
}

// Determine the end date for the database query (Y-m-d format)
if (isset($_GET['end_date']) && ($db_date = convert_display_to_db_date($_GET['end_date'])) !== null) {
    $end_date_db = $db_date;
} else {
    // Default to today
    $end_date_db = date('Y-m-d');
}

// --- Validation and Correction: Ensure start date is before or equal to end date ---
if (strtotime($start_date_db) > strtotime($end_date_db)) {
    // Swap dates if they are in the wrong order
    $temp = $start_date_db;
    $start_date_db = $end_date_db;
    $end_date_db = $temp;
}

// Format dates for the text input fields' display value (Month Day, Year)
$start_date_display = date('M d, Y', strtotime($start_date_db));
$end_date_display = date('M d, Y', strtotime($end_date_db));


// --- 2. Get Service ID and List ---
// service_id = 0 is used for the "All Services (Ranking Report)"
$service_id = isset($_GET['service_id']) ? intval($_GET['service_id']) : 0; 
$service_name = ($service_id == 0) ? "All Services" : "Select a Service";
$all_services = [];

// Fetch Service List for Selection (Dropdown)
if (isset($conn) && $conn instanceof mysqli && $conn->ping()) {
    $services_result = $conn->query("SELECT service_id, service_name FROM services ORDER BY service_name ASC");
    if ($services_result) {
        while ($row = $services_result->fetch_assoc()) {
            $all_services[] = $row;
            // Set service_name only if a specific service is selected
            if ($row['service_id'] == $service_id) {
                $service_name = $row['service_name'];
            }
        }
        $services_result->free();
    }
} else {
    $error_message = "Database connection failed. Cannot load service list.";
}


// --- 3. Fetch Usage Data: RANKING (if $service_id == 0) vs. TREND (if $service_id > 0) ---
$labels = [];
$usage_counts = [];
$max_count = 0;
$report_type = ''; // Will be 'Ranking' or 'Trend'

if (isset($conn) && $conn instanceof mysqli && $conn->ping()) {
    
    if ($service_id == 0) {
        // ============== RANKING REPORT (Most Used Services) ==============
        $report_type = 'Ranking';
        
        // SQL: Ranks all services used in the date range.
        $ranking_sql = "SELECT
                            s.service_name,
                            COUNT(a.appointment_id) AS usage_count
                        FROM 
                            appointments a
                        JOIN
                            services s ON a.service_id = s.service_id
                        WHERE
                            a.appointment_date BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)
                        GROUP BY
                            s.service_name
                        ORDER BY
                            usage_count DESC
                        LIMIT 15"; 

        if ($stmt = $conn->prepare($ranking_sql)) {
            // Bind parameters: ss (string, string for the dates)
            $stmt->bind_param("ss", $start_date_db, $end_date_db);
            
            if ($stmt->execute()) {
                $result = $stmt->get_result();

                while($row = $result->fetch_assoc()) {
                    $labels[] = $row['service_name'];
                    $current_count = intval($row['usage_count']);
                    $usage_counts[] = $current_count;
                    if ($current_count > $max_count) {
                        $max_count = $current_count;
                    }
                }
            } else {
                $error_message = "Ranking Query execution failed: " . $stmt->error;
                error_log($error_message);
            }
            $stmt->close();
        } else {
            $error_message = "Ranking Query preparation failed: " . $conn->error;
            error_log($error_message);
        }

    } else {
        // ============== TREND REPORT (Single Service Trend) ==============
        $report_type = 'Trend';
        
        // SQL to aggregate COUNT(a.appointment_id) for the selected service per month
        $sql = "SELECT
                    DATE_FORMAT(a.appointment_date, '%Y-%m') as usage_month,
                    COUNT(a.appointment_id) as usage_count
                FROM appointments a
                WHERE a.service_id = ?
                AND a.appointment_date BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)
                GROUP BY usage_month
                ORDER BY usage_month ASC";

        if ($stmt = $conn->prepare($sql)) {
            // Bind parameters: iss (integer for service_id, string, string for dates)
            $stmt->bind_param("iss", $service_id, $start_date_db, $end_date_db);
            
            if ($stmt->execute()) {
                $result = $stmt->get_result();
            
                while($row = $result->fetch_assoc()) {
                    // Convert 'Y-m' to a display format like 'Nov 2025'
                    $labels[] = date('M Y', strtotime($row['usage_month'] . '-01')); 
                    $current_count = intval($row['usage_count']);
                    $usage_counts[] = $current_count;
                    if ($current_count > $max_count) {
                        $max_count = $current_count;
                    }
                }
            } else {
                $error_message = "Trend Query execution failed: " . $stmt->error;
                error_log($error_message);
            }
            $stmt->close();
        } else {
             $error_message = "Trend Query preparation failed: " . $conn->error;
             error_log($error_message);
        }
    }
} else {
    $error_message = "Database connection is unavailable.";
}

// NOTE: Removed $conn->close() to prevent prematurely closing the connection for other included modules.


$labels_json = json_encode($labels);
$usage_counts_json = json_encode($usage_counts);
$report_type_js = json_encode($report_type); 

// Calculate suggested maximum for the Y-axis range
$y_buffer = max(5, ceil($max_count * 0.1)); 
$suggested_max = $max_count + $y_buffer;
$suggested_max_js = json_encode((int)$suggested_max);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Usage Report</title>
    <script src="https://cdn.tailwindcss.com"></script>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://code.jquery.com/ui/1.13.1/jquery-ui.min.js"></script>
    
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.13.1/themes/base/jquery-ui.css">

    <style>
        /* Custom styles for consistency */
        input[type="text"], select {
            border-color: #3B82F6 !important; /* Blue-500 */
        }
        input[type="text"]:focus, select:focus {
            box-shadow: 0 0 0 2px #93C5FD; /* Blue-300 ring */
        }
    </style>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'primary-blue': '#2563EB', // Blue-600
                        'light-blue': '#BFDBFE', // Blue-200
                        'accent-indigo': '#6366F1', // Indigo-500
                    }
                }
            }
        }
    </script>
</head>

<body class="bg-gray-50 font-sans min-h-screen p-4 sm:p-8">

    <div class="max-w-6xl mx-auto space-y-8">
        
        <h1 class="text-3xl sm:text-4xl font-extrabold text-primary-blue text-center mb-6">
            Service Usage Report
        </h1>

        <div class="bg-white p-6 md:p-8 rounded-xl shadow-lg border border-light-blue">
            <h2 class="text-xl font-semibold text-gray-700 mb-4">Select Report and Date Range</h2>
            
            <form method="GET" action="../secretary/secretary_dashboard.php" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                <input type="hidden" name="view" value="services"> 
                
                <div class="col-span-1 md:col-span-1">
                    <label for="service_id" class="block text-sm font-medium text-gray-600 mb-1">Select Report Type:</label>
                    <select name="service_id" id="service_id" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-accent-indigo focus:border-accent-indigo transition duration-150">
                        <option value="0" <?php echo ($service_id == 0) ? 'selected' : ''; ?>>
                            -- All Services (Ranking Report) --
                        </option>
                        <?php foreach ($all_services as $service): ?>
                            <option value="<?php echo $service['service_id']; ?>" 
                                            <?php echo ($service['service_id'] == $service_id) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($service['service_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-span-1 md:col-span-1">
                    <label for="start_date" class="block text-sm font-medium text-gray-600 mb-1">Start Date:</label>
                    <input 
                        type="text" 
                        name="start_date" 
                        id="start_date" 
                        value="<?php echo htmlspecialchars($start_date_display); ?>" 
                        required 
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-accent-indigo focus:border-accent-indigo transition duration-150"
                    >
                </div>
                
                <div class="col-span-1 md:col-span-1">
                    <label for="end_date" class="block text-sm font-medium text-gray-600 mb-1">End Date:</label>
                    <input 
                        type="text" 
                        name="end_date" 
                        id="end_date" 
                        value="<?php echo htmlspecialchars($end_date_display); ?>" 
                        required 
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-accent-indigo focus:border-accent-indigo transition duration-150"
                    >
                </div>
                
                <div class="col-span-1 md:col-span-1">
                    <button 
                        type="submit" 
                        class="w-full px-6 py-2 bg-accent-indigo text-white font-bold rounded-lg shadow-md hover:bg-indigo-600 transition duration-150 ease-in-out transform hover:scale-[1.02]"
                    >
                        Generate Report
                    </button>
                </div>
            </form>
        </div>

        <div class="bg-white p-6 md:p-8 rounded-xl shadow-lg border border-light-blue">
            <h2 class="text-2xl font-semibold text-gray-800 mb-4">
                <?php if ($report_type == 'Ranking'): ?>
                    📊 Top Used Services Ranking 
                <?php else: ?>
                    📈 Monthly Usage Trend for: <?php echo htmlspecialchars($service_name); ?>
                <?php endif; ?>
                <span class="text-sm font-normal text-gray-500 block">
                    (Data from <?php echo $start_date_display; ?> to <?php echo $end_date_display; ?>)
                </span>
            </h2>
            
            <div class="h-96 w-full">
                <?php if ($error_message !== null): ?>
                    <p class="text-center text-lg text-red-600 py-10 border border-red-300 bg-red-50 rounded-lg">
                        Error: <?php echo htmlspecialchars($error_message); ?><br>
                        Please check your database connection or try a different date range.
                    </p>
                <?php elseif (count($usage_counts) > 0): ?>
                    <canvas id="ServicesChart"></canvas>
                <?php else: ?>
                    <p class="text-center text-lg text-gray-500 py-10">
                        No usage data found for 
                        <?php echo ($service_id == 0) ? "all services" : htmlspecialchars($service_name); ?> 
                        in the selected date range.
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        const chartSuggestedMax = <?php echo $suggested_max_js; ?>;
        const reportType = <?php echo $report_type_js; ?>; // 'Ranking' or 'Trend'
        const primaryColor = 'rgba(63, 131, 248, 1)'; // Tailwind Blue-500
        const lightFillColor = 'rgba(63, 131, 248, 0.6)';

        // Initialize jQuery UI Datepickers
        $(function() {
            // Set the date format to Month Day, Year (e.g., Nov 15, 2025)
            const dateFormat = "M d, yy";

            $("#start_date").datepicker({ dateFormat: dateFormat });
            $("#end_date").datepicker({ dateFormat: dateFormat });
        });

        // Only initialize the chart if data is present
        if (<?php echo json_encode(count($usage_counts) > 0); ?>) {
            
            const isRanking = reportType === 'Ranking';

            const servicesConfig = {
                type: 'bar',
                data: {
                    labels: <?php echo $labels_json; ?>,
                    datasets: [{
                        label: isRanking ? 'Total Appointments' : 'Monthly Usage',
                        data: <?php echo $usage_counts_json; ?>,
                        backgroundColor: lightFillColor,
                        borderColor: primaryColor,
                        borderWidth: 1,
                        borderRadius: 4, 
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    
                    // CRUCIAL CHANGE: Set indexAxis to 'y' for horizontal ranking bars
                    indexAxis: isRanking ? 'y' : 'x',

                    plugins: {
                        legend: { display: true, labels: { font: { size: 14 } } },
                        tooltip: { mode: 'index', intersect: false }
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            // X-axis is the count in Ranking mode
                            title: { 
                                display: true, 
                                text: isRanking ? 'Total Appointments Count' : 'Month and Year',
                                color: '#4B5563'
                            },
                            suggestedMax: isRanking ? chartSuggestedMax : undefined,
                            ticks: { precision: 0 },
                            grid: { color: isRanking ? '#E5E7EB' : 'rgba(0, 0, 0, 0.1)' }
                        },
                        y: { 
                            beginAtZero: true, 
                            // Y-axis is the service name in Ranking mode
                            title: { 
                                display: true, 
                                text: isRanking ? 'Service Name' : 'Usage Count',
                                color: '#4B5563'
                            }, 
                            suggestedMax: isRanking ? undefined : chartSuggestedMax,
                            ticks: { 
                                // Ensure service names or integers are displayed
                                precision: isRanking ? undefined : 0 
                            },
                            grid: { color: isRanking ? 'rgba(0, 0, 0, 0.1)' : '#E5E7EB' }
                        }
                    }
                }
            };
            const ctx = document.getElementById('ServicesChart');
            if (ctx) {
                new Chart(ctx, servicesConfig);
            }
        }
    </script>

</body>
</html>