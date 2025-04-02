<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Starting testreg.php...\n";
require_once(__DIR__ . '/../rabbitMQLib.inc');

try {
    echo "Creating RabbitMQ client...\n";
    $client = new rabbitMQClient(__DIR__ . '/../testRabbitMQ.ini', "registerQueue");
    $request = [
        "type" => "register",
        "username" => "testuser" . rand(1, 1000),
        "password" => password_hash("password123", PASSWORD_DEFAULT),
        "first_name" => "Test",
        "last_name" => "User",
        "dob" => "2000-01-01",
        "email" => "test.user." . rand(1, 1000) . "@example.com"
    ];
    echo "Sending request: " . json_encode($request) . "\n";
    $response = $client->send_request($request);
    echo "Test Registration Response: ";
    print_r($response);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
