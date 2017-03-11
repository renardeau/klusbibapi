<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Api\Validator\UserValidator;
use Api\Exception\ForbiddenException;
use Api\ModelMapper\UserMapper;
use \Api\Model\User;
use Api\Authorisation;

$app->get('/users', function ($request, $response, $args) {
	$this->logger->info("Klusbib GET on '/users' route");

	if (false === $this->token->hasScope(["users.all", "users.list"])) {
		throw new ForbiddenException("Token not allowed to list users.", 403);
	}
	
	$users = Capsule::table('users')->orderBy('lastname', 'asc')->get();
	$data = array();
	foreach ($users as $user) {
		array_push($data, UserMapper::mapUserToArray($user));
	}
	return $response->withJson($data);
});

$app->post('/users', function ($request, $response, $args) {
	$this->logger->info("Klusbib POST on '/users' route");

	Authorisation::checkAccessByToken($this->token, ["users.all", "users.create"]);
	if (false === $this->token->hasScope(["users.all", "users.create"])) {
		throw new ForbiddenException("Token not allowed to create users.", 403);
	}
	
	$parsedBody = $request->getParsedBody();
	$this->logger->info("parsedbody=" . json_encode($parsedBody));
	if (empty($parsedBody) || !UserValidator::isValidUserData($parsedBody, $this->logger)) {
		return $response->withStatus(400); // Bad request
	}
	$user = new \Api\Model\User;
	$user->firstname = $parsedBody["firstname"];
	$user->lastname = $parsedBody["lastname"];
	$user->role = $parsedBody["role"];
	if (!empty($parsedBody["email"])) {
		$user->email= $parsedBody["email"];
	}
	if (!empty($parsedBody["membership_start_date"])) {
		$user->membership_start_date = $parsedBody["membership_start_date"];
		if (!empty($parsedBody["membership_end_date"])) {
			$user->membership_end_date = $parsedBody["membership_end_date"];
		} else { // default to 1 year membership
			$user->membership_end_date = strtotime("+1 year", strtotime($parsedBody["membership_start_date"]));
		}
	}
	$user->save();
	return $response->withJson(UserMapper::mapUserToArray($user));
});

	
$app->get('/users/{userid}', function ($request, $response, $args) {
	$this->logger->info("Klusbib GET on '/users/id' route");

	if (false === $this->token->hasScope(["users.all", "users.read"])) {
		throw new ForbiddenException("Token not allowed to read users.", 403);
	}
	
	$user = User::find($args['userid']);
	if (null == $user) {
		return $response->withStatus(404);
	}
	return $response->withJson(UserMapper::mapUserToArray($user));
});

$app->put('/users/{userid}', function ($request, $response, $args) {
	$this->logger->info("Klusbib PUT on '/users/id' route");

	if (false === $this->token->hasScope(["users.all", "users.update"])) {
		throw new ForbiddenException("Token not allowed to update users.", 403);
	}
	
	$usermapper = new UserMapper();
	$user = \Api\Model\User::find($args['userid']);
	if (null == $user) {
		return $response->withStatus(404);
	}

	$parsedBody = $request->getParsedBody();
	if (isset($parsedBody["firstname"])) {
		$user->firstname = $parsedBody["firstname"];
	}
	if (isset($parsedBody["lastname"])) {
		$user->lastname = $parsedBody["lastname"];
	}
	if (isset($parsedBody["email"])) {
		$user->email = $parsedBody["email"];
	}
	if (isset($parsedBody["role"])) {
		$user->role = $parsedBody["role"];
	}
	$user->save();
	
	return $response->withJson(UserMapper::mapUserToArray($user));
});
	
$app->delete('/users/{userid}', function ($request, $response, $args) {
	$this->logger->info("Klusbib DELETE on '/users/id' route");

	if (false === $this->token->hasScope(["users.all", "users.delete"])) {
		throw new ForbiddenException("Token not allowed to delete users.", 403);
	}
	
	$user = \Api\Model\User::find($args['userid']);
	if (null == $user) {
		return $response->withStatus(204);
	}
	$user->delete();
	return $response->withStatus(200);
});
		
	