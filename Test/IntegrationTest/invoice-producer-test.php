<?php
require_once __DIR__ . '/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$connection = new AMQPStreamConnection('localhost', 5672, 'attendify', 'rabbitmq', 'attendify');
$channel = $connection->channel();
$channel->queue_declare('invoice_xml', false, true, false, false);

$xml = <<<XML
<attendify>
  <invoice>
    <client_id>1</client_id>
    <date>2024-01-01</date>
    <currency>USD</currency>
    <item>
      <title>Test Item</title>
      <quantity>2</quantity>
      <price>15.5</price>
      <taxed>1</taxed>
    </item>
  </invoice>
</attendify>
XML;

$msg = new AMQPMessage($xml, ['content-type' => 'application/xml']);
$channel->basic_publish($msg, '', 'invoice_xml');

echo "Test XML message sent.\n";

$channel->close();
$connection->close();