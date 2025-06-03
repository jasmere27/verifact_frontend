<?php
require_once 'db.php'; // Ensure this defines $pdo (a PDO instance)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize and validate input
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $video_url = trim($_POST['video_url'] ?? '');
    $type = trim(strtolower($_POST['type'] ?? ''));

    // You can optionally validate that type is either 'video' or 'image'
    if (!in_array($type, ['video', 'image'])) {
        die("Invalid module type selected.");
    }

    // Single fixed table
    $table = 'cybersecurity_modules2';

    try {
        // Prepare and execute insert query
        $stmt = $pdo->prepare("
            INSERT INTO `$table` (title, description, video_url, type, updated_at)
            VALUES (:title, :description, :video_url, :type, NOW())
        ");

        $stmt->execute([
            ':title' => $title,
            ':description' => $description,
            ':video_url' => $video_url,
            ':type' => $type
        ]);

        // Redirect to dashboard after success
        header("Location: dashboard.php");
        exit();

    } catch (PDOException $e) {
        die("Database error: " . $e->getMessage());
    }
} else {
    die("Invalid request method.");
}
