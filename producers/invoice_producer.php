<?php

require_once __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/../parser.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

define("INTERVAL", 5); // interval between db polling
declare(ticks = 1); // signal handling for pcntl_signal

// MySQL credentials
$host       = getenv('MYSQL_HOST');
$db         = getenv('MYSQL_DB');
$user       = getenv('MYSQL_USER');
$pass       = getenv('MYSQL_PASSWORD');
$charset    = 'utf8mb4';
$port       = getenv('MYSQL_PORT');

// create pdo instance
$dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
$pdo = new PDO($dsn, $user, $pass, $options);

// RabbitMQ credentials
$connection = new AMQPStreamConnection(getenv('RABBITMQ_HOST'), getenv('RABBITMQ_PORT'), getenv('RABBITMQ_USER'), getenv('RABBITMQ_PASSWORD'), getenv('RABBITMQ_VHOST'));
$channel = $connection->channel();
echo " [x] Connected to RabbitMQ.\n";

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
echo " [*] Polling the invoices table. Press CTRL+C to exit.\n";

while (true) {
    // fetch all unprocessed invoices (adjust table/column names as needed)
    $statement = $pdo->prepare("SELECT * FROM invoices WHERE processed = FALSE");

    try {
        $statement->execute();
        $count = $statement->rowCount();
        echo " [x] Found {$count} unprocessed invoice(s).\n";

        while ($row = $statement->fetch()) {
            echo " [*] Processing invoice #{$row['id']} for client #{$row['client_id']}.\n";
            processInvoice($row, $channel);
            markInvoiceAsProcessed($row['id'], $pdo);
        }
    } catch (PDOException $e) {
        echo " [!] Database error: " . $e->getMessage() . "\n";
    }
    sleep(INTERVAL);
}

// Format invoice data to XML
function formatInvoice($invoiceData) {
    $formatted = [
        "info" => [
            "sender" => 'billing',
            "operation" => 'invoice_created',
        ],
        "invoice" => [
            "invoice_id" => $invoiceData['id'],
            "client_id" => $invoiceData['client_id'],
            "total" => $invoiceData['total'],
            "date" => $invoiceData['date'],
            // Add more fields as needed
        ]
    ];

    $xml = new SimpleXMLElement("<attendify/>");
    arrayToXml($formatted, $xml);

    $dom = new DOMDocument("1.0");
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;
    $dom->loadXML($xml->asXML());

    return $dom->saveXML();
}

// Send invoice message to RabbitMQ
function processInvoice($invoiceData, $channel) {
    $xmlString = formatInvoice($invoiceData);
    $msg = new AMQPMessage($xmlString, ['content-type' => 'application/xml']);
    $channel->basic_publish($msg, 'invoice', 'invoice.created');
    echo " [✔] Invoice message sent for invoice #{$invoiceData['id']} (XML format)\n";
}

// Mark invoice as processed
function markInvoiceAsProcessed($id, $pdo) {
    $stmt = $pdo->prepare("UPDATE invoices SET processed = 1 WHERE id = :id");
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

// FOSSBilling API config
$apiKey = 'Ec3vPtpUsgy3cEAHX9NVkr9VxB1FbtcF';
$fossbillingUrl = 'http://localhost:8081';

// Declare and bind the queue
$channel->queue_declare('invoice_queue', false, true, false, false);
$channel->queue_bind('invoice_queue', 'invoice', 'invoice.created');

// Declare the exchange for sending PDF links
$channel->exchange_declare('invoice', 'direct', false, true, false);

echo " [*] Waiting for invoice creation messages...\n";

$callback = function($msg) use ($apiKey, $fossbillingUrl, $channel) {
    echo " [x] Received invoice XML\n";
    $xml = simplexml_load_string($msg->body);

    // Parse XML (adjust these fields to match your XML structure)
    $clientId = (string)$xml->invoice->client_id;
    $total = (string)$xml->invoice->total;
    $date = (string)$xml->invoice->date;

    // Prepare data for FOSSBilling API
    $data = [
        'client_id' => $clientId,
        'status' => 'unpaid',
        'currency' => 'USD',
        'items' => [
            [
                'title' => 'Service/Product',
                'quantity' => 1,
                'price' => $total,
                'taxed' => 0
            ]
        ],
        'created_at' => $date,
        'due_at' => date('Y-m-d', strtotime($date . ' +5 days')),
    ];

    // Create invoice via FOSSBilling API
    $ch = curl_init($fossbillingUrl . '/api/admin/invoice/create');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $apiKey",
        "Content-Type: application/json"
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        echo " [!] Failed to create invoice. Response: $response\n";
        return;
    }

    $result = json_decode($response, true);
    if (!isset($result['hash'])) {
        echo " [!] Invoice creation failed or hash not returned. Response: $response\n";
        return;
    }

    $invoiceHash = $result['hash'];
    $pdfUrl = $fossbillingUrl . '/invoice/pdf/' . $invoiceHash;

    // Prepare PDF link message
    $pdfMessage = [
        'info' => [
            'sender' => 'billing',
            'operation' => 'pdf_ready'
        ],
        'pdf' => [
            'client_id' => $clientId,
            'url' => $pdfUrl
        ]
    ];

    // Convert to XML
    $xmlOut = new SimpleXMLElement("<attendify/>");
    arrayToXml($pdfMessage, $xmlOut);

    $dom = new DOMDocument("1.0");
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;
    $dom->loadXML($xmlOut->asXML());

    $msgOut = new AMQPMessage($dom->saveXML(), ['content-type' => 'application/xml']);
    $channel->basic_publish($msgOut, 'invoice', 'pdf.ready');

    echo " [✔] Invoice created and PDF link sent: $pdfUrl\n";
};

// Helper function to convert array to XML
function arrayToXml($data, &$xmlData) {
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            if (is_numeric($key)) {
                $key = 'item';
            }
            $subnode = $xmlData->addChild($key);
            arrayToXml($value, $subnode);
        } else {
            $xmlData->addChild("$key", htmlspecialchars("$value"));
        }
    }
}

$channel->basic_consume('invoice_queue', '', false, true, false, false, $callback);

while ($channel->is_consuming()) {
    $channel->wait();
} 