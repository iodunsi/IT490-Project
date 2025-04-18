<?php
require __DIR__ . '/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// RabbitMQ Connection Details
$host = '127.0.0.1';
$port = 5672;
$user = 'guest';
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
    $connection = new AMQPStreamConnection($host, $port, $user, $password, $vhost, false, 'AMQPLAIN', null, 'en_US', 3.0, 130.0, null, false, 60);
    echo "[DEBUG] Connected to RabbitMQ successfully!\n";

    $channel = $connection->channel();
    $channel->queue_declare($queue_name, false, true, false, false);
    echo "[EMAIL📧] Waiting for email messages...\n";

    $callback = function (AMQPMessage $msg) use ($smtp_host, $smtp_port, $smtp_user, $smtp_pass) {
        echo "[EMAIL📩] Received email request: " . $msg->body . "\n";
        $email_data = json_decode($msg->body, true);

        if (!isset($email_data['to'], $email_data['subject'], $email_data['message'])) {
            echo "[EMAIL⚠️] Invalid email request format: " . $msg->body . "\n";
            error_log("[EMAIL⚠️] Invalid email request: " . $msg->body, 3, "/home/paa39/git/IT490-Project/email-automated/email_consumer.log");
            return;
        }

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
            $mail->CharSet = 'UTF-8';
            $mail->Encoding = 'base64';
            $mail->setFrom('no-reply@newsnexus.com', 'News Nexus');
            $mail->addAddress($email_data['to']);
            $mail->Subject = $email_data['subject'];
            $mail->Body = $email_data['message'];
            $mail->isHTML(true);

            echo "[DEBUG] Attempting to send email...\n";
            if ($mail->send()) {
                echo "[EMAIL✅] Email successfully sent to: {$email_data['to']}\n";
                error_log("[EMAIL✅] Email sent to: {$email_data['to']}", 3, "/home/paa39/git/IT490-Project/email-automated/email_consumer.log");
            } else {
                echo "[EMAIL❌] Email sending failed for: {$email_data['to']}\n";
                error_log("[EMAIL❌] Email failed for: {$email_data['to']}", 3, "/home/paa39/git/IT490-Project/email-automated/email_consumer.log");
            }
        } catch (Exception $e) {
            echo "[EMAIL❌] PHPMailer Error: " . $mail->ErrorInfo . "\n";
            error_log("[EMAIL❌] PHPMailer Error: " . $mail->ErrorInfo, 3, "/home/paa39/git/IT490-Project/email-automated/email_consumer.log");
        }
    };

    $channel->basic_consume($queue_name, '', false, true, false, false, $callback);

    while (true) {
        try {
            echo "[DEBUG] Checking for messages...\n";
            $channel->wait();
        } catch (Exception $e) {
            echo "[ERROR] Channel wait failed: " . $e->getMessage() . "\n";
            error_log("[ERROR] Channel wait failed: " . $e->getMessage(), 3, "/home/paa39/git/IT490-Project/email-automated/email_consumer.log");
            $connection->reconnect();
            $channel = $connection->channel();
            $channel->queue_declare($queue_name, false, true, false, false);
            $channel->basic_consume($queue_name, '', false, true, false, false, $callback);
        }
    }

    $channel->close();
    $connection->close();
} catch (Exception $e) {
    echo "[ERROR] Failed to connect to RabbitMQ: " . $e->getMessage() . "\n";
    error_log("[ERROR] Failed to connect to RabbitMQ: " . $e->getMessage(), 3, "/home/paa39/git/IT490-Project/email-automated/email_consumer.log");
}
?>

