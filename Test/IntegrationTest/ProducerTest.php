<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;             
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Channel\AMQPChannel;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/ProducerFunctions.php';

#[Group('integration')]  
class ProducerTest extends TestCase
{
    private AMQPChannel $channel;
    private AMQPStreamConnection $connection;
    private string $queueName;

    protected function setUp(): void
    {
        $this->connection = new AMQPStreamConnection(
            getenv('RABBITMQ_HOST'),
            getenv('RABBITMQ_PORT'),
            getenv('RABBITMQ_USER'),
            getenv('RABBITMQ_PASSWORD'),
            getenv('RABBITMQ_VHOST')
        );
        $this->channel = $this->connection->channel();

        // make the queue unique & auto-delete when the channel closes
        $this->queueName = 'test_queue_' . uniqid();
        $this->channel->queue_declare($this->queueName, false, false, false, true);

        // bind once per routing key we want to inspect
        $this->channel->queue_bind($this->queueName, 'user-management', 'user.register');
        $this->channel->queue_bind($this->queueName, 'user-management', 'user.update');
        $this->channel->queue_bind($this->queueName, 'user-management', 'user.delete');
    }

    #[DataProvider('operationProvider')]           // ← modern attribute
    public function testRabbitMqPublish(string $operation, string $expectedRoutingKey): void
    {
        $userData = [
            'first_name' => 'Integration',
            'last_name'  => 'Test',
            'email'      => 'integration@test.com',
            'title'      => 'QA',
            'password'   => 'secure',
            'operation'  => $operation,
        ];

        // act
        processRow($userData, $operation, $this->channel);

        // give RabbitMQ a moment (helps on slower CI runners)
        usleep(100_000);

        // assert
        $msg = $this->channel->basic_get($this->queueName, true);
        $this->assertNotNull($msg, "No message was published for {$operation}");

        // correct routing key?
        $this->assertEquals($expectedRoutingKey, $msg->delivery_info['routing_key']);

        // correct XML payload?
        $xml = simplexml_load_string($msg->body);
        $this->assertSame('Integration', (string) $xml->user->first_name);
        $this->assertSame(strtolower($operation), (string) $xml->info->operation);
    }

    /** Data provider for the three operations */
    public static function operationProvider(): array   // ← must be static
    {
        return [
            ['CREATE', 'user.register'],
            ['UPDATE', 'user.update'],
            ['DELETE', 'user.delete'],
        ];
    }

    protected function tearDown(): void
    {
        $this->channel->queue_delete($this->queueName);
        $this->channel->close();
        $this->connection->close();
    }
}
