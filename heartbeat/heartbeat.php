<?php

require_once __DIR__ . '/../vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

// services to monitor - updated to match docker-compose services
$services = [
    [
        'name' => 'billing-producer',
        'host' => 'billing_producer',  // matches container_name in docker-compose
        'port' => 80,
        'type' => 'http'
    ],
    [
        'name' => 'billing-consumer',
        'host' => 'billing_consumer',  // matches container_name in docker-compose
        'port' => 80,
        'type' => 'http'
    ],
    [
        'name' => 'fossbilling',
        'host' => 'attendify-billing-fossbilling-1',  // default docker-compose name
        'port' => 80,
        'type' => 'http'
    ],
    [
        'name' => 'mysql',
        'host' => 'attendify-billing-mysql-1',  // default docker-compose name
        'port' => 3306,
        'type' => 'tcp'
    ],
    [
        'name' => 'rabbitmq',
        'host' => 'some-rabbit',  // matches container_name in docker-compose
        'port' => 5672,
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

function startHeartbeatService() {
    global $services;
    
    // Connect to RabbitMQ using Docker service name
    $connection = new AMQPStreamConnection(
        'localhost',  // matches container_name in docker-compose
        5672,           // port
        'attendify',    // username from docker-compose
        getenv('RABBITMQ_PASSWORD') ?: 'guest', // password from env
        'attendify'     // vhost from docker-compose
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
                        'some-rabbit',
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
}

// Simple test mode - just check services without RabbitMQ
if (isset($argv[1]) && $argv[1] === 'test') {
    echo "Testing service status...\n";
    foreach ($services as $service) {
        $result = checkServiceStatus($service);
        echo "Service: {$service['name']}\n";
        echo "Status: {$result['status']}\n";
        if ($result['error']) {
            echo "Error: {$result['error']}\n";
        }
        echo "-------------------\n";
    }
} else {
    startHeartbeatService();
}