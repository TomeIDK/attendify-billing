<?php
require_once __DIR__ . '/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

// 1. Database credentials
$host       = 'localhost';  // or your remote DB host
$db         = 'fossbilling';
$user       = 'fossbilling';
$pass       = 'fossbilling';
$charset    = 'utf8mb4';
$port       = 3306;         // adjust if needed (e.g., 3306)

// 2. Create a PDO instance
$dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
$pdo = new PDO($dsn, $user, $pass, $options);

// 3. Connect to RabbitMQ
$connection = new AMQPStreamConnection('localhost', 5672, 'user', 'password', 'vhost');
$channel = $connection->channel();

// 5. Define a callback function to process XML messages
$callback = function (AMQPMessage $msg) use ($pdo) {
    echo " [x] Received message.\n";

    // a) Parse the XML message
    $xmlObject = simplexml_load_string($msg->getBody(), "SimpleXMLElement", LIBXML_NOCDATA);
    if (!$xmlObject) {
        echo " [!] Error: Could not parse XML\n";
        return;
    }

    $jsonString = json_encode($xmlObject);
    $jsonArray = json_decode($jsonString, true);

    echo "[x] Message received with user data (JSON format):\n$jsonString\n";


    // b) Navigate to the User element: <Attendify><CRM><User>
    if (!isset($jsonArray['user'])) {
        echo " [!] Error: JSON does not contain 'user' data\n";
        return;
    }
    
    $userElement = $jsonArray['user'];

    // Ignore message if we are the sender
    if ($jsonArray['info']['sender'] === 'billing'){        
        echo "[x] Ignoring message received by {$jsonArray['info']['sender']}";
        return;
    }

    // c) Extract fields from XML

    // 1. Basic fields
    $email      = $userElement['email'] ?? null;
    $password   = $userElement['password'] ?? null;
    $first_name = $userElement['first_name'] ?? null;
    $last_name  = $userElement['last_name'] ?? null;
    $birthday   = $userElement['date_of_birth'] ?? null;
    $phone      = $userElement['phone_number'] ?? null;
    $title      = $userElement['title'] ?? null;

    // Extract address details
    $address    = $userElement['address'] ?? [];
    $address_1  = ($address['street'] ?? '') . ' ' . ($address['number'] ?? '') . ' ' . ($address['bus_number'] ?? '');
    $city       = $address['city'] ?? null;
    $state      = $address['province'] ?? null;
    $postcode   = $address['postal_code'] ?? null;
    $country    = $address['country'] ?? null;

    // Current timestamp
    $currentTime = date('Y-m-d H:i:s');


    // d) Prepare and execute the INSERT statement
    $stmt = $pdo->prepare("
        INSERT INTO client (
            email,
            pass,
            first_name,
            last_name,
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
            ':pass'       => $password,
            ':first_name' => $first_name,
            ':last_name'  => $last_name,
            ':birthday'   => $birthday,
            ':phone'      => $phone,
            ':address_1'  => $address_1,
            ':city'       => $city,
            ':state'      => $state,
            ':postcode'   => $postcode,
            ':country'    => $country,
            ':custom_1'   => $title,
            ':created_at' => $currentTime,
            ':updated_at' => $currentTime,
        ]);

        echo " [✔] Inserted data for email: {$email}\n";
    } catch (PDOException $e) {
        echo " [!]  Database error: " . $e->getMessage() . "\n";
    }
};

// 6. Consume messages from the 'test' queue
$channel->basic_consume('billing.user', '', false, true, false, false, $callback);

echo " [*] Waiting for messages from queue: billing.user. To exit press CTRL+C\n";

// 7. Keep the consumer running
while ($channel->is_consuming()) {
    $channel->wait();
}
