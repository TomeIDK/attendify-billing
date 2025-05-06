<?php

require_once __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/../parser.php';

use Dotenv\Dotenv;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

define("INTERVAL", 5); // interval between db polling
declare(ticks = 1); 
$dotenv = Dotenv::createImmutable(__DIR__ . '/..'); 
$dotenv->load();

// MySQL credentials
$host = $_ENV['MYSQL_HOST'];
$db = $_ENV['MYSQL_DB'];
$user = $_ENV['MYSQL_USER'];
$pass = $_ENV['MYSQL_PASSWORD'];
$charset = 'utf8mb4';
$port       = getenv('MYSQL_PORT');

// create pdo instance
$dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
$pdo = new PDO($dsn, $user, $pass, $options);

// RabbitMQ credentials
$connection = new AMQPStreamConnection(
        $_ENV['RABBITMQ_HOST'],
        $_ENV['RABBITMQ_PORT'],
        $_ENV['RABBITMQ_USER'],
    $_ENV['RABBITMQ_PASSWORD'],
       $_ENV['RABBITMQ_VHOST']
              
);
$channel = $connection->channel();


// close connection if shutdown command is given (CTRL+C)
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGINT, function() use ($channel, $connection) {
        shutdownHandler($channel, $connection);
    });
} else {
    register_shutdown_function(function() use ($channel, $connection) {
        shutdownHandler($channel, $connection);
    });
}
echo " [*] Polling the invoice table. Press CTRL+C to exit.\n";

while (true) {
    // fetch all unprocessed invoices (add a 'processed' column if you don't have one)
    $statement = $pdo->prepare("SELECT * FROM invoice WHERE processed = FALSE");

    try {
        $statement->execute();
        $count = $statement->rowCount();
        echo " [x] Found {$count} unprocessed invoice(s).\n";

        while ($row = $statement->fetch()) {
            echo " [*] Processing invoice #{$row['id']} for client #{$row['client_id']}.\n";
            sendPdfLink($row, $channel);
            markInvoiceAsProcessed($row['id'], $pdo);
        }
    } catch (PDOException $e) {
        echo " [!] Database error: " . $e->getMessage() . "\n";
    }
    sleep(INTERVAL);
}

// Send PDF download link through RabbitMQ
function sendPdfLink($invoiceData, $channel) {
    $pdfUrl = 'http://localhost:8081/invoice/pdf/' . $invoiceData['hash'];
    $message = [
        'info' => [
            'sender' => 'billing',
            'operation' => 'pdf_ready'
        ],
        'pdf' => [
            'invoice_id' => $invoiceData['id'],
            'client_id' => $invoiceData['client_id'],
            'url' => $pdfUrl
        ]
    ];

    $xml = new SimpleXMLElement("<attendify/>");
    arrayToXml($message, $xml);

    $dom = new DOMDocument("1.0");
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;
    $dom->loadXML($xml->asXML());

    $msg = new AMQPMessage($dom->saveXML(), ['content-type' => 'application/xml']);
    $channel->basic_publish($msg, 'invoice', 'pdf.ready');

    echo " [✔] PDF link sent for invoice #{$invoiceData['id']}\n";
}

// Mark invoice as processed
function markInvoiceAsProcessed($id, $pdo) {
    $stmt = $pdo->prepare("UPDATE invoice SET processed = 1 WHERE id = :id");
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
}

// Shutdown handler
function shutdownHandler($channel, $connection) {
    echo " [x] Closing RabbitMQ connection...\n";
    $channel->close();
    $connection->close();
    exit(0);
}

$channel->basic_consume('invoice_queue', '', false, true, false, false, $callback);

while ($channel->is_consuming()) {
    $channel->wait();
} 