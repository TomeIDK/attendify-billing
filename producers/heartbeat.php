<?php

require_once './vendor/autoload.php';
require __DIR__ . '/../parser.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

declare(ticks = 1); // signal handling for pcntl_signal

// rabbitmq credentials
$connection = new AMQPStreamConnection(getenv('RABBITMQ_HOST'), getenv('RABBITMQ_PORT'), getenv('RABBITMQ_USER'), getenv('RABBITMQ_PASSWORD'), getenv('RABBITMQ_VHOST'));
$channel = $connection->channel();
echo " [x] Connected to RabbitMQ.\n";

// close connection if shutdown command is given (CTRL+C)
pcntl_signal(SIGINT, function() use ($channel, $connection) {
    shutdownHandler($channel, $connection);
});

while (true) {
    echo " [x] Producer heartbeat started.\n";
    $xmlString = formatMessage();
    publishMessage($xmlString, $channel);
    echo " [✔] Heartbeat sent.\n";
    sleep(1);
}


function formatMessage() {
    $timestamp = (new DateTime())->format(DATE_ATOM);
    $array = [
        "info" =>
        [
            "sender" => "billing",
            "container_name" => "attendify-billing-producer-1",
            "timestamp" => $timestamp
        ],
    ];

    // convert formatted user to xml
    $xml = new SimpleXMLElement("<attendify/>");
    arrayToXml($array, $xml);

    // format xml
    $dom = new DOMDocument("1.0");
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;
    $dom->loadXML($xml->asXML());

    return $dom->saveXML();
}



// publish the xml message to rabbitmq
function publishMessage($xmlString, $channel) {
    $msg = new AMQPMessage(
        $xmlString,
        ['content-type' => 'application/xml']
    );
    $channel->basic_publish($msg, 'monitoring', 'monitoring.heartbeat');
}

// shutdown command handler
function shutdownHandler($channel, $connection) {
    echo " [x] Closing RabbitMQ connection...\n";
    $channel->close();
    $connection->close();
    echo " [x] Producer heartbeat stopped.\n";

    exit(0);
};