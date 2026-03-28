<?php
require_once '../config/db_pdo.php';

// Fetch clinic list
$stmt = $pdo->prepare("SELECT c.clinic_id, c.clinic_name, p.logo, p.description, p.address, p.contact_number
                       FROM clinics c
                       LEFT JOIN clinic_profiles p ON c.clinic_id = p.clinic_id");
$stmt->execute();
$clinics = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>DentiTrack - Patient Appointment</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet"
 href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>
/* Keep your clean styling */
body {font-family: 'Segoe UI', Tahoma; background:#f7f9fc; margin:0;}
header {background:#fff;padding:15px 40px;display:flex;justify-content:space-between;align-items:center;box-shadow:0 2px 10px rgba(0,0,0,0.05);}
.logo {font-size:1.8rem;font-weight:700;color:#0077b6;}
main {padding:60px 20px;max-width:1000px;margin:auto;}
.section-title {text-align:center;color:#023e8a;font-size:2rem;margin-bottom:40px;font-weight:700;}

.book-form {background:white;padding:40px;border-radius:15px;box-shadow:0 8px 20px rgba(0,0,0,0.08);border:1px solid #eee;}
.book-form h3 {text-align:center;color:#0077b6;margin-bottom:30px;}
label {display:block;margin-bottom:6px;font-weight:600;color:#023e8a;}
input, select, textarea {
 width:100%;padding:12px;border:1px solid #d0d7de;border-radius:8px;margin-bottom:15px;
 font-size:15px;background:#fcfcfc;
}
input:focus, select:focus, textarea:focus {
 border-color:#00b4d8;outline:none;box-shadow:0 0 5px rgba(0,180,216,0.3);
}
textarea {resize:vertical;}
button {
 background:#00b4d8;color:white;border:none;padding:12px 25px;border-radius:8px;
 cursor:pointer;font-weight:600;width:100%;
}
button:hover {background:#0096c7;}
</style>
</head>

<body>
<header>
 <div class="logo">DentiTrack</div>
</header>

<main>
 <h2 class="section-title">Book an Appointment 🗓️</h2>

 <form class="book-form" method="POST" action="book_service.php">
   <h3>Patient Information</h3>

   <label>Full Name</label>
   <input type="text" name="full_name" placeholder="Juan Dela Cruz" required>

   <label>Email</label>
   <input type="email" name="email" placeholder="juan@example.com" required>

   <label>Contact Number</label>
   <input type="text" name="contact_number" placeholder="09xxxxxxxxx" required>

   <label>Gender</label>
   <select name="gender" required>
     <option value="">-- Select Gender --</option>
     <option value="Male">Male</option>
     <option value="Female">Female</option>
   </select>

   <label>Age</label>
   <input type="number" name="age" min="1" placeholder="e.g., 25" required>

   <h3>Appointment Details</h3>

   <label for="clinicSelect">Select Clinic</label>
   <select name="clinic_id" id="clinicSelect" required onchange="loadServices()">
     <option value="">-- Select Clinic --</option>
     <?php foreach($clinics as $c): ?>
       <option value="<?= $c['clinic_id'] ?>"><?= htmlspecialchars($c['clinic_name']) ?></option>
     <?php endforeach; ?>
   </select>

   <label for="serviceSelect">Select Service</label>
   <select name="service_id" id="serviceSelect" required>
     <option value="">-- Select Service --</option>
   </select>

   <label>Preferred Date</label>
   <input type="date" name="preferred_date" required>

   <label>Preferred Time</label>
   <input type="time" name="preferred_time" required>

   <label>Additional Comments</label>
   <textarea name="comments" placeholder="Any special requests or concerns..."></textarea>

   <button type="submit">Submit Appointment</button>
 </form>
</main>

<script>
function loadServices() {
  const clinicId = document.getElementById('clinicSelect').value;
  const serviceSelect = document.getElementById('serviceSelect');
  serviceSelect.innerHTML = '<option>Loading...</option>';
  if (!clinicId) return;
  fetch('get_services.php?clinic_id=' + clinicId)
  .then(res => res.json())
  .then(data => {
    serviceSelect.innerHTML = '<option value="">-- Select Service --</option>';
    data.forEach(s => {
      const price = parseFloat(s.price).toFixed(0).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
      serviceSelect.innerHTML += `<option value="${s.service_id}">${s.service_name} - ₱${price}</option>`;
    });
  })
  .catch(err => {
    console.error(err);
    serviceSelect.innerHTML = '<option disabled>Error loading services</option>';
  });
}
</script>
</body>
</html>
