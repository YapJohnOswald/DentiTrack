<?php
// Include database connection
include 'config.php';

if (isset($_GET['search'])) {
    $search = $_GET['search'];
    $stmt = $conn->prepare("SELECT * FROM patients WHERE full_name LIKE ?");
    $search_param = "%" . $search . "%";
    $stmt->bind_param("s", $search_param);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        echo $row['full_name'] . "<br>";
        // Display other patient details as needed
    }
}
?>
