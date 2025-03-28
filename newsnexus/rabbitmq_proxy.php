<?php
require_once('/home/paa39/git/IT490-Project/rabbitMQLib.inc');

header("Content-Type: application/json");

// Enable error logging
ini_set("log_errors", 1);
ini_set("error_log", "/var/log/php_errors.log");

try {
    // Get the POST data
    $data = json_decode(file_get_contents("php://input"), true);

    // Validate the incoming data
    if (!isset($data['type']) || !isset($data['user']) || !isset($data['articleId']) || !isset($data['title']) || !isset($data['url']) || !isset($data['timestamp'])) {
        error_log("Error: Invalid data received - " . json_encode($data));
        echo json_encode(["status" => "error", "message" => "Invalid data received"]);
        exit;
    }

    // Prepare the message payload for RabbitMQ
    $message = [
        'type' => $data['type'],
        'user' => $data['user'],
        'articleId' => $data['articleId'],
        'title' => $data['title'],
        'url' => $data['url'],
        'category' => $data['category'] ?? 'Uncategorized',
        'timestamp' => $data['timestamp']
    ];

    // Call the RabbitMQ send function from your library
    $response = sendMessageToQueue("newsqueue", json_encode($message));

    if ($response) {
        echo json_encode(["status" => "success", "message" => "Message sent to RabbitMQ"]);
    } else {
        error_log("Error: Failed to send message to RabbitMQ");
        echo json_encode(["status" => "error", "message" => "Failed to send message to RabbitMQ"]);
    }

} catch (Exception $e) {
    error_log("Exception: " . $e->getMessage());
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
