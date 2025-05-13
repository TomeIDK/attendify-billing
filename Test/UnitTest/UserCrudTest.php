<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../consumers/user_consumer.php';

class UserCrudTest extends TestCase
{
    private $pdo;
    private $stmt;

    protected function setUp(): void
    {
        // Mock the PDO and PDOStatement
        $this->stmt = $this->createMock(PDOStatement::class);
        $this->pdo = $this->createMock(PDO::class);

        // For all calls to ->prepare(), return the mock statement
        $this->pdo->method('prepare')->willReturn($this->stmt);

        // Stub SET @is_consumer_source = 1 query
        $this->pdo->method('exec')->willReturn(1);
    }

    // --- USER CRUD TEST CASES ---
    
    public function testCreateUserSuccess(): void
    {
        $user = [
            'email' => 'john@example.com',
            'password' => 'hashed_pass',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'title' => 'Developer',
            'uid' => 'UID123'
        ];

        $this->stmt->method('execute')->willReturn(true);

        ob_start();
        createUser($user, $this->pdo);
        $output = ob_get_clean();

        $this->assertStringContainsString(" [✔] User created: john@example.com", $output);
    }

    public function testCreateUserDuplicate(): void
    {
        $user = [
            'email' => 'jane@example.com',
            'password' => 'pass123',
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'title' => 'Designer',
            'uid' => 'UID124'
        ];

        $this->stmt->method('execute')->willThrowException(new PDOException('Duplicate entry', 23000));

        ob_start();
        createUser($user, $this->pdo);
        $output = ob_get_clean();

        $this->assertStringContainsString("already exists", $output);
    }

    public function testUpdateUser(): void
    {
        $user = [
            'email' => 'jake@example.com',
            'password' => 'pass123',
            'first_name' => 'Jake',
            'last_name' => 'Old',
            'title' => 'Intern',
            'uid' => 'UID125'
        ];

        $this->stmt->method('execute')->willReturn(true);
        $this->stmt->method('rowCount')->willReturn(1);

        ob_start();
        updateUser($user, $this->pdo);
        $output = ob_get_clean();

        $this->assertStringContainsString(" [✔] User updated: UID125", $output);
    }

    public function testDeleteUser(): void
    {
        $user = [
            'uid' => 'UID126'
        ];

        $this->stmt->method('execute')->willReturn(true);
        $this->stmt->method('rowCount')->willReturn(1);

        ob_start();
        deleteUser($user, $this->pdo);
        $output = ob_get_clean();

        $this->assertStringContainsString(" [✔] User deleted: UID126", $output);
    }

    // --- EVENT CRUD TEST CASES ---
    
    public function testCreateEvent(): void
    {
        $event = [
            'uid_event' => 'EID001',
            'name' => 'Sample Event',
            'start_date' => '2025-06-01 10:00:00',
            'end_date' => '2025-06-01 12:00:00',
            'address' => '123 Event St.',
            'description' => 'This is a sample event.',
            'max_attendees' => 50
        ];

        $this->stmt->method('execute')->willReturn(true);

        ob_start();
        createEvent($event, $this->pdo);
        $output = ob_get_clean();

        $this->assertStringContainsString(" [✔] Event created: EID001", $output);
    }

    public function testUpdateEvent(): void
    {
        $event = [
            'uid_event' => 'EID002',
            'name' => 'Updated Event',
            'start_date' => '2025-06-05 09:00:00',
            'end_date' => '2025-06-05 11:00:00',
            'address' => '456 Event Ave.',
            'description' => 'Updated description.',
            'max_attendees' => 100
        ];

        $this->stmt->method('execute')->willReturn(true);
        $this->stmt->method('rowCount')->willReturn(1);

        ob_start();
        updateEvent($event, $this->pdo);
        $output = ob_get_clean();

        $this->assertStringContainsString(" [✔] Event updated: EID002", $output);
    }

    public function testDeleteEvent(): void
    {
        $event = [
            'uid_event' => 'EID003'
        ];

        $this->stmt->method('execute')->willReturn(true);
        $this->stmt->method('rowCount')->willReturn(1);

        ob_start();
        deleteEvent($event, $this->pdo);
        $output = ob_get_clean();

        $this->assertStringContainsString(" [✔] Event deleted: EID003", $output);
    }
}
