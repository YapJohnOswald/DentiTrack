<?php

require_once '../config/db_pdo.php';
require_once '../config/db_conn.php';

// Check if patient is logged in
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'patient') {
    header('Location: ../public/login.php');
    exit();
}

// Fetch announcements using PDO
try {
    $stmt = $conn->query("SELECT * FROM announcements ORDER BY posted_at DESC");
    $announcements = $stmt->fetchAll();
} catch (PDOException $e) {
    die("Failed to fetch announcements: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Patient Announcements - DentiTrack</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
<style>
    html { scroll-behavior: smooth; }
    body {
        margin: 0;
        font-family: 'Segoe UI', sans-serif;
        background: #e6f0ff;
        color: #003366;
        display: flex;
        min-height: 100vh;
    }

    /* Sidebar */
    .sidebar {
        width: 220px;
        background: linear-gradient(to bottom, #3399ff, #0066cc);
        padding: 20px;
        color: white;
        display: flex;
        flex-direction: column;
    }
    .sidebar h2 {
        text-align: center;
        margin-bottom: 30px;
        font-size: 24px;
        font-weight: 700;
        text-shadow: 1px 1px 2px rgba(0,0,0,0.3);
    }
    .sidebar a {
        display: block;
        padding: 12px 20px;
        margin: 10px 0;
        color: #cce0ff;
        text-decoration: none;
        border-left: 4px solid transparent;
        font-weight: 600;
        transition: 0.3s;
        border-radius: 8px;
    }
    .sidebar a:hover,
    .sidebar a.active {
        background-color: rgba(255,255,255,0.15);
        color: white;
        border-left: 4px solid #ffcc00;
    }

    /* Main Content */
    .main-content {
        flex: 1;
        padding: 40px;
        background: #f8fbff;
        overflow-y: auto;
    }

    header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 30px;
        border-bottom: 3px solid #007bff;
        padding-bottom: 10px;
    }
    header h1 {
        font-size: 2rem;
        color: #004080;
        text-shadow: 1px 1px 2px #a3c2ff;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    /* Announcement Board Layout */
    .announcement-board {
        max-width: 900px;
        margin: 0 auto;
        display: flex;
        flex-direction: column;
        gap: 25px;
    }

    /* Announcement Card */
    .announcement-card {
        background: #ffffff;
        border-radius: 15px;
        box-shadow: 0 6px 15px rgba(0,0,0,0.1);
        padding: 25px;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        border-left: 6px solid #007bff;
    }
    .announcement-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 18px rgba(0,0,0,0.15);
    }

    .announcement-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
    }
    .announcement-header h2 {
        font-size: 22px;
        color: #004a99;
        margin: 0;
    }
    .announcement-header span {
        font-size: 14px;
        color: #666;
    }

    .announcement-content {
        margin-top: 15px;
        line-height: 1.6;
        color: #003366;
        background: #f5f9ff;
        border-radius: 10px;
        padding: 15px;
    }

    .announcement-image {
        margin-top: 15px;
        display: flex;
        justify-content: center;
    }
    .announcement-image img {
        max-width: 100%;
        border-radius: 10px;
        box-shadow: 0 3px 10px rgba(0,0,0,0.15);
    }

    /* Empty State */
    .no-announcement {
        text-align: center;
        background: #ffffff;
        padding: 50px;
        border-radius: 12px;
        box-shadow: 0 6px 12px rgba(0,0,0,0.1);
        font-size: 18px;
        color: #666;
    }

    /* Scrollbar */
    ::-webkit-scrollbar {
        width: 8px;
    }
    ::-webkit-scrollbar-thumb {
        background: #007bff;
        border-radius: 10px;
    }
</style>
</head>
<body>


    <main class="main-content">
        <header>
            <h1><i class="fas fa-bullhorn"></i> Announcements</h1>
        </header>

        <section class="announcement-board">
        <?php if (empty($announcements)): ?>
            <div class="no-announcement">
                <i class="fas fa-info-circle" style="font-size: 30px; color: #007bff;"></i>
                <p>No announcements at the moment.</p>
            </div>
        <?php else: ?>
            <?php foreach ($announcements as $announcement): ?>
                <div class="announcement-card">
                    <div class="announcement-header">
                        <h2><i class="fas fa-newspaper"></i> <?= htmlspecialchars($announcement['title']) ?></h2>
                        <span><i class="fas fa-user"></i> <?= htmlspecialchars($announcement['posted_by']) ?> &nbsp;|&nbsp; <i class="fas fa-clock"></i> <?= date('F j, Y g:i A', strtotime($announcement['posted_at'])) ?></span>
                    </div>
                    <div class="announcement-content">
                        <?= nl2br(htmlspecialchars($announcement['content'])) ?>
                    </div>
                    <?php if (!empty($announcement['image'])): ?>
                    <div class="announcement-image">
                        <img src="../uploads/announcements/<?= htmlspecialchars($announcement['image']) ?>" alt="Announcement Image">
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        </section>
    </main>
</body>
</html>