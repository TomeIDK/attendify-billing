<?php
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
    } catch (Exception $e) {
        echo " [!] Error parsing XML: " . $e->getMessage() . "\n";
        return;
    }

    // Skip messages sent by this service
    if (isset($data['info']['sender']) && $data['info']['sender'] === 'billing') {
        echo " Skipping self-published event.\n";
        return;
    }

    $operation = $data['info']['operation'] ?? '';
    echo " [*] Operation to perform: {$operation}\n";
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
            echo " [!] Unknown operation '{$operation}'. Skipping...\n";
            break;
    }

    $msg->ack();
};

// Consume from the event queue
$channel->basic_consume('billing.invoice', '', false, false, false, false, $callback);
echo " [*] Waiting for event messages. Press CTRL+C to exit.\n";

while ($channel->is_consuming()) {
    $channel->wait();
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
            ':max'   => (int) trim($e['max_attendees']),
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
        ':max'   => (int) trim($e['max_attendees']),
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
