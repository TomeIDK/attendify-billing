<?php

require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

// Update credentials to match docker-compose.yml
$connection = new AMQPStreamConnection('localhost', 5672, 'user', 'password', 'vhost');
$channel = $connection->channel();


// Updated user data with a valid email format
$userData = [
    'id' => '12345', 
    'first_name' => 'Bilal',
    'last_name' => 'Belkasem',
    'date_of_birth' => '1990-01-01', // Example DOB
    'phone_number' => '+3212345678',
    'title' => 'Developer',
    'email' => 'Bilal.belkasem@gmail.com',
    'password' => 'SecurePassword123!',
    'email_registered' => 'true',
    'from_company' => 'false',
    
    'address' => [
        'street' => 'la nigrillo anarctito',
        'number' => '42',
        'bus_number' => 'B',
        'city' => 'Columbus',
        'province' => 'Ohio',
        'country' => 'US',
        'postal_code' => '170025'
    ],

    'company' => [
        'id' => 'COM001',
        'name' => 'souls inc.',
        'VAT_number' => 'BE987654321',
        'address' => [
            'street' => 'Corporate Avenue',
            'number' => '10',
            'city' => 'Ghent',
            'province' => 'East Flanders',
            'country' => 'Belgium',
            'postal_code' => '9000'
        ]
    ]
];

// Add debug output
echo "[*] Sending user data: " . json_encode($userData, JSON_PRETTY_PRINT) . "\n";

function arrayToXml($data, $xml) {
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            $subnode = $xml->addChild($key);
            arrayToXml($value, $subnode);
        } else {
            $xml->addChild($key, htmlspecialchars($value));
        }
    }
}

$xml = new SimpleXMLElement('<attendify></attendify>');
$info = $xml->addChild('info');
$info->addChild('sender', 'billing');
$info->addChild('operation', 'create');


$user = $xml->addChild('user');
arrayToXml($userData, $user);

// remove manually set fields
unset($userData['sender'], $userData['operation']);

$xmlString = $xml->asXML();

// Convert to JSON
$msg = new AMQPMessage(
    $xmlString,
    ['content-type' => 'application/xml']
);

// Publish message
$channel->basic_publish($msg, 'user-management', 'user.register');

echo "[✔] Message sent with user data (XML format):\n$xmlString\n";

$channel->close();
$connection->close();
