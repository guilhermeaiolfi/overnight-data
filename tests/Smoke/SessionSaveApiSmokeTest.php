<?php

declare(strict_types=1);

namespace Tests\ON\Data\Smoke;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\Definition\Relation\M2MRelation;
use ON\Data\ORM\Session;
use ON\Data\Query\SelectQuery;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\Smoke\Support\SqliteMemoryHarness;

#[RequiresPhpExtension('pdo_sqlite')]
final class SessionSaveApiSmokeTest extends TestCase
{
	public function testCreateFlatRecordViaProjectionSyncFlush(): void
	{
		$harness = SqliteMemoryHarness::create();
		$harness->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
		[$users] = $this->flatRegistry();
		$session = new Session($harness->commandExecutor);
		$row = new stdClass();
		$row->name = 'Guilherme';

		$q = new SelectQuery($users);
		$map = $q->select($q->name)->projection();
		$session->create($row, $map)->from($users);
		$session->sync($row);
		$session->flush();

		self::assertSame(['name' => 'Guilherme'], $harness->fetchRow('SELECT name FROM users WHERE id = 1'));
	}

	public function testUpdateFlatRecordViaProjectionSyncFlush(): void
	{
		$harness = SqliteMemoryHarness::create();
		$harness->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
		$harness->exec("INSERT INTO users (id, name) VALUES (1, 'Old')");
		[$users] = $this->flatRegistry();
		$session = new Session($harness->commandExecutor);
		$row = new stdClass();
		$row->id = 1;
		$row->name = 'Updated name';

		$q = new SelectQuery($users);
		$map = $q->select($q->id, $q->name)->projection();
		$session->update($row, $map)->from($users);
		$session->sync($row);
		$session->flush();

		self::assertSame(['name' => 'Updated name'], $harness->fetchRow('SELECT name FROM users WHERE id = 1'));
	}

	public function testCreateWithAppAssignedPrimaryKeyInsertsThatKey(): void
	{
		$harness = SqliteMemoryHarness::create();
		$harness->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
		[$users] = $this->flatRegistry(autoIncrement: false);
		$session = new Session($harness->commandExecutor);
		$row = new stdClass();
		$row->id = 10;
		$row->name = 'Assigned';

		$q = new SelectQuery($users);
		$map = $q->select($q->id, $q->name)->projection();
		$session->create($row, $map)->from($users);
		$session->sync($row);
		$session->flush();

		self::assertSame(['id' => 10, 'name' => 'Assigned'], $harness->fetchRow('SELECT id, name FROM users WHERE id = 10'));
	}

	public function testUpdateSkipsMissingSelectedPropertyAndWritesExplicitNull(): void
	{
		$harness = SqliteMemoryHarness::create();
		$harness->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, email TEXT)');
		$harness->exec("INSERT INTO users (id, name, email) VALUES (1, 'Old', 'old@example.test')");
		[$users] = $this->flatRegistry();
		$session = new Session($harness->commandExecutor);
		$row = new stdClass();
		$row->id = 1;
		$row->email = null;

		$q = new SelectQuery($users);
		$map = $q->select($q->id, $q->name, $q->email)->projection();
		$session->update($row, $map)->from($users);
		$session->sync($row);
		$session->flush();

		self::assertSame(['name' => 'Old', 'email' => null], $harness->fetchRow('SELECT name, email FROM users WHERE id = 1'));
	}

	public function testAliasWritesFromAliasPath(): void
	{
		$harness = SqliteMemoryHarness::create();
		$harness->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, email TEXT)');
		[$users] = $this->flatRegistry();
		$session = new Session($harness->commandExecutor);
		$row = new stdClass();
		$row->displayName = 'Alias';

		$q = new SelectQuery($users);
		$map = $q->select($q->name->as('displayName'))->projection();
		$session->create($row, $map)->from($users);
		$session->sync($row);
		$session->flush();

		self::assertSame(['name' => 'Alias'], $harness->fetchRow('SELECT name FROM users WHERE id = 1'));
	}

	public function testFlatCreateToOneTarget(): void
	{
		$harness = SqliteMemoryHarness::create();
		$harness->exec('CREATE TABLE profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
		$harness->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, profile_id INTEGER, name TEXT)');
		$harness->exec("INSERT INTO users (id, profile_id, name) VALUES (1, NULL, 'Ada')");
		[$users] = $this->profileRegistry();
		$session = new Session($harness->commandExecutor);
		$user = new stdClass();
		$user->id = 1;
		$user->profileName = 'Public profile';

		$q = new SelectQuery($users);
		$map = $q->select($q->id, $q->profile->name->as('profileName'))->projection();
		$session->update($user, $map)->from($users)->create('profile');
		$session->sync($user);
		$session->flush();

		self::assertSame(['name' => 'Public profile'], $harness->fetchRow('SELECT name FROM profiles WHERE id = 1'));
		self::assertSame(['profile_id' => 1], $harness->fetchRow('SELECT profile_id FROM users WHERE id = 1'));
	}

	public function testFlatCreateM2MTarget(): void
	{
		$harness = SqliteMemoryHarness::create();
		$harness->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
		$harness->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT)');
		$harness->exec('CREATE TABLE user_post (user_id INTEGER, post_id INTEGER)');
		$harness->exec("INSERT INTO users (id, name) VALUES (1, 'Ada')");
		[$users] = $this->m2mRegistry();
		$session = new Session($harness->commandExecutor);
		$user = new stdClass();
		$user->id = 1;
		$user->newPostTitle = 'New M2M post';

		$q = new SelectQuery($users);
		$map = $q->select($q->id, $q->posts->title->as('newPostTitle'))->projection();
		$session->update($user, $map)->from($users)->create('posts');
		$session->sync($user);
		$session->flush();

		self::assertSame(['title' => 'New M2M post'], $harness->fetchRow('SELECT title FROM posts WHERE id = 1'));
		self::assertSame(['user_id' => 1, 'post_id' => 1], $harness->fetchRow('SELECT user_id, post_id FROM user_post'));
	}

	public function testOverlayExtendsQueryCreatedMutableObject(): void
	{
		$harness = SqliteMemoryHarness::create();
		$harness->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
		$harness->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT)');
		$harness->exec('CREATE TABLE user_post (user_id INTEGER, post_id INTEGER)');
		$harness->exec("INSERT INTO users (id, name) VALUES (1, 'Ada')");
		[$users] = $this->m2mRegistry();
		$session = new Session($harness->commandExecutor);
		$query = $harness->database->query($users);
		$query->select($query->id, $query->name);

		$user = $query->to(stdClass::class)->mutable($session)->fetchOne();
		self::assertInstanceOf(stdClass::class, $user);
		$user->newPostTitle = 'New post';

		$u = $harness->database->query($users);
		$extra = $u->select($u->posts->title->as('newPostTitle'))->projection();
		$session->update($user, $extra)->create('posts');
		$session->sync($user);
		$session->flush();

		self::assertSame(['name' => 'Ada'], $harness->fetchRow('SELECT name FROM users WHERE id = 1'));
		self::assertSame(['title' => 'New post'], $harness->fetchRow('SELECT title FROM posts WHERE id = 1'));
		self::assertSame(['user_id' => 1, 'post_id' => 1], $harness->fetchRow('SELECT user_id, post_id FROM user_post'));
	}

	public function testFlushDoesNotApplyPendingUpdateIntentWithoutSync(): void
	{
		$harness = SqliteMemoryHarness::create();
		$harness->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
		$harness->exec("INSERT INTO users (id, name) VALUES (1, 'Old')");
		[$users] = $this->flatRegistry();
		$session = new Session($harness->commandExecutor);
		$row = new stdClass();
		$row->id = 1;
		$row->name = 'Should not persist';

		$q = new SelectQuery($users);
		$map = $q->select($q->id, $q->name)->projection();
		$session->update($row, $map)->from($users);
		$session->flush();

		self::assertSame(['name' => 'Old'], $harness->fetchRow('SELECT name FROM users WHERE id = 1'));
	}

	public function testDetachIdentifiedChildFromUnloadedM2M(): void
	{
		$harness = SqliteMemoryHarness::create();
		$harness->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
		$harness->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT)');
		$harness->exec('CREATE TABLE user_post (user_id INTEGER, post_id INTEGER)');
		$harness->exec("INSERT INTO users (id, name) VALUES (1, 'Ada')");
		$harness->exec("INSERT INTO posts (id, title) VALUES (2, 'Keep'), (3, 'Drop')");
		$harness->exec('INSERT INTO user_post (user_id, post_id) VALUES (1, 2), (1, 3)');
		[$users, $posts] = $this->m2mRegistry(autoIncrementPosts: false);
		$session = new Session($harness->commandExecutor);
		$query = $harness->database->query($users);
		$query->select($query->id, $query->name);

		$user = $query->to(stdClass::class)->mutable($session)->fetchOne();
		self::assertInstanceOf(stdClass::class, $user);

		$session->detach($session->identify($posts, ['id' => 3]), $user, 'posts');
		$session->flush();

		self::assertSame(['post_id' => 2], $harness->fetchRow('SELECT post_id FROM user_post WHERE user_id = 1 AND post_id = 2'));
		self::assertNull($harness->fetchRow('SELECT post_id FROM user_post WHERE user_id = 1 AND post_id = 3'));
		self::assertSame(['title' => 'Drop'], $harness->fetchRow('SELECT title FROM posts WHERE id = 3'));
	}

	/**
	 * @return array{0: CollectionInterface}
	 */
	private function flatRegistry(bool $autoIncrement = true): array
	{
		$registry = new Registry();
		$users = $registry->collection('users')
			->table('users')
			->primaryKey('id')
			->field('id', 'int')->autoIncrement($autoIncrement)->end()
			->field('name', 'string')->end()
			->field('email', 'string')->end();

		return [$users];
	}

	/**
	 * @return array{0: CollectionInterface, 1: CollectionInterface}
	 */
	private function profileRegistry(): array
	{
		$registry = new Registry();
		$profiles = $registry->collection('profiles')
			->table('profiles')
			->primaryKey('id')
			->field('id', 'int')->autoIncrement(true)->end()
			->field('name', 'string')->end();
		$users = $registry->collection('users')
			->table('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('profile_id', 'int')->end()
			->field('name', 'string')->end();
		$users->belongsTo('profile', 'profiles')->innerKey('profile_id')->outerKey('id')->nullable(true);

		return [$users, $profiles];
	}

	/**
	 * @return array{0: CollectionInterface, 1: CollectionInterface}
	 */
	private function m2mRegistry(bool $autoIncrementPosts = true): array
	{
		$registry = new Registry();
		$posts = $registry->collection('posts')
			->table('posts')
			->primaryKey('id')
			->field('id', 'int')->autoIncrement($autoIncrementPosts)->end()
			->field('title', 'string')->end();
		$registry->collection('user_post')
			->table('user_post')
			->field('user_id', 'int')->end()
			->field('post_id', 'int')->end();
		$users = $registry->collection('users')
			->table('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();
		$users->relation('posts', M2MRelation::class)
			->collection('posts')
			->innerKey('id')
			->outerKey('id')
			->through('user_post')
				->innerKey('user_id')
				->outerKey('post_id')
				->end();

		return [$users, $posts];
	}
}
