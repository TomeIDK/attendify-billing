<?php

require_once __DIR__ . '/vendor/autoload.php';
require 'parser.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

define("INTERVAL", 5); // interval between db polling
declare(ticks = 1); // signal handling for pcntl_signal

// mysql credentials
$host       = 'mysql';
$db         = 'fossbilling';
$user       = 'fossbilling';
$pass       = 'fossbilling';
$charset    = 'utf8mb4';
$port       = 3306; 

// create pdo instance
$dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
$pdo = new PDO($dsn, $user, $pass, $options);

// rabbitmq credentials
$connection = new AMQPStreamConnection('rabbitmq', 30001, 'attendify', 'uXe5u1oWkh32JyLA', 'attendify');
$channel = $connection->channel();
echo " [x] Connected to RabbitMQ.\n";

// close connection if shutdown command is given (CTRL+C)
pcntl_signal(SIGINT, function() use ($channel, $connection) {
    shutdownHandler($channel, $connection);
});
echo " [*] Polling the user_events. Press CTRL+C to exit.\n";

while (true) {

    // fetch all unprocessed user events
    $statement = $pdo->prepare("SELECT * FROM user_events WHERE processed = FALSE");

    // poll user_events table and process any unprocessed rows
    try {
        $statement->execute();
        $count = $statement->rowCount();
        echo " [x] Found {$count} unprocessed user event(s).\n";

        // process each row individually and publish a message
        while ($row = $statement->fetch()) {
            echo " [*] Currently processing user #{$row['id']}: {$row['first_name']} {$row['last_name']} for {$row['operation']} operation.\n";
            if ($row['operation'] == 'INSERT'){
                $row['operation'] = 'CREATE';
            }
            processRow($row, $row['operation'], $channel);
            markAsProcessed($row['id'], $pdo);
        }
    } catch (PDOException $e) {
        echo " [!] Database error: " . $e->getMessage() . "\n";
    }
    sleep(INTERVAL);
}

// process user data 
function processRow($userData, $operation, $channel) {
    switch ($operation) {
        case 'CREATE':
        case 'UPDATE':
        case 'DELETE':
            echo " [*] Creating '{$operation}' message...\n";
            $xmlString = formatUser($userData);
            publishMessage($xmlString, $channel, $operation);
            echo " [✔] $operation message sent with user data (XML format)\n";
            break;
        default:
            echo " [!] Error: Unknown operation '{$operation}'. Skipping...";
            return;
            break;
    }
}

// format user data to compatible format for rabbitmq
function formatUser($userData) {
    // format user data
    $formattedUser = [
        "info" => [
            "sender" => 'billing',
            "operation" => strtolower($userData['operation']),
        ],
        "user" => [
            "first_name" => $userData['first_name'],
            "last_name" => $userData['last_name'],
            "email" => $userData['email'],
            "title" => $userData['title'],
            "pass" => $userData['pass']
        ]
    ];

    // convert formatted user to xml
    $xml = new SimpleXMLElement("<attendify/>");
    arrayToXml($formattedUser, $xml);

    // format xml
    $dom = new DOMDocument("1.0");
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;
    $dom->loadXML($xml->asXML());

    return $dom->saveXML();
}

// publish the xml message to rabbitmq
function publishMessage($xmlString, $channel, $operation) {
    $msg = new AMQPMessage(
        $xmlString,
        ['content-type' => 'application/xml']
    );
    switch ($operation) {
        case 'CREATE':
            $channel->basic_publish($msg, 'user-management', 'user.register');
            break;
        case 'UPDATE':
            $channel->basic_publish($msg, 'user-management', 'user.update');
            break;
        case 'DELETE':
            $channel->basic_publish($msg, 'user-management', 'user.delete');
            break;
        default:
            echo " [!] Error: Unknown operation '{$operation}'. Canceled message publishing.";
            return;
            break;
    }
}

// mark row as processed
function markAsProcessed($id, $pdo) {
    $stmt = $pdo->prepare("UPDATE user_events SET processed = 1 WHERE id = :id");
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
}

// shutdown command handler
function shutdownHandler($channel, $connection) {
    echo " [x] Closing RabbitMQ connection...\n";
    $channel->close();
    $connection->close();
    exit(0);
};