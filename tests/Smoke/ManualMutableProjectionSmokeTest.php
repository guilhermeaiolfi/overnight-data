<?php

declare(strict_types=1);

namespace Tests\ON\Data\Smoke;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\Definition\Relation\M2MRelation;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Session;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\Smoke\Support\SqliteMemoryHarness;

#[RequiresPhpExtension('pdo_sqlite')]
final class ManualMutableProjectionSmokeTest extends TestCase
{
	public function testManualRepresentationCreatesFlatRecord(): void
	{
		$harness = SqliteMemoryHarness::create();
		$harness->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
		[$users] = $this->flatRegistry();
		$session = new Session($harness->commandExecutor);
		$row = new stdClass();
		$row->name = 'Guilherme';

		$p = $session->projection($row);
		$u = $p->from($users)->create();
		$p->properties($u->name)->end();

		$session->flush();

		self::assertSame(['name' => 'Guilherme'], $harness->fetchRow('SELECT name FROM users WHERE id = 1'));
	}

	public function testManualRepresentationUpdatesExistingFlatRecord(): void
	{
		$harness = SqliteMemoryHarness::create();
		$harness->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
		$harness->exec("INSERT INTO users (id, name) VALUES (1, 'Old')");
		[$users] = $this->flatRegistry();
		$session = new Session($harness->commandExecutor);
		$row = new stdClass();
		$row->name = 'Updated name';

		$p = $session->projection($row);
		$u = $p->from($users)->existing(['id' => 1], ['id' => 1, 'name' => 'Old']);
		$p->properties($u->name)->end();

		$session->flush();

		self::assertSame(['name' => 'Updated name'], $harness->fetchRow('SELECT name FROM users WHERE id = 1'));
	}

	public function testManualRepresentationTreatsAppAssignedPrimaryKeyInCreateAsInsert(): void
	{
		$harness = SqliteMemoryHarness::create();
		$harness->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
		[$users] = $this->flatRegistry(autoIncrement: false);
		$session = new Session($harness->commandExecutor);
		$row = new stdClass();
		$row->name = 'Assigned';

		$p = $session->projection($row);
		$u = $p->from($users)->create(['id' => 10]);
		$p->properties($u->name)->end();

		$session->flush();

		self::assertSame(['id' => 10, 'name' => 'Assigned'], $harness->fetchRow('SELECT id, name FROM users WHERE id = 10'));
	}

	public function testManualRepresentationSkipsMissingSelectedPropertyAndWritesExplicitNull(): void
	{
		$harness = SqliteMemoryHarness::create();
		$harness->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, email TEXT)');
		$harness->exec("INSERT INTO users (id, name, email) VALUES (1, 'Old', 'old@example.test')");
		[$users] = $this->flatRegistry();
		$session = new Session($harness->commandExecutor);
		$row = new stdClass();
		$row->email = null;

		$p = $session->projection($row);
		$u = $p->from($users)->existing(['id' => 1], ['id' => 1, 'name' => 'Old', 'email' => 'old@example.test']);
		$p->properties($u->name, $u->email)->end();

		$session->flush();

		self::assertSame(['name' => 'Old', 'email' => null], $harness->fetchRow('SELECT name, email FROM users WHERE id = 1'));
	}

	public function testManualRepresentationAliasWritesFromAliasPath(): void
	{
		$harness = SqliteMemoryHarness::create();
		$harness->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, email TEXT)');
		[$users] = $this->flatRegistry();
		$session = new Session($harness->commandExecutor);
		$row = new stdClass();
		$row->displayName = 'Alias';

		$p = $session->projection($row);
		$u = $p->from($users)->create();
		$p->properties($u->name->as('displayName'))->end();

		$session->flush();

		self::assertSame(['name' => 'Alias'], $harness->fetchRow('SELECT name FROM users WHERE id = 1'));
	}

	public function testSelectingManyRelationFieldWithoutConcreteItemThrows(): void
	{
		[$users] = $this->m2mRegistry();
		$session = new Session(SqliteMemoryHarness::create()->commandExecutor);
		$row = new stdClass();
		$p = $session->projection($row);
		$u = $p->from($users)->create(['id' => 1]);
		$p->properties($u->posts->title->as('postTitle'));

		$this->expectException(StateException::class);
		$this->expectExceptionMessage('without first creating or identifying one concrete relation item');

		$p->end();
	}

	public function testManualRepresentationCreatesFlattenedToOneTarget(): void
	{
		$harness = SqliteMemoryHarness::create();
		$harness->exec('CREATE TABLE profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
		$harness->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, profile_id INTEGER, name TEXT)');
		$harness->exec("INSERT INTO users (id, profile_id, name) VALUES (1, NULL, 'Ada')");
		[$users] = $this->profileRegistry();
		$session = new Session($harness->commandExecutor);
		$user = new stdClass();
		$user->profileName = 'Public profile';

		$p = $session->projection($user);
		$u = $p->from($users)->existing(['id' => 1], ['id' => 1, 'profile_id' => null, 'name' => 'Ada']);
		$profile = $p->create($u->profile);
		$p->properties($profile->name->as('profileName'))->end();

		$session->flush();

		self::assertSame(['name' => 'Public profile'], $harness->fetchRow('SELECT name FROM profiles WHERE id = 1'));
		self::assertSame(['profile_id' => 1], $harness->fetchRow('SELECT profile_id FROM users WHERE id = 1'));
	}

	public function testManualRepresentationCreatesFlattenedM2MTarget(): void
	{
		$harness = SqliteMemoryHarness::create();
		$harness->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
		$harness->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT)');
		$harness->exec('CREATE TABLE user_post (user_id INTEGER, post_id INTEGER)');
		$harness->exec("INSERT INTO users (id, name) VALUES (1, 'Ada')");
		[$users] = $this->m2mRegistry();
		$session = new Session($harness->commandExecutor);
		$user = new stdClass();
		$user->newPostTitle = 'New M2M post';

		$p = $session->projection($user);
		$u = $p->from($users)->existing(['id' => 1], ['id' => 1, 'name' => 'Ada']);
		$post = $p->create($u->posts);
		$p->properties($post->title->as('newPostTitle'))->end();

		$session->flush();

		self::assertSame(['title' => 'New M2M post'], $harness->fetchRow('SELECT title FROM posts WHERE id = 1'));
		self::assertSame(['user_id' => 1, 'post_id' => 1], $harness->fetchRow('SELECT user_id, post_id FROM user_post'));
	}

	public function testManualOverlayAttachesRootFieldToOwnRecordNotAnotherSameCollectionRecord(): void
	{
		$harness = SqliteMemoryHarness::create();
		$harness->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)');
		$harness->exec("INSERT INTO users (id, name, email) VALUES (1, 'Ada', 'ada@old.test')");
		$harness->exec("INSERT INTO users (id, name, email) VALUES (2, 'Bob', 'bob@old.test')");
		[$users] = $this->flatRegistry(autoIncrement: false);
		$session = new Session($harness->commandExecutor);

		$ada = new stdClass();
		$ada->name = 'Ada';
		$pa = $session->projection($ada);
		$a = $pa->from($users)->existing(['id' => 1], ['id' => 1, 'name' => 'Ada', 'email' => 'ada@old.test']);
		$pa->properties($a->name)->end();

		$bob = new stdClass();
		$bob->name = 'Bob';
		$pb = $session->projection($bob);
		$b = $pb->from($users)->existing(['id' => 2], ['id' => 2, 'name' => 'Bob', 'email' => 'bob@old.test']);
		$pb->properties($b->name)->end();

		$bob->email = 'bob@new.test';
		$overlay = $session->projection($bob);
		$u = $overlay->from($users)->tracked();
		$overlay->properties($u->email)->end();

		$session->flush();

		self::assertSame(['email' => 'bob@new.test'], $harness->fetchRow('SELECT email FROM users WHERE id = 2'));
		self::assertSame(['email' => 'ada@old.test'], $harness->fetchRow('SELECT email FROM users WHERE id = 1'));
	}

	public function testManualRepresentationExtendsQueryCreatedMutableObject(): void
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

		$p = $session->projection($user);
		$u = $p->from($users)->tracked();
		$post = $p->create($u->posts);
		$p->properties($post->title->as('newPostTitle'))->end();

		$session->flush();

		self::assertSame(['name' => 'Ada'], $harness->fetchRow('SELECT name FROM users WHERE id = 1'));
		self::assertSame(['title' => 'New post'], $harness->fetchRow('SELECT title FROM posts WHERE id = 1'));
		self::assertSame(['user_id' => 1, 'post_id' => 1], $harness->fetchRow('SELECT user_id, post_id FROM user_post'));
	}

	public function testFromPathCreatesObjectShapedTargetWithoutSelect(): void
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
		$query->posts->fields('id', 'title');

		$user = $query->to(stdClass::class)->mutable($session)->fetchOne();
		self::assertInstanceOf(stdClass::class, $user);

		$post = new stdClass();
		$post->title = 'Path-created post';
		$session
			->projection($post)
			->fromPath($user, 'posts')
			->create()
			->end();

		$relation = $session->getToManyRelations()->get($session->getRecords()->getFromRepresentation($session->getRepresentations()->get($user)), 'posts');
		self::assertNotNull($relation);
		self::assertSame([$post], $relation->getAdded());

		$session->flush();

		self::assertSame(['title' => 'Path-created post'], $harness->fetchRow('SELECT title FROM posts WHERE id = 1'));
		self::assertSame(['user_id' => 1, 'post_id' => 1], $harness->fetchRow('SELECT user_id, post_id FROM user_post'));
	}

	public function testFromPathFlattenedAliasSelectionUsesRelatedBinding(): void
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
		$query->posts->fields('id', 'title');

		$user = $query->to(stdClass::class)->mutable($session)->fetchOne();
		self::assertInstanceOf(stdClass::class, $user);
		$user->newPostTitle = 'Path alias post';

		$p = $session->projection($user);
		$post = $p->fromPath($user, 'posts')->create();
		$p->properties($post->title->as('newPostTitle'))->end();

		$session->flush();

		self::assertSame(['title' => 'Path alias post'], $harness->fetchRow('SELECT title FROM posts WHERE id = 1'));
		self::assertSame(['user_id' => 1, 'post_id' => 1], $harness->fetchRow('SELECT user_id, post_id FROM user_post'));
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
	 * @return array{0: CollectionInterface}
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
	 * @return array{0: CollectionInterface}
	 */
	private function m2mRegistry(): array
	{
		$registry = new Registry();
		$registry->collection('posts')
			->table('posts')
			->primaryKey('id')
			->field('id', 'int')->autoIncrement(true)->end()
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

		return [$users];
	}
}
