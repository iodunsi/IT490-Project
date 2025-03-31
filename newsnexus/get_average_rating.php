<?php
require_once('/home/paa39/git/IT490-Project/rabbitMQLib.inc');
header("Content-Type: application/json");
session_start();

$logData = json_decode(file_get_contents("php://input"), true);
$articleId = $logData['articleId'] ?? null;

if (!$articleId) {
    echo json_encode(["status" => "error", "message" => "Missing article ID"]);
    exit();
}

class RabbitMQConnection {
    private static $client = null;

    public static function getClient() {
        if (self::$client === null) {
            self::$client = new rabbitMQClient("testRabbitMQ.ini", "newsQueue");
        }
        return self::$client;
    }
}

$request = [
    "type" => "get_average_rating",
    "articleId" => $articleId
];

try {
    $client = RabbitMQConnection::getClient();
    $response = $client->send_request($request);
    echo json_encode($response);
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
