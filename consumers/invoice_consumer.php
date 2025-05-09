<?php

require_once __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/../parser.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Connect to RabbitMQ
$connection = new AMQPStreamConnection(
    $_ENV['RABBITMQ_HOST'],
    $_ENV['RABBITMQ_PORT'],
    $_ENV['RABBITMQ_USER'],
    $_ENV['RABBITMQ_PASSWORD'],
    $_ENV['RABBITMQ_VHOST']
);
$channel = $connection->channel();

// Declare durable named queue and bind to exchange
$queueName = 'pdf_notifications';
$channel->exchange_declare('invoice', 'direct', false, true, false);
$channel->queue_declare($queueName, false, true, false, false);
$channel->queue_bind($queueName, 'invoice', 'pdf.ready');

echo " [*] Waiting for pdf.ready messages on '$queueName'. Press CTRL+C to exit\n";

// Handle incoming messages
$callback = function (AMQPMessage $msg) {
    echo " [x] Received message on routing key '{$msg->getRoutingKey()}'.\n";

    $xmlContent = $msg->body;
    $xml = simplexml_load_string($xmlContent);
    if (!$xml) {
        echo " [!] Failed to parse XML\n";
        return;
    }

    $data = json_decode(json_encode($xml), true);

    $invoiceId = $data['pdf']['invoice_id'] ?? 'N/A';
    $clientId = $data['pdf']['client_id'] ?? 'N/A';
    $url = $data['pdf']['url'] ?? 'N/A';

    echo " [→] Invoice #$invoiceId for client #$clientId is ready at:\n      $url\n";
};

// Start consuming
$channel->basic_consume($queueName, '', false, true, false, false, $callback);

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
