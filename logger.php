<?php

require_once __DIR__ . '/app/../parser.php';
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Sends a structured XML log message to RabbitMQ.
 *
 * @param object $channel  AMQP channel instance
 * @param string $service  e.g. 'user', 'invoice'
 * @param string $messageContent  The log message text
 */
function sendLog($channel, $service, $messageContent, $exchange) {
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

    $msg = new AMQPMessage($xmlString, ['content_type' => 'application/xml']);

    try {
        echo " [debug] Sending log message to monitoring.log:\n$xmlString\n";
        $channel->basic_publish($msg, $exchange, 'monitoring.log');
    } catch (Exception $e) {
        echo " [!] Failed to publish log message: " . $e->getMessage() . "\n";
    }

    echo " [✔ LOG] Sent: $messageContent\n";
}
