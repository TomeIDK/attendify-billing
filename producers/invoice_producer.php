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
$channel->queue_declare('invoice_xml', false, true, false, false);
$channel->exchange_declare('invoice', 'direct', false, true, false);

register_shutdown_function(fn() => shutdownHandler($channel, $connection));

$callback = function (AMQPMessage $msg) use ($pdo, $channel) {
    echo " [>] Received XML, creating invoice...\n";

    $xml = simplexml_load_string($msg->body);

    $clientId = (int) $xml->invoice->client_id;
    $date = (string) $xml->invoice->date;
    $createdAt = date('Y-m-d H:i:s', strtotime($date));
    $dueAt = date('Y-m-d H:i:s', strtotime($date . ' +5 days'));
    $currency = (string) ($xml->invoice->currency ?? 'USD');
    $hash = bin2hex(random_bytes(16));

    $stmt = $pdo->prepare("INSERT INTO invoice (client_id, currency, status, created_at, due_at, hash, processed)
                           VALUES (?, ?, 'unpaid', ?, ?, ?, 0)");
    $stmt->execute([$clientId, $currency, $createdAt, $dueAt, $hash]);
    $invoiceId = $pdo->lastInsertId();

    foreach ($xml->invoice->item as $item) {
        $title = (string) $item->title;
        $quantity = (int) $item->quantity;
        $price = (float) $item->price;
        $taxed = (int) $item->taxed;

        $stmt = $pdo->prepare("INSERT INTO invoice_item (invoice_id, title, quantity, price, taxed)
                               VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$invoiceId, $title, $quantity, $price, $taxed]);
    }

    $pdfUrl = $_ENV['FOSSBILLING_URL'] . "/invoice/pdf/" . $hash;
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

    $xmlResponse = new SimpleXMLElement("<attendify/>");
    arrayToXml($message, $xmlResponse);

    $dom = new DOMDocument("1.0");
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;
    $dom->loadXML($xmlResponse->asXML());

    $msgOut = new AMQPMessage($dom->saveXML(), ['content-type' => 'application/xml']);
    $channel->basic_publish($msgOut, 'invoice', 'pdf.ready');

    echo " [✔] Invoice #$invoiceId created and PDF link sent.\n";
};

$channel->basic_consume('invoice_xml', '', false, true, false, false, $callback);

while ($channel->is_consuming()) {
    $channel->wait();
}

function shutdownHandler($channel, $connection)
{
    echo " [x] Shutting down.\n";
    $channel->close();
    $connection->close();
    exit(0);
}
