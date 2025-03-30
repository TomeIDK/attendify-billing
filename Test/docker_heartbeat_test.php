<?php
// Docker network heartbeat service test
// This script is meant to be run inside a Docker container with access to the Docker network

require_once __DIR__ . '/../vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

// Services to monitor - using internal Docker hostnames
$services = [
    [
        'name' => 'billing-producer',
        'host' => 'billing_producer',  // Docker container name
        'port' => 80,  // Internal container port
        'type' => 'http'
    ],
    [
        'name' => 'billing-consumer',
        'host' => 'billing_consumer',  // Docker container name
        'port' => 80,  // Internal container port
        'type' => 'http'
    ],
    [
        'name' => 'fossbilling',
        'host' => 'attendify-billing-fossbilling-1',  // Docker container name
        'port' => 80,  // Internal container port
        'type' => 'http'
    ],
    [
        'name' => 'mysql',
        'host' => 'attendify-billing-mysql-1',  // Docker container name
        'port' => 3306,  
        'type' => 'tcp'
    ],
    [
        'name' => 'rabbitmq',
        'host' => 'some-rabbit',  // Docker container name
        'port' => 5672,  // Internal container port
        'type' => 'tcp'
    ],
    [
        'name' => 'rabbitmq-management',
        'host' => 'some-rabbit',  // Docker container name
        'port' => 15672,  // Internal container port
        'type' => 'http'
    ]
];

// Function to check service status
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
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
    
    return [
        'status' => $status,
        'error' => $error
    ];
}

// Test mode - check services and then test RabbitMQ connection
echo "Testing Docker service status from within Docker network...\n";

// First, check all services
foreach ($services as $service) {
    $result = checkServiceStatus($service);
    echo "Service: {$service['name']}\n";
    echo "Status: {$result['status']}\n";
    if ($result['error']) {
        echo "Error: {$result['error']}\n";
    }
    echo "-------------------\n";
}

// Then try connecting to RabbitMQ
echo "\nTesting RabbitMQ connection...\n";
try {
    $connection = new AMQPStreamConnection(
        'some-rabbit',  // Docker container name
        5672,           // Internal port
        'attendify',    // Username from docker-compose
        getenv('RABBITMQ_PASSWORD') ?: 'guest', // Password from env
        'attendify'     // vhost from docker-compose
    );
    $channel = $connection->channel();
    
    // Declare the exchange
    $channel->exchange_declare('monitoring', 'topic', false, true, false);
    
    // Send a test message
    $timestamp = time();
    $xmlData = '
    <heartbeat xmlns="http://attendify-billing.local">
        <service>test-service</service>
        <timestamp>' . $timestamp . '</timestamp>
        <error></error>
        <status>up</status>
    </heartbeat>';
    
    $message = new AMQPMessage($xmlData, ['content_type' => 'text/xml']);
    $channel->basic_publish($message, 'monitoring', 'monitoring.heartbeat');
    echo "Test message sent to RabbitMQ successfully!\n";
    
    // Close connection
    $channel->close();
    $connection->close();
    
    echo "RabbitMQ connection test successful!\n";
} catch (Exception $e) {
    echo "RabbitMQ connection failed: " . $e->getMessage() . "\n";
}

echo "\nDocker heartbeat test completed!\n"; 