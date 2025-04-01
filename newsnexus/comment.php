<?php
require_once('/home/paa39/git/IT490-Project/rabbitMQLib.inc');
header("Content-Type: application/json");
session_start();

$logData = json_decode(file_get_contents("php://input"), true);
error_log("[COMMENT] 📩 Request received: " . json_encode($logData));

$request = [
  "type" => "comment",
  "user" => $_SESSION['username'],
  "articleId" => $logData['articleId'],
  "title" => $logData['title'],
  "comment" => $logData['comment'],
  "timestamp" => $logData['timestamp']
];

$client = new rabbitMQClient("testRabbitMQ.ini", "newsQueue");
$response = $client->send_request($request);
echo json_encode($response);
