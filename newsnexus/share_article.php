<?php
require_once('/home/paa39/git/IT490-Project/rabbitMQLib.inc');
header("Content-Type: application/json");
session_start();

$logData = json_decode(file_get_contents("php://input"), true);
error_log("[SHARE] 📩 Request received: " . json_encode($logData));

$request = [
  "type" => "share",
  "from_user" => $_SESSION['username'],
  "to_user" => $logData['to_user'],
  "articleId" => $logData['articleId'],
  "title" => $logData['title'],
  "url" => $logData['url'],
  "timestamp" => $logData['timestamp']
];

$client = new rabbitMQClient("testRabbitMQ.ini", "newsQueue");
$response = $client->send_request($request);
echo json_encode($response);
