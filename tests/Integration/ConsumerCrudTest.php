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
    private const COMPANY_QUEUE = 'billing.company';

    // --- Setup ---
    public static function setUpBeforeClass(): void
    {
        $dotenv = \Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2));
        $dotenv->safeload();

        self::$pdo = new PDO(
            sprintf(
                "mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4",
                '127.0.0.1',
                $_ENV['MYSQL_PORT'],
                $_ENV['MYSQL_DB']
            ),
            $_ENV['MYSQL_USER'],
            $_ENV['MYSQL_PASSWORD'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $conn = new AMQPStreamConnection(
            '127.0.0.1',
            $_ENV['RABBITMQ_PORT'],
            $_ENV['RABBITMQ_USER'],
            $_ENV['RABBITMQ_PASSWORD'],
            $_ENV['RABBITMQ_VHOST']
        );
        self::$channel = $conn->channel();
    }

    // --- TESTS: USER CRUD ---

    public function testUserCrud(): void
    {
        $uid = $this->randomUid('TST');
        $email = "test{$uid}@example.com";

        // Create
        $this->sendUserMsg('create', [
            'uid' => $uid, 'email' => $email, 'first_name' => 'John', 'last_name' => 'Doe',
            'title' => 'Mr', 'password' => 'secure123', 'is_admin' => 0
        ]);
        $user = $this->findUserByUid($uid);
        $this->assertNotEmpty($user, 'User should exist after create');
        $this->assertEquals($email, $user['email']);

        // Update
        $newEmail = "updated{$uid}@example.com";
        $this->sendUserMsg('update', [
            'uid' => $uid, 'email' => $newEmail, 'first_name' => 'Jane', 'title' => 'Dr', 'is_admin' => 1
        ]);
        $user = $this->findUserByUid($uid);
        $this->assertEquals($newEmail, $user['email']);
        $this->assertEquals('Dr', $user['custom_1']);

        // Delete
        $this->sendUserMsg('delete', ['uid' => $uid]);
        $stmt = self::$pdo->prepare('SELECT COUNT(*) FROM client WHERE custom_2 = :uid');
        $stmt->execute([':uid' => $uid]);
        $this->assertEquals(0, (int)$stmt->fetchColumn(), 'User should be deleted');
    }

    public function testUserCrudUnsuccessful(): void
    {
        $uid = $this->randomUid('DUP');
        $email = "dup{$uid}@example.com";

        // Create twice (duplicate)
        $this->sendUserMsg('create', ['uid' => $uid, 'email' => $email]);
        $this->sendUserMsg('create', ['uid' => $uid, 'email' => $email]);
        $this->assertEquals(1, $this->countUsersByEmail($email), 'Only one user should exist after duplicate create');

        // Update non-existent
        $ghostUid = $this->randomUid('NONEXIST');
        $this->sendUserMsg('update', ['uid' => $ghostUid, 'email' => "ghost$ghostUid@example.com"]);
        $this->assertEmpty($this->findUserByUid($ghostUid), 'No user for non-existent update');

        // Delete non-existent
        $this->sendUserMsg('delete', ['uid' => $ghostUid]);
        $this->assertEquals(0, $this->countUsersByUid($ghostUid), 'No user after delete non-existent user');
    }

    // --- TESTS: EVENT CRUD ---

    public function testEventCrud(): void
    {
        $uid = $this->randomUid('EVT');
        // Create
        $this->sendEventMsg('create', ['uid' => $uid, 'title' => 'Test Event']);
        $event = $this->findEventByUid($uid);
        $this->assertNotEmpty($event, 'Event should exist after create');

        // Update
        $this->sendEventMsg('update', ['uid' => $uid, 'title' => 'Updated Event', 'location' => 'Updated Address']);
        $event = $this->findEventByUid($uid);
        $this->assertEquals('Updated Event', $event['name']);

        // Delete
        $this->sendEventMsg('delete', ['uid' => $uid]);
        $this->assertEquals(0, $this->countEventsByUid($uid), 'Event should be deleted');
    }

    public function testEventCrudUnsuccessful(): void
    {
        $uid = $this->randomUid('EVTFAKE');
        // Update non-existent
        $this->sendEventMsg('update', ['uid' => $uid, 'title' => 'Ghost Event']);
        $this->assertEmpty($this->findEventByUid($uid), 'No event for non-existent update');

        // Delete non-existent
        $this->sendEventMsg('delete', ['uid' => $uid]);
        $this->assertEquals(0, $this->countEventsByUid($uid), 'No event after delete non-existent');
    }

    // --- TESTS: COMPANY CRUD & EMPLOYEE REGISTRATION ---

    public function testCompanyCrudAndEmployeeRegistration(): void
    {
        // Owner user
        $ownerUid = $this->randomUid('OWN');
        $ownerEmail = "owner{$ownerUid}@example.com";
        $this->sendUserMsg('create', ['uid' => $ownerUid, 'email' => $ownerEmail, 'first_name' => 'Owner', 'title' => 'Ms']);

        // Company
        $companyUid = $this->randomUid('CMP');
        $companyName = "Test Company $companyUid";
        $company = [
            'uid' => $companyUid,
            'owner_id' => $ownerUid,
            'name' => $companyName,
            'companyNumber' => 'BE1234567890',
            'VATNumber' => 'VAT123456',
            'address' => ['street' => 'Main St', 'number' => '12A', 'postcode' => '1000', 'city' => 'Brussels'],
            'billingAddress' => ['street' => 'Billing St', 'number' => '1B', 'postcode' => '2000', 'city' => 'Antwerp'],
            'email' => "company{$companyUid}@example.com",
            'phone' => '+32012345678'
        ];
        $this->sendCompanyMsg('create', $company);

        $companyRow = $this->findCompanyByUid($companyUid);
        $this->assertNotEmpty($companyRow, 'Company should exist after create');
        $this->assertEquals($companyName, $companyRow['name']);

        // Update
        $newCompanyName = "$companyName Updated";
        $company['name'] = $newCompanyName;
        $this->sendCompanyMsg('update', $company);
        $companyRow = $this->findCompanyByUid($companyUid);
        $this->assertEquals($newCompanyName, $companyRow['name'], 'Company name should be updated');

        // Register owner as employee
        $this->sendRegisterEmployeeMsg('register', $ownerUid, $companyUid, $newCompanyName, $company['companyNumber'], $company['VATNumber']);
        $employee = $this->findUserByUid($ownerUid);
        $this->assertEquals($newCompanyName, $employee['company'], 'Owner registered with company');

        // Unregister owner
        $this->sendRegisterEmployeeMsg('unregister', $ownerUid, $companyUid, $newCompanyName, $company['companyNumber'], $company['VATNumber']);
        $employee = $this->findUserByUid($ownerUid);
        $this->assertNull($employee['company'], 'Owner unregistered from company');

        // Delete company
        $this->sendCompanyMsg('delete', ['uid' => $companyUid, 'name' => $newCompanyName, 'companyNumber' => $company['companyNumber'], 'VATNumber' => $company['VATNumber']]);
        $this->assertEmpty($this->findCompanyByUid($companyUid), 'Company should be deleted');

        // Cleanup owner user
        $this->sendUserMsg('delete', ['uid' => $ownerUid]);
    }

    public function testCompanyCrudUnsuccessful(): void
    {
        $uid = $this->randomUid('CMPFAKE');
        // Update non-existent
        $this->sendCompanyMsg('update', ['uid' => $uid, 'owner_id' => 'OWNERFAKE', 'name' => 'Ghost Company', 'companyNumber' => '000', 'VATNumber' => 'VAT000', 'address' => ['street'=>'None', 'number'=>'0', 'postcode'=>'0000', 'city'=>'Nowhere'], 'billingAddress' => ['street'=>'None', 'number'=>'0', 'postcode'=>'0000', 'city'=>'Nowhere'], 'email'=>'none@none.com', 'phone'=>'+000000000']);
        $this->assertEmpty($this->findCompanyByUid($uid), 'No company for non-existent update');

        // Delete non-existent
        $this->sendCompanyMsg('delete', ['uid' => $uid, 'name' => 'Ghost Company', 'companyNumber' => '000', 'VATNumber' => 'VAT000']);
        $this->assertEmpty($this->findCompanyByUid($uid), 'No company after delete non-existent');
    }

    public function testRegisterCompanyEmployeeUnsuccessful(): void
    {
        // Register user to a non-existent company
        $fakeUserUid = $this->randomUid('REGFAKE');
        $fakeCompanyUid = $this->randomUid('CMPFAKE');
        $this->sendRegisterEmployeeMsg('register', $fakeUserUid, $fakeCompanyUid, 'FakeCo', '000', 'VAT000');

        $user = $this->findUserByUid($fakeUserUid);
        if ($user) {
            $this->assertNotEquals('FakeCo', $user['company'], 'User should not be assigned to fake company');
        } else {
            $this->assertEmpty($user, 'User does not exist as expected');
        }
    }

    // ---------------------------
    // --- Helper/Utility code ---
    // ---------------------------

    private function sendMessageAndWait($queue, $xml, $desc = ''): void
    {
        self::$channel->basic_publish(
            new AMQPMessage($xml, ['content_type' => 'application/xml']),
            '', $queue
        );
        sleep(2); // For consumer to process
    }

    // --- USER ---
    private function sendUserMsg($operation, $data = [])
    {
        $defaults = [
            'uid' => $this->randomUid('USR'),
            'email' => 'default@example.com',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'title' => 'Mr',
            'password' => 'pw',
            'is_admin' => 0
        ];
        $d = array_merge($defaults, $data);

        $xml = <<<XML
<?xml version="1.0"?>
<attendify>
  <info><sender>test</sender><operation>{$operation}</operation></info>
  <user>
    <first_name>{$d['first_name']}</first_name>
    <last_name>{$d['last_name']}</last_name>
    <email>{$d['email']}</email>
    <title>{$d['title']}</title>
    <uid>{$d['uid']}</uid>
    <password>{$d['password']}</password>
    <is_admin>{$d['is_admin']}</is_admin>
  </user>
</attendify>
XML;
        if ($operation === 'delete') {
            $xml = <<<XML
<?xml version="1.0"?>
<attendify>
  <info><sender>test</sender><operation>delete</operation></info>
  <user>
    <uid>{$d['uid']}</uid>
  </user>
</attendify>
XML;
        }
        $this->sendMessageAndWait(self::USER_QUEUE, $xml, "User $operation");
    }

    // --- EVENT ---
    private function sendEventMsg($operation, $data = [])
    {
        $defaults = [
            'uid' => $this->randomUid('EVT'),
            'title' => 'Test Event',
            'start_date' => '2025-06-01T10:00:00Z',
            'end_date' => '2025-06-01T18:00:00Z',
            'location' => '123 Test St, Test City',
            'description' => 'Event description',
            'max_attendees' => 50
        ];
        $d = array_merge($defaults, $data);

        $xml = <<<XML
<?xml version="1.0"?>
<attendify>
  <info><sender>test</sender><operation>{$operation}</operation></info>
  <event>
    <uid>{$d['uid']}</uid>
    <title>{$d['title']}</title>
    <start_date>{$d['start_date']}</start_date>
    <end_date>{$d['end_date']}</end_date>
    <location>{$d['location']}</location>
    <description>{$d['description']}</description>
    <max_attendees>{$d['max_attendees']}</max_attendees>
  </event>
</attendify>
XML;
        if ($operation === 'delete') {
            $xml = <<<XML
<?xml version="1.0"?>
<attendify>
  <info><sender>test</sender><operation>delete</operation></info>
  <event>
    <uid>{$d['uid']}</uid>
  </event>
</attendify>
XML;
        }
        $this->sendMessageAndWait(self::EVENT_QUEUE, $xml, "Event $operation");
    }

    // --- COMPANY ---
    private function sendCompanyMsg($operation, $data = [])
    {
        $defaults = [
            'uid' => $this->randomUid('CMP'),
            'owner_id' => $this->randomUid('OWN'),
            'name' => 'Company Name',
            'companyNumber' => 'BE0000000000',
            'VATNumber' => 'VAT000000',
            'address' => ['street'=>'Main St','number'=>'1','postcode'=>'1000','city'=>'Brussels'],
            'billingAddress' => ['street'=>'Billing St','number'=>'1','postcode'=>'2000','city'=>'Antwerp'],
            'email' => 'company@example.com',
            'phone' => '+32012345678'
        ];
        $d = array_merge($defaults, $data);

        $companyXml = <<<XML
<company>
  <uid>{$d['uid']}</uid>
  <owner_id>{$d['owner_id']}</owner_id>
  <name>{$d['name']}</name>
  <companyNumber>{$d['companyNumber']}</companyNumber>
  <VATNumber>{$d['VATNumber']}</VATNumber>
  <address>
    <street>{$d['address']['street']}</street>
    <number>{$d['address']['number']}</number>
    <postcode>{$d['address']['postcode']}</postcode>
    <city>{$d['address']['city']}</city>
  </address>
  <billingAddress>
    <street>{$d['billingAddress']['street']}</street>
    <number>{$d['billingAddress']['number']}</number>
    <postcode>{$d['billingAddress']['postcode']}</postcode>
    <city>{$d['billingAddress']['city']}</city>
  </billingAddress>
  <email>{$d['email']}</email>
  <phone>{$d['phone']}</phone>
</company>
XML;

        $xml = <<<XML
<?xml version="1.0"?>
<attendify>
  <info><sender>test</sender><operation>{$operation}</operation></info>
  <companies>
    {$companyXml}
  </companies>
</attendify>
XML;

        if ($operation === 'delete') {
            $xml = <<<XML
<?xml version="1.0"?>
<attendify>
  <info><sender>test</sender><operation>delete</operation></info>
  <companies>
    <company>
      <uid>{$d['uid']}</uid>
      <name>{$d['name']}</name>
      <companyNumber>{$d['companyNumber']}</companyNumber>
      <VATNumber>{$d['VATNumber']}</VATNumber>
    </company>
  </companies>
</attendify>
XML;
        }
        $this->sendMessageAndWait(self::COMPANY_QUEUE, $xml, "Company $operation");
    }

    private function sendRegisterEmployeeMsg($operation, $userUid, $companyUid, $name, $companyNumber, $vatNumber)
    {
        $xml = <<<XML
<?xml version="1.0"?>
<attendify>
  <info><sender>test</sender><operation>{$operation}</operation></info>
  <company_employee>
    <uid>{$userUid}</uid>
    <company_id>{$companyUid}</company_id>
    <name>{$name}</name>
    <companyNumber>{$companyNumber}</companyNumber>
    <VATNumber>{$vatNumber}</VATNumber>
  </company_employee>
</attendify>
XML;
        $this->sendMessageAndWait(self::COMPANY_QUEUE, $xml, "Register employee $operation");
    }

    private function randomUid($prefix) { return $prefix . random_int(10000, 99999); }

    // --- FINDERS AND COUNTERS ---
    private function findUserByUid($uid)
    {
        $stmt = self::$pdo->prepare('SELECT email, custom_1, company FROM client WHERE custom_2 = :uid');
        $stmt->execute([':uid' => $uid]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    private function findEventByUid($uid)
    {
        $stmt = self::$pdo->prepare('SELECT name, address FROM events WHERE uid_event = :uid');
        $stmt->execute([':uid' => $uid]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    private function findCompanyByUid($uid)
    {
        $stmt = self::$pdo->prepare('SELECT * FROM company WHERE uid = :uid');
        $stmt->execute([':uid' => $uid]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    private function countUsersByEmail($email)
    {
        $stmt = self::$pdo->prepare('SELECT COUNT(*) FROM client WHERE email = :email');
        $stmt->execute([':email' => $email]);
        return (int)$stmt->fetchColumn();
    }
    private function countUsersByUid($uid)
    {
        $stmt = self::$pdo->prepare('SELECT COUNT(*) FROM client WHERE custom_2 = :uid');
        $stmt->execute([':uid' => $uid]);
        return (int)$stmt->fetchColumn();
    }
    private function countEventsByUid($uid)
    {
        $stmt = self::$pdo->prepare('SELECT COUNT(*) FROM events WHERE uid_event = :uid');
        $stmt->execute([':uid' => $uid]);
        return (int)$stmt->fetchColumn();
    }
}
