<?php

/**
 * ownCloud - Activity App
 *
 * @author Joas Schilling
 * @copyright 2014 Joas Schilling nickvergessen@owncloud.com
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Affero General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\Activity\Tests;

use OC\ActivityManager;
use OCA\Activity\Data;
use OCA\Activity\Tests\Mock\Extension;
use OCP\Activity\IExtension;
use OCP\IUser;

class DataTest extends TestCase {
	/** @var \OCA\Activity\Data */
	protected $data;

	/** @var \OCP\IL10N */
	protected $activityLanguage;

	/** @var ActivityManager|\PHPUnit_Framework_MockObject_MockObject */
	protected $activityManager;

	/** @var \OCP\IUserSession|\PHPUnit_Framework_MockObject_MockObject */
	protected $session;

	protected function setUp() {
		parent::setUp();

		$this->activityLanguage = $activityLanguage = \OCP\Util::getL10N('activity', 'en');
		$this->activityManager = new ActivityManager(
			$this->getMock('OCP\IRequest'),
			$this->getMock('OCP\IUserSession'),
			$this->getMock('OCP\IConfig')
		);
		$this->session = $this->getMockBuilder('OCP\IUserSession')
			->disableOriginalConstructor()
			->getMock();

		$this->activityManager->registerExtension(function() use ($activityLanguage) {
			return new Extension($activityLanguage, $this->getMock('\OCP\IURLGenerator'));
		});
		$this->data = new Data(
			$this->activityManager,
			\OC::$server->getDatabaseConnection(),
			$this->session
		);
	}

	public function dataGetNotificationTypes() {
		return [
			['type1'],
		];
	}

	/**
	 * @dataProvider dataGetNotificationTypes
	 * @param string $typeKey
	 */
	public function testGetNotificationTypes($typeKey) {
		$this->assertArrayHasKey($typeKey, $this->data->getNotificationTypes($this->activityLanguage));
		// Check cached version aswell
		$this->assertArrayHasKey($typeKey, $this->data->getNotificationTypes($this->activityLanguage));
	}

	public function validateFilterData() {
		return array(
			// Default filters
			array('all', 'all'),
			array('by', 'by'),
			array('self', 'self'),

			// Filter from extension
			array('filter1', 'filter1'),

			// Inexistent or empty filter
			array('test', 'all'),
			array(null, 'all'),
		);
	}

	/**
	 * @dataProvider validateFilterData
	 *
	 * @param string $filter
	 * @param string $expected
	 */
	public function testValidateFilter($filter, $expected) {
		$this->assertEquals($expected, $this->data->validateFilter($filter));
	}

	public function dataSend() {
		return [
			// Default case
			['author', 'affectedUser', 'author', 'affectedUser', true],
			// Public page / Incognito mode
			['', 'affectedUser', '', 'affectedUser', true],
			// No affected user, falling back to author
			['author', '', 'author', 'author', true],
			// No affected user and no author => no activity
			['', '', '', '', false],
		];
	}

	/**
	 * @dataProvider dataSend
	 *
	 * @param string $actionUser
	 * @param string $affectedUser
	 */
	public function testSend($actionUser, $affectedUser, $expectedAuthor, $expectedAffected, $expectedActivity) {
		$mockSession = $this->getMockBuilder('\OC\User\Session')
			->disableOriginalConstructor()
			->getMock();

		if ($actionUser !== '') {
			$mockUser = $this->getMockBuilder('\OCP\IUser')
				->disableOriginalConstructor()
				->getMock();
			$mockUser->expects($this->any())
				->method('getUID')
				->willReturn($actionUser);

			$mockSession->expects($this->any())
				->method('getUser')
				->willReturn($mockUser);
		} else {
			$mockSession->expects($this->any())
				->method('getUser')
				->willReturn(null);
		}

		$this->overwriteService('UserSession', $mockSession);
		$this->deleteTestActivities();

		$this->assertSame($expectedActivity, Data::send('test', 'subject', [], '', [], '', '', $affectedUser, 'type', IExtension::PRIORITY_MEDIUM));

		$connection = \OC::$server->getDatabaseConnection();
		$query = $connection->prepare('SELECT `user`, `affecteduser` FROM `*PREFIX*activity` WHERE `app` = ? ORDER BY `activity_id` DESC');
		$query->execute(['test']);
		$row = $query->fetch();

		if ($expectedActivity) {
			$this->assertEquals(['user' => $expectedAuthor, 'affecteduser' => $expectedAffected], $row);
		} else {
			$this->assertFalse($row);
		}

		$this->deleteTestActivities();
		$this->restoreService('UserSession');
	}

	public function dataRead() {
		$user = $this->getMockBuilder('\OCP\IUser')
			->disableOriginalConstructor()
			->getMock();
		$user->expects($this->any())
			->method('getUID')
			->willReturn('username');

		return [
			[null, 0, 10, 'all', '', null, [], null, null, [], '', []],
			[$user, 0, 10, 'all', '', 'username', [], null, null, [], '', []],
			[$user, 0, 10, 'all', 'test', 'test', [], null, null, [], '', []],
			[$user, 0, 10, 'all', '', 'username', ['file_created'], false, null, [], ' AND `type` IN (?) AND `user` <> ?', ['username', 'file_created', 'username']],
			[$user, 0, 10, 'all', '', 'username', ['file_created'], true, null, [], ' AND `type` IN (?)', ['username', 'file_created']],
			[$user, 0, 10, 'by', '', 'username', ['file_created'], null, null, [], ' AND `type` IN (?) AND `user` <> ?', ['username', 'file_created', 'username']],
			[$user, 0, 10, 'self', '', 'username', ['file_created'], null, null, [], ' AND `type` IN (?) AND `user` = ?', ['username', 'file_created', 'username']],
			[$user, 0, 10, 'all', '', 'username', ['file_created'], true, 'OR `cond` = 1', null, ' AND `type` IN (?) OR `cond` = 1', ['username', 'file_created']],
			[$user, 0, 10, 'all', '', 'username', ['file_created'], true, 'OR `cond` = ?', ['con1'], ' AND `type` IN (?) OR `cond` = ?', ['username', 'file_created', 'con1']],
		];
	}

	/**
	 * @dataProvider dataRead
	 *
	 * @param IUser $sessionUser
	 * @param int $start
	 * @param int $count
	 * @param string $filter
	 * @param string $user
	 * @param string $expectedUser
	 * @param array $notificationTypes
	 * @param bool $selfSetting
	 * @param array $conditions
	 * @param array $params
	 * @param string $limitActivities
	 * @param array $parameters
	 */
	public function testRead($sessionUser, $start, $count, $filter, $user, $expectedUser, $notificationTypes, $selfSetting, $conditions, $params, $limitActivities, $parameters) {

		/** @var \OCA\Activity\GroupHelper|\PHPUnit_Framework_MockObject_MockObject $groupHelper */
		$groupHelper = $this->getMockBuilder('OCA\Activity\GroupHelper')
			->disableOriginalConstructor()
			->getMock();
		$groupHelper->expects(($expectedUser === null) ? $this->never() : $this->once())
			->method('setUser')
			->with($expectedUser);

		/** @var \OCA\Activity\UserSettings|\PHPUnit_Framework_MockObject_MockObject $settings */
		$settings = $this->getMockBuilder('OCA\Activity\UserSettings')
			->disableOriginalConstructor()
			->getMock();
		$settings->expects(($expectedUser === null) ? $this->never() : $this->once())
			->method('getNotificationTypes')
			->with($expectedUser, 'stream')
			->willReturn(['settings']);
		$settings->expects(($selfSetting === null) ? $this->never() : $this->any())
			->method('getUserSetting')
			->with($expectedUser, 'setting', 'self')
			->willReturn($selfSetting);

		/** @var ActivityManager|\PHPUnit_Framework_MockObject_MockObject $activityManager */
		$activityManager = $this->getMockBuilder('OCP\Activity\IManager')
			->disableOriginalConstructor()
			->getMock();
		$activityManager->expects($this->any())
			->method('filterNotificationTypes')
			->with(['settings'], $filter)
			->willReturn($notificationTypes);
		$activityManager->expects($this->any())
			->method('getQueryForFilter')
			->with($filter)
			->willReturn([$conditions, $params]);

		/** @var \OCA\Activity\Data|\PHPUnit_Framework_MockObject_MockObject $data */
		$data = $this->getMockBuilder('OCA\Activity\Data')
			->setConstructorArgs([
				$activityManager,
				\OC::$server->getDatabaseConnection(),
				$this->session
			])
			->setMethods(['getActivities'])
			->getMock();
		$data->expects($this->any())
			->method('getActivities')
			->with($count, $start, $limitActivities, $parameters, $groupHelper)
			->willReturn([]);

		$this->session->expects($this->any())
			->method('getUser')
			->willReturn($sessionUser);

		$this->assertEquals([], $data->read(
			$groupHelper, $settings,
			$start, $count, $filter, $user
		));
	}

	/**
	 * Delete all testing activities
	 */
	public function deleteTestActivities() {
		$connection = \OC::$server->getDatabaseConnection();
		$query = $connection->prepare('DELETE FROM `*PREFIX*activity` WHERE `app` = ?');
		$query->execute(['test']);
	}
}
