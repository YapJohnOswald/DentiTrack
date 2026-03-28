<?php
// user_logins_graph.php (or userlog_graph.php)
// Generates a graph of daily TOTAL login event counts over a user-defined date range.

// Suppress errors that might break JSON/JS if the database connection fails on the server
error_reporting(E_ALL & ~E_NOTICE); 

// --- 0. Database Connection ---
// This line assumes graph_conn.php is in the same directory as this file.
if (file_exists('../analytics_graph/graph_conn.php')) {
    include '../analytics_graph/graph_conn.php';
} else {
    // Fallback if the connection file is missing
    $conn = null;
}

// --- 1. Get User Inputs ---
$start_date = isset($_GET['start_date']) ? htmlspecialchars($_GET['start_date']) : date('Y-m-d', strtotime('-30 days'));
$end_date = isset($_GET['end_date']) ? htmlspecialchars($_GET['end_date']) : date('Y-m-d');

// --- 2. Fetch Data ---
$labels = [];
$login_counts = [];
$max_logins = 0;
$db_status_message = "";

// SQL: COUNT(log_id) to count the total number of login events per day
$sql = "SELECT
            DATE(timestamp) as login_day,
            COUNT(log_id) as daily_logins
        FROM user_logs 
        WHERE DATE(timestamp) >= ? AND DATE(timestamp) <= ?
        GROUP BY login_day
        ORDER BY login_day ASC";

if ($conn) {
    // Check if table exists (optional, but good practice)
    $table_check_result = @$conn->query("SHOW TABLES LIKE 'user_logs'");

    if ($table_check_result && $table_check_result->num_rows > 0) {
        $stmt = @$conn->prepare($sql);
        
        if ($stmt) {
            $stmt->bind_param("ss", $start_date, $end_date);
            if ($stmt->execute()) {
                $result = $stmt->get_result();

                while($row = $result->fetch_assoc()) {
                    $labels[] = date('M j, Y', strtotime($row['login_day']));
                    $count = intval($row['daily_logins']);
                    $login_counts[] = $count;
                    if ($count > $max_logins) {
                        $max_logins = $count;
                    }
                }
            } else {
                 $db_status_message = "Error executing query: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $db_status_message = "Error preparing SQL statement.";
        }
    } else {
        $db_status_message = "Error: The required 'user_logs' table is missing or inaccessible.";
    }

    if ($conn) {
        @$conn->close();
    }
} else {
    $db_status_message = "Error: Database connection failed (check graph_conn.php and its credentials).";
}


// Fallback data if no results or connection error
if (empty($labels)) {
    $labels = ["No Data"];
    $login_counts = [0];
}

$labels_json = json_encode($labels);
$login_counts_json = json_encode($login_counts);
$suggested_max = max(10, $max_logins + ceil($max_logins * 0.2)); 
$suggested_max_js = json_encode((int)$suggested_max);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Total Login Activity Graph</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'primary-orange': '#F97316', // Orange-600
                        'light-gray': '#F9FAFB', // Gray-50
                    },
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                    },
                }
            }
        }
    </script>
</head>
<body class="bg-light-gray font-sans min-h-screen p-4 sm:p-8">

    <div class="max-w-4xl mx-auto space-y-6">
        
        <h1 class="text-3xl sm:text-4xl font-extrabold text-gray-800 text-center mb-6">
            System Total Login Activity
        </h1>

        <div class="bg-white p-4 md:p-6 rounded-xl shadow-lg border border-gray-200">
            <form method="GET" action="../analytics_graph/analytics_admin.php" class="flex flex-col sm:flex-row items-center justify-between gap-4">
                
                <input type="hidden" name="view" value="user_logins">
                
                <div class="flex-grow w-full sm:w-auto">
                    <label for="start_date" class="block text-sm font-medium text-gray-700 mb-1">Start Date:</label>
                    <input type="date" name="start_date" value="<?php echo $start_date; ?>" required
                            class="w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-orange focus:ring-primary-orange transition duration-150 ease-in-out p-2.5">
                </div>
                
                <div class="flex-grow w-full sm:w-auto">
                    <label for="end_date" class="block text-sm font-medium text-gray-700 mb-1">End Date:</label>
                    <input type="date" name="end_date" value="<?php echo $end_date; ?>" required
                            class="w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-orange focus:ring-primary-orange transition duration-150 ease-in-out p-2.5">
                </div>
                
                <button type="submit"
                        class="w-full sm:w-auto mt-auto py-2.5 px-6 bg-primary-orange text-white font-semibold rounded-lg shadow-md hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-primary-orange focus:ring-offset-2 transition duration-300 ease-in-out">
                    View Logins
                </button>
            </form>
        </div>
        
        <?php if (!empty($db_status_message)): ?>
            <div class="p-4 bg-red-100 rounded-lg border border-red-300 text-red-800 font-semibold text-sm">
                <p>Database Error: <?php echo $db_status_message; ?></p>
            </div>
        <?php endif; ?>

        <div class="bg-white p-6 md:p-8 rounded-xl shadow-2xl border border-gray-200">
            <h2 class="text-xl font-semibold text-gray-800 mb-4 border-b pb-2">
                Daily Total Login Events (<?php echo date('M j, Y', strtotime($start_date)); ?> to <?php echo date('M j, Y', strtotime($end_date)); ?>)
            </h2>
            
            <?php if (count($labels) === 1 && $login_counts[0] === 0 && empty($db_status_message)): ?>
                <p class="text-center text-lg text-gray-500 py-10">
                    No login data found for the selected date range in the 'user_logs' table.
                </p>
            <?php else: ?>
                <div class="h-[400px] w-full mt-6">
                    <canvas id="UserlogChart"></canvas>
                </div>
            <?php endif; ?>
        </div>

    </div>

    <script>
        // PHP variables injected here as JSON
        const labels = <?php echo $labels_json; ?>;
        const loginCounts = <?php echo $login_counts_json; ?>;
        const suggestedMax = <?php echo $suggested_max_js; ?>;

        const userConfig = {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Total Login Events', 
                    data: loginCounts,
                    backgroundColor: 'rgba(249, 115, 22, 0.8)', // Primary Orange
                    borderColor: 'rgba(249, 115, 22, 1)',
                    borderWidth: 1,
                    borderRadius: 6,
                    hoverBackgroundColor: 'rgba(249, 115, 22, 1)',
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            font: { size: 14 }
                        }
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                    }
                },
                scales: {
                    x: {
                        title: { 
                            display: true, 
                            text: 'Date',
                            color: '#4B5563'
                        },
                        grid: { display: false }
                    },
                    y: { 
                        beginAtZero: true, 
                        suggestedMax: suggestedMax,
                        title: { 
                            display: true, 
                            text: 'Total Login Count', 
                        },
                        ticks: {
                            precision: 0 // Ensure y-axis labels are integers
                        },
                        grid: {
                            color: '#E5E7EB'
                        }
                    }
                }
            }
        };

        const ctx = document.getElementById('UserlogChart');
        if (ctx) {
            new Chart(ctx, userConfig);
        }
    </script>

</body>
</html>