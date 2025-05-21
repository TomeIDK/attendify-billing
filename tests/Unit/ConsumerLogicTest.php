<?php
use PHPUnit\Framework\TestCase;

// Still requires the consumer logic (do not change file name!)
require_once __DIR__ . '/../../consumers/user_consumer.php';

class ConsumerLogicTest extends TestCase
{
    private $pdo;
    private $stmt;
    private $channel;

    protected function setUp(): void
    {
        $this->stmt = $this->createMock(PDOStatement::class);
        $this->pdo = $this->createMock(PDO::class);

        // By default, prepare returns $stmt
        $this->pdo->method('prepare')->willReturn($this->stmt);

        // By default, exec returns 1 (simulate SET @is_consumer_source = 1)
        $this->pdo->method('exec')->willReturn(1);

        // Set global $channel as mock for sendLog
        global $channel;
        $this->channel = $this->createMock(stdClass::class);
        $channel = $this->channel;
    }

    // USER CRUD
    public function testAddUser()
    {
        $user = [
            'email' => 'u@e.com', 'password' => 'pw', 'first_name' => 'F',
            'last_name' => 'L', 'title' => 'T', 'uid' => 'UID', 'is_admin' => 0
        ];

        $this->stmt->expects($this->once())->method('execute')->willReturn(true);
        $this->expectOutputRegex('/User created successfully/');

        $result = createUser($user, $this->pdo);
        $this->assertNull($result);
    }

    public function testAddUserDuplicate()
    {
        $user = ['email'=>'dup@e.com','password'=>'pw','first_name'=>'F','last_name'=>'L','title'=>'T','uid'=>'UID','is_admin'=>0];
        $this->stmt->method('execute')->willThrowException(new PDOException('Duplicate entry','23000'));
        $this->expectOutputRegex('/already exists/');
        $this->assertNull(createUser($user, $this->pdo));
    }

    public function testAddUserError()
    {
        $user = ['email'=>'err@e.com','password'=>'pw','first_name'=>'F','last_name'=>'L','title'=>'T','uid'=>'UID','is_admin'=>0];
        $this->stmt->method('execute')->willThrowException(new PDOException('Other','HY000'));
        $this->expectOutputRegex('/Database failed to create user/');
        $this->assertNull(createUser($user, $this->pdo));
    }

    public function testEditUser()
    {
        $user = ['email'=>'e','password'=>'pw','first_name'=>'F','last_name'=>'L','title'=>'T','uid'=>'UID','is_admin'=>0];
        $this->stmt->method('execute')->willReturn(true);
        $this->stmt->method('rowCount')->willReturn(1);
        $this->expectOutputRegex('/User updated with UID/');
        $this->assertNull(updateUser($user, $this->pdo));
    }

    public function testEditUserNoRow()
    {
        $user = ['email'=>'e','password'=>'pw','first_name'=>'F','last_name'=>'L','title'=>'T','uid'=>'UID','is_admin'=>0];
        $this->stmt->method('execute')->willReturn(true);
        $this->stmt->method('rowCount')->willReturn(0);
        $this->expectOutputRegex('/No user found to update/');
        $this->assertNull(updateUser($user, $this->pdo));
    }

    public function testEditUserError()
    {
        $user = ['email'=>'e','password'=>'pw','first_name'=>'F','last_name'=>'L','title'=>'T','uid'=>'UID','is_admin'=>0];
        $this->stmt->method('execute')->willThrowException(new PDOException('fail','HY000'));
        $this->expectOutputRegex('/Database failed to update user/');
        $this->assertNull(updateUser($user, $this->pdo));
    }

    public function testRemoveUser()
    {
        $user = ['uid' => 'UID1'];
        $this->stmt->method('execute')->willReturn(true);
        $this->stmt->method('rowCount')->willReturn(1);
        $this->expectOutputRegex('/User successfully deleted/');
        $this->assertNull(deleteUser($user, $this->pdo));
    }

    public function testRemoveUserNoRow()
    {
        $user = ['uid'=>'UID2'];
        $this->stmt->method('execute')->willReturn(true);
        $this->stmt->method('rowCount')->willReturn(0);
        $this->expectOutputRegex('/No user found with UID/');
        $this->assertNull(deleteUser($user, $this->pdo));
    }

    public function testRemoveUserError()
    {
        $user = ['uid'=>'UID2'];
        $this->stmt->method('execute')->willThrowException(new PDOException('fail','HY000'));
        $this->expectOutputRegex('/Database failed to delete user/');
        $this->assertNull(deleteUser($user, $this->pdo));
    }

    // EVENT CRUD
    public function testAddEvent()
    {
        $event = [
            'uid'=>'E1','title'=>'T','start_date'=>'2024-06-01','end_date'=>'2024-06-02',
            'location'=>'A','description'=>'D','max_attendees'=>'12'
        ];
        $this->stmt->expects($this->once())->method('execute')->willReturn(true);
        $this->expectOutputRegex('/Event created/');
        $this->assertNull(createEvent($event, $this->pdo));
    }

    public function testAddEventError()
    {
        $event = [
            'uid'=>'E2','title'=>'T','start_date'=>'2024-06-01','end_date'=>'2024-06-02',
            'location'=>'A','description'=>'D','max_attendees'=>'12'
        ];
        $this->stmt->method('execute')->willThrowException(new PDOException('fail','HY000'));
        $this->expectOutputRegex('/Failed to create event/');
        $this->assertNull(createEvent($event, $this->pdo));
    }

    public function testEditEvent()
    {
        $event = [
            'uid'=>'E3','title'=>'T','start_date'=>'2024-06-01','end_date'=>'2024-06-02',
            'location'=>'A','description'=>'D','max_attendees'=>'12'
        ];
        $this->stmt->method('execute')->willReturn(true);
        $this->stmt->method('rowCount')->willReturn(1);
        $this->expectOutputRegex('/Event updated/');
        $this->assertNull(updateEvent($event, $this->pdo));
    }

    public function testEditEventNoRow()
    {
        $event = [
            'uid'=>'E4','title'=>'T','start_date'=>'2024-06-01','end_date'=>'2024-06-02',
            'location'=>'A','description'=>'D','max_attendees'=>'12'
        ];
        $this->stmt->method('execute')->willReturn(true);
        $this->stmt->method('rowCount')->willReturn(0);
        $this->expectOutputRegex('/No event found to update/');
        $this->assertNull(updateEvent($event, $this->pdo));
    }

    public function testRemoveEvent()
    {
        $event = ['uid'=>'E5'];
        $this->stmt->method('execute')->willReturn(true);
        $this->stmt->method('rowCount')->willReturn(1);
        $this->expectOutputRegex('/Event deleted/');
        $this->assertNull(deleteEvent($event, $this->pdo));
    }

    public function testRemoveEventNoRow()
    {
        $event = ['uid'=>'E6'];
        $this->stmt->method('execute')->willReturn(true);
        $this->stmt->method('rowCount')->willReturn(0);
        $this->expectOutputRegex('/No event found to delete/');
        $this->assertNull(deleteEvent($event, $this->pdo));
    }

    // COMPANY CRUD
    public function testAddCompany()
    {
        $company = [
            'uid'=>'C1','owner_id'=>'O1','name'=>'Co','companyNumber'=>'123','VATNumber'=>'VAT',
            'address'=>['street'=>'S','number'=>'1','postcode'=>'P','city'=>'Ci'],
            'billingAddress'=>['street'=>'BS','number'=>'2','postcode'=>'BP','city'=>'BC'],
            'email'=>'co@e.com','phone'=>'123456789'
        ];
        $this->stmt->expects($this->once())->method('execute')->willReturn(true);
        $this->expectOutputRegex('/created successfully/');
        $this->assertNull(createCompany($company, $this->pdo));
    }

    public function testAddCompanyDuplicate()
    {
        $company = [
            'uid'=>'C1','owner_id'=>'O1','name'=>'Co','companyNumber'=>'123','VATNumber'=>'VAT',
            'address'=>['street'=>'S','number'=>'1','postcode'=>'P','city'=>'Ci'],
            'billingAddress'=>['street'=>'BS','number'=>'2','postcode'=>'BP','city'=>'BC'],
            'email'=>'co@e.com','phone'=>'123456789'
        ];
        $this->stmt->method('execute')->willThrowException(new PDOException('Duplicate','23000'));
        $this->expectOutputRegex('/already exists/');
        $this->assertNull(createCompany($company, $this->pdo));
    }

    public function testEditCompany()
    {
        $company = [
            'uid'=>'C1','owner_id'=>'O1','name'=>'Co','companyNumber'=>'123','VATNumber'=>'VAT',
            'address'=>['street'=>'S','number'=>'1','postcode'=>'P','city'=>'Ci'],
            'billingAddress'=>['street'=>'BS','number'=>'2','postcode'=>'BP','city'=>'BC'],
            'email'=>'co@e.com','phone'=>'123456789'
        ];
        $this->stmt->method('execute')->willReturn(true);
        $this->expectOutputRegex('/Company updated/');
        $this->assertNull(updateCompany($company, $this->pdo));
    }

    public function testEditCompanyNoRow()
    {
        $company = [
            'uid'=>'C1','owner_id'=>'O1','name'=>'Co','companyNumber'=>'123','VATNumber'=>'VAT',
            'address'=>['street'=>'S','number'=>'1','postcode'=>'P','city'=>'Ci'],
            'billingAddress'=>['street'=>'BS','number'=>'2','postcode'=>'BP','city'=>'BC'],
            'email'=>'co@e.com','phone'=>'123456789'
        ];
        $this->stmt->method('execute')->willReturn(true);
        $this->stmt->method('rowCount')->willReturn(0);
        $this->expectOutputRegex('/No company found to update/');
        $this->assertNull(updateCompany($company, $this->pdo));
    }

    public function testRemoveCompany()
    {
        $company = ['uid'=>'CDEL'];
        $this->stmt->method('execute')->willReturn(true);
        $this->stmt->method('rowCount')->willReturn(1);
        $this->expectOutputRegex('/successfully deleted/');
        $this->assertNull(deleteCompany($company, $this->pdo));
    }

    // COMPANY EMPLOYEE
    public function testAddUserToCompany()
    {
        $data = [
            'name'=>'Co','companyNumber'=>'123','VATNumber'=>'VAT','uid'=>'UID'
        ];
        $this->stmt->method('execute')->willReturn(true);
        $this->stmt->method('rowCount')->willReturn(1);
        $this->expectOutputRegex('/registered with company/');
        $this->assertNull(registerCompanyEmployee($data, $this->pdo));
    }

    public function testRemoveUserFromCompany()
    {
        $data = ['uid'=>'UID','company_id'=>'CID'];
        $this->stmt->method('execute')->willReturn(true);
        $this->stmt->method('rowCount')->willReturn(1);
        $this->expectOutputRegex('/unregistered from company/');
        $this->assertNull(unregisterCompanyEmployee($data, $this->pdo));
    }

    // GENERAL HELPERS
    public function testFindCompany()
    {
        $data = ['company_id' => 'CID'];
        $this->stmt->method('execute')->willReturn(true);
        $this->stmt->method('fetch')->willReturn(['uid' => 'CID', 'name' => 'Co']);
        $this->pdo->method('prepare')->willReturn($this->stmt);

        $result = getCompany($data, $this->pdo);
        $this->assertEquals(['uid'=>'CID','name'=>'Co'], $result);
    }

    public function testFindCompanyNull()
    {
        $data = ['company_id' => 'CID'];
        $this->stmt->method('execute')->willThrowException(new PDOException('fail','HY000'));
        $this->pdo->method('prepare')->willReturn($this->stmt);

        $this->expectOutputRegex('/Database failed to fetch company/');
        $this->assertNull(getCompany($data, $this->pdo));
    }

    public function testRemoveAllUsersFromCompany()
    {
        $data = ['name'=>'C','companyNumber'=>'123','VATNumber'=>'VAT','uid'=>'CUID'];
        // getAllUsersWithCompany returns 2 fake users
        $this->pdo->method('prepare')->willReturn($this->stmt);
        $this->stmt->method('execute')->willReturn(true);
        $this->stmt->method('fetchAll')->willReturn([['custom_2'=>'U1'],['custom_2'=>'U2']]);
        // unregisterCompanyEmployee is called for both users (internally just echoing)
        $this->expectOutputRegex('/unregistered from company/');
        unregisterAllUsersFromCompany($data, $this->pdo);
    }

    public function testFindUsersByCompany()
    {
        $data = ['name'=>'C','companyNumber'=>'123','VATNumber'=>'VAT'];
        $this->pdo->method('prepare')->willReturn($this->stmt);
        $this->stmt->method('execute')->willReturn(true);
        $this->stmt->method('fetchAll')->willReturn([['custom_2'=>'U1'],['custom_2'=>'U2']]);

        $result = getAllUsersWithCompany($data, $this->pdo);
        $this->assertEquals([['custom_2'=>'U1'],['custom_2'=>'U2']], $result);
    }

    public function testFindUsersByCompanyError()
    {
        $data = ['name'=>'C','companyNumber'=>'123','VATNumber'=>'VAT'];
        $this->pdo->method('prepare')->willReturn($this->stmt);
        $this->stmt->method('execute')->willThrowException(new PDOException('fail','HY000'));

        $this->expectOutputRegex('/Database failed to fetch users/');
        $this->assertNull(getAllUsersWithCompany($data, $this->pdo));
    }
}
