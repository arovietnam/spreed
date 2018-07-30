<?php
/**
 *
 * @copyright Copyright (c) 2018, Joachim Bauch (bauch@struktur.de)
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Spreed\Tests\php\Signaling;

use OCA\Spreed\AppInfo\Application;
use OCA\Spreed\Chat\CommentsManager;
use OCA\Spreed\Config;
use OCA\Spreed\Manager;
use OCA\Spreed\Participant;
use OCA\Spreed\Room;
use OCA\Spreed\Signaling\BackendNotifier;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClientService;
use OCP\ILogger;
use OCP\IUser;
use OCP\Security\IHasher;
use OCP\Security\ISecureRandom;

class CustomBackendNotifier extends BackendNotifier {

	private $requests = [];

	public function getRequests(): array {
		return $this->requests;
	}

	public function clearRequests() {
		$this->requests = [];
	}

	protected function doRequest($url, $params) {
		$this->requests[] = [
			'url' => $url,
			'params' => $params,
		];
	}

}

class CustomApplication extends Application {

	private $notifier;

	public function setBackendNotifier($notifier) {
		$this->notifier = $notifier;
	}

	protected function getBackendNotifier() {
		return $this->notifier;
	}

}

/**
 * @group DB
 */
class BackendNotifierTest extends \Test\TestCase {

	/** @var Config */
	private $config;

	/** @var ISecureRandom */
	private $secureRandom;

	/** @var CustomBackendNotifier */
	private $controller;

	/** @var Manager */
	private $manager;

	/** @var string */
	private $userId;

	public function setUp() {
		parent::setUp();
		// Make sure necessary database tables are set up.
		\OC_App::updateApp('spreed');

		$this->userId = 'testUser';
		$this->secureRandom = \OC::$server->getSecureRandom();
		$timeFactory = $this->createMock(ITimeFactory::class);
		$config = \OC::$server->getConfig();
		$this->signalingSecret = 'the-signaling-secret';
		$this->baseUrl = 'https://localhost/signaling';
		$config->setAppValue('spreed', 'signaling_servers', json_encode([
			'secret' => $this->signalingSecret,
			'servers' => [
				[
					'server' => $this->baseUrl,
				],
			],
		]));

		$this->config = new Config($config, $this->secureRandom, $timeFactory);
		$this->recreateBackendNotifier();

		$app = new CustomApplication();
		$app->setBackendNotifier($this->controller);
		$app->register();

		\OC::$server->registerService(BackendNotifier::class, function() {
			return $this->controller;
		});

		$dbConnection = \OC::$server->getDatabaseConnection();
		$dispatcher = \OC::$server->getEventDispatcher();
		$this->manager = new Manager($dbConnection, $config, $this->secureRandom, $this->createMock(CommentsManager::class), $dispatcher, $this->createMock(IHasher::class));
	}

	private function recreateBackendNotifier() {
		$this->controller = new CustomBackendNotifier(
			$this->config,
			$this->createMock(ILogger::class),
			$this->createMock(IClientService::class),
			$this->secureRandom
		);
	}

	private function calculateBackendChecksum($data, $random) {
		if (empty($random) || strlen($random) < 32) {
			return false;
		}
		$hash = hash_hmac('sha256', $random . $data, $this->signalingSecret);
		return $hash;
	}

	private function validateBackendRequest($expectedUrl, $request) {
		$this->assertTrue(isset($request));
		$this->assertEquals($expectedUrl, $request['url']);
		$headers = $request['params']['headers'];
		$this->assertEquals('application/json', $headers['Content-Type']);
		$random = $headers['Spreed-Signaling-Random'];
		$checksum = $headers['Spreed-Signaling-Checksum'];
		$body = $request['params']['body'];
		$this->assertEquals($this->calculateBackendChecksum($body, $random), $checksum);
		return $body;
	}

	public function testRoomInvite() {
		$room = $this->manager->createPublicRoom();
		$room->addUsers([
			'userId' => $this->userId,
		]);

		$requests = $this->controller->getRequests();
		$bodies = array_map(function($request) use ($room) {
			return json_decode($this->validateBackendRequest($this->baseUrl . '/api/v1/room/' . $room->getToken(), $request), true);
		}, $requests);
		$this->assertContains([
			'type' => 'invite',
			'invite' => [
				'userids' => [
					$this->userId,
				],
				'alluserids' => [
					$this->userId,
				],
				'properties' => [
					'name' => $room->getName(),
					'type' => $room->getType(),
				],
			],
		], $bodies);
	}

	public function testRoomDisinvite() {
		$room = $this->manager->createPublicRoom();
		$room->addUsers([
			'userId' => $this->userId,
		]);
		$this->controller->clearRequests();
		$testUser = $this->createMock(IUser::class);
		$testUser
			->method('getUID')
			->willReturn($this->userId);
		$room->removeUser($testUser);

		$requests = $this->controller->getRequests();
		$bodies = array_map(function($request) use ($room) {
			return json_decode($this->validateBackendRequest($this->baseUrl . '/api/v1/room/' . $room->getToken(), $request), true);
		}, $requests);
		$this->assertContains([
			'type' => 'disinvite',
			'disinvite' => [
				'userids' => [
					$this->userId,
				],
				'alluserids' => [
				],
				'properties' => [
					'name' => $room->getName(),
					'type' => $room->getType(),
				],
			],
		], $bodies);
	}

	public function testRoomNameChanged() {
		$room = $this->manager->createPublicRoom();
		$room->setName('Test room');

		$requests = $this->controller->getRequests();
		$bodies = array_map(function($request) use ($room) {
			return json_decode($this->validateBackendRequest($this->baseUrl . '/api/v1/room/' . $room->getToken(), $request), true);
		}, $requests);
		$this->assertContains([
			'type' => 'update',
			'update' => [
				'userids' => [
				],
				'properties' => [
					'name' => $room->getName(),
					'type' => $room->getType(),
				],
			],
		], $bodies);
	}

	public function testRoomDelete() {
		$room = $this->manager->createPublicRoom();
		$room->addUsers([
			'userId' => $this->userId,
		]);
		$room->deleteRoom();

		$requests = $this->controller->getRequests();
		$bodies = array_map(function($request) use ($room) {
			return json_decode($this->validateBackendRequest($this->baseUrl . '/api/v1/room/' . $room->getToken(), $request), true);
		}, $requests);
		$this->assertContains([
			'type' => 'delete',
			'delete' => [
				'userids' => [
					$this->userId,
				],
			],
		], $bodies);
	}

	public function testRoomInCallChanged() {
		$room = $this->manager->createPublicRoom();
		$userSession = 'user-session';
		$room->addUsers([
			'userId' => $this->userId,
			'sessionId' => $userSession,
		]);
		$room->changeInCall($userSession, Participant::FLAG_IN_CALL | Participant::FLAG_WITH_AUDIO | Participant::FLAG_WITH_VIDEO);

		$requests = $this->controller->getRequests();
		$bodies = array_map(function($request) use ($room) {
			return json_decode($this->validateBackendRequest($this->baseUrl . '/api/v1/room/' . $room->getToken(), $request), true);
		}, $requests);
		$this->assertContains([
			'type' => 'incall',
			'incall' => [
				'incall' => 7,
				'changed' => [
					[
						'inCall' => 7,
						'lastPing' => 0,
						'sessionId' => $userSession,
						'participantType' => Participant::USER,
						'userId' => $this->userId,
					],
				],
				'users' => [
					[
						'inCall' => 7,
						'lastPing' => 0,
						'sessionId' => $userSession,
						'participantType' => Participant::USER,
						'userId' => $this->userId,
					],
				],
			],
		], $bodies);

		$this->controller->clearRequests();
		$guestSession = $room->joinRoomGuest('');
		$room->changeInCall($guestSession, Participant::FLAG_IN_CALL);

		$requests = $this->controller->getRequests();
		$bodies = array_map(function($request) use ($room) {
			return json_decode($this->validateBackendRequest($this->baseUrl . '/api/v1/room/' . $room->getToken(), $request), true);
		}, $requests);
		$this->assertContains([
			'type' => 'incall',
			'incall' => [
				'incall' => 1,
				'changed' => [
					[
						'inCall' => 1,
						'lastPing' => 0,
						'sessionId' => $guestSession,
						'participantType' => Participant::GUEST,
					],
				],
				'users' => [
					[
						'inCall' => 7,
						'lastPing' => 0,
						'sessionId' => $userSession,
						'participantType' => Participant::USER,
						'userId' => $this->userId,
					],
					[
						'inCall' => 1,
						'lastPing' => 0,
						'sessionId' => $guestSession,
						'participantType' => Participant::GUEST,
					],
				],
			],
		], $bodies);

		$this->controller->clearRequests();
		$room->changeInCall($userSession, Participant::FLAG_DISCONNECTED);

		$requests = $this->controller->getRequests();
		$bodies = array_map(function($request) use ($room) {
			return json_decode($this->validateBackendRequest($this->baseUrl . '/api/v1/room/' . $room->getToken(), $request), true);
		}, $requests);
		$this->assertContains([
			'type' => 'incall',
			'incall' => [
				'incall' => 0,
				'changed' => [
					[
						'inCall' => 0,
						'lastPing' => 0,
						'sessionId' => $userSession,
						'participantType' => Participant::USER,
						'userId' => $this->userId,
					],
				],
				'users' => [
					[
						'inCall' => 1,
						'lastPing' => 0,
						'sessionId' => $guestSession,
						'participantType' => Participant::GUEST,
					],
				],
			],
		], $bodies);
	}

}
