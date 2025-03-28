<?php
require_once('/home/paa39/git/IT490-Project/rabbitMQLib.inc');

header("Content-Type: application/json");

ini_set("log_errors", 1);
ini_set("error_log", "/var/log/php_errors.log");


ini_set("session.cookie_secure", 1);
ini_set("session.cookie_httponly", 1);
ini_set("session.use_only_cookies", 1);
ini_set("session.use_strict_mode", 1);

session_start();

error_log("[LIKE] 🆔 Session ID: " . session_id());
error_log("[LIKE] 🧑 User from session: " . (isset($_SESSION['username']) ? $_SESSION['username'] : 'not set'));

class RabbitMQConnection {
    private static $client = null;

    public static function getClient() {
        if (self::$client === null) {
            error_log("[RABBITMQ] 🔴 Establishing NEW RabbitMQ connection to Broker VM...");
            try {
                self::$client = new rabbitMQClient("testRabbitMQ.ini", "newsQueue");
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

// Log the received data
$logData = json_decode(file_get_contents("php://input"), true);
error_log("[LIKE] 📩 Request received: " . json_encode($logData));

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["status" => "error", "message" => "Invalid request method"]);
    exit();
}

// ✅ Extract and validate input
$articleId = isset($logData['articleId']) ? trim($logData['articleId']) : null;
$title = isset($logData['title']) ? trim($logData['title']) : null;
$url = isset($logData['url']) ? trim($logData['url']) : null;
$category = isset($logData['category']) ? trim($logData['category']) : 'Uncategorized';
$timestamp = isset($logData['timestamp']) ? trim($logData['timestamp']) : null;

if (!isset($_SESSION['username']) || empty($_SESSION['username'])) {
    echo json_encode(["status" => "error", "message" => "User not logged in"]);
    exit();
}
$user = $_SESSION['username'];

if (empty($articleId) || empty($title) || empty($url) || empty($timestamp)) {
    echo json_encode(["status" => "error", "message" => "Missing required fields"]);
    exit();
}

// ✅ Prepare RabbitMQ request
$request = [
    "type" => "like",
    "user" => $user,
    "articleId" => $articleId,
    "title" => $title,
    "url" => $url,
    "category" => $category,
    "timestamp" => $timestamp
];

try {
    $client = RabbitMQConnection::getClient();
    if (!$client) {
        echo json_encode(["status" => "error", "message" => "Could not connect to RabbitMQ"]);
        exit();
    }

    // ✅ Send the request and wait for a response
    $response = $client->publish($request);

    error_log("[LIKE] 📬 Received response from RabbitMQ Broker: " . json_encode($response));

    if ($response['status'] === "success") {
        echo json_encode([
            "status" => "success",
            "message" => "Article liked successfully",
            "article_id" => $response['article_id'],
            "timestamp" => $response['timestamp']
        ]);
    } else {
        echo json_encode(["status" => "error", "message" => $response['message']]);
        RabbitMQConnection::closeClient();
    }
    exit();

} catch (Exception $e) {
    error_log("[LIKE] ❌ ERROR: RabbitMQ Connection Failed - " . $e->getMessage());
    echo json_encode(["status" => "error", "message" => "Error connecting to RabbitMQ"]);
    RabbitMQConnection::closeClient();
    exit();
}
?>
