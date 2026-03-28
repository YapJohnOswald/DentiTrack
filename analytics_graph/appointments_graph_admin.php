<?php
// Appointments_graph.php
// Generates a responsive bar chart of daily appointments scheduled over a user-defined date range,
// fetching data from the 'appointments' table in the DentiTrack database.

// --- 0. Include Database Connection ---
// ASSUMPTION: 'graph_conn.php' contains the established MySQLi connection ($conn)
// and handles connection errors.
include '../analytics_graph/graph_conn.php';


// --- 1. Get User Inputs and Set Defaults ---
// The date range is received in Y-m-d format from the HIDDEN inputs for database querying.
$start_date_db = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date_db = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');

// CONVERSION: Format dates for the jQuery UI datepicker TEXT INPUTS' display value (Month Day, Year)
$start_date_display = date('M d, Y', strtotime($start_date_db));
$end_date_display = date('M d, Y', strtotime($end_date_db));

/**
 * Generates a continuous date range for the chart labels and inserts zero counts for days 
 * where no appointments were fetched from the database.
 * * @param string $startDate Start date (Y-m-d).
 * @param string $endDate End date (Y-m-d).
 * @param array $dataArray Array of associative arrays from DB results ({'appt_day': 'Y-m-d', 'daily_count': 1}).
 * @return array Contains 'labels' (M j, Y format), 'counts', and 'max_count'.
 */
function generateDateRange($startDate, $endDate, $dataArray) {
    $labels = [];
    $counts = [];
    $max_count = 0; 
    
    // Create an associative array for quick lookup of existing counts using Y-m-d date as key
    $counts_map = [];
    foreach ($dataArray as $item) {
        $counts_map[$item['appt_day']] = $item['daily_count'];
    }

    $currentDate = $startDate;
    while (strtotime($currentDate) <= strtotime($endDate)) {
        // 1. Label format: Month Day, Year (M j, Y) - for the Chart.js X-axis
        $labels[] = date('M j, Y', strtotime($currentDate));
        
        // 2. Count: Get the count from the map, defaulting to 0 if not found
        $count = $counts_map[$currentDate] ?? 0; 
        $counts[] = $count;

        // 3. Update max count for Y-axis scaling
        if ($count > $max_count) {
            $max_count = $count;
        }

        // Move to the next day
        $currentDate = date('Y-m-d', strtotime('+1 day', strtotime($currentDate)));
    }

    return ['labels' => $labels, 'counts' => $counts, 'max_count' => $max_count];
}

// --- 2. Fetch Data ---
$fetched_data = [];
$error_message = null; // Initialize error message here

// SQL query to count appointments by the date part of the 'appointment_date' column.
$sql = "SELECT
             DATE(appointment_date) as appt_day,
             COUNT(appointment_id) as daily_count
        FROM appointments
        WHERE DATE(appointment_date) BETWEEN ? AND ?
        AND status IN ('approved', 'booked', 'completed')
        GROUP BY appt_day
        ORDER BY appt_day ASC";

// Check if connection is successful before preparing the statement
if (isset($conn) && $conn instanceof mysqli) {
    // Check if the connection object is valid and is MySQLi
    if ($stmt = $conn->prepare($sql)) {
        // Bind parameters for security: "ss" means two string parameters (dates)
        $stmt->bind_param("ss", $start_date_db, $end_date_db); 
        $stmt->execute();
        $result = $stmt->get_result();

        while($row = $result->fetch_assoc()) {
            $fetched_data[] = [
                'appt_day' => $row['appt_day'], 
                'daily_count' => (int)$row['daily_count'] // Cast to int for safety
            ];
        }
        $stmt->close();
    } else {
        // Error handling for prepare statement failure
        $error_message = "SQL Prepare Failed: " . $conn->error;
        error_log($error_message);
    }
    // We do NOT close the connection here if the file is included in a larger script 
    // that uses $conn later (e.g., other analytics files). 
    // Let the parent script (secretary_dashboard.php) manage the connection lifecycle.
} else {
    // Fallback message if graph_conn.php failed to establish $conn
    $error_message = "Database connection object is invalid. Cannot fetch appointment data.";
}


// --- 3. Process Data for Graph ---
$processed_data = generateDateRange($start_date_db, $end_date_db, $fetched_data);

$labels_json = json_encode($processed_data['labels']);
$counts_json = json_encode($processed_data['counts']);

// Calculate suggested maximum for the Y-axis range (Max count + 20% buffer, min 5)
$max_count = $processed_data['max_count'];
$buffer = max(5, ceil($max_count * 0.20)); 
$suggested_max = $max_count + $buffer;

$suggested_max_js = json_encode((int)$suggested_max);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Appointments Scheduled Over Time</title>
    <script src="https://cdn.tailwindcss.com"></script> 
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://code.jquery.com/ui/1.13.1/jquery-ui.min.js"></script>
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.13.1/themes/base/jquery-ui.css">

    <style>
        /* Custom styles for the text inputs, overriding jQuery UI default styling for a modern look */
        input[type="text"] {
            border-color: #06B6D4 !important; /* Accent Cyan */
        }
        input[type="text"]:focus {
            box-shadow: 0 0 0 2px #06B6D4; 
        }
    </style>
    <script>
        // Tailwind configuration for custom colors
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'primary-blue': '#2563EB', // Blue-600
                        'light-blue': '#BFDBFE', // Blue-200
                        'accent-cyan': '#06B6D4', // Cyan-500
                    }
                }
            }
        }
    </script>
</head>

<body class="bg-gray-50 font-sans min-h-screen p-4 sm:p-8">

    <div class="max-w-6xl mx-auto space-y-8">
        
        <h1 class="text-3xl sm:text-4xl font-extrabold text-primary-blue text-center mb-6">
            Appointment Daily Activity
        </h1>

        <div class="bg-white p-6 md:p-8 rounded-xl shadow-lg border border-light-blue">
            <h2 class="text-xl font-semibold text-gray-700 mb-4">Select Date Range</h2>
            
            <form method="GET" action="../admin/admin_dashboard.php" class="flex flex-col md:flex-row items-end space-y-4 md:space-y-0 md:space-x-6">
                
                <input type="hidden" name="view" value="appointments">

                <div class="flex-1 w-full md:w-auto">
                    <label for="start_date_friendly" class="block text-sm font-medium text-gray-600 mb-1">Start Date (Month Day, Year):</label>
                    <input 
                        type="text" 
                        name="start_date_friendly" 
                        id="start_date_friendly" 
                        value="<?php echo htmlspecialchars($start_date_display); ?>" 
                        required 
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-accent-cyan focus:border-accent-cyan transition duration-150"
                        placeholder="e.g. Nov 15, 2025"
                    >
                    <input type="hidden" name="start_date" id="start_date_db" value="<?php echo htmlspecialchars($start_date_db); ?>">
                </div>
                
                <div class="flex-1 w-full md:w-auto">
                    <label for="end_date_friendly" class="block text-sm font-medium text-gray-600 mb-1">End Date (Month Day, Year):</label>
                    <input 
                        type="text" 
                        name="end_date_friendly" 
                        id="end_date_friendly" 
                        value="<?php echo htmlspecialchars($end_date_display); ?>" 
                        required 
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-accent-cyan focus:border-accent-cyan transition duration-150"
                        placeholder="e.g. Feb 28, 2026"
                    >
                    <input type="hidden" name="end_date" id="end_date_db" value="<?php echo htmlspecialchars($end_date_db); ?>">
                </div>
                
                <button 
                    type="submit" 
                    class="w-full md:w-auto px-6 py-2 bg-accent-cyan text-white font-bold rounded-lg shadow-md hover:bg-cyan-600 transition duration-150 ease-in-out transform hover:scale-[1.02]"
                >
                    View Appointments
                </button>
            </form>
            <p class="mt-4 text-xs text-gray-400">
                The visible dates are **Month Day, Year**, but the system submits the unambiguous **Y-m-d** format for reliable database querying.
            </p>
        </div>

        <div class="bg-white p-6 md:p-8 rounded-xl shadow-lg border border-light-blue">
            <h2 class="text-2xl font-semibold text-gray-800 mb-4">Appointments Scheduled Daily </h2>
            
            <?php if ($error_message !== null || empty($fetched_data)): ?>
                <div class="p-4 bg-red-100 border border-red-400 text-red-700 rounded-lg" role="alert">
                    <p class="font-bold">No appointments Found</p>
                    <p class="text-sm"><?php echo htmlspecialchars($error_message ?? "No appointment data available for the selected date range."); ?></p>
                </div>
            <?php else: ?>
                <div class="h-96 w-full">
                    <canvas id="AppointmentsChart"></canvas>
                </div>
            <?php endif; ?>
        </div>
    </div>

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
                // These two lines link the visible field to the hidden field, ensuring Y-m-d is submitted
                altField: "#start_date_db", 
                altFormat: dbFormat,
                onSelect: function(selectedDate) {
                    $("#end_date_friendly").datepicker("option", "minDate", selectedDate);
                }
            });

            $("#end_date_friendly").datepicker({
                dateFormat: displayFormat,
                // These two lines link the visible field to the hidden field, ensuring Y-m-d is submitted
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
        const labels = <?php echo $labels_json; ?>;
        const counts = <?php echo $counts_json; ?>;
        
        // Color Definitions
        const primaryColor = 'rgba(6, 182, 212, 1)'; // Cyan-500
        const lightFillColor = 'rgba(6, 182, 212, 0.7)'; // Cyan-500 with opacity

        const apptConfig = {
            type: 'bar', 
            data: {
                labels: labels,
                datasets: [{
                    label: 'Appointments Scheduled',
                    data: counts,
                    backgroundColor: lightFillColor, 
                    borderColor: primaryColor,
                    borderWidth: 2,
                    borderRadius: 6, // Rounded bar corners
                    hoverBackgroundColor: 'rgba(6, 182, 212, 1)'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false, // Allows the chart to fill the parent height (h-96)
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        labels: {
                            font: { size: 14 },
                            color: '#4B5563'
                        }
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        padding: 10,
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        titleFont: { size: 14, weight: 'bold' },
                        bodyFont: { size: 12 },
                        cornerRadius: 6
                    }
                },
                scales: {
                    x: {
                        title: {
                            display: true, 
                            text: 'Date',
                            color: '#4B5563',
                            font: { size: 14, weight: 'bold' }
                        },
                        ticks: {
                            color: '#6B7280' 
                        },
                        grid: {
                            display: false // No vertical grid lines
                        }
                    },
                    y: { 
                        beginAtZero: true, 
                        title: { 
                            display: true, 
                            text: 'Count of Appointments',
                            color: '#4B5563',
                            font: { size: 14, weight: 'bold' }
                        },
                        // Set the max Y value dynamically with a buffer
                        suggestedMax: chartSuggestedMax, 
                        ticks: {
                            color: '#6B7280', 
                            // Ensure only whole numbers are displayed on the Y-axis
                            callback: function(value) {
                                if (value % 1 === 0) {
                                    return value;
                                }
                            }
                        },
                        grid: {
                            color: '#E5E7EB' // Light horizontal grid lines
                        }
                    }
                }
            }
        };
        
        // Render the chart only if the canvas element exists
        const ctx = document.getElementById('AppointmentsChart');
        if (ctx) {
            new Chart(ctx, apptConfig);
        }
    </script>

</body>
</html>