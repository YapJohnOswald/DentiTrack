<?php
require_once '../config/db_pdo.php';
$stmt = $pdo->query("SELECT c.clinic_id, c.clinic_name, p.logo, p.description, p.address, p.contact_number 
                     FROM clinics c 
                     LEFT JOIN clinic_profiles p ON c.clinic_id = p.clinic_id");
$clinics = $stmt->fetchAll();
?>

<section id="clinics">
    <h2 class="section-title">Available Clinics</h2>
    <div class="clinics">
        <?php foreach($clinics as $c): ?>
            <div class="clinic-card">
                <?php if($c['logo']): ?>
                    <img src="../<?= htmlspecialchars($c['logo']) ?>" alt="Clinic Logo">
                <?php else: ?>
                    <img src="../images/default-clinic.png" alt="Default Clinic">
                <?php endif; ?>

                <h3><?= htmlspecialchars($c['clinic_name']) ?></h3>
                <p><?= htmlspecialchars($c['description']) ?></p>
                <p><strong>📍 Address:</strong> <?= htmlspecialchars($c['address']) ?></p>
                <p><strong>📞 Contact:</strong> <?= htmlspecialchars($c['contact_number']) ?></p>
                <a href="clinic_homepage.php?clinic_id=<?= $c['clinic_id'] ?>">Visit Clinic</a>
            </div>
        <?php endforeach; ?>
    </div>
</section>
