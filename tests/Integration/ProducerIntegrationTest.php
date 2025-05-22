<?php
namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use PDO;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class ProducerIntegrationTest extends TestCase
{
    private static PDO $db;
    private static $rmq;
    private static $conn;

    private const EXCHANGE = 'user-management';
    private const ROUTE_KEY = 'user.register';

    public static function setUpBeforeClass(): void
    {
        $dotenv = \Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2));
        $dotenv->safeload();

        self::$db = new PDO(
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

        self::$conn = new AMQPStreamConnection(
            '127.0.0.1',
            $_ENV['RABBITMQ_PORT'],
            $_ENV['RABBITMQ_USER'],
            $_ENV['RABBITMQ_PASSWORD'],
            $_ENV['RABBITMQ_VHOST']
        );
        self::$rmq = self::$conn->channel();
    }

    public static function tearDownAfterClass(): void
    {
        self::$rmq->close();
        self::$conn->close();
    }

    public function testRabbitMqProducer()
    {
        $queue = 'tmp_' . uniqid();
        self::$rmq->queue_declare($queue, false, false, false, true);
        self::$rmq->queue_bind($queue, self::EXCHANGE, self::ROUTE_KEY);

        $clientId = random_int(100000, 999999);
        $first = 'Integration';
        $last = 'Test';
        $email = 'integrationtest+' . uniqid() . '@example.com';
        $title = 'Mr';
        $is_admin = 0;
        $pass = 'testpw';

        $stmt = self::$db->prepare(
            "INSERT INTO client (id, first_name, last_name, email, custom_1, pass, custom_3, created_at, updated_at)
             VALUES (:id, :first, :last, :email, :title, :pass, :is_admin, NOW(), NOW())"
        );
        $stmt->execute([
            ':id' => $clientId,
            ':first' => $first,
            ':last' => $last,
            ':email' => $email,
            ':title' => $title,
            ':pass' => $pass,
            ':is_admin' => $is_admin,
        ]);

        $msgBody = null;
        $emailInMsg = null;
        $gotMsg = false;

        $callback = function(AMQPMessage $msg) use (&$msgBody, &$emailInMsg, &$gotMsg) {
            $msgBody = $msg->body;
            if (preg_match('/<email>([^<]+)<\/email>/', $msg->body, $matches)) {
                $emailInMsg = trim($matches[1]);
            }
            $gotMsg = true;
            $msg->ack();
            self::$rmq->basic_cancel($msg->getConsumerTag());
        };

        self::$rmq->basic_consume($queue, '', false, false, false, false, $callback);

        $start = time();
        while (!$gotMsg && (time() - $start) < 10) {
            try {
                self::$rmq->wait(null, false, 2);
            } catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) {
                // Just keep trying
            }
        }

        $this->assertNotNull($msgBody, "Producer didn't publish a message in 10s");
        $this->assertNotEmpty($emailInMsg, "No email found in the message");
        $this->assertEquals($email, $emailInMsg, "Email in message does not match email in DB");

        self::$rmq->queue_delete($queue);
        self::$db->exec("DELETE FROM client WHERE id = $clientId");
    }
}
