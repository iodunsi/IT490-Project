<?php
require_once('/home/paa39/git/IT490-Project/rabbitMQLib.inc');
header("Content-Type: application/json");

$data = json_decode(file_get_contents("php://input"), true);

$request = [
  "type" => "get_comments",
  "articleId" => $data['articleId']
];

$client = new rabbitMQClient("testRabbitMQ.ini", "newsQueue");
$response = $client->send_request($request);
echo json_encode($response);
?>