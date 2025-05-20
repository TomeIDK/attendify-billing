<?php

require_once __DIR__ . '/../../consumers/user_consumer.php';

use PHPUnit\Framework\TestCase;


class UserCrudTest extends TestCase
{
    private PDO $pdo;
    private PDOStatement $stmt;

    protected function setUp(): void
    {
        // Create mocks for PDO and PDOStatement
        $this->stmt = $this->createMock(PDOStatement::class);
        $this->pdo  = $this->createMock(PDO::class);

        // prepare() always returns our statement mock
        $this->pdo->method('prepare')->willReturn($this->stmt);
        // exec() stub for SET @is_consumer_source
        $this->pdo->method('exec')->willReturn(1);
    }

    public function testCreateUserReturnsTrueOnSuccess(): void
    {
        $user = [
            'email'      => 'alice@example.com',
            'password'   => 'secret',
            'first_name' => 'Alice',
            'last_name'  => 'Wonder',
            'title'      => 'Engineer',
            'uid'        => 'UID100'
        ];

        $this->stmt->method('execute')->willReturn(true);

        $this->assertTrue(
            createUser($user, $this->pdo),
            'createUser should return true when insertion succeeds'
        );
    }

    public function testCreateUserReturnsFalseOnDuplicateEntry(): void
    {
        $user = [
            'email'      => 'bob@example.com',
            'password'   => 'hunter2',
            'first_name' => 'Bob',
            'last_name'  => 'Builder',
            'title'      => 'Manager',
            'uid'        => 'UID101'
        ];

        // Simulate duplicate key PDOException with SQLSTATE 23000
        $this->stmt
            ->method('execute')
            ->will($this->throwException(new PDOException('Duplicate entry', '23000')));

        $this->assertFalse(
            createUser($user, $this->pdo),
            'createUser should return false on SQLSTATE 23000 duplicate entry'
        );
    }

    public function testCreateUserThrowsOnOtherPdoException(): void
    {
        $user = [
            'email'      => 'charlie@example.com',
            'password'   => 'pass',
            'first_name' => 'Charlie',
            'last_name'  => 'Chaplin',
            'title'      => 'Actor',
            'uid'        => 'UID102'
        ];

        // Simulate a generic PDOException
        $this->stmt
            ->method('execute')
            ->will($this->throwException(new PDOException('Connection lost', 'HY000')));

        $this->expectException(PDOException::class);
        createUser($user, $this->pdo);
    }

    public function testUpdateUserReturnsTrueWhenRowUpdated(): void
    {
        $user = [
            'email'      => 'dave@example.com',
            'password'   => 'newpass',
            'first_name' => 'Dave',
            'last_name'  => 'Grohl',
            'title'      => 'Musician',
            'uid'        => 'UID103'
        ];

        $this->stmt->method('execute')->willReturn(true);
        $this->stmt->method('rowCount')->willReturn(1);

        $this->assertTrue(
            updateUser($user, $this->pdo),
            'updateUser should return true when at least one row is affected'
        );
    }

    public function testUpdateUserReturnsFalseWhenNoRowsAffected(): void
    {
        $user = [
            'email'      => 'ed@example.com',
            'password'   => 'nopass',
            'first_name' => 'Ed',
            'last_name'  => 'Sheeran',
            'title'      => 'Singer',
            'uid'        => 'UID104'
        ];

        $this->stmt->method('execute')->willReturn(true);
        $this->stmt->method('rowCount')->willReturn(0);

        $this->assertFalse(
            updateUser($user, $this->pdo),
            'updateUser should return false when no rows are affected'
        );
    }

    public function testDeleteUserReturnsTrueWhenRowDeleted(): void
    {
        $user = ['uid' => 'UID105'];

        $this->stmt->method('execute')->willReturn(true);
        $this->stmt->method('rowCount')->willReturn(1);

        $this->assertTrue(
            deleteUser($user, $this->pdo),
            'deleteUser should return true when a row is deleted'
        );
    }

    public function testDeleteUserReturnsFalseWhenNoRowsDeleted(): void
    {
        $user = ['uid' => 'UID106'];

        $this->stmt->method('execute')->willReturn(true);
        $this->stmt->method('rowCount')->willReturn(0);

        $this->assertFalse(
            deleteUser($user, $this->pdo),
            'deleteUser should return false when no rows are deleted'
        );
    }
}
