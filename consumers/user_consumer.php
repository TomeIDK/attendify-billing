<?php
require_once __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/../parser.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeload();

define("INTERVAL", 5);
declare(ticks = 1);

// --- DATABASE CONNECTION ---
$host    = $_ENV['MYSQL_HOST'];
$db      = $_ENV['MYSQL_DB'];
$user    = $_ENV['MYSQL_USER'];
$pass    = $_ENV['MYSQL_PASSWORD'];
$charset = 'utf8mb4';
$port    = $_ENV['MYSQL_PORT'];

if ($_ENV['APP_ENV'] === 'testing') {
    $dsn = 'sqlite::memory:';
    $user = null;
    $pass = null;
} else {
    $dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";
}

$pdoOptions = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
$pdo = new PDO($dsn, $user, $pass, $pdoOptions);

// --- RABBITMQ ONLY IN NON-TESTING ENV ---
if ($_ENV['APP_ENV'] !== 'testing') {
    $connection = new AMQPStreamConnection(
        $_ENV['RABBITMQ_HOST'],
        $_ENV['RABBITMQ_PORT'],
        $_ENV['RABBITMQ_USER'],
        $_ENV['RABBITMQ_PASSWORD'],
        $_ENV['RABBITMQ_VHOST']
    );
    $channel = $connection->channel();
    echo " [x] Connected to RabbitMQ.\n";

    pcntl_signal(SIGINT, function () use ($channel, $connection) {
        echo " [x] Shutting down...\n";
        $channel->close();
        $connection->close();
        exit(0);
    });

    // --- CONSUMER CALLBACK ---
    $callback = function (AMQPMessage $msg) use ($pdo) {
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

        // Skip self-sent messages
        if (isset($data['info']['sender']) && $data['info']['sender'] === 'billing') {
            echo " Skipping self-published event.\n";
            return;
        }

        $operation = $data['info']['operation'] ?? '';
        echo " [*] Operation to perform: {$operation}\n";

        if (isset($data['user'])) {
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
            }
        } elseif (isset($data['event'])) {
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
            }
        } else {
            echo " [!] No recognized entity type in message. Skipping...\n";
        }

        $msg->ack();
    };

    // Consume from queues
    $queues = ['billing.invoice', 'billing.user'];
    foreach ($queues as $queue) {
        $channel->basic_consume($queue, '', false, false, false, false, $callback);
        echo " [*] Consuming from queue: $queue\n";
    }

    echo " [*] Waiting for messages. Press CTRL+C to exit.\n";

    while ($channel->is_consuming()) {
        $channel->wait();
    }
}

// --- USER CRUD ---
function createUser(array $data, PDO $pdo) {
    $pdo->exec("SET @is_consumer_source = 1");
    $now = date('Y-m-d H:i:s');

    $stmt = $pdo->prepare("INSERT INTO client (
        email, pass, first_name, last_name, custom_1, custom_2, created_at, updated_at
    ) VALUES (
        :email, :pass, :first_name, :last_name, :custom_1, :custom_2, :created_at, :updated_at
    )");

    try {
        $stmt->execute([
            ':email'      => $data['email'],
            ':pass'       => $data['password'],
            ':first_name' => $data['first_name'],
            ':last_name'  => $data['last_name'],
            ':custom_1'   => trim($data['title']),
            ':custom_2'   => $data['uid'],
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        echo " [✔] User created: {$data['email']}\n";
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            echo " [!] User with email {$data['email']} already exists. Skipping...\n";
        } else {
            echo " [!] Failed to create user: " . $e->getMessage() . "\n";
        }
    }
}

function updateUser(array $data, PDO $pdo) {
    $stmt = $pdo->prepare("UPDATE client SET
        pass = :pass, email = :email, first_name = :first_name, last_name = :last_name,
        custom_1 = :custom_1, updated_at = :updated_at
        WHERE custom_2 = :custom_2");

    try {
        $stmt->execute([
            ':email'      => $data['email'],
            ':pass'       => $data['password'],
            ':first_name' => $data['first_name'],
            ':last_name'  => $data['last_name'],
            ':custom_1'   => trim($data['title']),
            ':custom_2'   => $data['uid'],
            ':updated_at' => date('Y-m-d H:i:s'),
        ]);
        echo $stmt->rowCount()
            ? " [✔] User updated: {$data['uid']}\n"
            : " [!] No user found for UID: {$data['uid']}\n";
    } catch (PDOException $e) {
        echo " [!] Failed to update user: " . $e->getMessage() . "\n";
    }
}

function deleteUser(array $data, PDO $pdo) {
    $stmt = $pdo->prepare("DELETE FROM client WHERE custom_2 = :custom_2");
    try {
        $stmt->execute([':custom_2' => $data['uid']]);
        echo $stmt->rowCount()
            ? " [✔] User deleted: {$data['uid']}\n"
            : " [!] No user found for UID: {$data['uid']}\n";
    } catch (PDOException $e) {
        echo " [!] Failed to delete user: " . $e->getMessage() . "\n";
    }
}

// --- EVENT CRUD ---
function createEvent(array $e, PDO $pdo) {
    $stmt = $pdo->prepare("INSERT INTO events
        (uid_event, name, start_date, end_date, address, description, max_attendees)
        VALUES (:uniqueid, :name, :start, :end, :addr, :desc, :max)");
    try {
        $stmt->execute([
            ':uniqueid' => $e['uid_event'],
            ':name'     => $e['name'],
            ':start'    => date('Y-m-d H:i:s', strtotime($e['start_date'])),
            ':end'      => date('Y-m-d H:i:s', strtotime($e['end_date'])),
            ':addr'     => $e['address'],
            ':desc'     => $e['description'] ?? null,
            ':max'      => (int) trim($e['max_attendees'] ?? 0),
        ]);
        echo " [✔] Event created: {$e['uid_event']}\n";
    } catch (PDOException $ex) {
        echo " [!] Failed to create event {$e['uid_event']}: " . $ex->getMessage() . "\n";
    }
}

function updateEvent(array $e, PDO $pdo) {
    $stmt = $pdo->prepare("UPDATE events SET
        name = :name, start_date = :start, end_date = :end, address = :addr,
        description = :desc, max_attendees = :max, updated_at = CURRENT_TIMESTAMP
        WHERE uid_event = :uniqueid");

    $stmt->execute([
        ':uniqueid' => $e['uid_event'],
        ':name'     => $e['name'],
        ':start'    => date('Y-m-d H:i:s', strtotime($e['start_date'])),
        ':end'      => date('Y-m-d H:i:s', strtotime($e['end_date'])),
        ':addr'     => $e['address'],
        ':desc'     => $e['description'] ?? null,
        ':max'      => (int) trim($e['max_attendees'] ?? 0),
    ]);
    echo $stmt->rowCount()
        ? " [✔] Event updated: {$e['uid_event']}\n"
        : " [!] No event found to update: {$e['uid_event']}\n";
}

function deleteEvent(array $e, PDO $pdo) {
    $stmt = $pdo->prepare("DELETE FROM events WHERE uid_event = :uniqueid");
    $stmt->execute([':uniqueid' => $e['uid_event']]);
    echo $stmt->rowCount()
        ? " [✔] Event deleted: {$e['uid_event']}\n"
        : " [!] No event found to delete: {$e['uid_event']}\n";
}
