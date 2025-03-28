<?php
require_once('/home/paa39/git/IT490-Project/rabbitMQLib.inc');

header("Content-Type: application/json");

ini_set("log_errors", 1);
ini_set("error_log", "/var/log/php_errors.log");

session_start();

class RabbitMQConnection {
    private static $client = null;

    public static function getClient() {
        if (self::$client === null) {
            error_log("[RABBITMQ] 🔴 Establishing NEW RabbitMQ connection to Broker VM...");
            try {
                self::$client = new rabbitMQClient("testRabbitMQ.ini", "loginQueue");
            } catch (Exception $e) {
                error_log("[RABBITMQ] ❌ ERROR: Could not connect to RabbitMQ Broker - " . $e->getMessage());
                return null;
            }
        } else {
            error_log("[RABBITMQ] 🟢 Using EXISTING RabbitMQ connection...");
        }
        return self::$client;
    }

    public static function closeClient() {
        if (self::$client !== null) {
            error_log("[RABBITMQ] 🔴 Closing RabbitMQ connection...");
            self::$client = null;
        }
    }
}

// Sanitize the POST data to remove the password before logging
$logData = $_POST;
if (isset($logData['pword'])) {
    $logData['pword'] = '[REDACTED]';
}

error_log("[LOGIN] 📩 Request received: " . json_encode($logData));


if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["status" => "error", "message" => "Invalid request method"]);
    exit();
}

// ✅ Extract and validate input
$username = isset($_POST['uname']) ? trim($_POST['uname']) : null;
$password = isset($_POST['pword']) ? trim($_POST['pword']) : null;

if (empty($username) || empty($password)) {
    echo json_encode(["status" => "error", "message" => "Please enter both username and password"]);
    exit();
}

// ✅ Prepare RabbitMQ request
$request = [
    "type" => "login",
    "username" => $username,
    "password" => $password
];

try {
    $client = RabbitMQConnection::getClient();
    if (!$client) {
        echo json_encode(["status" => "error", "message" => "Could not connect to RabbitMQ"]);
        exit();
    }

    // ✅ Send the request and wait for a response
    $response = $client->send_request($request);

    error_log("[LOGIN] 📬 Received response from RabbitMQ Broker: " . json_encode($response));

    if ($response['status'] === "success") {
        // ✅ Store session details
        $_SESSION['username'] = $username;
        $_SESSION['logged_in'] = true;
        $_SESSION['session_key'] = $response['session_key'];
        $_SESSION['expires_at'] = $response['expires_at'];

        echo json_encode([
            "status" => "success",
            "message" => "Login successful",
            "user_id" => $response['user_id'],
            "session_key" => $response['session_key']
        ]);
    } else {
        echo json_encode(["status" => "error", "message" => $response['message']]);
        RabbitMQConnection::closeClient();
    }
    exit();

} catch (Exception $e) {
    error_log("[LOGIN] ❌ ERROR: RabbitMQ Connection Failed - " . $e->getMessage());
    echo json_encode(["status" => "error", "message" => "Error connecting to RabbitMQ"]);
    RabbitMQConnection::closeClient();
    exit();
}
?>
