<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/../parser.php';
require_once __DIR__ . '/../logger.php';
require_once __DIR__ . '/helper.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeload();

/**
 * register a user with a company.
 */
function registerCompanyEmployee(array $data, PDO $pdo, $channel) {  
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
            ':name' => $data['name'],
            ':companyNumber' => $data['companyNumber'],
            ':VATNumber' => $data['VATNumber'],
            ':uid' => $data['uid']
        ]);

        if ($stmt->rowCount() > 0) {
            echo " [✔] User {$data['uid']} registered with company {$data['name']}.\n";
            sendLog($channel, "company", "User {$data['uid']} registered with company {$data['name']}.", 'company');
        } else {
            echo " [!] No user found to register with UID: {$data['uid']}. Check if it exists.\n";
            sendLog($channel, "company", "No user found to register with UID: {$data['uid']}.", 'company');
        }
        $stmt->closeCursor();
    } catch (PDOException $e) {
        echo " [!] Error: Database failed to register user {$data['uid']} with company {$data['name']}.\n" . $e->getMessage() . "\n";
        sendLog($channel, "company", "Database failed to register user {$data['uid']} with company {$data['name']}: " . $e->getMessage(), 'company');
        $stmt->closeCursor();
    }
}

function linkUserWithCompany($data, $pdo, $channel) {
    // check if user is already registered with a company. update company id if true, insert user with company id if false
    $isUserRegisteredWithCompany = isUserRegisteredWithACompany($data['uid'], $pdo);

    if ($isUserRegisteredWithCompany == true) {
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
            $stmt->closeCursor();
        } catch (PDOException $e) {
            echo " [!] Error: Database failed to update user {$data['uid']} with company {$data['company_id']}.\n" . $e->getMessage() . "\n";
            $stmt->closeCursor();
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
            $stmt->closeCursor();
        } catch (PDOException $e) {
            echo " [!] Error: Database failed to register user {$data['uid']} with company {$data['company_id']}.\n" . $e->getMessage() . "\n";
            $stmt->closeCursor();
        }
    }
}

/**
 * unregister a user from a company.
 */
function unregisterCompanyEmployee(array $data, PDO $pdo, $channel) {  
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
            sendLog($channel, "company", "User {$data['uid']} unregistered from company {$data['company_id']}.", 'company');
        } else {
            echo " [!] No user found to unregister with UID: {$data['uid']}. Check if it exists.\n";
            sendLog($channel, "company", "No user found to unregister with UID: {$data['uid']}.", 'company');
        }
        $stmt->closeCursor();
    } catch (PDOException $e) {
        echo " [!] Error: Database failed to unregister user {$data['uid']} from company {$data['company_id']}.\n" . $e->getMessage() . "\n";
        sendLog($channel, "company", "Database failed to unregister user {$data['uid']} from company {$data['company_id']}: " . $e->getMessage(), 'company');
        $stmt->closeCursor();
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
        $stmt->closeCursor();
    } catch (PDOException $e) {
        echo " [!] Error: Failed to remove User {$data['uid']} from company_client table.\n" . $e->getMessage() . "\n";
        $stmt->closeCursor();
    }
}

/**
 * unregister multiple users from a company
 */
function unregisterAllUsersFromCompany($data, $pdo, $channel) {
    $users = getAllUsersWithCompany($data, $pdo, $channel);
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
        unregisterCompanyEmployee($company, $pdo, $channel);
    }

}