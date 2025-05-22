<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/../parser.php';
require_once __DIR__ . '/../logger.php';
require_once __DIR__ . '/helper.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeload();

/**
 * Insert a new event into the events table.
 */
function createEvent(array $e, PDO $pdo, $channel) {
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
        $stmt->closeCursor();
        echo " [✔] Event created: {$e['uid']}\n";
        sendLog($channel, "event", "Event created: {$e['uid']}", "event");
    } catch (PDOException $ex) {
        echo " [!] Failed to create event {$e['uid']}: " . $ex->getMessage() . "\n";
        sendLog($channel, "event", "Failed to create event {$e['uid']}: " . $ex->getMessage(), "event");
        $stmt->closeCursor();
    }
}

/**
 * Update an existing event in the events table.
 */
function updateEvent(array $e, PDO $pdo, $channel) {
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
    $stmt->closeCursor();
}

/**
 * Delete an event from the events table.
 */
function deleteEvent(array $e, PDO $pdo, $channel) {
    $stmt = $pdo->prepare("DELETE FROM events WHERE uid_event = :uniqueid");

    $stmt->execute([':uniqueid' => $e['uid']]);

    if ($stmt->rowCount() > 0) {
        echo " [✔] Event deleted: {$e['uid']}\n";
        sendLog($channel, "event", "Event deleted: {$e['uid']}", "event");
    } else {
        echo " [!] No event found to delete: {$e['uid']}\n";
        sendLog($channel, "event", "No event found to delete: {$e['uid']}", "event");
    }
    $stmt->closeCursor();
}