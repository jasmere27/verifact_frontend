<?php
require 'db.php';

$id = $_POST['id'];
$table = $_POST['table'];

$allowed_tables = ['cybersecurity_modules', 'cybersecurity_modules1', 'cybersecurity_modules2'];
if (!in_array($table, $allowed_tables)) {
    echo 'fail';
    exit;
}

$sql = "DELETE FROM $table WHERE id=?";
$stmt = $pdo->prepare($sql);

if ($stmt->execute([$id])) {
    echo 'success';
} else {
    echo 'fail';
}
?>
