<?php

require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

// Update credentials to match docker-compose.yml
$connection = new AMQPStreamConnection(
    'rabbitmq',    // Container name in Docker network
    5672,          // Default RabbitMQ port
    'guest',       // Default username
    'guest'        // Default password
);

$channel = $connection->channel();

// Declare the exchange and queue
$channel->exchange_declare('user_events', 'topic', false, true, false);
$channel->queue_declare('fossbilling_users', false, true, false, false);
$channel->queue_bind('fossbilling_users', 'user_events', 'user.#');

// Callback function to process messages
$callback = function ($msg) {
    try {
        // Get the original data
        $userData = json_decode($msg->body, true);
        
        // Add password_confirm field
        $userData['password_confirm'] = $userData['password'];
        
        echo "Received user data: " . print_r($userData, true) . "\n";
        
        // FOSSBilling API call still points to Azure (or change to your local FOSSBilling if you have it)
        $apiUrl = 'http://integrationproject-2425s2-002.westeurope.cloudapp.azure.com:30056/api/guest/client/create';
        $ch = curl_init($apiUrl);
        
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($userData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ZuCkyT3dmjbxRejszOKf6j4unW7uHiui'
            ]
        ]);
        
        $response = curl_exec($ch);
        echo "API Response: " . $response . "\n";
        
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        echo "HTTP Status Code: " . $httpCode . "\n";
        
        if (curl_errno($ch)) {
            throw new Exception(curl_error($ch));
        }
        
        curl_close($ch);
        $msg->ack();
        
        echo "User creation attempt completed\n";
        
    } catch (Exception $e) {
        echo "Error processing message: " . $e->getMessage() . "\n";
        $msg->reject();
    }
};

// Start consuming messages
$channel->basic_consume('fossbilling_users', '', false, false, false, false, $callback);

echo "Waiting for messages...\n";

while ($channel->is_consuming()) {
    $channel->wait();
}

$channel->close();
$connection->close();
