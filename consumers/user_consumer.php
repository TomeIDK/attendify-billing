<?php
require_once __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/../parser.php';
require_once __DIR__ . '/../logger.php';

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
    echo " [x] Message received.\n";
    try {
        $jsonData = xmlToJson($msg->getBody());
        $data = json_decode($jsonData, true)['attendify'];
        echo " [x] Parsed data.\n";
        echo " [debug] Decoded data:\n";
        print_r($data);

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
    } elseif (isset($data['companies'])) {
        // Process company operations
        switch ($operation) {
            case 'create':
                createCompany($data['companies']['company'], $pdo);
                break;
            case 'update':
                updateCompany($data['companies']['company'], $pdo);
                break;
            case 'delete':
                deleteCompany($data['companies']['company'], $pdo);
                unregisterAllUsersFromCompany($data['companies']['company'], $pdo);
                break;
            default:
                echo " [!] Unknown operation '{$operation}' for event. Skipping...\n";
                break;
        }
    } elseif (isset($data['company_employee'])) {
        // Process company employee operations
        switch ($operation) {
            case 'register':
                $companyData = getCompany($data['company_employee'], $pdo);
                if ($companyData == null){ 
                    echo " [!] No company found with UID: {$data['company_id']}. Check if it exists.\n";
                    break;
                }

                $data['company_employee']['name'] = $companyData['name'];
                $data['company_employee']['companyNumber'] = $companyData['companyNumber'];
                $data['company_employee']['VATNumber'] = $companyData['VATNumber'];

                registerCompanyEmployee($data['company_employee'], $pdo);
                break;
            case 'unregister':
                unregisterCompanyEmployee($data['company_employee'], $pdo);
                break;
            default:
                echo " [!] Unknown operation '{$operation}' for company employee. Skipping...\n";
                break;
        }
    } elseif (isset($data['tab'])) {
        // process payment messages
        switch ($operation) {
            case 'create':
                $row = isUserRegistered($data['tab']['uid'], $data['tab']['event_id'], $pdo);
                if ($row == null) {
                    break;
                }

                if ($row == false) {
                    $company_id = getUserCompanyId($data['tab']['uid'], $pdo);
                    if ($company_id == null) {
                        break;
                    }

                    if ($company_id) {
                        $row['invoice_id'] = getCompanyInvoiceIdForEvent($company_id, $data['tab']['event_id'], $pdo);
                        if ($row['invoice_id'] == null) {
                            break;
                        }
                    }
                    $row['row_id'] = registerUserWithEvent($data['tab']['uid'], $data['tab']['event_id'], $row['invoice_id'], $data['tab']['timestamp'], $pdo);
                    if ($row['row_id'] == null) {
                        break;
                    }
                }
                saveItem($data['tab']['items'], $row['row_id'], $row['invoice_id'], $pdo);
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


// --- USER CRUD FUNCTIONS ---

/**
 * Insert a new user into the users table.
 */
function createUser(array $data, PDO $pdo) {
    global $channel;
    $currentTime = date('Y-m-d H:i:s');

    // Set session variable to indicate consumer is making the change
    $pdo->exec("SET @is_consumer_source = 1");

    $sql = "INSERT INTO client (
                email, pass, first_name, last_name, custom_1, custom_2, custom_3, created_at, updated_at
            ) VALUES (
                :email, :pass, :first_name, :last_name, :custom_1, :custom_2, :custom_3, :created_at, :updated_at
            )";
    $stmt = $pdo->prepare($sql);
    try {
        $stmt->execute([
            ':email'          => $data['email'],
            ':pass'           => $data['password'],
            ':first_name'     => $data['first_name'],
            ':last_name'      => $data['last_name'],
            ':custom_1'       => trim($data['title']),
            ':custom_2'       => $data['uid'],
            ':custom_3'       => $data['is_admin'],
            ':created_at'     => $currentTime,
            ':updated_at'     => $currentTime,
        ]);
        echo " [✔] User created successfully: {$data['email']}\n";
        sendLog($channel, "user", "User created successfully: {$data['uid']}", 'user-management');
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            echo " [!] User with email {$data['email']} already exists. Skipping...\n";
            sendLog($channel, "user", "User with email {$data['email']} already exists. Skipping...", 'user-management');
        } else {
            echo " [!] Error: Database failed to create user.\n" . $e->getMessage() . "\n";
            sendLog($channel, "user", "Database failed to create user: " . $e->getMessage(), 'user-management');
        }
    }
}

/**
 * Update an existing user in the users table.
 */
function updateUser(array $data, PDO $pdo) {
    global $channel;
    $currentTime = date('Y-m-d H:i:s');

    // set session variable
    $pdo->exec("SET @is_consumer_source = 1");

    $sql = "UPDATE client SET
                pass = :pass,
                email = :email,
                first_name = :first_name,
                last_name = :last_name,
                custom_1 = :custom_1,
                custom_3 = :custom_3,
                updated_at = :updated_at
            WHERE custom_2 = :custom_2";
    $stmt = $pdo->prepare($sql);
    try {
        $stmt->execute([
            ':email'          => $data['email'],
            ':pass'           => $data['password'],
            ':first_name'     => $data['first_name'],
            ':last_name'      => $data['last_name'],
            ':custom_1'       => trim($data['title']),
            ':custom_2'       => $data['uid'],
            ':custom_3'       => $data['is_admin'],
            ':updated_at'     => $currentTime,
        ]);
        if ($stmt->rowCount() > 0) {
            echo " [✔] User updated with UID: {$data['uid']}\n";
            sendLog($channel, "user", "User updated with UID: {$data['uid']}", 'user-management');
        } else {
            echo " [!] No user found to update with UID: {$data['uid']}. Check if it exists.\n";
            sendLog($channel, "user", "No user found to update with UID: {$data['uid']}.", 'user-management');
        }
    } catch (PDOException $e) {
        echo " [!] Error: Database failed to update user.\n" . $e->getMessage() . "\n";
        sendLog($channel, "user", "Database failed to update user: " . $e->getMessage(), 'user-management');
    }
}

/**
 * Delete a user from the users table.
 */
function deleteUser(array $data, PDO $pdo) {
    global $channel;

    // set session variable
    $pdo->exec("SET @is_consumer_source = 1");

    $sql = "DELETE FROM client WHERE custom_2 = :custom_2";
    $stmt = $pdo->prepare($sql);
    try {
        $stmt->execute([':custom_2' => $data['uid']]);
        if ($stmt->rowCount() > 0) {
            echo " [✔] User successfully deleted with UID: {$data['uid']}\n";
            sendLog($channel, "user", "User successfully deleted with UID: {$data['uid']}", 'user-management');
        } else {
            echo " [!] No user found with UID: {$data['uid']}.\n";
            sendLog($channel, "user", "No user found with UID: {$data['uid']}.", 'user-management');
        }
    } catch (PDOException $e) {
        echo " [!] Error: Database failed to delete user.\n" . $e->getMessage() . "\n";
        sendLog($channel, "user", "Database failed to delete user: " . $e->getMessage(), 'user-management');
    }
}

// --- EVENT CRUD FUNCTIONS ---

/**
 * Insert a new event into the events table.
 */
function createEvent(array $e, PDO $pdo) {
    global $channel;
    $sql = "INSERT INTO events
        (uid_event, name, start_date, end_date, address, description, max_attendees)
     VALUES
        (:uniqueid, :name, :start, :end, :addr, :desc, :max)";
    $stmt = $pdo->prepare($sql);
    try {
        $stmt->execute([
            ':uniqueid'   => $e['uid'],
            ':name'  => $e['title'],
            ':start' => date('Y-m-d H:i:s', strtotime($e['start_date'])),
            ':end'   => date('Y-m-d H:i:s', strtotime($e['end_date'])),
            ':addr'  => $e['location'],
            ':desc'  => $e['description'] ?? null,
            ':max'   => (int) trim($e['max_attendees'] ?? 0),
        ]);
        echo " [✔] Event created: {$e['uid']}\n";
        sendLog($channel, "event", "Event created: {$e['uid']}", "event");
    } catch (PDOException $ex) {
        echo " [!] Failed to create event {$e['uid']}: " . $ex->getMessage() . "\n";
        sendLog($channel, "event", "Failed to create event {$e['uid']}: " . $ex->getMessage(), "event");
    }
}

/**
 * Update an existing event in the events table.
 */
function updateEvent(array $e, PDO $pdo) {
    global $channel;
    $sql = "UPDATE events SET
                name = :name,
                start_date = :start,
                end_date   = :end,
                address    = :addr,
                description= :desc,
                max_attendees = :max,
                updated_at = CURRENT_TIMESTAMP
            WHERE uid_event = :uniqueid";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':uniqueid'   => $e['uid'],
        ':name'  => $e['title'],
        ':start' => date('Y-m-d H:i:s', strtotime($e['start_date'])),
        ':end'   => date('Y-m-d H:i:s', strtotime($e['end_date'])),
        ':addr'  => $e['location'],
        ':desc'  => $e['description'] ?? null,
        ':max'   => (int) trim($e['max_attendees'] ?? 0),
    ]);
    if ($stmt->rowCount() > 0) {
        echo " [✔] Event updated: {$e['uid']}\n";
        sendLog($channel, "event", "Event updated: {$e['uid']}", "event");
    } else {
        echo " [!] No event found to update: {$e['uid']}\n";
        sendLog($channel, "event", "No event found to update: {$e['uid']}", "event");
    }
}

/**
 * Delete an event from the events table.
 */
function deleteEvent(array $e, PDO $pdo) {
    global $channel;
    $stmt = $pdo->prepare("DELETE FROM events WHERE uid_event = :uniqueid");
    $stmt->execute([':uniqueid' => $e['uid']]);
    if ($stmt->rowCount() > 0) {
        echo " [✔] Event deleted: {$e['uid']}\n";
        sendLog($channel, "event", "Event deleted: {$e['uid']}", "event");
    } else {
        echo " [!] No event found to delete: {$e['uid']}\n";
        sendLog($channel, "event", "No event found to delete: {$e['uid']}", "event");
    }
}


// --- COMPANIES CRUD FUNCTIONS ---

/**
 * insert a new company into the company table.
 */
function createCompany(array $data, PDO $pdo) {
    global $channel;
    $sql = "INSERT INTO company (
                uid, owner_id, name, companyNumber, VATNumber, address_street, address_number, address_postcode, address_city, billing_address_street, billing_address_number, billing_address_postcode, billing_address_city, email, phone
            ) VALUES (
                :uid, :owner_id, :name, :companyNumber, :VATNumber, :address_street, :address_number, :address_postcode, :address_city, :billing_address_street, :billing_address_number, :billing_address_postcode, :billing_address_city, :email, :phone
            )";
    $stmt = $pdo->prepare($sql);
    try {
        $stmt->execute([
            ':uid' => $data['uid'],
            ':owner_id' => $data['owner_id'],
            ':name' => trim($data['name']),
            ':companyNumber' => $data['companyNumber'],
            ':VATNumber' => $data['VATNumber'],
            ':address_street' => $data['address']['street'],
            ':address_number' => $data['address']['number'],
            ':address_postcode' => $data['address']['postcode'],
            ':address_city' => $data['address']['city'],
            ':billing_address_street' => $data['billingAddress']['street'],
            ':billing_address_number' => $data['billingAddress']['number'],
            ':billing_address_postcode' => $data['billingAddress']['postcode'],
            ':billing_address_city' => $data['billingAddress']['city'],
            ':email' => $data['email'], 
            ':phone' => $data['phone']
        ]);
        echo " [✔] Company {$data['name']} created successfully: {$data['uid']}\n";
        sendLog($channel, "company", "Company {$data['name']} created successfully: {$data['uid']}", 'company');
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            echo " [!] Company with UID {$data['uid']} already exists. Skipping...\n";
            sendLog($channel, "company", "Company with UID {$data['uid']} already exists. Skipping...", 'company');
        } else {
            echo " [!] Error: Database failed to create company.\n" . $e->getMessage() . "\n";
            sendLog($channel, "company", "Database failed to create company: " . $e->getMessage(), 'company');
        }
    }

    // set uid to owner_id for compatibility with registerCompanyEmployee()
    
    $employeeData = $data;
    $employeeData['uid'] = $data['owner_id'];
    
    // add owner to company
    registerCompanyEmployee($employeeData, $pdo);
}

/**
 * Update an existing user in the users table.
 */
function updateCompany(array $data, PDO $pdo) {
    global $channel;
    $sql = "UPDATE company SET
                owner_id = :owner_id,
                name = :name,
                companyNumber = :companyNumber,
                VATNumber = :VATNumber,
                address_street = :address_street,
                address_number = :address_number,
                address_postcode = :address_postcode,
                address_city = :address_city,
                billing_address_street = :billing_address_street,
                billing_address_number = :billing_address_number,
                billing_address_postcode = :billing_address_postcode,
                billing_address_city = :billing_address_city,
                email = :email,
                phone = :phone
            WHERE uid = :uid";
    $stmt = $pdo->prepare($sql);
    try {
        $stmt->execute([
            ':owner_id' => $data['owner_id'],
            ':name' => trim($data['name']),
            ':companyNumber' => $data['companyNumber'],
            ':VATNumber' => $data['VATNumber'],
            ':address_street' => $data['address']['street'],
            ':address_number' => $data['address']['number'],
            ':address_postcode' => $data['address']['postcode'],
            ':address_city' => $data['address']['city'],
            ':billing_address_street' => $data['billingAddress']['street'],
            ':billing_address_number' => $data['billingAddress']['number'],
            ':billing_address_postcode' => $data['billingAddress']['postcode'],
            ':billing_address_city' => $data['billingAddress']['city'],
            ':email' => $data['email'],
            ':phone' => $data['phone'],
            ':uid' => $data['uid']
        ]);
        if ($stmt->rowCount() > 0) {
            echo " [✔] Company updated with UID: {$data['uid']}\n";
            sendLog($channel, "company", "Company updated with UID: {$data['uid']}", 'company');
        } else {
            echo " [!] No company found to update with UID: {$data['uid']}. Check if it exists.\n";
            sendLog($channel, "company", "No company found to update with UID: {$data['uid']}.", 'company');
        }
    } catch (PDOException $e) {
        echo " [!] Error: Database failed to update company.\n" . $e->getMessage() . "\n";
        sendLog($channel, "company", "Database failed to update company: " . $e->getMessage(), 'company');
    }

    // set uid to owner_id for compatibility with registerCompanyEmployee()
    
    $employeeData = $data;
    $employeeData['uid'] = $data['owner_id'];

    // add owner to company
    registerCompanyEmployee($employeeData, $pdo);
}

/**
 * Delete a user from the users table.
 */
function deleteCompany(array $data, PDO $pdo) {
    global $channel;
    $sql = "DELETE FROM company WHERE uid = :uid";
    $stmt = $pdo->prepare($sql);
    try {
        $stmt->execute([':uid' => $data['uid']]);
        if ($stmt->rowCount() > 0) {
            echo " [✔] Company successfully deleted with UID: {$data['uid']}\n";
        } else {
            echo " [!] No company found with UID: {$data['uid']}.\n";
        }
    } catch (PDOException $e) {
        echo " [!] Error: Database failed to delete company.\n" . $e->getMessage() . "\n";
    }
}


// --- COMPANY_EMPLOYEE CRUD FUNCTIONS ---

/**
 * register a user with a company.
 */
function registerCompanyEmployee(array $data, PDO $pdo) {
    global $channel;
  
    // set session variable
    $pdo->exec("SET @is_consumer_source = 1");

        $sql = "UPDATE client SET
            company = :name,
            company_number = :companyNumber,
            company_vat = :VATNumber
        WHERE custom_2 = :uid";
    $stmt = $pdo->prepare($sql);
    try {
        $stmt->execute([
            ':name' => trim($data['name']),
            ':companyNumber' => $data['companyNumber'],
            ':VATNumber' => $data['VATNumber'],
            ':uid' => $data['uid']
        ]);
        if ($stmt->rowCount() > 0) {
            echo " [✔] User {$data['uid']} registered with company {$data['name']}.\n";
            sendLog($channel, "company", "User {$data['uid']} registered with company {$data['name']}.", 'company-management');
        } else {
            echo " [!] No user found to register with UID: {$data['uid']}. Check if it exists.\n";
            sendLog($channel, "company", "No user found to register with UID: {$data['uid']}.", 'company-management');
        }
    } catch (PDOException $e) {
        echo " [!] Error: Database failed to register user {$data['uid']} with company {$data['name']}.\n" . $e->getMessage() . "\n";
        sendLog($channel, "company", "Database failed to register user {$data['uid']} with company {$data['name']}: " . $e->getMessage(), 'company-management');
    }


    // check if user is already registered with a company. update company id if true, insert user with company id if false
    $isUserRegisteredWithCompany = isUserRegisteredWithACompany($data['uid'], $pdo);
    if ($isUserRegisteredWithCompany == null) {
        return;
    }

    if ($isUserRegisteredWithCompany) {
        $sql = "UPDATE company_client SET
                    company_id = :company_id
                WHERE client_id = :client_id";
        $stmt = $pdo->prepare($sql);
        try {
            $stmt->execute([
                ':company_id' => $data['company_id'],
                ':client_id' => $data['uid']
            ]);

            if ($stmt->rowCount() > 0) {
                echo " [✔] User {$data['uid']} company updated to company {$data['company_id']}.\n";
            }
        } catch (PDOException $e) {
            echo " [!] Error: Database failed to update user {$data['uid']} with company {$data['company_id']}.\n" . $e->getMessage() . "\n";
        }
    } else {
        $sql = "INSERT INTO company_client (
                    company_id, 
                    client_id) VALUES (
                    :company_id, :client_id
                )";
        $stmt = $pdo->prepare($sql);
        try {
            $stmt->execute([
                ':company_id' => $data['company_id'],
                ':client_id' => $data['uid'],
            ]);

            if ($stmt->rowCount() > 0) {
                echo " [✔] User {$data['uid']} registered with company {$data['company_id']}.\n";
            } else {
                echo " [!] No user found to register with UID: {$data['uid']}. Check if it exists.\n";
            }
        } catch (PDOException $e) {
        echo " [!] Error: Database failed to register user {$data['uid']} with company {$data['company_id']}.\n" . $e->getMessage() . "\n";
        }
    }
}

/**
 * unregister a user with a company.
 */
function unregisterCompanyEmployee(array $data, PDO $pdo) {
    global $channel;
  
    // set session variable
    $pdo->exec("SET @is_consumer_source = 1");
    
    $sql = "UPDATE client SET
        company = NULL,
        company_number = NULL,
        company_vat = NULL
    WHERE custom_2 = :uid";
    $stmt = $pdo->prepare($sql);
    try {
        $stmt->execute([
            ':uid' => $data['uid']
        ]);
        if ($stmt->rowCount() > 0) {
            echo " [✔] User {$data['uid']} unregistered from company {$data['company_id']}.\n";
            sendLog($channel, "company", "User {$data['uid']} unregistered from company {$data['company_id']}.", 'company-management');
        } else {
            echo " [!] No user found to unregister with UID: {$data['uid']}. Check if it exists.\n";
            sendLog($channel, "company", "No user found to unregister with UID: {$data['uid']}.", 'company-management');
        }
    } catch (PDOException $e) {
        echo " [!] Error: Database failed to unregister user {$data['uid']} from company {$data['company_id']}.\n" . $e->getMessage() . "\n";
        sendLog($channel, "company", "Database failed to unregister user {$data['uid']} from company {$data['company_id']}: " . $e->getMessage(), 'company-management');
    }


    $sql = "DELETE FROM company_client WHERE client_id = :client_id";
    $stmt = $pdo->prepare($sql);
    try {
        $stmt->execute([
            ':client_id' => $data['uid']
        ]);

        if ($stmt->rowCount() > 0) {
            echo " [✔] User {$data['uid']} removed from company_client table.\n";
        } else {
            echo " [!] No record found with User ID: {$data['uid']}.\n";
        }
    } catch (PDOException $e) {
        echo " [!] Error: Failed to remove User {$data['uid']} from company_client table.\n" . $e->getMessage() . "\n";
    }
}

// --- PAYMENTS ---
/**
 * check if user has already made a payment at event
 */
function isUserRegistered($client_id, $event_id, $pdo) {
    // return row id + invoice id if true, otherwise return false
    $sql = "SELECT id, invoice_id 
            FROM client_event 
            WHERE client_id = :client_id AND event_uid = :event_id";

    $stmt = $pdo->prepare($sql);
    try {
        $stmt->execute([
            ':client_id' => $client_id,
            ':event_id' => $event_id
        ]);
    } catch (PDOException $e) {
        echo " [!] Database failed to fetch row: " . $e->getMessage() . "\n";
        return null;
    }

    $row = $stmt->fetch();

    return ($row !== false) ? $row : false;
}

/**
 * get the company id of a user if he is with one
 */
function getUserCompanyId($client_id, $pdo) {
    // return company_id if user is with company, otherwise return false
    $sql = "SELECT company_id 
    FROM company_client 
    WHERE client_id = :client_id";

    $stmt = $pdo->prepare($sql);
    try {
        $stmt->execute([
            ':client_id' => $client_id
        ]);
    } catch (PDOException $e) {
        echo " [!] Database failed to fetch client's company_id: " . $e->getMessage() . "\n";
        return null;
    }

    $row = $stmt->fetch();

    return ($row !== false) ? $row['company_id'] : false;
}

/**
 * get the invoice_id of a company for a specific event
 */
function getCompanyInvoiceIdForEvent($company_id, $event_id, $pdo) {
    // return invoice_id if exists, generate if not exists
    $sql = "SELECT invoice_id 
    FROM company_invoice 
    WHERE company_id = :company_id AND event_id = :event_id";

    $stmt = $pdo->prepare($sql);
    try {
        $stmt->execute([
            ':company_id' => $company_id,
            ':event_id' => $event_id
        ]);
    } catch (PDOException $e) {
        echo " [!] Database failed to fetch invoice_id: " . $e->getMessage() . "\n";
        return null;
    }

    $row = $stmt->fetch();

    if ($row !== false) {
        return $row['invoice_id'];
    } else {
        return generateInvoiceId($company_id, $event_id, $pdo);
    }
}

function generateInvoiceId($company_id, $event_id, $pdo) {
    
    $owner_id = getCompanyOwnerId($company_id, $pdo); 
    if ($owner_id === null) { 
        echo " [!] Could not generate invoice ID: Company owner not found for company_id {$company_id}\\n";
        return null; 
    }

    
    $companyDetails = getCompany(['company_id' => $company_id], $pdo); // Fetches company details

    // 1. create new row in invoice table, set client_id to fetched owner_id
    // 2. add any available data too (company details, created/updated_at)
    $currentTime = date('Y-m-d H:i:s');
    $insertInvoiceSql = "INSERT INTO invoice (client_id, created_at, updated_at) VALUES (:client_id, :created_at, :updated_at)"; // SQL to insert into invoice
    $stmtInvoice = $pdo->prepare($insertInvoiceSql);
    try {
        $stmtInvoice->execute([
            ':client_id' => $owner_id, 
            ':created_at' => $currentTime,
            ':updated_at' => $currentTime
        ]);
        $invoiceId = $pdo->lastInsertId(); 
        echo " [✔] Created new invoice #{$invoiceId} for company {$company_id}\\n";
    } catch (PDOException $e) {
        echo " [!] Database failed to create invoice: " . $e->getMessage() . "\\n";
        return null;
    }

    // 3. create new row in company_invoice table with necessary data
    $insertCompanyInvoiceSql = "INSERT INTO company_invoice (company_id, event_id, invoice_id) VALUES (:company_id, :event_id, :invoice_id)"; // SQL to link company, event, and invoice
    $stmtCompanyInvoice = $pdo->prepare($insertCompanyInvoiceSql);
    try {
        $stmtCompanyInvoice->execute([
            ':company_id' => $company_id,
            ':event_id' => $event_id,
            ':invoice_id' => $invoiceId 
        ]);
        echo " [✔] Linked invoice #{$invoiceId} to company {$company_id} and event {$event_id}\\n";
    } catch (PDOException $e) {
        echo " [!] Database failed to link invoice to company/event: " . $e->getMessage() . "\\n";
        
        return null;
    }

    // 4. return invoice id
    return $invoiceId; 
}

function getCompanyOwnerId($company_id, $pdo) {
    // 1. fetch owner_id and other data of company by company_id in company table.
    // 2. return this data
    $sql = "SELECT owner_id FROM company WHERE uid = :company_id"; // Fetches the owner_id
    $stmt = $pdo->prepare($sql);
    try {
        $stmt->execute([':company_id' => $company_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC); // Fetches the data (specifically the owner_id column)
        return $row ? $row['owner_id'] : null; // Returns the owner_id, or null if not found
    } catch (PDOException $e) {
        echo " [!] Database failed to fetch company owner ID: " . $e->getMessage() . "\\n";
        return null;
    }
}

/**
 * add user to client_event table for specific event
 */
function registerUserWithEvent($client_id, $event_id, $invoice_id = null, $registered_at, $pdo) {
    // return row_id
    $sql = "INSERT INTO client_event(
                event_uid, client_id, invoice_id, registered_at
            ) VALUES (
                :event_id, :client_id, :invoice_id, :registered_at
            )";

    $stmt = $pdo->prepare($sql);
    try {
        $stmt->execute([
            ':event_id' => $event_id,
            ':client_id' => $client_id,
            ':invoice_id' => $invoice_id,
            ':registered_at' => $registered_at
        ]);

        return $pdo->lastInsertId();
    } catch (PDOException $e) {
        echo " [!] Database failed to register client to event: " . $e->getMessage() . "\n";
        return null;
    }
}

/**
 * save invoice item to invoice_item table
 */
function saveItem($data, $row_id, $invoice_id, $pdo) {
    // for each tab_item in $data create a new invoice_item with invoice_id
    // row_id is the id of the client_event row, maybe rel_id can be used for this? Check docs or ask chat what it's for
    // no return, just log messages

    // If invoice_id is null, save item without an invoice_id (should happen automatically but check to make sure this happens)

    if (empty($data) || !is_array($data)) {
        echo " [!] No items data provided to saveItem. Skipping.\n";
        return;
    }

    $insertItemSql = "INSERT INTO invoice_item (invoice_id, title, quantity, price, taxed) VALUES (:invoice_id, :title, :quantity, :price, :taxed)";
    $stmtItem = $pdo->prepare($insertItemSql);

    foreach ($data as $item) {
        
        if (!isset($item['title'], $item['quantity'], $item['price'], $item['taxed'])) {
            echo " [!] Skipping item due to missing data.\n";
            continue; 
        }

        try {
            $stmtItem->execute([
                ':invoice_id' => $invoice_id, 
                ':title'      => $item['title'],
                ':quantity'   => $item['quantity'],
                ':price'      => $item['price'],
                ':taxed'      => $item['taxed'],
            ]);
            echo " [✔] Saved item '{$item['title']}' to invoice_item table (Invoice ID: " . ($invoice_id ?? 'NULL') . ")\n";
        } catch (PDOException $e) {
            echo " [!] Error: Database failed to save item: " . $e->getMessage() . "\n";
        }
    }
}






// --- GENERAL FUNCTIONS ---
function getCompany($data, $pdo) {
    global $channel;
    $sql = "SELECT * FROM company WHERE uid = :uid";

    $stmt = $pdo->prepare($sql);
    try {
        $stmt->execute([
            ':uid' => $data['company_id']
        ]);
    } catch (PDOException $e) {
        echo " [!] Database failed to fetch company with UID: {$data['company_id']} " . $e->getMessage() . "\n";
        sendLog($channel, "company", "Database failed to fetch company with UID: {$data['company_id']}: " . $e->getMessage(), 'company');
        return null;
    }

    $row = $stmt->fetch();

    return ($row !== false) ? $row : null;
}

function unregisterAllUsersFromCompany($data, $pdo) {
    global $channel;
    $users = getAllUsersWithCompany($data, $pdo);
    if (!$users) {
        echo " [!] No users found for company {$data['name']}.\n";
        sendLog($channel, "company", "No users found for company {$data['name']}.", 'company');
        return;
    }
    $company = [
        'uid' => 0,
        'company_id' => $data['uid']
    ];
    foreach ($users as $user) {
        $company['uid'] = $user['custom_2'];
        unregisterCompanyEmployee($company, $pdo);
    }

}

function getAllUsersWithCompany($data, $pdo) {
    global $channel;
    $sql = "SELECT custom_2 FROM client WHERE 
    company = :name AND
    company_number = :companyNumber AND
    company_vat = :VATNumber";

    $stmt = $pdo->prepare($sql);
    try {
        $stmt->execute([
            ':name' => trim($data['name']),
            ':companyNumber' => $data['companyNumber'],
            ':VATNumber' => $data['VATNumber'],
        ]);
    } catch (PDOException $e) {
        echo " [!] Database failed to fetch users with company {$data['name']} " . $e->getMessage() . "\n";
        sendLog($channel, "company", "Database failed to fetch users with company {$data['name']}: " . $e->getMessage(), 'company');
        return null;
    }

    $rows = $stmt->fetchAll();

    return (!empty($rows)) ? $rows : null;
}

function isUserRegisteredWithACompany($client_id, $pdo) {
    $sql = "SELECT * FROM company_client WHERE 
    client_id = :client_id";

    $stmt = $pdo->prepare($sql);
    try {
        $stmt->execute([
            ':client_id' => $client_id
        ]);
    } catch (PDOException $e) {
        echo " [!] Database failed to fetch user {$client_id} from company_client table" . $e->getMessage() . "\n";
        return null;
    }

    $row = $stmt->fetch();

    return ($row !== true);
}
