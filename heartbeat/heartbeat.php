<?php

require_once __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/../parser.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeload();

// services to monitor - updated to match docker-compose services
$services = [
    [
        'name' => 'fossbilling',
        'host' => 'fossbilling',
        'port' => 80,
        'type' => 'http'
    ],
    [
        'name' => 'mysql',
        'host' => 'mysql',
        'port' => 3306,
        'type' => 'tcp'
    ]
];

function checkServiceStatus($service) {
    $status = "down";
    $error = "";
    
    try {
        if ($service['type'] === 'http') {
            // Check HTTP service
            $fp = @fsockopen($service['host'], $service['port'], $errno, $errstr, 3);
            if ($fp) {
                $status = "up";
                fclose($fp);
            } else {
                $error = "HTTP connection failed: $errstr ($errno)";
            }
        } else if ($service['type'] === 'tcp') {
            // Check TCP service (like MySQL)
            $fp = @fsockopen($service['host'], $service['port'], $errno, $errstr, 3);
            if ($fp) {
                $status = "up";
                fclose($fp);
            } else {
                $error = "TCP connection failed: $errstr ($errno)";
            }
        }
    } catch (\Exception $e) {
        $error = $e->getMessage();
    }
    
    return [
        'status' => $status,
        'error' => $error
    ];
}

function sendHeartbeat($channel, $service, $status, $error) {
    // format user data to compatible format for rabbitmq
    $timestamp = round(microtime(true) * 1000);
    $formattedHeartbeat = [
            "sender" => "billing-$service",
            "timestamp" => $timestamp,
    ];

    // convert formatted user to xml
    $xml = new SimpleXMLElement("<heartbeat/>");
    arrayToXml($formattedHeartbeat, $xml);

    // format xml
    $dom = new DOMDocument("1.0");
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;
    $dom->loadXML($xml->asXML());

    $xmlString = $dom->saveXML();

    // Create XML message
    $message = new AMQPMessage($xmlString, ['content_type' => 'application/xml']);

    // Publish to monitoring exchange with routing key
    $channel->basic_publish($message, 'monitoring', 'monitoring.heartbeat');
    echo " [✔] Heartbeat sent for '$service'";
}


function startHeartbeatService() {
    global $services;
    
    // Connect to RabbitMQ using Docker service name
    $connection = new AMQPStreamConnection($_ENV['RABBITMQ_HOST'], $_ENV['RABBITMQ_PORT'], $_ENV['RABBITMQ_USER'], $_ENV['RABBITMQ_PASSWORD'], $_ENV['RABBITMQ_VHOST']);

    $channel = $connection->channel();

    echo " [x] Heartbeat service started!\n";

    // Poll every second
    while (true) {
        try {
            // Check each service
            foreach ($services as $service) {
                $result = checkServiceStatus($service);
                if ($result["status"] == "up")
                {
                    sendHeartbeat($channel, $service['name'], $result['status'], $result['error']);
                }
            }
            sleep(1);
        } catch (\Exception $e) {
            echo " [!] Error in heartbeat service: " . $e->getMessage() . "\n";
            sleep(1);
            
            // Try to reconnect if connection was lost
            try {
                if (!$connection->isConnected()) {
                    $connection = new AMQPStreamConnection($_ENV['RABBITMQ_HOST'], $_ENV['RABBITMQ_PORT'], $_ENV['RABBITMQ_USER'], $_ENV['RABBITMQ_PASSWORD'], $_ENV['RABBITMQ_VHOST']);

                    $channel = $connection->channel();
                }
            } catch (\Exception $reconnectException) {
                echo " [!] Failed to reconnect: " . $reconnectException->getMessage() . "\n";
            }
        }
    }

    // Close channel and connection
    $channel->close();
    $connection->close();
}

// // Simple test mode - just check services without RabbitMQ
// if (isset($argv[1]) && $argv[1] === 'test') {
//     echo "Testing service status...\n";
//     foreach ($services as $service) {
//         $result = checkServiceStatus($service);
//         echo "Service: {$service['name']}\n";
//         echo "Status: {$result['status']}\n";
//         if ($result['error']) {
//             echo "Error: {$result['error']}\n";
//         }
//         echo "-------------------\n";
//     }
// } else {
    startHeartbeatService();
// }