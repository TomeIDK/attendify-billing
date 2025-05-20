<?php
namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use PDO;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class ConsumerCrudTest extends TestCase
{
    private static PDO $pdo;
    private static $channel;

    private const USER_QUEUE = 'billing.user';
    private const EVENT_QUEUE = 'billing.invoice';

    public static function setUpBeforeClass(): void
    {
        echo "[INFO] Connecting to MySQL at 127.0.0.1:3306 (DB=fossbilling)\n";
        $dbHost = '127.0.0.1';
        $dbPort = 3306;
        $dbName = 'fossbilling';
        $dbUser = 'fossbilling';
        $dbPass = 'fossbilling';

        self::$pdo = new PDO(
            "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
            $dbUser,
            $dbPass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        echo "[INFO] Connected to MySQL.\n";

        echo "[INFO] Connecting to RabbitMQ at 127.0.0.1:5672 (vhost=attendify)\n";
        $rmqHost = '127.0.0.1';
        $rmqPort = 5672;
        $rmqUser = 'attendify';
        $rmqPass = 'uXe5u1oWkh32JyLA';
        $rmqVhost = 'attendify';

        $conn = new AMQPStreamConnection(
            $rmqHost, $rmqPort,
            $rmqUser, $rmqPass,
            $rmqVhost
        );
        self::$channel = $conn->channel();
        echo "[INFO] Connected to RabbitMQ.\n";
    }

    // --- USER CRUD TESTS ---

    public function testUserCreate(): void
    {
        $uid = 'TST' . random_int(10000, 99999);
        $email = "test{$uid}@example.com";

        echo "[TEST] testUserCreate: Creating user $email with UID $uid\n";

        $xml = <<<XML
<?xml version="1.0"?>
<attendify>
  <info><sender>test</sender><operation>create</operation></info>
  <user>
    <first_name>John</first_name>
    <last_name>Doe</last_name>
    <email>{$email}</email>
    <title>Mr</title>
    <uid>{$uid}</uid>
    <password>secure123</password>
  </user>
</attendify>
XML;

        echo "[SEND] Publishing user create XML to queue " . self::USER_QUEUE . "\n";
        self::$channel->basic_publish(new AMQPMessage($xml, ['content_type' => 'application/xml']), '', self::USER_QUEUE);
        sleep(2); // Wait for message processing

        echo "[CHECK] Querying for created user with UID $uid\n";
        $stmt = self::$pdo->prepare('SELECT email, custom_1 FROM client WHERE custom_2 = :uid');
        $stmt->execute([':uid' => $uid]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        echo "[RESULT] User DB entry: " . json_encode($user) . "\n";

        $this->assertNotEmpty($user, 'User should exist after create operation');
        $this->assertEquals($email, $user['email'], 'User email should match');
        $this->assertEquals('Mr', $user['custom_1'], 'User title should match');

        // Cleanup
        echo "[CLEANUP] Deleting test user with UID $uid\n";
        self::$pdo->prepare('DELETE FROM client WHERE custom_2 = :uid')->execute([':uid' => $uid]);
    }

    public function testUserUpdate(): void
    {
        $uid = 'TST' . random_int(10000, 99999);
        echo "[TEST] testUserUpdate: Creating initial user with UID $uid\n";
        self::$pdo->prepare(
            'INSERT INTO client (email, pass, first_name, last_name, custom_1, custom_2, created_at, updated_at) 
             VALUES (:email, :pass, :fn, :ln, :title, :uid, NOW(), NOW())'
        )->execute([
            ':email' => "original{$uid}@example.com",
            ':pass'  => 'original',
            ':fn'    => 'Original',
            ':ln'    => 'User',
            ':title' => 'Ms',
            ':uid'   => $uid
        ]);

        $newEmail = "updated{$uid}@example.com";
        echo "[UPDATE] Sending update XML for user UID $uid to change email to $newEmail\n";
        $xml = <<<XML
<?xml version="1.0"?>
<attendify>
  <info><sender>test</sender><operation>update</operation></info>
  <user>
    <first_name>Updated</first_name>
    <last_name>User</last_name>
    <email>{$newEmail}</email>
    <title>Dr</title>
    <uid>{$uid}</uid>
    <password>updated123</password>
  </user>
</attendify>
XML;

        self::$channel->basic_publish(new AMQPMessage($xml, ['content_type' => 'application/xml']), '', self::USER_QUEUE);
        sleep(2); // Wait for message processing

        echo "[CHECK] Querying for updated user with UID $uid\n";
        $stmt = self::$pdo->prepare('SELECT email, custom_1 FROM client WHERE custom_2 = :uid');
        $stmt->execute([':uid' => $uid]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        echo "[RESULT] User DB entry after update: " . json_encode($user) . "\n";

        $this->assertEquals($newEmail, $user['email'], 'User email should be updated');
        $this->assertEquals('Dr', $user['custom_1'], 'User title should be updated');

        // Cleanup
        echo "[CLEANUP] Deleting test user with UID $uid\n";
        self::$pdo->prepare('DELETE FROM client WHERE custom_2 = :uid')->execute([':uid' => $uid]);
    }

    public function testUserDelete(): void
    {
        $uid = 'TST' . random_int(10000, 99999);
        echo "[TEST] testUserDelete: Creating user to delete, UID $uid\n";
        self::$pdo->prepare(
            'INSERT INTO client (email, pass, first_name, last_name, custom_1, custom_2, created_at, updated_at) 
             VALUES (:email, :pass, :fn, :ln, :title, :uid, NOW(), NOW())'
        )->execute([
            ':email' => "delete{$uid}@example.com",
            ':pass'  => 'delete',
            ':fn'    => 'Delete',
            ':ln'    => 'User',
            ':title' => 'Sir',
            ':uid'   => $uid
        ]);

        echo "[DELETE] Sending delete XML for user UID $uid\n";
        $xml = <<<XML
<?xml version="1.0"?>
<attendify>
  <info><sender>test</sender><operation>delete</operation></info>
  <user>
    <uid>{$uid}</uid>
  </user>
</attendify>
XML;

        self::$channel->basic_publish(new AMQPMessage($xml, ['content_type' => 'application/xml']), '', self::USER_QUEUE);
        sleep(2); // Wait for message processing

        echo "[CHECK] Querying for deleted user with UID $uid\n";
        $stmt = self::$pdo->prepare('SELECT COUNT(*) FROM client WHERE custom_2 = :uid');
        $stmt->execute([':uid' => $uid]);
        $count = (int)$stmt->fetchColumn();

        echo "[RESULT] Remaining user count for UID $uid: $count\n";

        $this->assertEquals(0, $count, 'User should be deleted');
    }

    // --- EVENT CRUD TESTS ---

    public function testEventCreate(): void
    {
        $uid = 'EVT' . random_int(10000, 99999);
        echo "[TEST] testEventCreate: Creating event UID $uid\n";
        $xml = <<<XML
<?xml version="1.0"?>
<attendify>
  <info><sender>test</sender><operation>create</operation></info>
  <event>
    <uid_event>{$uid}</uid_event>
    <name>Test Event</name>
    <start_date>2025-06-01T10:00:00Z</start_date>
    <end_date>2025-06-01T18:00:00Z</end_date>
    <address>123 Test St, Test City</address>
    <description>Event description</description>
    <max_attendees>50</max_attendees>
  </event>
</attendify>
XML;

        echo "[SEND] Publishing event create XML to queue " . self::EVENT_QUEUE . "\n";
        self::$channel->basic_publish(new AMQPMessage($xml, ['content_type' => 'application/xml']), '', self::EVENT_QUEUE);
        sleep(2); // Wait for message processing

        echo "[CHECK] Querying for created event UID $uid\n";
        $stmt = self::$pdo->prepare('SELECT name, address FROM events WHERE uid_event = :uid');
        $stmt->execute([':uid' => $uid]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);

        echo "[RESULT] Event DB entry: " . json_encode($event) . "\n";

        $this->assertNotEmpty($event, 'Event should exist after create operation');
        $this->assertEquals('Test Event', $event['name'], 'Event name should match');
        $this->assertEquals('123 Test St, Test City', $event['address'], 'Event address should match');
    }

    public function testEventUpdate(): void
    {
        $uid = 'EVT' . random_int(10000, 99999);
        echo "[TEST] testEventUpdate: Creating initial event UID $uid\n";
        // Create event first
        $createXml = <<<XML
<?xml version="1.0"?>
<attendify>
  <info><sender>test</sender><operation>create</operation></info>
  <event>
    <uid_event>{$uid}</uid_event>
    <name>Original Event</name>
    <start_date>2025-07-01T10:00:00Z</start_date>
    <end_date>2025-07-01T18:00:00Z</end_date>
    <address>Original Address</address>
    <description>Original description</description>
    <max_attendees>100</max_attendees>
  </event>
</attendify>
XML;

        echo "[SEND] Publishing event create XML for update test.\n";
        self::$channel->basic_publish(new AMQPMessage($createXml, ['content_type' => 'application/xml']), '', self::EVENT_QUEUE);
        sleep(2); // Wait for message processing

        // Update event
        $updateXml = <<<XML
<?xml version="1.0"?>
<attendify>
  <info><sender>test</sender><operation>update</operation></info>
  <event>
    <uid_event>{$uid}</uid_event>
    <name>Updated Event</name>
    <start_date>2025-07-02T10:00:00Z</start_date>
    <end_date>2025-07-02T18:00:00Z</end_date>
    <address>Updated Address</address>
    <description>Updated description</description>
    <max_attendees>200</max_attendees>
  </event>
</attendify>
XML;

        echo "[UPDATE] Publishing event update XML for event UID $uid\n";
        self::$channel->basic_publish(new AMQPMessage($updateXml, ['content_type' => 'application/xml']), '', self::EVENT_QUEUE);
        sleep(2); // Wait for message processing

        echo "[CHECK] Querying for updated event UID $uid\n";
        $stmt = self::$pdo->prepare('SELECT name, address FROM events WHERE uid_event = :uid');
        $stmt->execute([':uid' => $uid]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);

        echo "[RESULT] Event DB entry after update: " . json_encode($event) . "\n";

        $this->assertEquals('Updated Event', $event['name'], 'Event name should be updated');
        $this->assertEquals('Updated Address', $event['address'], 'Event address should be updated');
    }

    public function testEventDelete(): void
    {
        $uid = 'EVT' . random_int(10000, 99999);
        echo "[TEST] testEventDelete: Creating event UID $uid to delete\n";
        // Create event first
        $createXml = <<<XML
<?xml version="1.0"?>
<attendify>
  <info><sender>test</sender><operation>create</operation></info>
  <event>
    <uid_event>{$uid}</uid_event>
    <name>Temporary Event</name>
    <start_date>2025-08-01T10:00:00Z</start_date>
    <end_date>2025-08-01T18:00:00Z</end_date>
    <address>Temporary Address</address>
  </event>
</attendify>
XML;

        echo "[SEND] Publishing event create XML for delete test.\n";
        self::$channel->basic_publish(new AMQPMessage($createXml, ['content_type' => 'application/xml']), '', self::EVENT_QUEUE);
        sleep(2); // Wait for message processing

        // Delete event
        $deleteXml = <<<XML
<?xml version="1.0"?>
<attendify>
  <info><sender>test</sender><operation>delete</operation></info>
  <event>
    <uid_event>{$uid}</uid_event>
  </event>
</attendify>
XML;

        echo "[DELETE] Publishing event delete XML for event UID $uid\n";
        self::$channel->basic_publish(new AMQPMessage($deleteXml, ['content_type' => 'application/xml']), '', self::EVENT_QUEUE);
        sleep(2); // Wait for message processing

        echo "[CHECK] Querying for deleted event UID $uid\n";
        $stmt = self::$pdo->prepare('SELECT COUNT(*) FROM events WHERE uid_event = :uid');
        $stmt->execute([':uid' => $uid]);
        $count = (int)$stmt->fetchColumn();

        echo "[RESULT] Remaining event count for UID $uid: $count\n";

        $this->assertEquals(0, $count, 'Event should be deleted');
    }
}
