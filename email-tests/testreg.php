<?php
require_once( __DIR__ .'/../rabbitMQLib.inc');
require_once( __DIR__ .'/../mysqlconnect.php');


$client = new rabbitMQClient("testRabbitMQ.ini", "registerQueue");

  $request = [
        "type" => "register",
        "username" => "testuser",
        "password" => password_hash("password123", PASSWORD_DEFAULT),
        "first_name" => "Test",
        "last_name" => "User",
        "dob" => "2000-01-01", // Format: YYYY-MM-DD
        "email" => "test2@example.com"
    ];

// Send test registration request(hopefully this works)
$response = $client->send_request($request);

echo "Test Registration Response: ";
print_r($response);
?>
