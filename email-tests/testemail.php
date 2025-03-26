<?php
require_once( __DIR__ .'/../rabbitMQLib.inc');

try {
    $client = new rabbitMQClient("emailRabbitMQ.ini", "emailQueue");

    $request = [
        "type" => "send_email",
        "to" => "test@example.com",
        "subject" => "Test Email",
        "message" => "This is a test email from PHPMailer via RabbitMQ!"
    ];

    echo "[DEBUG] Sending email request to RabbitMQ...\n";
    $response = $client->send_request($request);
    
    if ($response === false) {
        echo "[ERROR] Failed to send request to RabbitMQ\n";
    } else {
        echo "[DEBUG] Received response from RabbitMQ:\n";
        print_r($response);
    }
} catch (Exception $e) {
    echo "[ERROR] Exception: " . $e->getMessage() . "\n";
}
?>
