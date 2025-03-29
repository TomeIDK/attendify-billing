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
 * We gebruiken de AttendifyXMLParser om de XML om te zetten naar een gestructureerde array.
 */
$parser = new AttendifyXMLParser();

// CONSUMER CALLBACK
$callback = function (AMQPMessage $msg) use ($pdo, $parser) {
    echo " [x] Message received.\n";
    try {
        // Parse de XML naar JSON en decoderen naar een array
        $jsonData = $parser->parseMessage($msg->body);
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
 * Bouwt één adresstring door street, number en bus_number te combineren.
 */
function buildAddress(array $address): string {
    $addr = trim($address['street'] . ' ' . $address['number']);
    if (!empty($address['bus_number'])) {
        $addr .= ' bus ' . trim($address['bus_number']);
    }
    return $addr;
}

/**
 * Voegt een nieuwe gebruiker toe in de fossbilling database.
 * Mapped enkel de velden die beschikbaar zijn in de tabel 'client'.
 */
function createUser(array $data, PDO $pdo) {
    $currentTime = date('Y-m-d H:i:s');
    $address1 = buildAddress($data['address']);

    $sql = "INSERT INTO client (
                external_id, email, pass, first_name, last_name, birthday, phone,
                company, company_vat, company_number, address_1, city, state, postcode, country,
                email_approved, custom_1, created_at, updated_at
            ) VALUES (
                :external_id, :email, :pass, :first_name, :last_name, :birthday, :phone,
                :company, :company_vat, :company_number, :address_1, :city, :state, :postcode, :country,
                :email_approved, :custom_1, :created_at, :updated_at
            )";
    $stmt = $pdo->prepare($sql);
    try {
        $stmt->execute([
            ':external_id'    => $data['id'],
            ':email'          => $data['email'],
            ':pass'           => $data['password'],
            ':first_name'     => $data['first_name'],
            ':last_name'      => $data['last_name'],
            ':birthday'       => $data['date_of_birth'],
            ':phone'          => trim($data['phone_number']),
            // Bedrijfsgegevens: we slaan alleen de naam, VAT en ID op
            ':company'        => $data['company']['name'],
            ':company_vat'    => $data['company']['VAT_number'],
            ':company_number' => trim($data['company']['id']),
            // Adres
            ':address_1'      => $address1,
            ':city'           => $data['address']['city'],
            ':state'          => $data['address']['province'],
            ':postcode'       => $data['address']['postal_code'],
            ':country'        => $data['address']['country'],
            // Gebruik email_registered als indicatie of de email is goedgekeurd
            ':email_approved' => $data['email_registered'] ? 1 : 0,
            // Sla de title op in custom_1
            ':custom_1'       => trim($data['title']),
            ':created_at'     => $currentTime,
            ':updated_at'     => $currentTime,
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
 * Wijzigt een bestaande gebruiker in de fossbilling database.
 * Update enkel de velden die beschikbaar zijn in de tabel 'client'.
 */
function updateUser(array $data, PDO $pdo) {
    $currentTime = date('Y-m-d H:i:s');
    $address1 = buildAddress($data['address']);

    $sql = "UPDATE client SET
                external_id = :external_id,
                pass = :pass,
                first_name = :first_name,
                last_name = :last_name,
                birthday = :birthday,
                phone = :phone,
                company = :company,
                company_vat = :company_vat,
                company_number = :company_number,
                address_1 = :address_1,
                city = :city,
                state = :state,
                postcode = :postcode,
                country = :country,
                email_approved = :email_approved,
                custom_1 = :custom_1,
                updated_at = :updated_at
            WHERE email = :email";
    $stmt = $pdo->prepare($sql);
    try {
        $stmt->execute([
            ':external_id'    => $data['id'],
            ':email'          => $data['email'],
            ':pass'           => $data['password'],
            ':first_name'     => $data['first_name'],
            ':last_name'      => $data['last_name'],
            ':birthday'       => $data['date_of_birth'],
            ':phone'          => trim($data['phone_number']),
            ':company'        => $data['company']['name'],
            ':company_vat'    => $data['company']['VAT_number'],
            ':company_number' => trim($data['company']['id']),
            ':address_1'      => $address1,
            ':city'           => $data['address']['city'],
            ':state'          => $data['address']['province'],
            ':postcode'       => $data['address']['postal_code'],
            ':country'        => $data['address']['country'],
            ':email_approved' => $data['email_registered'] ? 1 : 0,
            ':custom_1'       => trim($data['title']),
            ':updated_at'     => $currentTime,
        ]);
        if ($stmt->rowCount() > 0) {
            echo " [x] Gebruiker bijgewerkt met email: {$data['email']}\n";
        } else {
            echo " [!] Geen gebruiker bijgewerkt met email: {$data['email']}. Controleer of deze bestaat.\n";
        }
    } catch (PDOException $e) {
        echo " [!] Database fout bij update: " . $e->getMessage() . "\n";
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
            echo " [x] Gebruiker verwijderd met email: {$data['email']}\n";
        } else {
            echo " [!] Geen gebruiker verwijderd met email: {$data['email']}. Controleer of deze bestaat.\n";
        }
    } catch (PDOException $e) {
        echo " [!] Database fout bij delete: " . $e->getMessage() . "\n";
    }
}
