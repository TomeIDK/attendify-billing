<?php

require_once __DIR__ . '/vendor/autoload.php';
require 'parser.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// services to monitor - updated to match docker-compose services
$services = [
    [
        'name' => 'billing-producer',
        'host' => 'attendify-billing-producer-1',  // matches container_name in docker-compose
        'port' => 80,
        'type' => 'tcp'
    ],
    [
        'name' => 'billing-consumer',
        'host' => 'attendify-billing-consumer-1',  // matches container_name in docker-compose
        'port' => 80,
        'type' => 'tcp'
    ],
    [
        'name' => 'fossbilling',
        'host' => 'attendify-fossbilling-1',  // default docker-compose name
        'port' => 80,
        'type' => 'tcp'
    ],
    [
        'name' => 'mysql',
        'host' => 'attendify-mysql-1',  // default docker-compose name
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
    if ($status == 'down') {
        echo " [x] Service '$service' is down: $error\n";
        return;
    }

// format user data to compatible format for rabbitmq
    $formattedHeartbeat = [
        "info" => [
            "sender" => 'billing',
            "container_name" => $service,
            "timestamp" => time(),
        ]
    ];

    // convert formatted user to xml
    $xml = new SimpleXMLElement("<attendify/>");
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
    echo " [✔] Heartbeat sent for '$service' at " . date('Y-m-d H:i:s') . ". Status: $status\n";
}


function startHeartbeatService($services) {
    
    // Connect to RabbitMQ using Docker service name
    $connection = new AMQPStreamConnection($_ENV['RABBITMQ_HOST'], $_ENV['RABBITMQ_PORT'], $_ENV['RABBITMQ_USER'], $_ENV['RABBITMQ_PASSWORD'], $_ENV['RABBITMQ_VHOST']);
    $channel = $connection->channel();

    $channel->exchange_declare('monitoring', 'topic', false, true, false);

    echo " [x] Heartbeat service started!\n";

    // Poll every second
    while (true) {
        try {
            // Check each service
            foreach ($services as $service) {
                $result = checkServiceStatus($service);
                sendHeartbeat($channel, $service['name'], $result['status'], $result['error']);
            }
            sleep(1);
        } catch (\Exception $e) {
            echo " [!] Error in heartbeat service: " . $e->getMessage() . "\n";
            sleep(1);
            
            // Try to reconnect if connection was lost
            try {
                if (!$connection->isConnected()) {
                    $connection = new AMQPStreamConnection(
                        'rabbitmq',
                        5672,
                        'attendify',
                        'uXe5u1oWkh32JyLA',
                        'attendify'
                    );
                    $channel = $connection->channel();
                    $channel->exchange_declare('monitoring', 'topic', false, true, false);
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

// Simple test mode - just check services without RabbitMQ
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
    startHeartbeatService($services);
// }