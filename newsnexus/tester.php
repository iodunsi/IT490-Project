<?php
require_once('/home/paa39/git/IT490-Project/rabbitMQLib.inc');

$client = new rabbitMQClient("testRabbitMQ.ini", "newsQueue");

$request = [
    "type" => "like",
    "user" => "tester",
    "articleId" => "12345",
    "title" => "Test Article",
    "url" => "https://example.com",
    "category" => "Tech",
    "timestamp" => date('c')
];

$response = $client->send_request($request);
echo "Response: " . json_encode($response) . "\n";
?>
