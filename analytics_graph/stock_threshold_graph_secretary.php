<?php
error_reporting(0); // Suppress any PHP errors or warnings that might cause unexpected HTML output
// stock_threshold_graph.php
// Shows current stock levels versus the low stock threshold for dental_supplies.

// --- 0. Include Database Connection ---
// NOTE: This file relies on '../analytics_graph/graph_conn.php' to define $conn (a mysqli object).
include '../analytics_graph/graph_conn.php'; 

// SQL to fetch all supplies, ordered by criticality (quantity closest to or below threshold)
$sql = "SELECT name, quantity, low_stock_threshold
        FROM dental_supplies
        ORDER BY (quantity - low_stock_threshold) ASC";

// Check if $conn exists before querying (in case graph_conn.php failed)
$result = (isset($conn) && $conn->ping()) ? $conn->query($sql) : false;

$supply_names = [];
$stock_limit_data = [];        // Data for the 'Stock Limit' line (Threshold)
$restock_needed_data = [];     // Data for RED bars (quantity <= threshold)
$current_stock_data = [];      // Data for BLUE bars (quantity > threshold)
$max_quantity = 0;

if ($result && $result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $supply_names[] = $row['name'];
        $current_quantity = intval($row['quantity']);
        $current_threshold = intval($row['low_stock_threshold']);
        
        $stock_limit_data[] = $current_threshold;
        
        // Update max quantity for Y-axis scaling
        if ($current_quantity > $max_quantity) {
            $max_quantity = $current_quantity;
        }

        // --- COLOR LOGIC: Separate current quantity into two datasets based on threshold ---
        if ($current_quantity <= $current_threshold) {
            // RED: Critical / Restock needed
            $restock_needed_data[] = $current_quantity;
            $current_stock_data[] = null; // Hide the blue bar
        } else {
            // BLUE: Adequate stock
            $restock_needed_data[] = null; // Hide the red bar
            $current_stock_data[] = $current_quantity;
        }
    }
}

// Close the connection
if (isset($conn) && method_exists($conn, 'close')) {
    $conn->close();
}

// Calculate suggested maximum for the Y-axis range
$y_buffer = max(10, ceil($max_quantity * 0.1)); 
$suggested_max = $max_quantity + $y_buffer;
$suggested_max_js = json_encode((int)$suggested_max);

// Prepare data as JSON for JavaScript
$supply_names_json = json_encode($supply_names);
$stock_limit_json = json_encode($stock_limit_data);
$restock_needed_json = json_encode($restock_needed_data);
$current_stock_json = json_encode($current_stock_data);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supply Stock Threshold Check</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'primary-indigo': '#4F46E5', // Indigo-600
                        'light-gray': '#F3F4F6', // Gray-100
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

    <div class="max-w-6xl mx-auto space-y-8">
        
        <h1 class="text-3xl sm:text-4xl font-extrabold text-primary-indigo text-center mb-6">
            Dental Supply Stock Threshold Check
        </h1>

        <div class="bg-white p-6 md:p-8 rounded-xl shadow-2xl border border-gray-200">
            <h2 class="text-xl font-semibold text-gray-800 mb-4 border-b pb-2">
                Current Stock Levels vs. Stock Limit 
                <span class="text-sm font-normal text-gray-500 block sm:inline-block">(Ordered by criticality, most critical on left)</span>
            </h2>
            
            <div class="h-[500px] w-full mt-6">
                <?php if (count($supply_names) > 0): ?>
                    <canvas id="SupplyChart"></canvas>
                <?php else: ?>
                    <p class="text-center text-lg text-gray-500 py-10">
                        No dental supply data found to display.
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <div class="p-4 bg-white rounded-xl shadow border border-gray-200">
            <h3 class="text-lg font-semibold text-gray-800 mb-2">Key:</h3>
            <ul class="flex flex-wrap gap-4 text-sm text-gray-700">
                <li class="flex items-center">
                    <span class="inline-block w-4 h-4 rounded-full bg-red-500 mr-2"></span>
                    <span class="font-bold">Need to Restock:</span> Current stock is at or below the threshold.
                </li>
                <li class="flex items-center">
                    <span class="inline-block w-4 h-4 rounded-full bg-blue-500 mr-2"></span>
                    <span class="font-bold">Current Stock:</span> Current stock is above the threshold.
                </li>
                <li class="flex items-center">
                    <span class="inline-block w-4 h-0 border-t-2 border-red-500 border-dashed mr-2"></span>
                    
                </li>
            </ul>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // PHP variables injected here as JSON
        const chartSuggestedMax = <?php echo $suggested_max_js; ?>;
        
        const supplyData = {
            labels: <?php echo $supply_names_json; ?>,
            datasets: [
                {
                    label: 'Stock Limit', // The threshold line
                    data: <?php echo $stock_limit_json; ?>,
                    type: 'line', 
                    fill: false,
                    // Line color is critical red
                    borderColor: 'rgba(239, 68, 68, 1)', 
                    borderWidth: 3,
                    pointRadius: 5,
                    pointHoverRadius: 8,
                    // Use a dashed line for clear differentiation
                    borderDash: [5, 5] 
                },
                {
                    label: 'Need to Restock', // Red bars for critical items (<= threshold)
                    data: <?php echo $restock_needed_json; ?>,
                    backgroundColor: 'rgba(239, 68, 68, 0.7)', // Red Bar
                    borderColor: 'rgba(239, 68, 68, 1)',
                    borderWidth: 1,
                    borderRadius: 4,
                },
                {
                    label: 'Current Stock', // Blue bars for adequate stock (> threshold)
                    data: <?php echo $current_stock_json; ?>,
                    backgroundColor: 'rgba(59, 130, 246, 0.7)', // Blue Bar
                    borderColor: 'rgba(59, 130, 246, 1)',
                    borderWidth: 1,
                    borderRadius: 4,
                }
            ]
        };

        const config = {
            type: 'bar',
            data: supplyData,
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
                        callbacks: {
                            // Custom tooltip label to show only non-null values for the bar datasets
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    if (context.dataset.type === 'line') {
                                        label += ': ' + context.formattedValue;
                                    } else if (context.parsed.y !== null) {
                                        label += ': ' + context.formattedValue;
                                    } else {
                                        return null; // Do not show null values for the bars
                                    }
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
                            text: 'Dental Supply Item (Most Critical on Left)',
                            color: '#4B5563'
                        },
                        grid: { display: false }
                    },
                    y: { 
                        beginAtZero: true,
                        title: { 
                            display: true, 
                            text: 'Quantity',
                            color: '#4B5563'
                        }, 
                        suggestedMax: chartSuggestedMax,
                        ticks: {
                            precision: 0 // Ensure ticks are integers
                        },
                        grid: {
                            color: '#E5E7EB'
                        }
                    }
                }
            }
        };

        const ctx = document.getElementById('SupplyChart');
        if (ctx) {
            new Chart(ctx, config);
        }
    </script>

</body>
</html>