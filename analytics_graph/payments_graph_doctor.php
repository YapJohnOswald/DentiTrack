<?php
// Payments_graph.php
// Generates a responsive bar chart of total **daily** revenue within a user-defined date range,
// fetching data from the 'payments' table in the DentiTrack database.

// --- 0. Include Database Connection ---
// ASSUMPTION: 'graph_conn.php' contains the established MySQLi connection ($conn)
// and handles connection errors.
include '../analytics_graph/graph_conn.php';

// --- Date Range Helper Function ---
/**
 * Generates a continuous date range for the chart labels and inserts zero counts for days 
 * where no payments were fetched from the database.
 * * @param string $startDate Start date (Y-m-d).
 * @param string $endDate End date (Y-m-d).
 * @param array $dataArray Array of associative arrays from DB results ({'payment_day': 'Y-m-d', 'daily_revenue': 123.45}).
 * @return array Contains 'labels' (M j, Y format), 'revenue' (floats), and 'max_revenue'.
 */
function generateDateRange($startDate, $endDate, $dataArray) {
    $labels = [];
    $revenue = [];
    $max_revenue = 0; 
    
    // Create an associative array for quick lookup of existing revenue using Y-m-d date as key
    $revenue_map = [];
    foreach ($dataArray as $item) {
        // Ensure revenue is treated as a float
        $revenue_map[$item['payment_day']] = (float)$item['daily_revenue'];
    }

    $currentDate = $startDate;
    while (strtotime($currentDate) <= strtotime($endDate)) {
        // 1. Label format: Month Day, Year (M j, Y) - for the Chart.js X-axis
        $labels[] = date('M j, Y', strtotime($currentDate));
        
        // 2. Revenue: Get the revenue from the map, defaulting to 0.0 if not found
        $daily_revenue = $revenue_map[$currentDate] ?? 0.0; 
        $revenue[] = $daily_revenue;

        // 3. Update max count for Y-axis scaling
        if ($daily_revenue > $max_revenue) {
            $max_revenue = $daily_revenue;
        }

        // Move to the next day
        $currentDate = date('Y-m-d', strtotime('+1 day', strtotime($currentDate)));
    }

    return ['labels' => $labels, 'revenue' => $revenue, 'max_revenue' => $max_revenue];
}


// --- 1. Get User Inputs and Set Defaults ---

// The date range is received in Y-m-d format from the HIDDEN inputs for database querying.
// Default range set to 90 days for daily view.
$start_date_db = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-90 days'));
$end_date_db = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

// --- Validation and Correction: Ensure start date is before end date ---
if (strtotime($start_date_db) > strtotime($end_date_db)) {
    // If dates are reversed, swap them to prevent an empty result set
    $temp = $start_date_db;
    $start_date_db = $end_date_db;
    $end_date_db = $temp;
}

// CONVERSION: Format dates for the jQuery UI datepicker TEXT INPUTS' display value (Month Day, Year)
$start_date_display = date('M d, Y', strtotime($start_date_db));
$end_date_display = date('M d, Y', strtotime($end_date_db));


// --- 2. Fetch Data ---
$fetched_data = [];
$error_message = null; // Initialize error message variable

// UPDATED SQL: Aggregate total_amount by DATE(payment_date) instead of month.
$sql = "SELECT
            DATE(payment_date) as payment_day,
            SUM(total_amount) as daily_revenue
        FROM payments
        WHERE DATE(payment_date) BETWEEN ? AND ?
        GROUP BY payment_day
        ORDER BY payment_day ASC";

// Check if connection is successful before preparing the statement
if (isset($conn) && $conn && !$conn->connect_error) {
    if ($stmt = $conn->prepare($sql)) {
        // Bind parameters: "ss" means two string parameters (dates)
        $stmt->bind_param("ss", $start_date_db, $end_date_db);
        $stmt->execute();
        $result = $stmt->get_result();

        while($row = $result->fetch_assoc()) {
            $fetched_data[] = [
                'payment_day' => $row['payment_day'], 
                'daily_revenue' => (float)$row['daily_revenue'] // Cast to float
            ];
        }
        $stmt->close();
    } else {
        error_log("SQL Prepare Failed: " . $conn->error);
        $error_message = "Could not prepare the revenue query.";
    }

    $conn->close();
} else {
    $error_message = "Database connection error. Cannot fetch revenue data.";
}

// --- 3. Process Data for Graph ---
$processed_data = generateDateRange($start_date_db, $end_date_db, $fetched_data);

$labels_json = json_encode($processed_data['labels']);
$revenue_json = json_encode($processed_data['revenue']);
$max_revenue = $processed_data['max_revenue']; 

// Calculate suggested maximum for the Y-axis range
$buffer = max(500, ceil($max_revenue * 0.20)); 
$suggested_max = $max_revenue + $buffer;
$suggested_max_js = json_encode((int)ceil($suggested_max)); // Ensure it's an integer for Chart.js
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Revenue Report</title>
    <!-- Load Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Load jQuery and jQuery UI for custom date picker -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://code.jquery.com/ui/1.13.1/jquery-ui.min.js"></script>
    
    <!-- jQuery UI CSS (Needed for the date picker look) -->
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.13.1/themes/base/jquery-ui.css">

    <style>
        /* Custom styles for the text inputs */
        input[type="text"] {
            border-color: #3B82F6 !important; /* Blue-500 */
        }
        input[type="text"]:focus {
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
        
        <!-- Header: Changed to Daily -->
        <h1 class="text-3xl sm:text-4xl font-extrabold text-primary-blue text-center mb-6">
            Daily Revenue Activity Report
        </h1>

        <!-- Date Range Filter Card -->
        <div class="bg-white p-6 md:p-8 rounded-xl shadow-lg border border-light-blue">
            <h2 class="text-xl font-semibold text-gray-700 mb-4">Select Date Range</h2>
            
            <!-- FORM: Points to user_graph.php -->
            <form method="GET" action="../doctor/doctor_dashboard.php" class="flex flex-col md:flex-row items-end space-y-4 md:space-y-0 md:space-x-6">
                
                <!-- HIDDEN FIELD TO SET THE VIEW PARAMETER -->
                <input type="hidden" name="view" value="payments">

                <div class="flex-1 w-full md:w-auto">
                    <!-- Text field for friendly display -->
                    <label for="start_date_friendly" class="block text-sm font-medium text-gray-600 mb-1">Start Date (Month Day, Year):</label>
                    <input 
                        type="text" 
                        id="start_date_friendly" 
                        value="<?php echo htmlspecialchars($start_date_display); ?>" 
                        required 
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-accent-indigo focus:border-accent-indigo transition duration-150"
                    >
                    <!-- HIDDEN FIELD: Sends Y-m-d (DB Format) to the server -->
                    <input type="hidden" name="start_date" id="start_date_db" value="<?php echo htmlspecialchars($start_date_db); ?>">
                </div>
                
                <div class="flex-1 w-full md:w-auto">
                    <!-- Text field for friendly display -->
                    <label for="end_date_friendly" class="block text-sm font-medium text-gray-600 mb-1">End Date (Month Day, Year):</label>
                    <input 
                        type="text" 
                        id="end_date_friendly" 
                        value="<?php echo htmlspecialchars($end_date_display); ?>" 
                        required 
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-accent-indigo focus:border-accent-indigo transition duration-150"
                    >
                    <!-- HIDDEN FIELD: Sends Y-m-d (DB Format) to the server -->
                    <input type="hidden" name="end_date" id="end_date_db" value="<?php echo htmlspecialchars($end_date_db); ?>">
                </div>
                
                <button 
                    type="submit" 
                    class="w-full md:w-auto px-6 py-2 bg-accent-indigo text-white font-bold rounded-lg shadow-md hover:bg-indigo-600 transition duration-150 ease-in-out transform hover:scale-[1.02]"
                >
                    View Revenue
                </button>
            </form>
            <p class="mt-4 text-xs text-gray-400">
                The visible dates are **Month Day, Year**, but the system submits the unambiguous **Y-m-d** format for reliable database querying.
            </p>
        </div>

        <!-- Chart Card: Changed to Daily -->
        <div class="bg-white p-6 md:p-8 rounded-xl shadow-lg border border-light-blue">
            <h2 class="text-2xl font-semibold text-gray-800 mb-4">Total Revenue by Day (Bar Chart)</h2>
            
            <?php if ($error_message): ?>
                <div class="p-4 bg-red-100 border border-red-400 text-red-700 rounded-lg" role="alert">
                    <p class="font-bold">Error:</p>
                    <p class="text-sm"><?php echo htmlspecialchars($error_message); ?></p>
                </div>
            <?php else: ?>
                <div class="h-96 w-full">
                    <!-- Chart.js Canvas -->
                    <canvas id="PaymentsChart"></canvas>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Chart.js Script & Datepicker Initialization -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // --- JQUERY UI DATEPICKER INITIALIZATION ---
        $(function() {
            // Define the date format used for DISPLAY in the text box (Month Day, Year)
            const displayFormat = "M d, yy";
            // Define the date format used for the SUBMISSION in the hidden field (Y-m-d)
            const dbFormat = "yy-mm-dd"; 

            $("#start_date_friendly").datepicker({
                dateFormat: displayFormat,
                // Link the friendly input to the hidden input for DB submission
                altField: "#start_date_db", 
                altFormat: dbFormat,
                onSelect: function(selectedDate) {
                    $("#end_date_friendly").datepicker("option", "minDate", selectedDate);
                }
            });

            $("#end_date_friendly").datepicker({
                dateFormat: displayFormat,
                // Link the friendly input to the hidden input for DB submission
                altField: "#end_date_db", 
                altFormat: dbFormat,
                onSelect: function(selectedDate) {
                    $("#start_date_friendly").datepicker("option", "maxDate", selectedDate);
                }
            });
        });

        // --- CHART.JS CONFIGURATION ---
        // PHP variables injected into JavaScript
        const chartSuggestedMax = <?php echo $suggested_max_js; ?>;
        const primaryColor = 'rgba(99, 102, 241, 1)'; // Indigo-500
        const lightFillColor = 'rgba(99, 102, 241, 0.4)'; // Indigo-500 with opacity

        const paymentsConfig = {
            type: 'bar',
            data: {
                labels: <?php echo $labels_json; ?>,
                datasets: [{
                    label: 'Daily Revenue',
                    data: <?php echo $revenue_json; ?>,
                    backgroundColor: lightFillColor,
                    borderColor: primaryColor,
                    borderWidth: 1,
                    borderRadius: 4, 
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        labels: { font: { size: 14 } }
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                if (context.parsed.y !== null) {
                                    // Format the revenue as currency
                                    label += new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(context.parsed.y);
                                }
                                return label;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        title: { 
                            display: true, 
                            text: 'Date', // Changed X-axis label
                            color: '#4B5563'
                        },
                        grid: {
                            display: false
                        }
                    },
                    y: { 
                        beginAtZero: true, 
                        title: { 
                            display: true, 
                            text: 'Daily Revenue (USD)', // Changed Y-axis label
                            color: '#4B5563'
                        }, 
                        suggestedMax: chartSuggestedMax,
                        ticks: {
                            callback: function(value, index, ticks) {
                                // Format Y-axis ticks as currency
                                return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', minimumFractionDigits: 0 }).format(value);
                            }
                        },
                        grid: {
                            color: '#E5E7EB'
                        }
                    }
                }
            }
        };
        
        // Render the chart only if the canvas element exists
        const ctx = document.getElementById('PaymentsChart');
        if (ctx) {
            new Chart(ctx, paymentsConfig);
        }
    </script>

</body>
</html>