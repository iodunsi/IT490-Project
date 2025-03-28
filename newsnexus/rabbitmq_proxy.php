<?php
require_once('/home/paa39/git/IT490-Project/rabbitMQLib.inc');

header("Content-Type: application/json");
ini_set("log_errors", 1);
ini_set("error_log", "/var/log/php_errors.log");

session_start();

// 🔧 RabbitMQ Connection Class
class RabbitMQConnection {
    private static $client = null;

    public static function getClient() {
        if (self::$client !== null) {
            error_log("[RABBITMQ] 🟢 Using existing connection...");
            return self::$client;
        }

        error_log("[RABBITMQ] 🔴 Establishing new connection...");
        try {
            self::$client = new rabbitMQClient("/home/paa39/git/IT490-Project/testRabbitMQ.ini", "newsQueue");
            return self::$client;
        } catch (Exception $e) {
            error_log("[RABBITMQ] ❌ ERROR: Connection failed - " . $e->getMessage());
            return null;
        }
    }

    public static function closeClient() {
        if (self::$client !== null) {
            error_log("[RABBITMQ] 🔴 Closing connection...");
            self::$client = null;
        }
    }
}

// 📝 Helper function to send JSON response
function jsonResponse($status, $message, $response = null) {
    echo json_encode(["status" => $status, "message" => $message, "response" => $response]);
    exit();
}

// ✅ Validate and extract user input
$data = file_get_contents("php://input");
$request = json_decode($data, true);
$username = $_SESSION['username'] ?? null;
$articleId = $request['articleId'] ?? null;
$title = $request['title'] ?? null;
$url = $request['url'] ?? null;
$category = $request['category'] ?? "Uncategorized";
$timestamp = date("Y-m-d H:i:s");

error_log("[LIKE] 📩 Request received: " . json_encode($request));

// 🛑 Check request method and user session
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    jsonResponse("error", "Invalid request method");
}
if (!$username) {
    error_log("[LIKE] ❌ ERROR: User session not found");
    jsonResponse("error", "User session not found");
}
if (!$articleId || !$title || !$url) {
    error_log("[LIKE] ❌ ERROR: Missing article data");
    jsonResponse("error", "Missing article data");
}

// ✅ Prepare RabbitMQ message
$likeRequest = [
    "type" => "like",
    "user" => $username,
    "articleId" => $articleId,
    "title" => $title,
    "url" => $url,
    "category" => $category,
    "timestamp" => $timestamp
];

try {
    $client = RabbitMQConnection::getClient();
    if (!$client) {
        jsonResponse("error", "Could not connect to RabbitMQ");
    }

    // 📤 Send request to RabbitMQ and get the response
    $response = $client->send_request($likeRequest);
    if (!$response) {
        jsonResponse("error", "No response from RabbitMQ");
    }

    error_log("[LIKE] 📬 Response received: " . json_encode($response));
    jsonResponse("success", "Article liked successfully!", $response);
} catch (Exception $e) {
    error_log("[LIKE] ❌ ERROR: Connection failed - " . $e->getMessage());
    RabbitMQConnection::closeClient();
    jsonResponse("error", "Error connecting to RabbitMQ");
}
?>
