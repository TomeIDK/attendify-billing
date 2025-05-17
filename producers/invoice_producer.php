<?php

require_once __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/../parser.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// RabbitMQ setup
$connection = new AMQPStreamConnection(
    $_ENV['RABBITMQ_HOST'],
    $_ENV['RABBITMQ_PORT'],
    $_ENV['RABBITMQ_USER'],
    $_ENV['RABBITMQ_PASSWORD'],
    $_ENV['RABBITMQ_VHOST']
);
$channel = $connection->channel();

// Declare exchange and queue for outgoing purchase messages
$channel->exchange_declare('invoice', 'direct', false, true, false);
$channel->queue_declare('invoice_xml', false, true, false, false);
$channel->queue_bind('invoice_xml', 'invoice', 'invoice.created');

// Load or generate XML (for example purposes only)
$xmlFile = __DIR__ . '/../Test/IntegrationTest/invoice-producer-test.php'; 
$xmlContent = file_get_contents($xmlFile);

// Send XML to consumer
$msgOut = new AMQPMessage($xmlContent, ['content-type' => 'application/xml']);
$channel->basic_publish($msgOut, 'invoice', 'invoice.created');

echo " Sent purchase message to queue.\n";

$channel->close();
$connection->close();