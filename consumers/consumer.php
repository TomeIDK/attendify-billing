<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/parser.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

// --- DATABASE CONNECTIE via PDO ---
$host       = '127.0.0.1';
$db         = 'fossbilling';
$user       = 'root';
$pass       = 'root';
$charset    = 'utf8mb4';
$port       = 3307;
$dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
$pdo = new PDO($dsn, $user, $pass, $options);

// --- VERBINDING MET RABBITMQ ---
$connection = new AMQPStreamConnection('localhost', 5672, 'guest', 'guest');
$channel    = $connection->channel();
$queueName  = 'test';
$channel->queue_declare($queueName, false, true, false, false);

/**
 * Parseert de XML-tekst en zet de benodigde velden in een associatieve array.
 */
$parser = new AttendifyXMLParser();

// Replace your old parseUserXML function usage in the callback with the parser from parser.php:
$callback = function (AMQPMessage $msg) use ($pdo, $parser) {
    echo " [x] Message received.\n";
    try {
        // Use the parser from parser.php
        $jsonData = $parser->parseMessage($msg->body);
        // Convert JSON back to an associative array if needed
        $data = json_decode($jsonData, true);
        echo " [x] Parsed data:\n" . print_r($data, true) . "\n";
    } catch (Exception $e) {
        echo " [!] Error parsing XML: " . $e->getMessage() . "\n";
        return;
    }

    $operation = strtolower($data['info']['operation']);
    echo " [x] Operation to perform: " . $operation . "\n";
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
            echo " [!] Unknown operation: {$data['info']['operation']}\n";
            break;
    }
    $msg->ack();
};

$channel->basic_consume($queueName, '', false, false, false, false, $callback);

echo " [*] Waiting for messages. Press CTRL+C to exit.\n";
while ($channel->is_consuming()) {
    $channel->wait();
}
/**
 * Voegt een nieuwe gebruiker toe in de database.
 * Bij duplicate entry (zelfde email) wordt automatisch een update uitgevoerd.
 */
function createUser(array $data, PDO $pdo) {
    $currentTime = date('Y-m-d H:i:s');
    $sql = "INSERT INTO client (
                email, pass, first_name, last_name, gender, birthday, phone,
                address_1, city, state, postcode, country, custom_1, created_at, updated_at
            ) VALUES (
                :email, :pass, :first_name, :last_name, :gender, :birthday, :phone,
                :address_1, :city, :state, :postcode, :country, :custom_1, :created_at, :updated_at
            )";
    $stmt = $pdo->prepare($sql);
    try {
        $stmt->execute([
            ':email'      => $data['email'],
            ':pass'       => $data['pass'],
            ':first_name' => $data['first_name'],
            ':last_name'  => $data['last_name'],
            ':gender'     => $data['gender'],
            ':birthday'   => $data['birthday'],
            ':phone'      => $data['phone'],
            ':address_1'  => $data['address_1'],
            ':city'       => $data['city'],
            ':state'      => $data['state'],
            ':postcode'   => $data['postcode'],
            ':country'    => $data['country'],
            ':custom_1'   => $data['custom_1'],
            ':created_at' => $currentTime,
            ':updated_at' => $currentTime,
        ]);
        echo " [x] Gebruiker aangemaakt: {$data['email']}\n";
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            echo " [!] Duplicate entry voor email {$data['email']}. Voer update uit.\n";
            updateUser($data, $pdo);
        } else {
            echo " [!] Database fout bij create: " . $e->getMessage() . "\n";
        }
    }
}

/**
 * Wijzigt een bestaande gebruiker in de database.
 * Hier gebruiken we 'email' als unieke identifier.
 */
function updateUser(array $data, PDO $pdo) {
    $currentTime = date('Y-m-d H:i:s');
    $sql = "UPDATE client SET
                pass       = :pass,
                first_name = :first_name,
                last_name  = :last_name,
                gender     = :gender,
                birthday   = :birthday,
                phone      = :phone,
                address_1  = :address_1,
                city       = :city,
                state      = :state,
                postcode   = :postcode,
                country    = :country,
                custom_1   = :custom_1,
                updated_at = :updated_at
            WHERE email = :email";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':pass'       => $data['pass'],
        ':first_name' => $data['first_name'],
        ':last_name'  => $data['last_name'],
        ':gender'     => $data['gender'],
        ':birthday'   => $data['birthday'],
        ':phone'      => $data['phone'],
        ':address_1'  => $data['address_1'],
        ':city'       => $data['city'],
        ':state'      => $data['state'],
        ':postcode'   => $data['postcode'],
        ':country'    => $data['country'],
        ':custom_1'   => $data['custom_1'],
        ':updated_at' => $currentTime,
        ':email'      => $data['email']
    ]);
    if ($stmt->rowCount() > 0) {
        echo " [x] Gebruiker bijgewerkt met email: {$data['email']}\n";
    } else {
        echo " [!] Geen gebruiker bijgewerkt met email: {$data['email']}. Controleer of deze bestaat.\n";
    }
}

/**
 * Verwijdert een gebruiker uit de database.
 * We verwijderen op basis van het emailadres.
 */
function deleteUser(array $data, PDO $pdo) {
    $sql = "DELETE FROM client WHERE email = :email";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':email' => $data['email']]);
    if ($stmt->rowCount() > 0) {
        echo " [x] Gebruiker verwijderd met email: {$data['email']}\n";
    } else {
        echo " [!] Geen gebruiker verwijderd met email: {$data['email']}. Controleer of deze bestaat.\n";
    }
}

