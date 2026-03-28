<?php
// Include database connection
include 'db.php';

$result = $conn->query("SELECT * FROM services");

while ($row = $result->fetch_assoc()) {
    echo $row['service_name'] . " - $" . $row['cost'] . "<br>";
    echo $row['description'] . "<br><br>";
}
?>
