<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/../parser.php';
require_once __DIR__ . '/../logger.php';
require_once __DIR__ . '/company_employee.php';
require_once __DIR__ . '/helper.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeload();

/**
 * insert a new company into the company table.
 */
function createCompany(array $data, PDO $pdo, $channel) {
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
        $stmt->closeCursor();
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
        $stmt->closeCursor();
    }
}

/**
 * Update an existing user in the users table.
 */
function updateCompany(array $data, PDO $pdo, $channel) {
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
        $stmt->closeCursor();
    } catch (PDOException $e) {
        echo " [!] Error: Database failed to update company.\n" . $e->getMessage() . "\n";
        sendLog($channel, "company", "Database failed to update company: " . $e->getMessage(), 'company');
        $stmt->closeCursor();
    }
}

/**
 * Delete a user from the users table.
 */
function deleteCompany(array $data, PDO $pdo, $channel) {
    $sql = "DELETE FROM company WHERE uid = :uid";
    $stmt = $pdo->prepare($sql);
    try {
        $stmt->execute([':uid' => $data['uid']]);
        if ($stmt->rowCount() > 0) {
            echo " [✔] Company successfully deleted with UID: {$data['uid']}\n";
            sendLog($channel, "company", "Company successfully deleted with UID: {$data['uid']}.", 'company');
        } else {
            echo " [!] No company found with UID: {$data['uid']}.\n";
            sendLog($channel, "company", "No company found with UID: {$data['uid']}.", 'company');
        }
        $stmt->closeCursor();
    } catch (PDOException $e) {
        echo " [!] Error: Database failed to delete company.\n" . $e->getMessage() . "\n";
        sendLog($channel, "company", "Error: Database failed to delete company: " . $e->getMessage(), 'company');
        $stmt->closeCursor();
    }
}