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
error_log("[RATE] 📩 Request received: " . json_encode($logData));

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["status" => "error", "message" => "Invalid request method"]);
    exit();
}

// Extract and validate input
$articleId = isset($logData['articleId']) ? trim($logData['articleId']) : null;
$title = isset($logData['title']) ? trim($logData['title']) : null;
$url = isset($logData['url']) ? trim($logData['url']) : null;
$rating = isset($logData['rating']) ? (int)$logData['rating'] : null;
$timestamp = isset($logData['timestamp']) ? trim($logData['timestamp']) : null;
$user = isset($_SESSION['username']) ? $_SESSION['username'] : 'anonymous';

if (empty($articleId) || empty($title) || empty($url) || $rating === null || empty($timestamp)) {
    echo json_encode(["status" => "error", "message" => "Missing required fields"]);
    exit();
}

// Prepare RabbitMQ request
$request = [
    "type" => "rate",
    "user" => $user,
    "articleId" => $articleId,
    "title" => $title,
    "url" => $url,
    "rating" => $rating,
    "timestamp" => $timestamp
];

try {
    $client = RabbitMQConnection::getClient();
    if (!$client) {
        echo json_encode(["status" => "error", "message" => "Could not connect to RabbitMQ"]);
        exit();
    }

    // Send the request and wait for a response
    $response = $client->send_request($request);

    error_log("[RATE] 📬 Received response from RabbitMQ Broker: " . json_encode($response));

    if ($response['status'] === "success") {
        echo json_encode([
            "status" => "success",
            "message" => "Article rated successfully",
            "article_id" => $response['article_id'],
            "timestamp" => $response['timestamp']
        ]);
    } else {
        echo json_encode(["status" => "error", "message" => $response['message']]);
        RabbitMQConnection::closeClient();
    }
    exit();

} catch (Exception $e) {
    error_log("[RATE] ❌ ERROR: RabbitMQ Connection Failed - " . $e->getMessage());
    echo json_encode(["status" => "error", "message" => "Error connecting to RabbitMQ"]);
    RabbitMQConnection::closeClient();
    exit();
}
?>
