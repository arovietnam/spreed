<?php
/**
 * @copyright Copyright (c) 2016 Joas Schilling <coding@schilljs.com>
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

namespace OCA\Spreed\Tests\php\Notifications;

use OCA\Spreed\Chat\RichMessageHelper;
use OCA\Spreed\Exceptions\RoomNotFoundException;
use OCA\Spreed\Manager;
use OCA\Spreed\Notification\Notifier;
use OCA\Spreed\Room;
use OCP\Comments\IComment;
use OCP\Comments\ICommentsManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\RichObjectStrings\Definitions;

class NotifierTest extends \Test\TestCase {

	/** @var IFactory|\PHPUnit_Framework_MockObject_MockObject */
	protected $lFactory;
	/** @var IURLGenerator|\PHPUnit_Framework_MockObject_MockObject */
	protected $url;
	/** @var IUserManager|\PHPUnit_Framework_MockObject_MockObject */
	protected $userManager;
	/** @var Manager|\PHPUnit_Framework_MockObject_MockObject */
	protected $manager;
	/** @var ICommentsManager|\PHPUnit_Framework_MockObject_MockObject */
	protected $commentsManager;
	/** @var RichMessageHelper|\PHPUnit_Framework_MockObject_MockObject */
	protected $richMessageHelper;
	/** @var Definitions|\PHPUnit_Framework_MockObject_MockObject */
	protected $definitions;
	/** @var Notifier */
	protected $notifier;

	public function setUp() {
		parent::setUp();

		$this->lFactory = $this->createMock(IFactory::class);
		$this->url = $this->createMock(IURLGenerator::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->manager = $this->createMock(Manager::class);
		$this->commentsManager = $this->createMock(ICommentsManager::class);
		$this->richMessageHelper = $this->createMock(RichMessageHelper::class);
		$this->definitions = $this->createMock(Definitions::class);

		$this->notifier = new Notifier(
			$this->lFactory,
			$this->url,
			$this->userManager,
			$this->manager,
			$this->commentsManager,
			$this->richMessageHelper,
			$this->definitions
		);
	}

	public function dataPrepareOne2One() {
		return [
			['admin', 'Admin', 'Admin invited you to a private conversation'],
			['test', 'Test user', 'Test user invited you to a private conversation'],
		];
	}

	/**
	 * @dataProvider dataPrepareOne2One
	 * @param string $uid
	 * @param string $displayName
	 * @param string $parsedSubject
	 */
	public function testPrepareOne2One($uid, $displayName, $parsedSubject) {
		$n = $this->createMock(INotification::class);
		$l = $this->createMock(IL10N::class);
		$l->expects($this->any())
			->method('t')
			->will($this->returnCallback(function($text, $parameters = []) {
				return vsprintf($text, $parameters);
			}));

		$room = $this->createMock(Room::class);
		$room->expects($this->any())
			->method('getType')
			->willReturn(Room::ONE_TO_ONE_CALL);
		$room->expects($this->any())
			->method('getId')
			->willReturn(123);
		$this->manager->expects($this->once())
			->method('getRoomByToken')
			->willReturn($room);

		$this->lFactory->expects($this->once())
			->method('get')
			->with('spreed', 'de')
			->willReturn($l);

		$u = $this->createMock(IUser::class);
		$u->expects($this->exactly(2))
			->method('getDisplayName')
			->willReturn($displayName);
		$this->userManager->expects($this->once())
			->method('get')
			->with($uid)
			->willReturn($u);

		$n->expects($this->once())
			->method('setIcon')
			->willReturnSelf();
		$n->expects($this->once())
			->method('setLink')
			->willReturnSelf();
		$n->expects($this->once())
			->method('setParsedSubject')
			->with($parsedSubject)
			->willReturnSelf();
		$n->expects($this->once())
			->method('setRichSubject')
			->with('{user} invited you to a private conversation',[
				'user' => [
					'type' => 'user',
					'id' => $uid,
					'name' => $displayName,
				],
				'call' => [
					'type' => 'call',
					'id' => 123,
					'name' => 'a conversation',
					'call-type' => 'one2one'
				],
			])
			->willReturnSelf();

		$n->expects($this->once())
			->method('getApp')
			->willReturn('spreed');
		$n->expects($this->once())
			->method('getSubject')
			->willReturn('invitation');
		$n->expects($this->once())
			->method('getSubjectParameters')
			->willReturn([$uid]);
		$n->expects($this->once())
			->method('getObjectType')
			->willReturn('room');

		$this->notifier->prepare($n, 'de');
	}

	public function dataPrepareGroup() {
		return [
			[Room::GROUP_CALL, 'admin', 'Admin', '', 'Admin invited you to a group conversation'],
			[Room::PUBLIC_CALL, 'test', 'Test user', 'Name', 'Test user invited you to a group conversation: Name'],
		];
	}

	/**
	 * @dataProvider dataPrepareGroup
	 * @param int $type
	 * @param string $uid
	 * @param string $displayName
	 * @param string $name
	 * @param string $parsedSubject
	 */
	public function testPrepareGroup($type, $uid, $displayName, $name, $parsedSubject) {
		$roomId = $type;
		$n = $this->createMock(INotification::class);
		$l = $this->createMock(IL10N::class);
		$l->expects($this->any())
			->method('t')
			->will($this->returnCallback(function($text, $parameters = []) {
				return vsprintf($text, $parameters);
			}));

		$room = $this->createMock(Room::class);
		$room->expects($this->atLeastOnce())
			->method('getType')
			->willReturn($type);
		$room->expects($this->atLeastOnce())
			->method('getName')
			->willReturn($name);
		$this->manager->expects($this->once())
			->method('getRoomByToken')
			->willReturn($room);

		$this->lFactory->expects($this->once())
			->method('get')
			->with('spreed', 'de')
			->willReturn($l);

		$u = $this->createMock(IUser::class);
		$u->expects($this->exactly(2))
			->method('getDisplayName')
			->willReturn($displayName);
		$this->userManager->expects($this->once())
			->method('get')
			->with($uid)
			->willReturn($u);

		$n->expects($this->once())
			->method('setIcon')
			->willReturnSelf();
		$n->expects($this->once())
			->method('setLink')
			->willReturnSelf();
		$n->expects($this->once())
			->method('setParsedSubject')
			->with($parsedSubject)
			->willReturnSelf();

		$room->expects($this->once())
			->method('getId')
			->willReturn($roomId);

		if ($name === '') {
			$n->expects($this->once())
				->method('setRichSubject')
				->with('{user} invited you to a group conversation',[
					'user' => [
						'type' => 'user',
						'id' => $uid,
						'name' => $displayName,
					],
					'call' => [
						'type' => 'call',
						'id' => $roomId,
						'name' => 'a conversation',
						'call-type' => 'group',
					],
				])
				->willReturnSelf();
		} else {
			$n->expects($this->once())
				->method('setRichSubject')
				->with('{user} invited you to a group conversation: {call}', [
					'user' => [
						'type' => 'user',
						'id' => $uid,
						'name' => $displayName,
					],
					'call' => [
						'type' => 'call',
						'id' => $roomId,
						'name' => $name,
						'call-type' => 'public',
					],
				])
				->willReturnSelf();
		}

		$n->expects($this->once())
			->method('getApp')
			->willReturn('spreed');
		$n->expects($this->once())
			->method('getSubject')
			->willReturn('invitation');
		$n->expects($this->once())
			->method('getSubjectParameters')
			->willReturn([$uid]);
		$n->expects($this->once())
			->method('getObjectType')
			->willReturn('room');

		$this->notifier->prepare($n, 'de');
	}

	public function dataPrepareMention() {
		return [
			[
				Room::ONE_TO_ONE_CALL, ['userType' => 'users', 'userId' => 'testUser'], 'Test user', '',
				'Test user mentioned you in a private conversation',
				['{user} mentioned you in a private conversation',
					[
						'user' => ['type' => 'user', 'id' => 'testUser', 'name' => 'Test user'],
						'call' => ['type' => 'call', 'id' => 'testRoomId', 'name' => 'a conversation', 'call-type' => 'one2one'],
					]
				],
			],
			// If the user is deleted in a one to one conversation the conversation is also
			// deleted, and that in turn would delete the pending notification.
			[
				Room::GROUP_CALL,      ['userType' => 'users', 'userId' => 'testUser'], 'Test user', '',
				'Test user mentioned you in a group conversation',
				['{user} mentioned you in a group conversation',
					[
						'user' => ['type' => 'user', 'id' => 'testUser', 'name' => 'Test user'],
						'call' => ['type' => 'call', 'id' => 'testRoomId', 'name' => 'a conversation', 'call-type' => 'group'],
					]
				],
			],
			[
				Room::GROUP_CALL,      ['userType' => 'users', 'userId' => 'testUser'], null,        '',
				'You were mentioned in a group conversation by a deleted user',
				['You were mentioned in a group conversation by a deleted user',
					[
						'call' => ['type' => 'call', 'id' => 'testRoomId', 'name' => 'a conversation', 'call-type' => 'group'],
					]
				],
				true],
			[
				Room::GROUP_CALL,      ['userType' => 'users', 'userId' => 'testUser'], 'Test user', 'Room name',
				'Test user mentioned you in a group conversation: Room name',
				['{user} mentioned you in a group conversation: {call}',
					[
						'user' => ['type' => 'user', 'id' => 'testUser', 'name' => 'Test user'],
						'call' => ['type' => 'call', 'id' => 'testRoomId', 'name' => 'Room name', 'call-type' => 'group']
					]
				],
			],
			[
				Room::GROUP_CALL,      ['userType' => 'users', 'userId' => 'testUser'], null,        'Room name',
				'You were mentioned in a group conversation by a deleted user: Room name',
				['You were mentioned in a group conversation by a deleted user: {call}',
					[
						'call' => ['type' => 'call', 'id' => 'testRoomId', 'name' => 'Room name', 'call-type' => 'group']
					]
				],
				true],
			[
				Room::PUBLIC_CALL,     ['userType' => 'users', 'userId' => 'testUser'], 'Test user', '',
				'Test user mentioned you in a group conversation',
				['{user} mentioned you in a group conversation',
					[
						'user' => ['type' => 'user', 'id' => 'testUser', 'name' => 'Test user'],
						'call' => ['type' => 'call', 'id' => 'testRoomId', 'name' => 'a conversation', 'call-type' => 'public'],
					]
				],
			],
			[
				Room::PUBLIC_CALL,     ['userType' => 'users', 'userId' => 'testUser'], null,        '',
				'You were mentioned in a group conversation by a deleted user',
				['You were mentioned in a group conversation by a deleted user',
					[
						'call' => ['type' => 'call', 'id' => 'testRoomId', 'name' => 'a conversation', 'call-type' => 'public']
					]
				],
				true],
			[
				Room::PUBLIC_CALL,     ['userType' => 'guests', 'userId' => 'testSpreedSession'], null,        '',
				'A guest mentioned you in a group conversation',
				['A guest mentioned you in a group conversation',
					[
						'call' => ['type' => 'call', 'id' => 'testRoomId', 'name' => 'a conversation', 'call-type' => 'public']
					]
				],
			],
			[
				Room::PUBLIC_CALL,     ['userType' => 'users', 'userId' => 'testUser'], 'Test user', 'Room name',
				'Test user mentioned you in a group conversation: Room name',
				['{user} mentioned you in a group conversation: {call}',
					[
						'user' => ['type' => 'user', 'id' => 'testUser', 'name' => 'Test user'],
						'call' => ['type' => 'call', 'id' => 'testRoomId', 'name' => 'Room name', 'call-type' => 'public']
					]
				],
			],
			[
				Room::PUBLIC_CALL,     ['userType' => 'users', 'userId' => 'testUser'], null,    'Room name',
				'You were mentioned in a group conversation by a deleted user: Room name',
				['You were mentioned in a group conversation by a deleted user: {call}',
					[
						'call' => ['type' => 'call', 'id' => 'testRoomId', 'name' => 'Room name', 'call-type' => 'public']
					]
				],
				true],
			[
				Room::PUBLIC_CALL,     ['userType' => 'guests', 'userId' => 'testSpreedSession'], null,    'Room name',
				'A guest mentioned you in a group conversation: Room name',
				['A guest mentioned you in a group conversation: {call}',
					['call' => ['type' => 'call', 'id' => 'testRoomId', 'name' => 'Room name', 'call-type' => 'public']]
				],
			]
		];
	}

	/**
	 * @dataProvider dataPrepareMention
	 * @param int $roomType
	 * @param array $subjectParameters
	 * @param string $displayName
	 * @param string $roomName
	 * @param string $parsedSubject
	 * @param array $richSubject
	 * @param bool $deletedUser
	 */
	public function testPrepareMention($roomType, $subjectParameters, $displayName, $roomName, $parsedSubject, $richSubject, $deletedUser = false) {
		$notification = $this->createMock(INotification::class);
		$l = $this->createMock(IL10N::class);
		$l->expects($this->any())
			->method('t')
			->will($this->returnCallback(function($text, $parameters = []) {
				return vsprintf($text, $parameters);
			}));

		$room = $this->createMock(Room::class);
		$room->expects($this->atLeastOnce())
			->method('getType')
			->willReturn($roomType);
		$room->expects($this->any())
			->method('getId')
			->willReturn('testRoomId');
		$room->expects($this->atLeastOnce())
			->method('getName')
			->willReturn($roomName);
		if ($roomName !== '') {
			$room->expects($this->atLeastOnce())
				->method('getId')
				->willReturn('testRoomId');
		}
		$this->manager->expects($this->once())
			->method('getRoomByToken')
			->willReturn($room);

		$this->lFactory->expects($this->once())
			->method('get')
			->with('spreed', 'de')
			->willReturn($l);

		$user = $this->createMock(IUser::class);
		if ($subjectParameters['userType'] === 'users' && !$deletedUser) {
			$user->expects($this->exactly(2))
				->method('getDisplayName')
				->willReturn($displayName);
			$this->userManager->expects($this->once())
				->method('get')
				->with($subjectParameters['userId'])
				->willReturn($user);
		} else if ($subjectParameters['userType'] === 'users' && $deletedUser) {
			$user->expects($this->never())
				->method('getDisplayName');
			$this->userManager->expects($this->once())
				->method('get')
				->with($subjectParameters['userId'])
				->willReturn(null);
		} else {
			$user->expects($this->never())
				->method('getDisplayName');
			$this->userManager->expects($this->never())
				->method('get');
		}

		$comment = $this->createMock(IComment::class);
		$this->commentsManager->expects($this->once())
			->method('get')
			->with('23')
			->willReturn($comment);
		$this->richMessageHelper->expects($this->once())
			->method('getRichMessage')
			->with($comment)
			->willReturn(['Hi {mention-user1}', [
				'mention-user1' => [
					'type' => 'user',
					'id' => 'admin',
					'name' => 'Administrator',
				],
			]]);

		$notification->expects($this->once())
			->method('setIcon')
			->willReturnSelf();
		$notification->expects($this->once())
			->method('setLink')
			->willReturnSelf();
		$notification->expects($this->once())
			->method('setParsedSubject')
			->with($parsedSubject)
			->willReturnSelf();
		$notification->expects($this->once())
			->method('setRichSubject')
			->with($richSubject[0], $richSubject[1])
			->willReturnSelf();
		$notification->expects($this->once())
			->method('setParsedMessage')
			->with('Hi @Administrator')
			->willReturnSelf();

		$notification->expects($this->once())
			->method('getApp')
			->willReturn('spreed');
		$notification->expects($this->exactly(2))
			->method('getSubject')
			->willReturn('mention');
		$notification->expects($this->once())
			->method('getSubjectParameters')
			->willReturn($subjectParameters);
		$notification->expects($this->once())
			->method('getObjectType')
			->willReturn('chat');
		$notification->expects($this->once())
			->method('getMessageParameters')
			->willReturn(['commentId' => '23']);

		$this->assertEquals($notification, $this->notifier->prepare($notification, 'de'));
	}

	public function dataPrepareThrows() {
		return [
			['Incorrect app', 'invalid-app', null, null, null, null],
			['Invalid room', 'spreed', false, null, null, null],
			['Unknown subject', 'spreed', true, 'invalid-subject', null, null],
			['Unknown object type', 'spreed', true, 'invitation', null, 'invalid-object-type'],
			['Calling user does not exist anymore', 'spreed', true, 'invitation', ['admin'], 'room'],
			['Unknown object type', 'spreed', true, 'mention', null, 'invalid-object-type'],
		];
	}

	/**
	 * @dataProvider dataPrepareThrows
	 *
	 * @expectedException \InvalidArgumentException
	 *
	 * @param string $message
	 * @param string $app
	 * @param bool|null $validRoom
	 * @param string|null $subject
	 * @param array|null $params
	 * @param string|null $objectType
	 */
	public function testPrepareThrows($message, $app, $validRoom, $subject, $params, $objectType) {
		$n = $this->createMock(INotification::class);
		$l = $this->createMock(IL10N::class);

		if ($validRoom === null) {
			$this->manager->expects($this->never())
				->method('getRoomByToken');
		} else if ($validRoom === true) {
			$room = $this->createMock(Room::class);
			$room->expects($this->never())
				->method('getType');
			$this->manager->expects($this->once())
				->method('getRoomByToken')
				->willReturn($room);
		} else if ($validRoom === false) {
			$this->manager->expects($this->once())
				->method('getRoomByToken')
				->willThrowException(new RoomNotFoundException());
			$this->manager->expects($this->once())
				->method('getRoomById')
				->willThrowException(new RoomNotFoundException());
		}

		$this->lFactory->expects($validRoom === null ? $this->never() : $this->once())
			->method('get')
			->with('spreed', 'de')
			->willReturn($l);

		$n->expects($validRoom !== true ? $this->never() : $this->once())
			->method('setIcon')
			->willReturnSelf();
		$n->expects($validRoom !== true ? $this->never() : $this->once())
			->method('setLink')
			->willReturnSelf();

		$n->expects($this->once())
			->method('getApp')
			->willReturn($app);
		$n->expects($subject === null ? $this->never() : $this->atLeastOnce())
			->method('getSubject')
			->willReturn($subject);
		$n->expects($params === null ? $this->never() : $this->once())
			->method('getSubjectParameters')
			->willReturn($params);
		$n->expects($objectType === null ? $this->never() : $this->once())
			->method('getObjectType')
			->willReturn($objectType);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage($message);
		$this->notifier->prepare($n, 'de');
	}
}
