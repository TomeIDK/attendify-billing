<?php
/*******************************************************************************
 *  invoice_producer.php
 *  — stuurt ELKE factuur (PDF‑link) naar RabbitMQ  ➜  exchange 'invoice',
 *    routing‑key 'invoice.payed', queue 'billing.invoice'.
 *  ➜ gebruikt exact dezelfde connectie‑ & helper‑code als user_producer.php
 ******************************************************************************/

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../parser.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

/* ---------- unchanged connection / signal handling ---------- */

define("INTERVAL", 5);               // poll‑interval
declare(ticks = 1);

// MySQL credentials via env
$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    getenv('MYSQL_HOST'),
    getenv('MYSQL_PORT'),
    getenv('MYSQL_DB')
);
$pdo = new PDO(
    $dsn,
    getenv('MYSQL_USER'),
    getenv('MYSQL_PASSWORD'),
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

// RabbitMQ credentials via env, vhost volgt uit var
$connection = new AMQPStreamConnection(
    getenv('RABBITMQ_HOST'),
    getenv('RABBITMQ_PORT'),
    getenv('RABBITMQ_USER'),
    getenv('RABBITMQ_PASSWORD'),
    getenv('RABBITMQ_VHOST')
);
$channel = $connection->channel();

// declare exchange 'invoice' (direct, durable) – idempotent
$channel->exchange_declare('invoice', 'direct', false, true, false);

echo " [x] Connected to MySQL & RabbitMQ – polling invoices every "
   . INTERVAL . " s\n";

pcntl_signal(SIGINT, function() use ($channel, $connection) {
    $channel->close();
    $connection->close();
    exit(0);
});

/* ---------------------- main loop ---------------------- */

while (true) {
    // 1. pak alle facturen die nog niet verstuurd zijn
    $stmt = $pdo->query("
        SELECT id, nr, hash
        FROM   invoice
        WHERE  sent_to_queue = 0
    ");
    $cnt = $stmt->rowCount();
    if ($cnt) echo " [x] Found {$cnt} unsent invoice(s)\n";

    // 2. verwerk per factuur
    foreach ($stmt as $inv) {
        $xmlString = formatInvoice($inv);
        publishInvoice($xmlString, $channel);
        markAsSent($inv['id'], $pdo);
        echo " [✔] Invoice {$inv['nr']} pushed to invoice.payed\n";
    }
    sleep(INTERVAL);
}

/* ---------------------- helpers ---------------------- */

function formatInvoice(array $inv): string
{
    $base = rtrim(getenv('BILLING_URL') ?: 'https://billing.example.com', '/');

    $payload = [
        'invoice' => [
            'id'      => $inv['id'],
            'number'  => $inv['nr'],
            'pdf_url' => "{$base}/invoice/pdf/{$inv['hash']}",
        ],
    ];

    $xml = new SimpleXMLElement('<attendify/>');
    arrayToXml($payload, $xml);

    $dom = new DOMDocument('1.0', 'utf-8');
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput       = true;
    $dom->loadXML($xml->asXML());

    return $dom->saveXML();
}

function publishInvoice(string $xml, $channel): void
{
    $msg = new AMQPMessage($xml, ['content-type' => 'application/xml']);
    // publish naar exchange 'invoice' + routing‑key 'invoice.payed'
    $channel->basic_publish($msg, 'invoice', 'invoice.payed');
}

function markAsSent(int $id, PDO $pdo): void
{
    $pdo->prepare("UPDATE invoice SET sent_to_queue = 1 WHERE id = :id")
        ->execute([':id' => $id]);
}
