<?php

require_once __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/../parser.php';
require_once __DIR__ . '/../logger.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

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

// Declare exchange and queue for outgoing invoice messages
$channel->exchange_declare('invoice', 'direct', false, true, false);
$channel->queue_declare('invoice_xml', false, true, false, false);
$channel->queue_bind('invoice_xml', 'invoice', 'invoice.created');

echo " [x] Connected to RabbitMQ.\n";

// Graceful shutdown on CTRL+C
pcntl_signal(SIGINT, function() use ($channel, $connection) {
    shutdownHandler($channel, $connection);
});

echo " [*] Starting invoice producer. Press CTRL+C to exit\n";

while (true) {
    try {
        // Get events that have ended and their associated invoices
        $stmt = $pdo->prepare("
            SELECT 
                e.uid_event, 
                e.name as event_name, 
                e.end_date,
                ce.client_id,
                ce.invoice_id,
                c.email,
                c.first_name,
                c.last_name,
                c.company,
                c.company_number,
                c.company_vat,
                i.hash as invoice_hash
            FROM events e
            JOIN client_event ce ON e.uid_event = ce.event_uid
            JOIN client c ON ce.client_id = c.id
            JOIN invoice i ON ce.invoice_id = i.id
            WHERE e.end_date < NOW()
            AND ce.invoice_sent = 0
        ");
        $stmt->execute();
        $events = $stmt->fetchAll();
        
        $count = count($events);
        echo " [x] Found {$count} event(s) with invoices to send.\n";

        foreach ($events as $event) {
            echo " [*] Processing invoice for event {$event['event_name']} for client {$event['email']}\n";
            
            // Create XML with invoice URL and client/company information
            $xml = new SimpleXMLElement("<attendify/>");
            $info = $xml->addChild('info');
            $info->addChild('sender', 'billing');
            $info->addChild('operation', 'send');
            
            $invoice = $xml->addChild('invoice');
            $invoice->addChild('event_uid', $event['uid_event']);
            $invoice->addChild('event_name', $event['event_name']);
            $invoice->addChild('invoice_id', $event['invoice_id']);
            $invoice->addChild('invoice_url', "https://billing.attendify.com/invoice/{$event['invoice_hash']}");
            
            $client = $xml->addChild('client');
            $client->addChild('email', $event['email']);
            $client->addChild('first_name', $event['first_name']);
            $client->addChild('last_name', $event['last_name']);
            
            if ($event['company']) {
                $company = $xml->addChild('company');
                $company->addChild('name', $event['company']);
                $company->addChild('number', $event['company_number']);
                $company->addChild('vat', $event['company_vat']);
            }

            // Format XML
            $dom = new DOMDocument("1.0");
            $dom->preserveWhiteSpace = false;
            $dom->formatOutput = true;
            $dom->loadXML($xml->asXML());

            // Send to invoice queue
            $msgOut = new AMQPMessage($dom->saveXML(), ['content-type' => 'application/xml']);
            $channel->basic_publish($msgOut, 'invoice', 'invoice.created');

            // Mark invoice as sent
            $updateStmt = $pdo->prepare("UPDATE client_event SET invoice_sent = 1 WHERE event_uid = :event_uid AND client_id = :client_id");
            $updateStmt->execute([
                ':event_uid' => $event['uid_event'],
                ':client_id' => $event['client_id']
            ]);

            echo " [✔] Sent invoice URL for event {$event['event_name']} to client {$event['email']}\n";
            sendLog($channel, "invoice", "Sent invoice URL for event {$event['event_name']} to client {$event['email']}", 'invoice');
        }
    } catch (Exception $e) {
        echo " [!] Error: " . $e->getMessage() . "\n";
        sendLog($channel, "invoice", "Error processing invoices: " . $e->getMessage(), 'invoice');
        sleep(60); // Sleep for 1 minute on error
        continue;
    }
    
    sleep(INTERVAL);
}

// Shutdown handler
function shutdownHandler($channel, $connection) {
    echo " [x] Closing RabbitMQ connection...\n";
    $channel->close();
    $connection->close();
    exit(0);
}