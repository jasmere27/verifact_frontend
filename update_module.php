<?php
require 'db.php';

// Validate incoming POST data
$id = $_POST['id'] ?? null;
$table = $_POST['table'] ?? null;
$title = $_POST['title'] ?? '';
$desc = $_POST['description'] ?? '';
$type = $_POST['type'] ?? '';
$subtype = $_POST['subtype'] ?? '';
$video = $_POST['video_url'] ?? '';

// Sanitize and whitelist the table name
$allowed_tables = ['cybersecurity_modules', 'cybersecurity_modules1', 'cybersecurity_modules2'];
if (!$id || !$table || !in_array($table, $allowed_tables)) {
    echo 'fail';
    exit;
}

// Build and execute query
$sql = "UPDATE `$table` SET title = ?, description = ?, type = ?, subtype = ?, video_url = ? WHERE id = ?";
$stmt = $pdo->prepare($sql);

if ($stmt->execute([$title, $desc, $type, $subtype, $video, $id])) {
    echo 'success';
} else {
    echo 'fail';
}


?>