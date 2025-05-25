<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/../parser.php';
require_once __DIR__ . '/../logger.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

declare(ticks = 1);

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// DB setup
$pdo = new PDO(
    "mysql:host={$_ENV['MYSQL_HOST']};port={$_ENV['MYSQL_PORT']};dbname={$_ENV['MYSQL_DB']};charset=utf8mb4",
    $_ENV['MYSQL_USER'],
    $_ENV['MYSQL_PASSWORD'],
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

// RabbitMQ setup
$connection = new AMQPStreamConnection(
    $_ENV['RABBITMQ_HOST'],
    $_ENV['RABBITMQ_PORT'],
    $_ENV['RABBITMQ_USER'],
    $_ENV['RABBITMQ_PASSWORD'],
    $_ENV['RABBITMQ_VHOST']
);
$channel = $connection->channel();

echo " [x] Connected to RabbitMQ.\n";

// Graceful shutdown on CTRL+C
pcntl_signal(SIGINT, function() use ($channel, $connection) {
    shutdownHandler($channel, $connection);
});

echo " [*] Starting invoice producer. Press CTRL+C to exit\n";

while (true) {
    try {
        $stmt = $pdo->prepare("
            SELECT uid_event, name
            FROM events
            WHERE end_date < NOW()
            AND processed = FALSE LIMIT 1
        ");
        $stmt->execute();
        $data = $stmt->fetch();

        if (!$data) {
            sleep(5);
            continue;
        }

        $event_id = $data['uid_event'];
        $event_name = $data['name'];
        $stmt->closeCursor();

        echo " [x] Event {$event_id} has ended. Fetching invoices...\n";
        $invoices = fetchInvoicesData($event_id, $pdo, $channel);

        if (empty($invoices)) {
            echo " [!] No invoices found for event {$event_id}\n";
            sendLog($channel, "invoice", "No invoices found for event {$event_id}", 'invoice');
            continue;
        }

        foreach ($invoices as $invoice) {
            try {
                $xmlString = formatInvoice($invoice, $event_name);
                publishMessage($xmlString, $channel);
                echo " [✔] Invoice ID #{$invoice['invoice_id']} sent for company email {$invoice['email']}\n";
                sendLog($channel, "invoice", "[✔] Invoice ID #{$invoice['invoice_id']} sent for company email {$invoice['email']}\n", 'invoice');
            } catch (Exception $e) {
                echo " [!] Failed to format invoice ID #{$invoice['invoice_id']}: " . $e->getMessage() . "\n";
                sendLog($channel, "invoice", "Failed to format invoice ID #{$invoice['invoice_id']}: " . $e->getMessage(), 'invoice');
                continue;
            }
        }

    } catch (Exception $e) {
        echo " [!] Error with invoices: " . $e->getMessage() . "\n";
        sendLog($channel, "invoice", "Error processing invoices: " . $e->getMessage(), 'invoice');
        continue;
    }
    markProcessed($event_id, $pdo);
    sleep(5);
}

// Shutdown handler
function shutdownHandler($channel, $connection) {
    echo " [x] Closing RabbitMQ connection...\n";
    $channel->close();
    $connection->close();
    exit(0);
}

function fetchInvoicesData($event_id, $pdo, $channel) {
    try {
        $stmt = $pdo->prepare("
            SELECT ci.invoice_id, i.hash, c.email
            FROM company_invoice ci
            JOIN invoice i ON ci.invoice_id = i.id
            JOIN company c ON ci.company_id = c.uid
            WHERE ci.event_id = :event_id
        ");
        $stmt->execute([
            ':event_id' => $event_id
        ]);
        $invoices = $stmt->fetchAll();
        return $invoices;
    } catch (PDOException $e) {
        echo " [!] Database error: " . $e->getMessage() . "\n";
        sendLog($channel, "invoice", "Database error while polling fetching invoices: " . $e->getMessage(), 'invoice');
    }

}

function formatInvoice($invoice, $event_name) {
        $formattedInvoice = [
            'recipient' => $invoice['email'],
            'company' => [
                'name' => 'Attendify',
                'address' => "Nijverheidskaai 170",
                'zip' => '1070',
                'city' => 'Anderlecht',
                'vat' => 'BE 0897.456.321',
                'support_email' => 'support@attendify.com',
                'signature' => 'The Attendify Team',
            ],
            'event' => [
                'name' => $event_name,
            ],
            'invoice' => [
                'url' => "http://integrationproject-2425s2-002.westeurope.cloudapp.azure.com:30056/invoice/" . $invoice['hash'],
                'download' => "http://integrationproject-2425s2-002.westeurope.cloudapp.azure.com:30056/invoice/pdf/" . $invoice['hash'],
            ]
        ];
        // convert formatted user to xml
        $xml = new SimpleXMLElement("<dto/>");
        arrayToXml($formattedInvoice, $xml);

        // format xml
        $dom = new DOMDocument("1.0");
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->loadXML($xml->asXML());
        return $dom->saveXML();
}

// publish the xml message to rabbitmq
function publishMessage($xmlString, $channel) {
    $msg = new AMQPMessage(
        $xmlString,
        ['content-type' => 'application/xml']
    );
    $channel->basic_publish($msg, 'invoice', 'invoice.send');
}

function markProcessed($event_id, $pdo) {
    $pdo->prepare("UPDATE events SET processed = TRUE WHERE uid_event = :uid")
    ->execute([':uid' => $event_id]);
}
