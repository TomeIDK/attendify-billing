<?php

use PHPUnit\Framework\TestCase;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Channel\AMQPChannel;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/ProducerFunctions.php';

class ProducerTest extends TestCase
{
    
    private AMQPChannel $channel;
    private $connection;

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

        // Setup a test queue bound to a test routing key
        $this->channel->queue_declare('test_queue', false, false, false, true);
        $this->channel->queue_bind('test_queue', 'user-management', 'user.register');
    }

    public function testRealRabbitMqPublish()
    {
        $userData = [
            'first_name' => 'Integration',
            'last_name' => 'Test',
            'email' => 'integration@test.com',
            'title' => 'QA',
            'password' => 'secure',
            'operation' => 'CREATE'
        ];

        processRow($userData, 'CREATE', $this->channel);

        // Get the message back
        $msg = $this->channel->basic_get('test_queue', true);
        $this->assertNotNull($msg, "No message was published to the queue");

        $xml = simplexml_load_string($msg->body);
        $this->assertEquals('Integration', (string)$xml->user->first_name);
        $this->assertEquals('create', (string)$xml->info->operation);
    }

    protected function tearDown(): void
    {
        $this->channel->queue_delete('test_queue');
        $this->channel->close();
        $this->connection->close();
    }
}

