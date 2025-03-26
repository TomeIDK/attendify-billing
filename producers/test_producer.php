<?php

require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

// Update credentials to match docker-compose.yml
$connection = new AMQPStreamConnection(
    'rabbitmq',    // host
    5672,          // port
    'guest',       // user (changed from 'fossbilling')
    'guest'        // password (changed from 'fossbilling')
);

$channel = $connection->channel();

// Declare the exchange
$channel->exchange_declare('user_events', 'topic', false, true, false);

// Updated user data with a valid email format
$userData = [
    'status' => 'active',
    'group_id' => 1,  // You might need to adjust this based on your FOSSBilling groups
    'email' => 'Bilal.belkasem@gmail.com',  // Updated email format
    'first_name' => 'Bilal',
    'last_name' => 'Belkasem',
    'company' => 'souls inc.',
    'address_1' => 'la nigrillo anarctito',
    'address_2' => 'Bombardino crocodilo',
    'city' => 'Columbus',
    'state' => 'Ohio',
    'country' => 'US',  // Country code
    'postcode' => '170025',
    'phone_cc' => '56',  // Country calling code for Netherlands
    'phone' => '254876156',
    'currency' => 'USD',
    'password' => 'SecurePassword123!',
    'password_confirm' => 'SecurePassword123!'  // Added password_confirm
];

// Add debug output
echo "Sending user data: " . json_encode($userData, JSON_PRETTY_PRINT) . "\n";

// Convert to JSON
$msg = new AMQPMessage(
    json_encode($userData),
    ['content_type' => 'application/json']
);

// Publish message
$channel->basic_publish($msg, 'user_events', 'user.created');

echo "Message sent with user data\n";

$channel->close();
$connection->close();
