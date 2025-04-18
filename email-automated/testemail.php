<?php
require_once 'rabbitMQLib.inc';
$client = new rabbitMQClient("emailRabbitMQ.ini", "emailQueue");
$request = [
    "type" => "send_email",
    "to" => "test@example.com",
    "subject" => "Test Email",
    "message" => "This is a test."
];
try {
    $response = $client->send_request($request);
    echo json_encode($response);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>


