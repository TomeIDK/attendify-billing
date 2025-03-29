<?php

require_once __DIR__ . '/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

// services to monitor
$services = [
    [
        'name' => 'billing-producer',
        'host' => 'billing-producer',
        'port' => 80,
        'type' => 'http'
    ],
    [
        'name' => 'billing-consumer',
        'host' => 'billing-consumer',
        'port' => 80,
        'type' => 'http'
    ],
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
    // Define the XML data based on the schema
    $timestamp = time();
    $xmlData = '
    <heartbeat xmlns="http://attendify-billing.local">
        <service>' . $service . '</service>
        <timestamp>' . $timestamp . '</timestamp>
        <error>' . $error . '</error>
        <status>' . $status . '</status>
    </heartbeat>';

    // Create XML message
    $message = new AMQPMessage($xmlData, ['content_type' => 'text/xml']);

    // Publish to monitoring exchange with routing key
    $channel->basic_publish($message, 'monitoring', 'monitoring.heartbeat');
    echo "Heartbeat sent for $service at " . date('Y-m-d H:i:s') . " Status: $status\n";
}

// Connect to RabbitMQ
$connection = new AMQPStreamConnection(
    'rabbitmq',   // hostname
    5672,         // port
    'attendify',  // username - from docker-compose.yml
    getenv('RABBITMQ_PASSWORD') ?: 'guest', // password - from env variable or default
    'attendify'   // vhost - from docker-compose.yml
);
$channel = $connection->channel();


$channel->exchange_declare('monitoring', 'topic', false, true, false);

echo "Heartbeat service started!\n";

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
        echo "Error in heartbeat service: " . $e->getMessage() . "\n";
        sleep(1);
        
        // Try to reconnect if connection was lost
        try {
            if (!$connection->isConnected()) {
                $connection = new AMQPStreamConnection(
                    'rabbitmq',
                    5672,
                    'attendify',
                    getenv('RABBITMQ_PASSWORD') ?: 'guest',
                    'attendify'
                );
                $channel = $connection->channel();
                $channel->exchange_declare('monitoring', 'topic', false, true, false);
            }
        } catch (\Exception $reconnectException) {
            echo "Failed to reconnect: " . $reconnectException->getMessage() . "\n";
        }
    }
}

// Close channel and connection
$channel->close();
$connection->close();