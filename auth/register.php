<?php
// Database configuration
include_once'../includes/db.php';

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Sanitize inputs
    $userName = trim($_POST["name"]);
    $userEmail = trim($_POST["email"]);
    $userPassword = trim($_POST["password"]);
    $acceptPolicy = isset($_POST["accept"]) ? $_POST["accept"] : '';

    // Validate inputs
    if (empty($userName) || empty($userEmail) || empty($userPassword) || empty($acceptPolicy)) {
        echo json_encode(["status" => "error", "message" => "All fields are required."]);
        exit;
    }

    if (!filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(["status" => "error", "message" => "Invalid email format."]);
        exit;
    }

    // Check if email already exists
    $stmt = $conn->prepare("SELECT User_ID FROM users WHERE User_Email = ?");
    $stmt->bind_param("s", $userEmail);
    $stmt->execute();
    $stmt->store_result();
    
    if ($stmt->num_rows > 0) {
        echo json_encode(["status" => "error", "message" => "Email already registered."]);
        exit;
    }
    $stmt->close();

    // Hash password
    $hashedPassword = password_hash($userPassword, PASSWORD_DEFAULT);

    // Assign default role and set join date
    $userRole = 'user';  // Default role, can be changed as needed
    $userJoin = date("Y-m-d H:i:s");

    // Insert into database
    $stmt = $conn->prepare("INSERT INTO users (User_Name, User_Email, User_Password, User_Role, User_Join) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sssss", $userName, $userEmail, $hashedPassword, $userRole, $userJoin);

    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Registration successful.Now you can login."]);
    } else {
        echo json_encode(["status" => "error", "message" => "Error: " . $stmt->error]);
    }

    $stmt->close();
    $conn->close();
}
?>
