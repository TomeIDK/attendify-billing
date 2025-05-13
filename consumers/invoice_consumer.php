<?php

require_once __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/../parser.php';

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

// Declare queues and exchange
$channel->exchange_declare('invoice', 'direct', false, true, false);
$channel->queue_declare('invoice_xml', false, true, false, false);
$channel->queue_bind('invoice_xml', 'invoice', 'invoice.created');

echo " [*] Waiting for purchase messages on 'invoice_xml'. Press CTRL+C to exit\n";

$callback = function (AMQPMessage $msg) use ($pdo, $channel) {
    echo " [x] Received purchase message\n";

    echo " [>] Raw message:\n" . $msg->body . "\n";

    $xml = simplexml_load_string($msg->body);
    if (!$xml) {
        echo " [!] Invalid XML\n";
        return;
    }

    $clientId = (int) $xml->invoice->client_id;
    $date = (string) $xml->invoice->date;
    $createdAt = date('Y-m-d H:i:s', strtotime($date));
    $dueAt = date('Y-m-d H:i:s', strtotime($date . ' +5 days'));
    $currency = (string) ($xml->invoice->currency ?? 'USD');

    // Check for existing unpaid invoice
    $stmt = $pdo->prepare("SELECT id, hash FROM invoice 
                       WHERE client_id = ? AND status = 'unpaid' 
                       ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$clientId]);
    $invoice = $stmt->fetch();

    if ($invoice) {
        $invoiceId = $invoice['id'];
        $hash = $invoice['hash'];
        echo " [ℹ] Using existing invoice #$invoiceId\n";
    } else {
        $hash = bin2hex(random_bytes(16));
        $stmt = $pdo->prepare("INSERT INTO invoice (client_id, currency, status, created_at, due_at, hash)
                               VALUES (?, ?, 'unpaid', ?, ?, ?)");
        $stmt->execute([$clientId, $currency, $createdAt, $dueAt, $hash]);
        $invoiceId = $pdo->lastInsertId();
        echo " [✔] Created new invoice #$invoiceId\n";
    }

    $items = $xml->invoice->item;
    if (!is_array($items) && !is_object($items)) {
        $items = [$items]; 
}

    foreach($items as $item){
        $title = (string) $item->title;
        $quantity = (int) $item->quantity;
        $price = (float) $item->price;
        $taxed = (int) $item->taxed;
    
        $stmt = $pdo->prepare("INSERT INTO invoice_item (invoice_id, title, quantity, price, taxed)
                               VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$invoiceId, $title, $quantity, $price, $taxed]);
        echo " [→] Added item '$title' to invoice #$invoiceId\n";
    }

    // Send PDF-ready message
    $pdfUrl = $_ENV['FOSSBILLING_URL'] . "/invoice/pdf/" . $hash;
    $message = [
        'info' => ['sender' => 'billing', 'operation' => 'pdf_ready'],
        'pdf' => ['invoice_id' => $invoiceId, 'client_id' => $clientId, 'url' => $pdfUrl]
    ];

    $xmlResponse = new SimpleXMLElement("<attendify/>");
    arrayToXml($message, $xmlResponse);

    $dom = new DOMDocument("1.0");
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;
    $dom->loadXML($xmlResponse->asXML());

    $msgOut = new AMQPMessage($dom->saveXML(), ['content-type' => 'application/xml']);
    $channel->basic_publish($msgOut, 'invoice', 'pdf.ready');

    echo " [✔] Published PDF URL to queue.\n";
};

// Start consuming
$channel->basic_consume('invoice_xml', '', false, true, false, false, $callback);

// Graceful shutdown
register_shutdown_function(function () use ($channel, $connection) {
    echo " [x] Closing RabbitMQ connection...\n";
    $channel->close();
    $connection->close();
    exit(0);
});

while ($channel->is_consuming()) {
    $channel->wait();
}