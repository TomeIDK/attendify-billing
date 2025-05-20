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

    public function testUserCrud(): void
    {
        // --- CREATE USER ---
        $uid = 'TST' . random_int(10000, 99999);
        $email = "test{$uid}@example.com";
        echo "[TEST] Creating user $email with UID $uid\n";

        $createXml = <<<XML
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
    <is_admin>0</is_admin>
  </user>
</attendify>
XML;

        $this->sendMessageAndWait(self::USER_QUEUE, $createXml, "User create");

        // Check user was created
        echo "[CHECK] Verifying user exists with UID $uid\n";
        $user = $this->findUserByUid($uid);
        echo "[RESULT] User DB entry: " . json_encode($user) . "\n";
        $this->assertNotEmpty($user, 'User should exist after create operation');
        $this->assertEquals($email, $user['email'], 'User email should match');
        $this->assertEquals('Mr', $user['custom_1'], 'User title should match');

        // --- UPDATE USER ---
        $newEmail = "updated{$uid}@example.com";
        echo "[TEST] Updating user UID $uid, changing email to $newEmail and title to Dr\n";
        $updateXml = <<<XML
<?xml version="1.0"?>
<attendify>
  <info><sender>test</sender><operation>update</operation></info>
  <user>
    <first_name>Jane</first_name>
    <last_name>Doe</last_name>
    <email>{$newEmail}</email>
    <title>Dr</title>
    <uid>{$uid}</uid>
    <password>updated123</password>
    <is_admin>1</is_admin>
  </user>
</attendify>
XML;

        $this->sendMessageAndWait(self::USER_QUEUE, $updateXml, "User update");

        // Check user was updated
        echo "[CHECK] Verifying user update with UID $uid\n";
        $user = $this->findUserByUid($uid);
        echo "[RESULT] User DB entry after update: " . json_encode($user) . "\n";
        $this->assertEquals($newEmail, $user['email'], 'User email should be updated');
        $this->assertEquals('Dr', $user['custom_1'], 'User title should be updated');

        // --- DELETE USER ---
        echo "[TEST] Deleting user UID $uid\n";
        $deleteXml = <<<XML
<?xml version="1.0"?>
<attendify>
  <info><sender>test</sender><operation>delete</operation></info>
  <user>
    <uid>{$uid}</uid>
  </user>
</attendify>
XML;

        $this->sendMessageAndWait(self::USER_QUEUE, $deleteXml, "User delete");

        // Check user was deleted
        echo "[CHECK] Verifying user deleted UID $uid\n";
        $stmt = self::$pdo->prepare('SELECT COUNT(*) FROM client WHERE custom_2 = :uid');
        $stmt->execute([':uid' => $uid]);
        $count = (int)$stmt->fetchColumn();
        echo "[RESULT] Remaining user count for UID $uid: $count\n";
        $this->assertEquals(0, $count, 'User should be deleted');
    }

    public function testEventCrud(): void
    {
        // --- CREATE EVENT ---
        $uid = 'EVT' . random_int(10000, 99999);
        echo "[TEST] Creating event with UID $uid\n";
        $createXml = <<<XML
<?xml version="1.0"?>
<attendify>
  <info><sender>test</sender><operation>create</operation></info>
  <event>
    <uid>{$uid}</uid>
    <title>Test Event</title>
    <start_date>2025-06-01T10:00:00Z</start_date>
    <end_date>2025-06-01T18:00:00Z</end_date>
    <location>123 Test St, Test City</location>
    <description>Event description</description>
    <max_attendees>50</max_attendees>
  </event>
</attendify>
XML;

        $this->sendMessageAndWait(self::EVENT_QUEUE, $createXml, "Event create");

        // Check event was created
        echo "[CHECK] Verifying event exists with UID $uid\n";
        $event = $this->findEventByUid($uid);
        echo "[RESULT] Event DB entry: " . json_encode($event) . "\n";
        $this->assertNotEmpty($event, 'Event should exist after create operation');
        $this->assertEquals('Test Event', $event['name'], 'Event name should match');
        $this->assertEquals('123 Test St, Test City', $event['address'], 'Event address should match');

        // --- UPDATE EVENT ---
        echo "[TEST] Updating event UID $uid, changing name/address\n";
        $updateXml = <<<XML
<?xml version="1.0"?>
<attendify>
  <info><sender>test</sender><operation>update</operation></info>
  <event>
    <uid>{$uid}</uid>
    <title>Updated Event</title>
    <start_date>2025-07-02T10:00:00Z</start_date>
    <end_date>2025-07-02T18:00:00Z</end_date>
    <location>Updated Address</location>
    <description>Updated description</description>
    <max_attendees>200</max_attendees>
  </event>
</attendify>
XML;

        $this->sendMessageAndWait(self::EVENT_QUEUE, $updateXml, "Event update");

        // Check event was updated
        echo "[CHECK] Verifying event update with UID $uid\n";
        $event = $this->findEventByUid($uid);
        echo "[RESULT] Event DB entry after update: " . json_encode($event) . "\n";
        $this->assertEquals('Updated Event', $event['name'], 'Event name should be updated');
        $this->assertEquals('Updated Address', $event['address'], 'Event address should be updated');

        // --- DELETE EVENT ---
        echo "[TEST] Deleting event UID $uid\n";
        $deleteXml = <<<XML
<?xml version="1.0"?>
<attendify>
  <info><sender>test</sender><operation>delete</operation></info>
  <event>
    <uid>{$uid}</uid>
  </event>
</attendify>
XML;

        $this->sendMessageAndWait(self::EVENT_QUEUE, $deleteXml, "Event delete");

        // Check event was deleted
        echo "[CHECK] Verifying event deleted UID $uid\n";
        $stmt = self::$pdo->prepare('SELECT COUNT(*) FROM events WHERE uid_event = :uid');
        $stmt->execute([':uid' => $uid]);
        $count = (int)$stmt->fetchColumn();
        echo "[RESULT] Remaining event count for UID $uid: $count\n";
        $this->assertEquals(0, $count, 'Event should be deleted');
    }

    // --- Utility functions ---

    /** Sends a message and waits a few seconds for async processing */
    private function sendMessageAndWait($queue, $xml, $desc = ''): void
    {
        echo "[SEND] Publishing $desc XML to queue $queue\n";
        self::$channel->basic_publish(
            new AMQPMessage($xml, ['content_type' => 'application/xml']),
            '', $queue
        );
        sleep(2); // Give consumer time to process
    }

    /** Find user in DB by UID */
    private function findUserByUid($uid)
    {
        $stmt = self::$pdo->prepare('SELECT email, custom_1 FROM client WHERE custom_2 = :uid');
        $stmt->execute([':uid' => $uid]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /** Find event in DB by UID */
    private function findEventByUid($uid)
    {
        $stmt = self::$pdo->prepare('SELECT name, address FROM events WHERE uid_event = :uid');
        $stmt->execute([':uid' => $uid]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
