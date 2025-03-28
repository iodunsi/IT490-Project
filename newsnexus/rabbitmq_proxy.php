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
                self::$client = new rabbitMQClient("/home/paa39/git/IT490-Project/testRabbitMQ.ini", "newsQueue");
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

// ✅ Capture user "like" request
$data = file_get_contents("php://input");
$request = json_decode($data, true);

error_log("[LIKE] 📩 Request received: " . json_encode($request));

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["status" => "error", "message" => "Invalid request method"]);
    exit();
}

// ✅ Ensure user session exists
if (!isset($_SESSION['username'])) {
    error_log("[LIKE] ❌ ERROR: User session missing!");
    echo json_encode(["status" => "error", "message" => "User session not found"]);
    exit();
}

$username = $_SESSION['username'];

// ✅ Validate article data
$articleId = $request['articleId'] ?? null;
$title = $request['title'] ?? null;
$url = $request['url'] ?? null;
$category = $request['category'] ?? null;
$timestamp = date("Y-m-d H:i:s");

if (!$articleId || !$title || !$url || !$category) {
    error_log("[LIKE] ❌ ERROR: Invalid article data");
    echo json_encode(["status" => "error", "message" => "Invalid article data"]);
    exit();
}

// ✅ Prepare RabbitMQ request
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
        error_log("[LIKE] ❌ ERROR: Could not establish RabbitMQ connection");
        echo json_encode(["status" => "error", "message" => "Could not connect to RabbitMQ"]);
        exit();
    }

    // ✅ Send the request to RabbitMQ and wait for response
    $response = $client->send_request($likeRequest);

    if (!$response) {
        error_log("[LIKE] ❌ ERROR: No response from RabbitMQ");
        echo json_encode(["status" => "error", "message" => "No response from RabbitMQ"]);
        exit();
    }

    error_log("[LIKE] 📬 Received response from RabbitMQ Broker: " . json_encode($response));

    echo json_encode(["status" => "success", "message" => "Article liked successfully!", "response" => $response]);
} catch (Exception $e) {
    error_log("[LIKE] ❌ ERROR: RabbitMQ Connection Failed - " . $e->getMessage());
    echo json_encode(["status" => "error", "message" => "Error connecting to RabbitMQ"]);
    RabbitMQConnection::closeClient();
    exit();
}
?>
