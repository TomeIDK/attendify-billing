<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/../parser.php';
require_once __DIR__ . '/../logger.php';
require_once __DIR__ . '/user.php';
require_once __DIR__ . '/event.php';
require_once __DIR__ . '/company.php';
require_once __DIR__ . '/company_employee.php';
require_once __DIR__ . '/invoice_item.php';
require_once __DIR__ . '/invoice.php';
require_once __DIR__ . '/helper.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeload();

declare(ticks = 1); // signal handling for pcntl_signal

// --- DATABASE CONNECTIE via PDO ---
$host       = $_ENV['MYSQL_HOST'];
$db         = $_ENV['MYSQL_DB'];
$user       = $_ENV['MYSQL_USER'];
$pass       = $_ENV['MYSQL_PASSWORD'];
$charset    = 'utf8mb4';
$port       = $_ENV['MYSQL_PORT'];


// create pdo instance
$dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";
$pdoOptions = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
$pdo = new PDO($dsn, $user, $pass, $pdoOptions);

// --- VERBINDING MET RABBITMQ ---
$connection = new AMQPStreamConnection($_ENV['RABBITMQ_HOST'], $_ENV['RABBITMQ_PORT'], $_ENV['RABBITMQ_USER'], $_ENV['RABBITMQ_PASSWORD'], $_ENV['RABBITMQ_VHOST']);
$channel    = $connection->channel();
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
    global $channel;
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
                createUser($data['user'], $pdo, $channel);
                break;
            case 'update':
                updateUser($data['user'], $pdo, $channel);
                break;
            case 'delete':
                deleteUser($data['user'], $pdo, $channel);
                break;
            default:
                echo " [!] Unknown operation '{$operation}' for user. Skipping...\n";
                break;
        }
    } elseif (isset($data['event'])) {
        // Process event operations
        switch ($operation) {
            case 'create':
                createEvent($data['event'], $pdo, $channel);
                break;
            case 'update':
                updateEvent($data['event'], $pdo, $channel);
                break;
            case 'delete':
                deleteEvent($data['event'], $pdo, $channel);
                break;
            default:
                echo " [!] Unknown operation '{$operation}' for event. Skipping...\n";
                break;
        }
    } elseif (isset($data['companies'])) {
        // Process company operations
        switch ($operation) {
            case 'create':
                createCompany($data['companies']['company'], $pdo, $channel);
                // set uid to owner_id for compatibility with registerCompanyEmployee()
                $employeeData = $data['companies']['company'];
                $employeeData['company_id'] = $data['companies']['company']['uid'];
                $employeeData['uid'] = $data['companies']['company']['owner_id'];
                registerCompanyEmployee($employeeData, $pdo, $channel);
                linkUserWithCompany($employeeData, $pdo, $channel);
                break;
            case 'update':
                updateCompany($data['companies']['company'], $pdo, $channel);
                // set uid to owner_id for compatibility with registerCompanyEmployee()
                $employeeData = $data['companies']['company'];
                $employeeData['company_id'] = $data['companies']['company']['uid'];
                $employeeData['uid'] = $data['companies']['company']['owner_id'];
                registerCompanyEmployee($employeeData, $pdo, $channel);
                linkUserWithCompany($employeeData, $pdo, $channel);
                break;
            case 'delete':
                deleteCompany($data['companies']['company'], $pdo, $channel);
                unregisterAllUsersFromCompany($data['companies']['company'], $pdo, $channel);
                break;
            default:
                echo " [!] Unknown operation '{$operation}' for event. Skipping...\n";
                break;
        }
    } elseif (isset($data['company_employee'])) {
        // Process company employee operations
        switch ($operation) {
            case 'register':
                $companyData = getCompany($data['company_employee'], $pdo, $channel);
                if ($companyData == null){ 
                    echo " [!] No company found with UID: {$data['company_id']}. Check if it exists.\n";
                    break;
                }

                $data['company_employee']['name'] = $companyData['name'];
                $data['company_employee']['companyNumber'] = $companyData['companyNumber'];
                $data['company_employee']['VATNumber'] = $companyData['VATNumber'];

                registerCompanyEmployee($data['company_employee'], $pdo, $channel);
                linkUserWithCompany($data['company_employee'], $pdo, $channel);
                break;
            case 'unregister':
                unregisterCompanyEmployee($data['company_employee'], $pdo, $channel);
                break;
            default:
                echo " [!] Unknown operation '{$operation}' for company employee. Skipping...\n";
                break;
        }
    } elseif (isset($data['tab'])) {
        // process payment messages
        switch ($operation) {
            case 'create':
                $row = [];
                $row = isUserRegistered($data['tab']['uid'], $data['tab']['event_id'], $pdo, $channel);

                if ($row == false) {
                    $company_id = getUserCompanyId($data['tab']['uid'], $pdo, $channel);
                    if ($company_id == null) {
                        break;
                    }

                    if ($company_id) {
                        $row['invoice_id'] = getCompanyInvoiceIdForEvent($company_id, $data['tab']['event_id'], $pdo, $channel);
                    }
                    $row['id'] = registerUserWithEvent($data['tab']['uid'], $data['tab']['event_id'], $row['invoice_id'], $data['tab']['timestamp'], $pdo, $channel);
                    if ($row['id'] == null) {
                        break;
                    }
                }
                saveItem($data['tab']['items'], $row['id'], $row['invoice_id'], $data['tab']['is_paid'], $pdo, $channel);
                break;
            default:
                echo " [!] Unknown operation '{$operation}' for company employee. Skipping...\n";
                break;
        }
    } else {
        echo " [!] No recognized entity type in message. Skipping...\n";
    }

    $msg->ack();
};

// Declare which queues to consume from
$queues = ['billing.event', 'billing.user', 'billing.company', 'billing.sale'];

// Set up consumption for all queues
foreach ($queues as $queue) {
    $channel->basic_consume($queue, '', false, false, false, false, $callback);
    echo " [*] Consuming from queue: $queue\n";
}

echo " [*] Waiting for messages. Press CTRL+C to exit.\n";

// Process messages from any of the queues
while ($channel->is_consuming()) {
    $channel->wait();
}