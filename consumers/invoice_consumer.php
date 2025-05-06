<?php

require_once __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/../parser.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeload();

// --- RABBITMQ CONNECTION ---
try {
    $connection = new AMQPStreamConnection(
        $_ENV['RABBITMQ_HOST'] ?? 'localhost',
        $_ENV['RABBITMQ_PORT'] ?? '5672',
        $_ENV['RABBITMQ_USER'] ?? 'attendify',
        $_ENV['RABBITMQ_PASSWORD'] ?? 'rabbitmq',
        $_ENV['RABBITMQ_VHOST'] ?? 'attendify'
    );
    $channel = $connection->channel();

    // Declare the exchange
    $channel->exchange_declare('invoice', 'direct', false, true, false);

    // Declare a queue
    list($queue_name, ,) = $channel->queue_declare("", false, false, true, false);

    // Bind the queue to the exchange with the routing key
    $channel->queue_bind($queue_name, 'invoice', 'pdf.ready');

    echo " [*] Waiting for invoice messages. To exit press CTRL+C\n";

    $callback = function ($msg) {
        echo " [x] Received message:\n";
        echo "     Content: " . $msg->body . "\n";
        echo "     Routing Key: " . $msg->getRoutingKey() . "\n";
        echo "     Exchange: " . $msg->getExchange() . "\n";
        echo "     Content Type: " . $msg->get('content_type') . "\n";
        echo " [x] Done\n";
    };

    $channel->basic_consume($queue_name, '', false, true, false, false, $callback);

    while ($channel->is_consuming()) {
        $channel->wait();
    }

} catch (Exception $e) {
    die(" [x] Failed to connect to RabbitMQ: " . $e->getMessage() . "\n");
}

// Shutdown handler
function shutdownHandler($channel, $connection) {
    echo " [x] Closing RabbitMQ connection...\n";
    $channel->close();
    $connection->close();
    exit(0);
}

// Register shutdown handler for both Windows and Unix-like systems
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGINT, function() use ($channel, $connection) {
        shutdownHandler($channel, $connection);
    });
} else {
    // For Windows, we'll use a simpler approach
    register_shutdown_function(function() use ($channel, $connection) {
        shutdownHandler($channel, $connection);
    });
} 