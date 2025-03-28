<?php
require_once('/home/paa39/git/IT490-Project/rabbitMQLib.inc');

header("Content-Type: application/json");
ini_set("log_errors", 1);
ini_set("error_log", "/var/log/php_errors.log");

session_start();

// Sanitize log by removing the password field
$logData = $_POST;
if (isset($logData['pword'])) {
    $logData['pword'] = '[REDACTED]';
}

error_log("[REGISTER] 📩 Request received: " . json_encode($logData));


if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["status" => "error", "message" => "Invalid request method"]);
    exit();
}

// Extract and validate input
$firstName = trim($_POST['fname'] ?? '');
$lastName = trim($_POST['lname'] ?? '');
$dob = trim($_POST['dob'] ?? '');
$username = trim($_POST['uname'] ?? '');
$password = trim($_POST['pword'] ?? '');
$email = trim($_POST['email'] ?? '');
$email = empty($email) ? null : $email;


// Password validation
if (!preg_match('/^(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{8,}$/', $password)) {
    echo json_encode(["status" => "error", "message" => "Password must contain at least 8 characters, an uppercase letter, a number, and a special character."]);
    exit();
}

// Prepare RabbitMQ request
$request = [
    "type" => "register",
    "first_name" => $firstName,
    "last_name" => $lastName,
    "dob" => $dob,
    "username" => $username,
    "password" => password_hash($password, PASSWORD_BCRYPT),
    "email" => $email
];

// Send the request to RabbitMQ
try {
    $client = new rabbitMQClient("testRabbitMQ.ini", "loginQueue");
    $response = $client->send_request($request);

    if ($response['status'] === "success") {
        error_log("[REGISTER] ✅ User registered successfully!");
        echo json_encode(["status" => "success", "message" => "Registration successful"]);
    } else {
        error_log("[REGISTER] ❌ Registration failed: " . $response['message']);
        echo json_encode(["status" => "error", "message" => $response['message']]);
    }
} catch (Exception $e) {
    error_log("[REGISTER] ❌ ERROR: RabbitMQ Broker Connection Failed - " . $e->getMessage());
    echo json_encode(["status" => "error", "message" => "Error connecting to RabbitMQ Broker"]);
    exit();
}
?>
