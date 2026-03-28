<?php
//supply_graph.php
include '../analytics_graph/graph_conn.php';
// SQL to fetch supplies below or near the low_stock_threshold (e.g., within 5 units of the threshold)
$sql = "SELECT name, quantity, low_stock_threshold
        FROM dental_supplies
        WHERE quantity <= low_stock_threshold + 5
        ORDER BY quantity ASC";
$result = $conn->query($sql);

$supply_names = [];
$quantities = [];
$thresholds = [];

if ($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $supply_names[] = $row['name'];
        $quantities[] = $row['quantity'];
        $thresholds[] = $row['low_stock_threshold'];
    }
}

close_db_connection($conn);

// Prepare data as JSON for JavaScript
$supply_names_json = json_encode($supply_names);
$quantities_json = json_encode($quantities);
$thresholds_json = json_encode($thresholds);
?>

    <title>Supply Stock Levels</title>

    <h2>Dental Supply Stock Levels (Low Stock/Near Threshold)</h2>
    <div style="width: 80%; margin: auto;">
        <canvas id="SupplyChart"></canvas>
    </div>

    <script>
        const supplyData = {
            labels: <?php echo $supply_names_json; ?>,
            datasets: [
                {
                    label: 'Current Quantity',
                    data: <?php echo $quantities_json; ?>,
                    backgroundColor: 'rgba(54, 162, 235, 0.5)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 1
                },
                {
                    label: 'Low Stock Threshold',
                    data: <?php echo $thresholds_json; ?>,
                    type: 'line', // Use a line for the threshold
                    fill: false,
                    borderColor: 'rgba(255, 99, 132, 1)',
                    tension: 0.1
                }
            ]
        };

        const config = {
            type: 'bar',
            data: supplyData,
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        };

        new Chart(
            document.getElementById('SupplyChart'),
            config
        );
    </script>

