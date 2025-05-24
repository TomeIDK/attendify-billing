<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/../parser.php';
require_once __DIR__ . '/../logger.php';
require_once __DIR__ . '/helper.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeload();

/**
 * add user to client_event table for specific event
 */
function registerUserWithEvent($client_id, $event_id, $invoice_id = null, $registered_at, $pdo, $channel) {
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
        $id = $pdo->lastInsertId();
        $stmt->closeCursor();

        return $id;
    } catch (PDOException $e) {
        echo " [!] Database failed to register client to event: " . $e->getMessage() . "\n";
        $stmt->closeCursor();
        return null;
    }
}

/**
 * save invoice item to invoice_item table
 */
function saveItem($data, $row_id, $invoice_id, $charged, $pdo, $channel) {
    if (empty($data) || !is_array($data)) {
        echo " [!] No items data provided to saveItem. Skipping.\n";
        return;
    }

    $items = $data['tab_item'] ?? [];
    if (isset($items['item_name'])) {
        $items = [$items];
    }

    $currentTime = date('Y-m-d H:i:s');
    $charged = $charged ? 1 : 0;

    $insertItemSql = "INSERT INTO invoice_item (
                        invoice_id, rel_id, status, title, quantity, price, charged, taxed, created_at, updated_at
                    ) VALUES (
                        :invoice_id, :rel_id, :status, :title, :quantity, :price, :charged, :taxed, :created_at, :updated_at)";
    $stmtItem = $pdo->prepare($insertItemSql);

    foreach ($items as $item) {
        try {
            // Calculate BTW amount based on taxed status
            //$isTaxed = $item['taxed'] ?? false;
            //$btwAmount = $isTaxed ? ($item['price'] * $item['quantity'] * 0.21) : 0;
            //$totalWithBTW = ($item['price'] * $item['quantity']) + $btwAmount;

            $stmtItem->execute([
                ':invoice_id' => $invoice_id,
                ':rel_id' => $row_id,
                ':status' => $charged ? 'paid' : null,
                ':title' => $item['item_name'],
                ':quantity' => $item['quantity'],
                ':price' => $item['price'],
                ':charged' => $charged,
                ':taxed' => 1,
                ':created_at' => $currentTime,
                ':updated_at' => $currentTime,
            ]);
            echo " [✔] Saved item '{$item['item_name']}' to invoice_item table (Invoice ID: " . ($invoice_id ?? 'NULL') . ")\n";
            $stmtItem->closeCursor();
        } catch (PDOException $e) {
            echo " [!] Error: Database failed to save item: " . $e->getMessage() . "\n";
            $stmtItem->closeCursor();
        }
    }
}