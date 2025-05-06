<?php

require_once __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/../parser.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeload();

// --- RABBITMQ CONNECTION ---
$connection = new AMQPStreamConnection($_ENV['RABBITMQ_HOST'], $_ENV['RABBITMQ_PORT'], $_ENV['RABBITMQ_USER'], $_ENV['RABBITMQ_PASSWORD'], $_ENV['RABBITMQ_VHOST']);
$channel = $connection->channel();

// Declare the exchange for invoice operations
$channel->exchange_declare('invoice', 'direct', false, true, false);

echo " [x] Connected to RabbitMQ.\n";

/**
 * Get the PDF download URL for an invoice
 */
function getInvoicePdfUrl($invoiceId, $clientId) {
    // Construct the URL based on your FOSSBilling installation
    $baseUrl = $_ENV['FOSSBILLING_URL'] ?? 'http://fossbilling:80';
    return $baseUrl . '/client/' . $clientId . '/invoice/' . $invoiceId . '/pdf';
}

/**
 * Send PDF download link through RabbitMQ
 */
function sendPdfLink($invoiceId, $clientId, $pdfUrl = null) {
    global $channel;
    
    // If no PDF URL is provided, generate one
    if ($pdfUrl === null) {
        $pdfUrl = getInvoicePdfUrl($invoiceId, $clientId);
    }
    
    $message = [
        'info' => [
            'sender' => 'billing',
            'operation' => 'pdf_ready'
        ],
        'pdf' => [
            'invoice_id' => $invoiceId,
            'client_id' => $clientId,
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
    
    echo " [✔] PDF link sent for invoice #{$invoiceId}\n";
}

// Example usage:
// sendPdfLink(123, 456); // Will use default FOSSBilling URL


// Shutdown handler
function shutdownHandler($channel, $connection) {
    echo " [x] Closing RabbitMQ connection...\n";
    $channel->close();
    $connection->close();
    exit(0);
}

// Register shutdown handler
pcntl_signal(SIGINT, function() use ($channel, $connection) {
    shutdownHandler($channel, $connection);
}); 