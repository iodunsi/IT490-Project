<?php
require __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;

try {
    echo "[DEBUG] Testing RabbitMQ Connection...\n";
    $connection = new AMQPStreamConnection('127.0.0.1', 5672, 'guest', 'guest', 'emailhost');
    echo "[DEBUG] Connection Successful!\n";
    $connection->close();
} catch (Exception $e) {
    echo "[ERROR] Failed to connect: " . $e->getMessage() . "\n";
}
?>
