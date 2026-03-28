<?php
session_start();
require_once '../config/db_pdo.php';
$pdo = $conn;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? null;
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $imageName = $_POST['currentImage'] ?? null;

    if (!$id || !$title || !$content) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid input']);
        exit;
    }

    // Handle new image upload if provided
    if (!empty($_FILES['image']['name'])) {
        $allowedTypes = ['image/jpeg', 'image/png'];
        $imageTmpName = $_FILES['image']['tmp_name'];
        $imageType = mime_content_type($imageTmpName);
        $imageSize = $_FILES['image']['size'];

        if (!in_array($imageType, $allowedTypes) || $imageSize > 2 * 1024 * 1024) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid image']);
            exit;
        }

        $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
        $imageName = uniqid('ann_') . '.' . $ext;
        $uploadDir = __DIR__ . '/../uploads/announcements/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $uploadPath = $uploadDir . $imageName;
        move_uploaded_file($imageTmpName, $uploadPath);
    }

    try {
        $stmt = $pdo->prepare("UPDATE announcements SET title = ?, content = ?, image = ? WHERE id = ?");
        $stmt->execute([$title, $content, $imageName, $id]);
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Update failed']);
    }
}
?>
