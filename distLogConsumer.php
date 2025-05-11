#!/usr/bin/php
<?php
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

function processLog($request) {
    if (!isset($request['type']) || $request['type'] !== 'log') {
        return;
    }
    $logMessage = $request['message'];
    file_put_contents("/var/log/dist_logs.log", $logMessage . "\n", FILE_APPEND);
    echo "Received log: $logMessage\n";
}

$server = new rabbitMQServer("/rabbitmqini/rabbitMQ.ini", "distLogging");
$server->process_requests('processLog');
?>



