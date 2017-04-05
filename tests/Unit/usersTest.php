<?php
use Api\Token;
use Tests\DbUnitArrayDataSet;

class UsersTest extends LocalDbWebTestCase
{
	private $startdate;
	private $enddate;
	/**
	 * @return PHPUnit_Extensions_Database_DataSet_IDataSet
	 */
	public function getDataSet()
	{
		$this->startdate = new DateTime();
		$this->enddate = clone $this->startdate;
		$this->enddate->add(new DateInterval('P365D'));
		
		return new DbUnitArrayDataSet(array(
			'users' => array(
				array('user_id' => 1, 'firstname' => 'firstname', 'lastname' => 'lastname',
						'role' => 'admin', 'email' => 'admin@klusbib.be', 
						'hash' => password_hash("test", PASSWORD_DEFAULT),
						'membership_start_date' => $this->startdate->format('Y-m-d H:i:s'),
						'membership_end_date' => $this->enddate->format('Y-m-d H:i:s')
						),
				array('user_id' => 2, 'firstname' => 'harry', 'lastname' => 'De Handige',
						'role' => 'volunteer', 'email' => 'harry@klusbib.be',
						'hash' => password_hash("test", PASSWORD_DEFAULT),
						'membership_start_date' => $this->startdate->format('Y-m-d H:i:s'),
						'membership_end_date' => $this->enddate->format('Y-m-d H:i:s')
						),
				array('user_id' => 3, 'firstname' => 'daniel', 'lastname' => 'De Deler',
						'role' => 'member', 'email' => 'daniel@klusbib.be',
						'hash' => password_hash("test", PASSWORD_DEFAULT),
						'membership_start_date' => $this->startdate->format('Y-m-d H:i:s'),
						'membership_end_date' => $this->enddate->format('Y-m-d H:i:s')
						),
			),
		));
	}
	
	public function testGetUsers()
	{
		echo "test GET users\n";
		$body = $this->client->get('/users');
// 		print_r($body);
		$this->assertEquals(200, $this->client->response->getStatusCode());
		$users = json_decode($body);
		$this->assertEquals(3, count($users));
	}

	public function testPostUsers()
	{
		echo "test POST users\n";
		// get token
		$data = ["users.all"];
		$header = array('Authorization' => "Basic YWRtaW5Aa2x1c2JpYi5iZTp0ZXN0");
		$response = $this->client->post('/token', $data, $header);
		$responseData = json_decode($response);
		
		$scopes = array("users.all");
		$header = array('Authorization' => "bearer $responseData->token");
		$container = $this->app->getContainer();

		$data = array("firstname" => "myname", 
				"lastname" => "my lastname",
				"email" => "myname.lastname@klusbib.be",
				"role" => "member"
		);
		$body = $this->client->post('/users', $data, $header);
// 		print_r($body);
		$this->assertEquals(200, $this->client->response->getStatusCode());
		$user = json_decode($body);
		$this->assertNotNull($user->user_id);
		
		// check user has properly been updated
		$bodyGet = $this->client->get('/users/' . $user->user_id);
		$this->assertEquals(200, $this->client->response->getStatusCode());
		$user = json_decode($bodyGet);
		$this->assertEquals($data["firstname"], $user->firstname);
		$this->assertEquals($data["lastname"], $user->lastname);
		$this->assertEquals($data["email"], $user->email);
		$this->assertEquals($data["role"], $user->role);
		
	}
	
	public function testGetUser()
	{
		echo "test GET users\n";
		$body = $this->client->get('/users/1');
// 		print_r($body);
		$this->assertEquals(200, $this->client->response->getStatusCode());
		$user = json_decode($body);
		$this->assertEquals("1", $user->user_id);
		$this->assertEquals("firstname", $user->firstname);
		$this->assertEquals("lastname", $user->lastname);
		$this->assertEquals("admin@klusbib.be", $user->email);
		$this->assertEquals("admin", $user->role);
		$this->assertEquals($this->startdate->format('Y-m-d'), $user->membership_start_date);
		$this->assertEquals($this->enddate->format('Y-m-d'), $user->membership_end_date);
	}
	
	public function testPutUser()
	{
		echo "test PUT users\n";
		$data = array("firstname" => "new firstname", 
				"lastname" =>"my new lastname",
				"email" =>"newemail@klusbib.be",
				"role" => "new admin");
		$header = array();
		$this->client->put('/users/1', $data, $header);
		$this->assertEquals(200, $this->client->response->getStatusCode());

		// check user has properly been updated
		$bodyGet = $this->client->get('/users/1');
		$this->assertEquals(200, $this->client->response->getStatusCode());
		$user = json_decode($bodyGet);
		$this->assertEquals($data["firstname"], $user->firstname);
		$this->assertEquals($data["lastname"], $user->lastname);
		$this->assertEquals($data["email"], $user->email);
		$this->assertEquals($data["role"], $user->role);
		
	}
	public function testPutUserPassword()
	{
		echo "test PUT users\n";
		$data = array("password" => "new pwd");
		$header = array();
// 		$newHash = password_hash("new pwd", PASSWORD_DEFAULT);
// 		echo "expected new hash = $newHash \n";
		$responsePut = $this->client->put('/users/1', $data, $header);
		$this->assertEquals(200, $this->client->response->getStatusCode());
		print_r($responsePut);
		
		// check get token no longer possible
		$data = ["users.all"];
		$header = array('Authorization' => "Basic YWRtaW5Aa2x1c2JpYi5iZTp0ZXN0");
		$response = $this->client->post('/token', $data, $header);
		print_r($response);
		$this->assertEquals(401, $this->client->response->getStatusCode());

// 		// check get token with new password is ok
// 		$basicAuth = base64_encode("admin@klusbib.be:new pwd");
// 		$header = array('Authorization' => "Basic $basicAuth",
// 				"PHP_AUTH_USER" => "admin@klusbib.be",
// 				"PHP_AUTH_PW" => "new pwd"
// 		);
// 		$response = $this->client->post('/token', $data, $header);
// 		$this->assertEquals(200, $this->client->response->getStatusCode());
// 		$responseData = json_decode($response);
	}
	
	public function testPutUserNotFound()
	{
		echo "test PUT users\n";
		$data = array("firstname" => "new firstname",
				"lastname" =>"my new lastname",
				"email" =>"newemail@klusbib.be",
				"role" => "new admin");
		$header = array();
		$this->client->put('/users/999', $data, $header);
		$this->assertEquals(404, $this->client->response->getStatusCode());
	}
	
	public function testDeleteUser()
	{
		echo "test DELETE user\n";
		$this->client->delete('/users/1');
		$body = $this->assertEquals(200, $this->client->response->getStatusCode());
		
		// delete inexistant user
		$this->client->delete('/users/1');
		$body = $this->assertEquals(204, $this->client->response->getStatusCode());
	}
	
}