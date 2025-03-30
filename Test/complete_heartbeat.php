<?php
// Complete heartbeat service that continuously sends messages

// Require the AMQP library
require_once __DIR__ . '/../vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

// Services to monitor
$services = [
    [
        'name' => 'billing-producer',
        'host' => 'billing-producer',
        'port' => 8080,  
        'type' => 'http'
    ],
    [
        'name' => 'billing-consumer',
        'host' => 'billing-consumer',
        'port' => 8080,  
        'type' => 'http'
    ],
    [
        'name' => 'fossbilling',
        'host' => 'fossbilling',
        'port' => 8081,  
        'type' => 'http'
    ],
    [
        'name' => 'mysql',
        'host' => 'mysql',
        'port' => 3306,  
        'type' => 'tcp'
    ],
    [
        'name' => 'rabbitmq',
        'host' => 'rabbitmq',
        'port' => 5672,  
        'type' => 'tcp'
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

// Function to send heartbeat messages
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
echo "Connecting to RabbitMQ...\n";
$connection = new AMQPStreamConnection('localhost', 5672, 'attendify', 'guest', 'attendify');

$channel = $connection->channel();



 echo "RabbitMQ connection established and exchange declared.\n";

echo "Starting heartbeat service...\n";

// Set interval (in seconds)
$interval = 1; // Send heartbeat every second

// Get the time limit from command line (default: run for 10 seconds)
$timeLimit = isset($argv[1]) ? (int)$argv[1] : 10;
$startTime = time();
$endTime = $startTime + $timeLimit;

echo "Heartbeat service will run for $timeLimit seconds.\n";

// Main loop - keep checking and sending heartbeats
while (time() < $endTime) {
    try {
        // Check each service
        foreach ($services as $service) {
            $result = checkServiceStatus($service);
            sendHeartbeat($channel, $service['name'], $result['status'], $result['error']);
        }
        
        // Wait for the specified interval
        sleep($interval);
    } catch (Exception $e) {
        echo "Error in heartbeat service: " . $e->getMessage() . "\n";
        sleep(1);
        
        // Try to reconnect if connection was lost
        try {
            if (!$connection->isConnected()) {
                $connection = new AMQPStreamConnection(
                    'localhost',
                    5672,
                    'attendify',
                    getenv('RABBITMQ_PASSWORD') ?: 'guest',
                    'attendify'
                );
                $channel = $connection->channel();
                $channel->exchange_declare('monitoring', 'topic', false, true, false);
            }
        } catch (Exception $reconnectException) {
            echo "Failed to reconnect: " . $reconnectException->getMessage() . "\n";
        }
    }
}

// Close channel and connection
$channel->close();
$connection->close();

echo "Heartbeat service completed after running for $timeLimit seconds.\n"; 