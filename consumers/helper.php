<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/../parser.php';
require_once __DIR__ . '/../logger.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeload();

/**
 * get the company id of a user if he is with one
 */
function getUserCompanyId($client_id, $pdo, $channel) {
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
    $stmt->closeCursor();

    return ($row !== false) ? $row['company_id'] : false;
}

/**
 * get the invoice_id of a company for a specific event
 */
function getCompanyInvoiceIdForEvent($company_id, $event_id, $pdo, $channel) {
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

    $row = $stmt->fetchColumn();
    $stmt->closeCursor();

    if ($row !== false) {
        echo " [x] Fetched invoice_id {$row} for company {$company_id} and event {$event_id}";
        return $row['invoice_id'];
    } else {
        return generateInvoiceId($company_id, $event_id, $pdo, $channel);
    }
}

/**
 * check if user has already made a payment at event
 */
function isUserRegistered($client_id, $event_id, $pdo, $channel) {
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
    $stmt->closeCursor();

    return ($row !== false) ? $row : false;
}

/**
 * get all client id's that are registered with a company
 */
function getAllUsersWithCompany($data, $pdo, $channel) {
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
    $stmt->closeCursor();

    return (!empty($rows)) ? $rows : null;
}

/**
 * check if a user is already with a company
 */
function isUserRegisteredWithACompany($client_id, $pdo) {
    $sql = "SELECT 1 FROM company_client WHERE client_id = :client_id LIMIT 1";

    $stmt = $pdo->prepare($sql);
    try {
        $stmt->execute([
            ':client_id' => $client_id
        ]);
        $row = $stmt->fetchColumn();
        $stmt->closeCursor();
        return $row !== false;
    } catch (PDOException $e) {
        echo " [!] Database failed to fetch user {$client_id} from company_client table" . $e->getMessage() . "\n";
        $stmt->closeCursor();
        return null;
    }
}

/**
 * get the client_id of the company's owner
 */
function getCompanyOwnerId($company_id, $pdo, $channel) {
    $sql = "SELECT owner_id FROM company WHERE uid = :company_id";
    $stmt = $pdo->prepare($sql);
    try {
        $stmt->execute([':company_id' => $company_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        return $row ? $row['owner_id'] : null;
    } catch (PDOException $e) {
        echo " [!] Database failed to fetch company owner ID: " . $e->getMessage() . "\n";
        $stmt->closeCursor();

        return null;
    }
}

/**
 * get a company
 */
function getCompany($data, $pdo, $channel) {
    $sql = "SELECT * FROM company WHERE uid = :uid";

    $stmt = $pdo->prepare($sql);
    try {
        $stmt->execute([
            ':uid' => $data['company_id']
        ]);
    } catch (PDOException $e) {
        echo " [!] Database failed to fetch company with UID: {$data['company_id']} " . $e->getMessage() . "\n";
        sendLog($channel, "company", "Database failed to fetch company with UID: {$data['company_id']}: " . $e->getMessage(), 'company');
        $stmt->closeCursor();
        return null;
    }

    $row = $stmt->fetch();
    $stmt->closeCursor();

    return ($row !== false) ? $row : null;
}

function getClientInternalId($client_id, $pdo, $channel) {
    $sql = "SELECT id FROM client WHERE custom_2 = :client_id";

    $stmt = $pdo->prepare($sql);
    try {
        $stmt->execute([
            ':client_id' => $client_id
        ]);
    } catch (PDOException $e) {
        echo " [!] Database failed to fetch user with UID: {$client_id} " . $e->getMessage() . "\n";
        $stmt->closeCursor();
        return null;
    }

    $row = $stmt->fetch();
    $stmt->closeCursor();

    return ($row !== false) ? $row['id'] : null;
}