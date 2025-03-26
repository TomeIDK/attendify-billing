<?php
require_once __DIR__ . '/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

// 1. Database credentials
$host       = '127.0.0.1';  // or your remote DB host
$db         = 'fossbilling';
$user       = 'root';
$pass       = 'root';
$charset    = 'utf8mb4';
$port       = 3307;         // adjust if needed (e.g., 3306)

// 2. Create a PDO instance
$dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
$pdo = new PDO($dsn, $user, $pass, $options);

// 3. Connect to RabbitMQ
$connection = new AMQPStreamConnection('localhost', 5672, 'guest', 'guest');
$channel = $connection->channel();

// 4. Declare the queue (adjust as needed for your environment)
$channel->queue_declare('test', false, true, false, false);

// 5. Define a callback function to process XML messages
$callback = function (AMQPMessage $msg) use ($pdo) {
    echo " [x] Received message.\n";

    // a) Parse the XML message
    $xmlObject = simplexml_load_string($msg->body, "SimpleXMLElement", LIBXML_NOCDATA);
    if (!$xmlObject) {
        echo " [!] Error: Could not parse XML\n";
        return;
    }

    // b) Navigate to the User element: <Attendify><CRM><User>
    if (!isset($xmlObject->CRM->User)) {
        echo " [!] Error: XML does not contain CRM->User element\n";
        return;
    }
    
    $userElement = $xmlObject->CRM->User;

    // c) Extract fields from XML

    // 1. Basic fields
    $email      = trim((string)$userElement->email);
    $pass       = trim((string)$userElement->password);
    $first_name = trim((string)$userElement->first_name);
    $last_name  = trim((string)$userElement->last_name);
    // The XML does not have a <gender> field; set to null or fill if needed
    $gender     = null;
    // birthday => date_of_birth
    $birthday   = trim((string)$userElement->date_of_birth);
    // phone => phone_number
    $phone      = trim((string)$userElement->phone_number);

    // 2. Address fields
    $addressXml = $userElement->address;
    $street     = isset($addressXml->street) ? trim((string)$addressXml->street) : '';
    $number     = isset($addressXml->number) ? trim((string)$addressXml->number) : '';
    $bus        = isset($addressXml->bus_number) ? trim((string)$addressXml->bus_number) : '';
    
    // Combine street + number + bus into address_1
    $address_1 = $street;
    if ($number !== '') {
        $address_1 .= ' ' . $number;
    }
    if ($bus !== '') {
        $address_1 .= ' bus ' . $bus;
    }
    
    $city     = isset($addressXml->city)        ? trim((string)$addressXml->city)        : '';
    $state    = isset($addressXml->province)    ? trim((string)$addressXml->province)    : '';
    $postcode = isset($addressXml->postal_code) ? trim((string)$addressXml->postal_code) : '';
    $country  = isset($addressXml->country)     ? trim((string)$addressXml->country)     : '';

    // 3. custom_1 => from <title>
    $custom_1 = trim((string)$userElement->title);

    // 4. created_at / updated_at => current timestamp
    $currentTime = date('Y-m-d H:i:s');

    // d) Prepare and execute the INSERT statement
    $stmt = $pdo->prepare("
        INSERT INTO client (
            email,
            pass,
            first_name,
            last_name,
            gender,
            birthday,
            phone,
            address_1,
            city,
            state,
            postcode,
            country,
            custom_1,
            created_at,
            updated_at
        ) VALUES (
            :email,
            :pass,
            :first_name,
            :last_name,
            :gender,
            :birthday,
            :phone,
            :address_1,
            :city,
            :state,
            :postcode,
            :country,
            :custom_1,
            :created_at,
            :updated_at
        )
    ");

    try {
        $stmt->execute([
            ':email'      => $email,
            ':pass'       => $pass,
            ':first_name' => $first_name,
            ':last_name'  => $last_name,
            ':gender'     => $gender,
            ':birthday'   => $birthday,
            ':phone'      => $phone,
            ':address_1'  => $address_1,
            ':city'       => $city,
            ':state'      => $state,
            ':postcode'   => $postcode,
            ':country'    => $country,
            ':custom_1'   => $custom_1,
            ':created_at' => $currentTime,
            ':updated_at' => $currentTime,
        ]);

        echo " [x] Inserted data for email: {$email}\n";
    } catch (PDOException $e) {
        echo " [!] Database error: " . $e->getMessage() . "\n";
    }
};

// 6. Consume messages from the 'test' queue
$channel->basic_consume('test', '', false, true, false, false, $callback);

echo " [*] Waiting for messages. To exit press CTRL+C\n";

// 7. Keep the consumer running
while ($channel->is_consuming()) {
    $channel->wait();
}
