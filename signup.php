<?php
require 'db.php'; // Ensure this sets $pdo correctly (PDO instance)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name']);
    $email    = trim($_POST['email']);
    $password = $_POST['password'];

    try {
        // Check if email already exists
        $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $checkStmt->execute([$email]);

        if ($checkStmt->rowCount() > 0) {
            echo "Email already registered. Please login.";
        } else {
            // Hash the password
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            // Insert new user
            $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)");
            $stmt->execute([$name, $email, $hashedPassword]);

            echo "Signup successful! Redirecting to login...";
            header("Location: index.php"); // redirect to login page
            exit;
        }
    } catch (PDOException $e) {
        echo "Signup failed: " . $e->getMessage();
    }
}
?>

