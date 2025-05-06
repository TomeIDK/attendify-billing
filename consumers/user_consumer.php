<?php
/**
 * General Consumer for Attendify System
 * 
 * This consumer processes messages from multiple queues (billing.invoice and billing.user)
 * and performs the appropriate database operations based on the message content.
 */

require_once __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/../parser.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

// Interval between polls (unused here, but kept for reference)
define("INTERVAL", 5);
declare(ticks = 1); // For graceful shutdown via pcntl_signal

// --- DATABASE CONNECTION via PDO ---
$host    = getenv('MYSQL_HOST');
$db      = getenv('MYSQL_DB');
$user    = getenv('MYSQL_USER');
$pass    = getenv('MYSQL_PASSWORD');
$charset = 'utf8mb4';
$port    = getenv('MYSQL_PORT');
$dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";
$pdoOptions = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
$pdo = new PDO($dsn, $user, $pass, $pdoOptions);

// --- RABBITMQ CONNECTION ---
$connection = new AMQPStreamConnection(
    getenv('RABBITMQ_HOST'),
    getenv('RABBITMQ_PORT'),
    getenv('RABBITMQ_USER'),
    getenv('RABBITMQ_PASSWORD'),
    getenv('RABBITMQ_VHOST')
);
$channel = $connection->channel();
echo " [x] Connected to RabbitMQ.\n";

// Graceful shutdown on CTRL+C
pcntl_signal(SIGINT, function() use ($channel, $connection) {
    echo " [x] Shutting down...\n";
    $channel->close();
    $connection->close();
    exit(0);
});

// --- CONSUMER CALLBACK ---
$callback = function(AMQPMessage $msg) use ($pdo) {
    echo " [x] Message received.\n";
    try {
        $jsonData = xmlToJson($msg->getBody());
        $data = json_decode($jsonData, true)['attendify'];
        echo " [x] Parsed data.\n";
    } catch (Exception $e) {
        echo " [!] Error parsing XML: " . $e->getMessage() . "\n";
        return;
    }

    echo " [x] Message sender is '{$data['info']['sender']}'\n";

    // Skip messages sent by this service
    if (isset($data['info']['sender']) && $data['info']['sender'] === 'billing') {
        echo " Skipping self-published event.\n";
        return;
    }

    $operation = $data['info']['operation'] ?? '';
    echo " [*] Operation to perform: {$operation}\n";
    
    // Check which entity type is present in the message
    if (isset($data['user'])) {
        // Process user operations
        switch ($operation) {
            case 'create':
                createUser($data['user'], $pdo);
                break;
            case 'update':
                updateUser($data['user'], $pdo);
                break;
            case 'delete':
                deleteUser($data['user'], $pdo);
                break;
            default:
                echo " [!] Unknown operation '{$operation}' for user. Skipping...\n";
                break;
        }
    } elseif (isset($data['event'])) {
        // Process event operations
        switch ($operation) {
            case 'create':
                createEvent($data['event'], $pdo);
                break;
            case 'update':
                updateEvent($data['event'], $pdo);
                break;
            case 'delete':
                deleteEvent($data['event'], $pdo);
                break;
            default:
                echo " [!] Unknown operation '{$operation}' for event. Skipping...\n";
                break;
        }
    } else {
        echo " [!] No recognized entity type in message. Skipping...\n";
    }

    $msg->ack();
};

// Declare which queues to consume from
$queues = ['billing.invoice', 'billing.user'];

// Set up consumption from both queues
foreach ($queues as $queue) {
    $channel->basic_consume($queue, '', false, false, false, false, $callback);
    echo " [*] Consuming from queue: $queue\n";
}

echo " [*] Waiting for messages. Press CTRL+C to exit.\n";

// Process messages from any of the queues
while ($channel->is_consuming()) {
    $channel->wait();
}


// --- USER CRUD FUNCTIONS ---

/**
 * Insert a new user into the users table.
 */
function createUser(array $data, PDO $pdo) {
    $currentTime = date('Y-m-d H:i:s');

    // Set session variable to indicate consumer is making the change
    $pdo->exec("SET @is_consumer_source = 1");

    $sql = "INSERT INTO client (
                email, pass, first_name, last_name, custom_1, created_at, updated_at
            ) VALUES (
                :email, :pass, :first_name, :last_name, :custom_1, :created_at, :updated_at
            )";
    $stmt = $pdo->prepare($sql);
    try {
        $stmt->execute([
            ':email'      => $data['email'],
            ':pass'       => $data['password'],
            ':first_name' => $data['first_name'],
            ':last_name'  => $data['last_name'],
            ':custom_1'      => trim($data['title']),
            ':created_at' => $currentTime,
            ':updated_at' => $currentTime,
        ]);
        echo " [✔] User created successfully: {$data['email']}\n";
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            echo " [!] User with email {$data['email']} already exists. Skipping...\n";
        } else {
            echo " [!] Error: Database failed to create user.\n" . $e->getMessage() . "\n";
        }
    }
}

/**
 * Update an existing user in the users table.
 */
function updateUser(array $data, PDO $pdo) {
    $currentTime = date('Y-m-d H:i:s');

    $sql = "UPDATE client SET
                pass = :pass,
                first_name = :first_name,
                last_name = :last_name,
                custom_1 = :custom_1,
                updated_at = :updated_at
            WHERE email = :email";
    $stmt = $pdo->prepare($sql);
    try {
        $stmt->execute([
            ':email'      => $data['email'],
            ':pass'       => $data['password'],
            ':first_name' => $data['first_name'],
            ':last_name'  => $data['last_name'],
            ':custom_1'      => trim($data['title']),
            ':updated_at' => $currentTime,
        ]);
        if ($stmt->rowCount() > 0) {
            echo " [✔] User updated with email: {$data['email']}\n";
        } else {
            echo " [!] No user found to update with email: {$data['email']}. Check if it exists.\n";
        }
    } catch (PDOException $e) {
        echo " [!] Error: Database failed to update user.\n" . $e->getMessage() . "\n";
    }
}

/**
 * Delete a user from the users table.
 */
function deleteUser(array $data, PDO $pdo) {
    $sql = "DELETE FROM client WHERE email = :email";
    $stmt = $pdo->prepare($sql);
    try {
        $stmt->execute([':email' => $data['email']]);
        if ($stmt->rowCount() > 0) {
            echo " [✔] User successfully deleted with email: {$data['email']}\n";
        } else {
            echo " [!] No user found with email: {$data['email']}.\n";
        }
    } catch (PDOException $e) {
        echo " [!] Error: Database failed to delete user.\n" . $e->getMessage() . "\n";
    }
}

// --- EVENT CRUD FUNCTIONS ---

/**
 * Insert a new event into the events table.
 */
function createEvent(array $e, PDO $pdo) {
    $sql = "INSERT INTO events
        (uid_event, name, start_date, end_date, address, description, max_attendees)
     VALUES
        (:uid, :name, :start, :end, :addr, :desc, :max)";
    $stmt = $pdo->prepare($sql);
    try {
        $stmt->execute([
            ':uid'   => $e['uid_event'],
            ':name'  => $e['name'],
            ':start' => date('Y-m-d H:i:s', strtotime($e['start_date'])),
            ':end'   => date('Y-m-d H:i:s', strtotime($e['end_date'])),
            ':addr'  => $e['address'],
            ':desc'  => $e['description'] ?? null,
            ':max'   => (int) trim($e['max_attendees'] ?? 0),
        ]);
        echo " [✔] Event created: {$e['uid_event']}\n";
    } catch (PDOException $ex) {
        echo " [!] Failed to create event {$e['uid_event']}: " . $ex->getMessage() . "\n";
    }
}

/**
 * Update an existing event in the events table.
 */
function updateEvent(array $e, PDO $pdo) {
    $sql = "UPDATE events SET
                name = :name,
                start_date = :start,
                end_date   = :end,
                address    = :addr,
                description= :desc,
                max_attendees = :max,
                updated_at = CURRENT_TIMESTAMP
            WHERE uid_event = :uid";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':uid'   => $e['uid_event'],
        ':name'  => $e['name'],
        ':start' => date('Y-m-d H:i:s', strtotime($e['start_date'])),
        ':end'   => date('Y-m-d H:i:s', strtotime($e['end_date'])),
        ':addr'  => $e['address'],
        ':desc'  => $e['description'] ?? null,
        ':max'   => (int) trim($e['max_attendees'] ?? 0),
    ]);
    if ($stmt->rowCount() > 0) {
        echo " [✔] Event updated: {$e['uid_event']}\n";
    } else {
        echo " [!] No event found to update: {$e['uid_event']}\n";
    }
}

/**
 * Delete an event from the events table.
 */
function deleteEvent(array $e, PDO $pdo) {
    $stmt = $pdo->prepare("DELETE FROM events WHERE uid_event = :uid");
    $stmt->execute([':uid' => $e['uid_event']]);
    if ($stmt->rowCount() > 0) {
        echo " [✔] Event deleted: {$e['uid_event']}\n";
    } else {
        echo " [!] No event found to delete: {$e['uid_event']}\n";
    }
}