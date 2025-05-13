<?php

require_once __DIR__ . '/parser.php';
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Sends a structured XML log message to RabbitMQ.
 *
 * @param object $channel  AMQP channel instance
 * @param string $service  e.g. 'user', 'invoice'
 * @param string $messageContent  The log message text
 */
function sendLog($channel, $service, $messageContent) {
    $timestamp = round(microtime(true) * 1000);
    $logData = [
        "sender" => "billing-$service",
        "timestamp" => $timestamp,
        "message" => $messageContent
    ];

    $xml = new SimpleXMLElement("<log/>");
    arrayToXml($logData, $xml);

    $dom = new DOMDocument("1.0", "UTF-8");
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;
    $dom->loadXML($xml->asXML());

    $xmlString = $dom->saveXML();

    // Declare exchange/queue/binding
    $channel->exchange_declare('monitoring', 'topic', true, true, false);
    $channel->queue_declare('monitoring.log', true, true, false, false);
    $channel->queue_bind('monitoring.log', 'monitoring', 'monitoring.log');

    $msg = new AMQPMessage($xmlString, ['content_type' => 'application/xml']);

    try {
        echo " [debug] Sending log message to monitoring.log:\n$xmlString\n";
        $channel->basic_publish($msg, 'monitoring', 'monitoring.log');
    } catch (Exception $e) {
        echo " [!] Failed to publish log message: " . $e->getMessage() . "\n";
    }

    echo " [✔ LOG] Sent: $messageContent\n";
}
