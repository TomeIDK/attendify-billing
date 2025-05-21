<?php
require_once __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/../parser.php';
require_once __DIR__ . '/../logger.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

// $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
// $dotenv->load();

define("INTERVAL", 5); // interval between db polling
declare(ticks = 1); // signal handling for pcntl_signal

// mysql credentials
$host       = getenv('MYSQL_HOST');
$db         = getenv('MYSQL_DB');
$user       = getenv('MYSQL_USER');
$pass       = getenv('MYSQL_PASSWORD');
$charset    = 'utf8mb4';
$port       = getenv('MYSQL_PORT');

// create pdo instance
$dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
$pdo = new PDO($dsn, $user, $pass, $options);

// rabbitmq credentials
$connection = new AMQPStreamConnection(getenv('RABBITMQ_HOST'), getenv('RABBITMQ_PORT'), getenv('RABBITMQ_USER'), getenv('RABBITMQ_PASSWORD'), getenv('RABBITMQ_VHOST'));
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
            $isTestUser = isset($row['uid']) && strpos($row['uid'], 'TST') === 0;
            if ($isTestUser) {
                echo " [!] Skipping test user with UID: " . ($row['uid'] ?? '') . "\n";
                markAsProcessed($row['id'], $pdo); // Mark as processed so it doesn't get stuck
                continue;
            }

            
            echo " [*] Currently processing user #{$row['client_id']}: {$row['first_name']} {$row['last_name']} for {$row['operation']} operation.\n";
            if ($row['operation'] == 'INSERT'){
                $row['operation'] = 'CREATE';
            }
            if ($row['operation'] === 'CREATE' && empty($row['uid'])) {    
                echo " [*] Generating UID...\n";

                $row['uid'] = 'FB' . round(microtime(true) * 1000); 

                echo " [x] UID '{$row['uid']}' generated.\n";
                //Update client.custom_2
                $pdo->exec("SET @is_consumer_source = 1");

                $clientUpdate = $pdo->prepare(
                    "UPDATE client SET 
                        custom_2 = :uniqueid,
                        updated_at = :updated_at
                    WHERE id = :client_id"
                );
                try {
                    $currentTime = date('Y-m-d H:i:s');
                    echo " [*] Updating UID in DB for user #" . $row['client_id'] . " with UID " . $row['uid'] . " at " . $currentTime . "\n";
                    $clientUpdate->execute([
                        ':uniqueid' => $row['uid'],
                        ':updated_at' => $currentTime,
                        ':client_id' => $row['client_id']
                    ]);

                    if ($clientUpdate->rowCount() === 0) {
                        echo " [!] No rows affected. Update may have failed or was unnecessary.\n";
                        sendLog($channel, "user", "No rows affected while updating UID for client #{$row['client_id']}. Possibly missing client.", 'user-management');
                    }

                }  catch (PDOException $e) {
                    echo " [!] Error: Database failed to update user.\n" . $e->getMessage() . "\n";
                }

            }
            processRow($row, $row['operation'], $channel);
            markAsProcessed($row['id'], $pdo);
        }
    } catch (PDOException $e) {
        echo " [!] Database error: " . $e->getMessage() . "\n";
        sendLog($channel, "user", "Database error while polling user_events: " . $e->getMessage(), 'user-management');
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
            sendLog($channel, "user", "Unknown operation '{$operation}'", 'user-management');
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
            "uid" => $userData['uid'],
            "password" => $userData['password'],
            "is_admin" => $userData['is_admin']
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
            sendLog($channel, "billing", "Canceled publish due to unknown operation '{$operation}'", 'user-management');
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