<?php
// Start session to manage user login session
session_start();

// Database configuration
include_once '../includes/db.php';

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Sanitize inputs
    $username = trim($_POST["username"]);
    $password = trim($_POST["password"]);
    $rememberMe = isset($_POST["remember"]) ? true : false;

    // Validate inputs
    if (empty($username) || empty($password)) {
        echo json_encode(["status" => "error", "message" => "Username and password are required."]);
        exit;
    }

    // Check if user exists (by username or email)
    $stmt = $conn->prepare("SELECT User_ID, User_Name, User_Password, User_Role FROM users WHERE User_Name = ? OR User_Email = ?");
    $stmt->bind_param("ss", $username, $username);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows == 0) {
        echo json_encode(["status" => "error", "message" => "Invalid username/email or password."]);
        exit;
    }

    // Fetch the user data
    $stmt->bind_result($userID, $userName, $hashedPassword, $userRole);
    $stmt->fetch();
    $stmt->close();

    // Verify the password
    if (!password_verify($password, $hashedPassword)) {
        echo json_encode(["status" => "error", "message" => "Invalid username/email or password."]);
        exit;
    }

    // Start user session
    $_SESSION["userID"] = $userID;
    $_SESSION["userName"] = $userName;
    $_SESSION["userRole"] = $userRole;

    // If "Remember Me" is checked, set cookies for 3 days
    if ($rememberMe) {
        $cookieExpiration = time() + (3 * 24 * 60 * 60); // 3 days
        setcookie("userID", $userID, $cookieExpiration, "/");
        setcookie("userName", $userName, $cookieExpiration, "/");
        setcookie("userRole", $userRole, $cookieExpiration, "/");
    }

    echo json_encode(["status" => "success", "message" => "Login successful."]);
} else {
    echo json_encode(["status" => "error", "message" => "Invalid request method."]);
}
?>
