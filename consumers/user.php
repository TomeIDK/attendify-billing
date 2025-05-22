<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/../parser.php';
require_once __DIR__ . '/../logger.php';
require_once __DIR__ . '/helper.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeload();

/**
 * Insert a new user into the users table.
 */
function createUser(array $data, PDO $pdo, $channel) {
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
        $stmt->closeCursor();
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
        $stmt->closeCursor();
    }
}

/**
 * Update an existing user in the users table.
 */
function updateUser(array $data, PDO $pdo, $channel) {
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
        $stmt->closeCursor();
    } catch (PDOException $e) {
        echo " [!] Error: Database failed to update user.\n" . $e->getMessage() . "\n";
        sendLog($channel, "user", "Database failed to update user: " . $e->getMessage(), 'user-management');
        $stmt->closeCursor();
    }
}

/**
 * Delete a user from the users table.
 */
function deleteUser(array $data, PDO $pdo, $channel) {
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
        $stmt->closeCursor();
    } catch (PDOException $e) {
        echo " [!] Error: Database failed to delete user.\n" . $e->getMessage() . "\n";
        sendLog($channel, "user", "Database failed to delete user: " . $e->getMessage(), 'user-management');
        $stmt->closeCursor();
    }
}