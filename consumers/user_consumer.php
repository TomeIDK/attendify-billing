<?php
require_once __DIR__ . '/vendor/autoload.php';
require 'parser.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

define("INTERVAL", 5); // interval between db polling
declare(ticks = 1); // signal handling for pcntl_signal

// --- DATABASE CONNECTIE via PDO ---
$host       = $_ENV['MYSQL_HOST'];
$db         = $_ENV['MYSQL_DB'];
$user       = $_ENV['MYSQL_USER'];
$pass       = $_ENV['MYSQL_PASSWORD'];
$charset    = 'utf8mb4';
$port       = $_ENV['MYSQL_PORT'];
$dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
$pdo = new PDO($dsn, $user, $pass, $options);

// --- VERBINDING MET RABBITMQ ---
$connection = new AMQPStreamConnection($_ENV['RABBITMQ_HOST'], $_ENV['RABBITMQ_PORT'], $_ENV['RABBITMQ_USER'], $_ENV['RABBITMQ_PASSWORD'], $_ENV['RABBITMQ_VHOST']);
$channel    = $connection->channel();

// close connection if shutdown command is given (CTRL+C)
pcntl_signal(SIGINT, function() use ($channel, $connection) {
    shutdownHandler($channel, $connection);
});

// CONSUMER CALLBACK
$callback = function (AMQPMessage $msg) use ($pdo) {
    echo " [x] Message received.\n";
    try {
        // Parse de XML naar JSON en decoderen naar een array
        $jsonData = xmlToJson($msg->getBody());
        $array = json_decode($jsonData, true);
        echo " [x] Parsed data.\n";
        $data = $array['attendify'];
    } catch (Exception $e) {
        echo " [!] Error parsing XML: " . $e->getMessage() . "\n";
        return;
    }
        echo " [x] Message sender is '{$data['info']['sender']}'\n";

    // skip message if we are the sender
    if ($data['info']['sender'] == 'billing') {
        echo " Skipping...\n";
        // $msg->ack();
        return;
    }

    $operation = $data['info']['operation'];
    echo " [*] Operation to perform: " . $operation . "\n";
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
            echo " [!] Error: Unknown operation '{$operation}'. Skipping...\n";
            break;
    }
    $msg->ack();
};

$channel->basic_consume("billing.user", '', false, false, false, false, $callback);

echo " [*] Waiting for messages. Press CTRL+C to exit.\n";
while ($channel->is_consuming()) {
    $channel->wait();
}

/**
 * Bouwt één adresstring door street, number en bus_number te combineren.
 * => currently unnecessary -Cedric
 */
// function buildAddress(array $address): string {
//     $addr = trim($address['street'] . ' ' . $address['number']);
//     if (!empty($address['bus_number'])) {
//         $addr .= ' bus ' . trim($address['bus_number']);
//     }
//     return $addr;
// }

/**
 * Voegt een nieuwe gebruiker toe in de fossbilling database.
 * Mapped enkel de velden die beschikbaar zijn in de tabel 'client'.
 */
function createUser(array $data, PDO $pdo) {
    $currentTime = date('Y-m-d H:i:s');
    // $address1 = buildAddress($data['address']);

    // set session variable to indicate consumer is making the change
    $pdo->exec("SET @is_consumer_source = 1");

    $sql = "INSERT INTO client (
                email, pass, first_name, last_name, custom_1, created_at, updated_at
            ) VALUES (
                :email, :pass, :first_name, :last_name, :custom_1, :created_at, :updated_at
            )";
    $stmt = $pdo->prepare($sql);
    try {
        $stmt->execute([
            ':email'          => $data['email'],
            ':pass'           => $data['password'],
            ':first_name'     => $data['first_name'],
            ':last_name'      => $data['last_name'],
            ':custom_1'       => trim($data['title']),
            ':created_at'     => $currentTime,
            ':updated_at'     => $currentTime,
        ]);
        echo " [✔] User created successfully: {$data['email']}\n";
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            echo " [!] User with email {$data['email']} already exists. Skipping...\n";
            // updateUser($data, $pdo); => Needs to discussed with other services -Cedric
        } else {
            echo " [!] Error: Database failed to create user.\n" . $e->getMessage() . "\n";
        }
    }
}

/**
 * Wijzigt een bestaande gebruiker in de fossbilling database.
 * Update enkel de velden die beschikbaar zijn in de tabel 'client'.
 */
function updateUser(array $data, PDO $pdo) {
    $currentTime = date('Y-m-d H:i:s');
    // $address1 = buildAddress($data['address']);

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
            ':email'          => $data['email'],
            ':pass'           => $data['password'],
            ':first_name'     => $data['first_name'],
            ':last_name'      => $data['last_name'],
            ':custom_1'       => trim($data['title']),
            ':updated_at'     => $currentTime,
        ]);
        if ($stmt->rowCount() > 0) {
            echo " [✔] User updated with email: {$data['email']}\n";
        } else {
            echo " [!] Geen gebruiker bijgewerkt met email: {$data['email']}. Controleer of deze bestaat.\n";
        }
    } catch (PDOException $e) {
        echo " [!] Error: Database failed to update user.\n" . $e->getMessage() . "\n";
    }
}

/**
 * Verwijdert een gebruiker uit de fossbilling database op basis van het emailadres.
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

// shutdown command handler
function shutdownHandler($channel, $connection) {
    echo " [x] Closing RabbitMQ connection...\n";
    $channel->close();
    $connection->close();
    exit(0);
};
