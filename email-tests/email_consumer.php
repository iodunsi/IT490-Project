<?php
require __DIR__ . '/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// RabbitMQ Connection Details
$host = '127.0.0.1';  // PLS CHANGE IF RABBITMQ IS ON ANOTHER VM
$port = 5672;
$user = 'guest';      // MAKE SURE THESE RABBITMQ CREDS ARE ACCURATE
$password = 'guest';
$vhost = 'emailhost';
$queue_name = 'emailQueue';

// SMTP Configuration (Mailtrap)
$smtp_host = "sandbox.smtp.mailtrap.io";
$smtp_port = 587;
$smtp_user = "16139ec659b1a0";
$smtp_pass = "40ff8c81fc5487";

try {
    echo "[DEBUG] Connecting to RabbitMQ at $host...\n";

    // Establish RabbitMQ Connection with 60-second heartbeat
    $connection = new AMQPStreamConnection($host, $port, $user, $password, $vhost, false, 'AMQPLAIN', null, 'en_US', 3.0, 130.0, null, false, 60);
    echo "[DEBUG] Connected to RabbitMQ successfully!\n";

    $channel = $connection->channel();

    // Declare Queue
    $channel->queue_declare($queue_name, false, true, false, false);
    echo "[EMAIL📧] Waiting for email messages...\n";

    // Callback function to handle incoming messages
    $callback = function (AMQPMessage $msg) use ($smtp_host, $smtp_port, $smtp_user, $smtp_pass) {
        echo "[EMAIL📩] Received email request: " . $msg->body . "\n";

        $email_data = json_decode($msg->body, true);

        if (!isset($email_data['to'], $email_data['subject'], $email_data['message'])) {
            echo "[EMAIL⚠️] Invalid email request format\n";
            return;
        }

        // Send email using PHPMailer
        $mail = new PHPMailer(true);
        try {
            echo "[DEBUG] Configuring PHPMailer...\n";
            $mail->isSMTP();
            $mail->Host = $smtp_host;
            $mail->SMTPAuth = true;
            $mail->Username = $smtp_user;
            $mail->Password = $smtp_pass;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = $smtp_port;

            $mail->setFrom('no-reply@yourdomain.com', 'IT490 Team');
            $mail->addAddress($email_data['to']);
            $mail->Subject = $email_data['subject'];
            $mail->Body = $email_data['message'];
            $mail->isHTML(true);

            echo "[DEBUG] Attempting to send email...\n";
            if ($mail->send()) {
                echo "[EMAIL✅] Email successfully sent to: {$email_data['to']}\n";
            } else {
                echo "[EMAIL❌] Email sending failed for: {$email_data['to']}\n";
            }
        } catch (Exception $e) {
            echo "[EMAIL❌] PHPMailer Error: " . $mail->ErrorInfo . "\n";
        }

        
    };

    $channel->basic_consume($queue_name, '', false, true, false, false, $callback);

    // Loop to wait for messages
    while (true) {
        try {
            echo "[DEBUG] Checking for messages...\n";
            $channel->wait();
        } catch (Exception $e) {
            echo "[ERROR] Channel wait failed: " . $e->getMessage() . "\n";
            // Attempt to reconnect
            $connection->reconnect();
            $channel = $connection->channel();
            $channel->queue_declare($queue_name, false, true, false, false);
            $channel->basic_consume($queue_name, '', false, true, false, false, $callback);
        }
    }

    // Close connection (unreachable in this loop)
    $channel->close();
    $connection->close();
} catch (Exception $e) {
    echo "[ERROR] Failed to connect to RabbitMQ: " . $e->getMessage() . "\n";
}
?>
