<?php
require_once __DIR__ . '/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

//DB Connectie
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

//RABBITMQ verbinding
$connection = new AMQPStreamConnection('localhost', 5672, 'guest', 'guest');
$channel    = $connection->channel();
$queueName  = 'user_events';
$channel->queue_declare($queueName, false, true, false, false);

/**
 * Parseert de XML-tekst en zet de benodigde velden in een associatieve array.
 */
function parseUserXML(string $xmlString): array {
    $xmlObject = simplexml_load_string($xmlString, "SimpleXMLElement", LIBXML_NOCDATA);
    if (!$xmlObject) {
        throw new Exception("Kon XML niet parsen");
    }
    if (!isset($xmlObject->CRM->User)) {
        throw new Exception("XML bevat geen CRM->User element");
    }
    $userElement = $xmlObject->CRM->User;
    $data = [];

    $data['operation']   = trim((string)$userElement->operation);
    $data['id']          = trim((string)$userElement->id);
    $data['email']       = trim((string)$userElement->email);
    $data['pass']        = trim((string)$userElement->password);
    $data['first_name']  = trim((string)$userElement->first_name);
    $data['last_name']   = trim((string)$userElement->last_name);
    $data['gender']      = null;
    $data['birthday']    = trim((string)$userElement->date_of_birth);
    $data['phone']       = trim((string)$userElement->phone_number);
    
    $addressXml = $userElement->address;
    $street     = isset($addressXml->street) ? trim((string)$addressXml->street) : '';
    $number     = isset($addressXml->number) ? trim((string)$addressXml->number) : '';
    $bus        = isset($addressXml->bus_number) ? trim((string)$addressXml->bus_number) : '';
    $data['address_1'] = $street;
    if ($number !== '') {
        $data['address_1'] .= ' ' . $number;
    }
    if ($bus !== '') {
        $data['address_1'] .= ' bus ' . $bus;
    }
    $data['city']     = isset($addressXml->city) ? trim((string)$addressXml->city) : '';
    $data['state']    = isset($addressXml->province) ? trim((string)$addressXml->province) : '';
    $data['postcode'] = isset($addressXml->postal_code) ? trim((string)$addressXml->postal_code) : '';
    $data['country']  = isset($addressXml->country) ? trim((string)$addressXml->country) : '';
    $data['custom_1'] = trim((string)$userElement->title);
    return $data;
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
 * email = unieke identifier.
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

//CONSUMER CALLBACK
$callback = function (AMQPMessage $msg) use ($pdo) {
    echo " [x] Bericht ontvangen.\n";
    echo " [x] Bericht payload:\n" . $msg->body . "\n";
    try {
        $data = parseUserXML($msg->body);
        echo " [x] Geparseerde data:\n" . print_r($data, true) . "\n";
    } catch (Exception $e) {
        echo " [!] Fout bij XML-parsing: " . $e->getMessage() . "\n";
        return;
    }
    
    $operation = strtolower($data['operation']);
    echo " [x] Uit te voeren operatie: " . $operation . "\n";
    switch ($operation) {
        case 'create':
            createUser($data, $pdo);
            break;
        case 'update':
            updateUser($data, $pdo);
            break;
        case 'delete':
            deleteUser($data, $pdo);
            break;
        default:
            echo " [!] Onbekende operatie: {$data['operation']}\n";
            break;
    }
    $msg->ack();
};

$channel->basic_consume($queueName, '', false, false, false, false, $callback);

echo " [*] Wachten op berichten. Druk op CTRL+C om te stoppen.\n";
while ($channel->is_consuming()) {
    $channel->wait();
}
