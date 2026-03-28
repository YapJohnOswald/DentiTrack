<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'secretary') {
    header('Location: secretary_login.php');
    exit();
}

include '../config/db_pdo.php'; // Your PDO connection file

$creatorId = $_SESSION['user_id'] ?? null;
if (!$creatorId) {
    header('Location: secretary_login.php');
    exit();
}

// Fetch patients joined with their user accounts (username, user_id)
$sql = "SELECT p.patient_id, p.full_name, u.user_id, u.username
        FROM patient p
        JOIN users u ON p.user_id = u.user_id
        WHERE p.created_by = :creator
        ORDER BY p.patient_id DESC";

$stmt = $conn->prepare($sql);
$stmt->execute(['creator' => $creatorId]);
$patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>View Patient Accounts</title>
<style>
body { font-family: Arial, sans-serif; background:#eef3ff; color:#003366; padding: 20px; }
table { width: 100%; border-collapse: collapse; margin-top: 20px; }
th, td { padding: 10px; border: 1px solid #ccc; text-align: left; }
th { background: #3399ff; color: white; }
.action-btn { background: #3399ff; border: none; color: white; padding: 6px 12px; border-radius: 4px; cursor: pointer; }
.action-btn:hover { background: #267acc; }
.modal {
  display: none; 
  position: fixed; 
  z-index: 9999; 
  left: 0; top: 0; width: 100%; height: 100%; 
  overflow: auto; 
  background-color: rgba(0,0,0,0.4);
}
.modal-content {
  background-color: #fff;
  margin: 10% auto; 
  padding: 20px;
  border-radius: 8px;
  width: 320px;
  box-shadow: 0 5px 15px rgba(0,0,0,.3);
  position: relative;
}
.close-btn {
  position: absolute;
  right: 15px; top: 10px;
  font-size: 24px;
  font-weight: bold;
  color: #aaa;
  cursor: pointer;
}
.close-btn:hover { color: #000; }
label { display: block; margin-top: 15px; font-weight: 600; }
input[type=text], input[type=password] {
  width: 100%; padding: 8px; margin-top: 5px;
  border: 1px solid #ccc; border-radius: 4px;
}
button[type=submit] {
  margin-top: 20px; width: 100%; background: #3399ff; border: none;
  color: white; padding: 10px; font-size: 16px; border-radius: 6px;
  cursor: pointer;
}
button[type=submit]:hover { background: #267acc; }
</style>
</head>
<body>

<h1>My Created Patient Accounts</h1>

<?php if (isset($_GET['success'])): ?>
<p style="color:green;">Account updated successfully.</p>
<?php endif; ?>

<table>
    <thead>
        <tr>
            <th>Patient Name</th>
            <th>Username</th>
            <th>Action</th>
        </tr>
    </thead>
    <tbody>
        <?php if (!$patients): ?>
        <tr><td colspan="3">No patients found.</td></tr>
        <?php else: ?>
        <?php foreach ($patients as $patient): ?>
        <tr>
            <td><?= htmlspecialchars($patient['full_name']) ?></td>
            <td><?= htmlspecialchars($patient['username']) ?></td>
            <td>
                <button
                    class="edit-account-btn action-btn"
                    data-userid="<?= (int)$patient['user_id'] ?>"
                    data-username="<?= htmlspecialchars($patient['username'], ENT_QUOTES) ?>"
                    aria-label="Edit account for patient <?= htmlspecialchars($patient['full_name'], ENT_QUOTES) ?>"
                >
                    &#9998; Edit Account
                </button>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>

<!-- Modal Popup for Edit Account -->
<div id="editAccountModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="editAccountTitle" aria-hidden="true">
  <div class="modal-content">
    <span class="close-btn" aria-label="Close">&times;</span>
    <h2 id="editAccountTitle">Edit Patient Account</h2>
    <form id="editAccountForm" method="POST" action="save_account.php" novalidate>
      <input type="hidden" name="user_id" id="account_user_id" value="">
      
      <label for="account_username">Username:</label>
      <input type="text" name="username" id="account_username" required autocomplete="username">
      
      <label for="account_password">Password (leave blank to keep current):</label>
      <input type="password" name="password" id="account_password" autocomplete="new-password">
      
      <button type="submit">Save Changes</button>
    </form>
  </div>
</div>

<script>
// Modal logic
const modal = document.getElementById('editAccountModal');
const closeBtn = modal.querySelector('.close-btn');

document.querySelectorAll('.edit-account-btn').forEach(button => {
    button.addEventListener('click', () => {
        document.getElementById('account_user_id').value = button.dataset.userid;
        document.getElementById('account_username').value = button.dataset.username;
        document.getElementById('account_password').value = '';
        modal.style.display = 'block';
        modal.setAttribute('aria-hidden', 'false');
        document.getElementById('account_username').focus();
    });
});

closeBtn.onclick = () => {
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
};

window.onclick = event => {
    if (event.target === modal) {
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
    }
};
</script>

</body>
</html>
