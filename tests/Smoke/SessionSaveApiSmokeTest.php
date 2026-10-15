<?php

declare(strict_types=1);

namespace Tests\ON\Data\Smoke;

use LogicException;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\Definition\Relation\M2MRelation;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Persistence\CommandExecutorInterface;
use ON\Data\ORM\Persistence\CommandInterface;
use ON\Data\ORM\Persistence\CommandResult;
use ON\Data\ORM\Persistence\TransactionalCommandExecutorInterface;
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
		$session->create($row, $map);
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
		$session->update($row, $map);
		$session->sync($row);
		$session->flush();

		self::assertSame(['name' => 'Updated name'], $harness->fetchRow('SELECT name FROM users WHERE id = 1'));
	}

	public function testIdentifyThenUpdateProjectionSyncFlush(): void
	{
		$harness = SqliteMemoryHarness::create();
		$harness->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
		$harness->exec("INSERT INTO users (id, name) VALUES (1, 'Old')");
		[$users] = $this->flatRegistry();
		$session = new Session($harness->commandExecutor);

		$row = $session->identify($users, ['id' => 1]);
		$row->name = 'Updated after identify';

		$q = new SelectQuery($users);
		$map = $q->select($q->id, $q->name)->projection();
		$session->update($row, $map);
		$session->sync($row);
		$session->flush();

		self::assertSame(['name' => 'Updated after identify'], $harness->fetchRow('SELECT name FROM users WHERE id = 1'));
	}

	public function testUpdateFlatRecordWithExplicitIdentityWhenPkNotOnDto(): void
	{
		$harness = SqliteMemoryHarness::create();
		$harness->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
		$harness->exec("INSERT INTO users (id, name) VALUES (1, 'Old')");
		[$users] = $this->flatRegistry();
		$session = new Session($harness->commandExecutor);
		$row = new stdClass();
		$row->name = 'Patched by identity';

		$q = new SelectQuery($users);
		$map = $q->select($q->name)->projection();
		$session->update($row, $map)->identity(['id' => 1]);
		$session->sync($row);
		$session->flush();

		self::assertSame(['name' => 'Patched by identity'], $harness->fetchRow('SELECT name FROM users WHERE id = 1'));
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
		$session->create($row, $map);
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
		$session->update($row, $map);
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
		$session->create($row, $map);
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
		$session->update($user, $map)->create('profile');
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
		$session->update($user, $map)->create('posts');
		$session->sync($user);
		$session->flush();

		self::assertSame(['title' => 'New M2M post'], $harness->fetchRow('SELECT title FROM posts WHERE id = 1'));
		self::assertSame(['user_id' => 1, 'post_id' => 1], $harness->fetchRow('SELECT user_id, post_id FROM user_post'));
	}

	public function testFlushAppliesOverlayToQueryCreatedWritableObjectWithoutExplicitSync(): void
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

		$user = $query->to(stdClass::class)->writable($session)->fetchOne();
		self::assertInstanceOf(stdClass::class, $user);
		$user->newPostTitle = 'New post';

		$u = $harness->database->query($users);
		$extra = $u->select($u->posts->title->as('newPostTitle'))->projection();
		$session->update($user, $extra)->create('posts');
		$session->flush();

		self::assertSame(['name' => 'Ada'], $harness->fetchRow('SELECT name FROM users WHERE id = 1'));
		self::assertSame(['title' => 'New post'], $harness->fetchRow('SELECT title FROM posts WHERE id = 1'));
		self::assertSame(['user_id' => 1, 'post_id' => 1], $harness->fetchRow('SELECT user_id, post_id FROM user_post'));
	}

	public function testFlushAppliesPendingUpdateIntentWithoutSync(): void
	{
		$harness = SqliteMemoryHarness::create();
		$harness->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
		$harness->exec("INSERT INTO users (id, name) VALUES (1, 'Old')");
		[$users] = $this->flatRegistry();
		$session = new Session($harness->commandExecutor);
		$row = new stdClass();
		$row->id = 1;
		$row->name = 'Updated without sync';

		$q = new SelectQuery($users);
		$map = $q->select($q->id, $q->name)->projection();
		$session->update($row, $map);
		$session->flush();

		self::assertSame(['name' => 'Updated without sync'], $harness->fetchRow('SELECT name FROM users WHERE id = 1'));
		self::assertFalse($session->getContext()->getIntents()->has($row));
		self::assertSame([], $session->flush()->getCommandResults());
	}

	public function testSyncAllAppliesStagedCreateWithoutWritingUntilFlush(): void
	{
		$harness = SqliteMemoryHarness::create();
		$harness->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
		[$users] = $this->flatRegistry();
		$session = new Session($harness->commandExecutor);
		$object = (object) ['name' => 'Staged'];
		$query = new SelectQuery($users);
		$session->create($object, $query->select($query->name)->projection());

		$session->sync();

		self::assertTrue($session->getRepresentations()->has($object));
		self::assertFalse($session->getContext()->getIntents()->has($object));
		self::assertNull($harness->fetchRow('SELECT id FROM users'));
		$session->flush();
		self::assertSame(['name' => 'Staged'], $harness->fetchRow('SELECT name FROM users WHERE id = 1'));
		self::assertSame([], $session->flush()->getCommandResults());
	}

	public function testStagedPatchOrderMatchesExplicitSyncOrder(): void
	{
		$harness = SqliteMemoryHarness::create();
		$harness->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
		$harness->exec("INSERT INTO users VALUES (1, 'Original')");
		[$users] = $this->flatRegistry();
		$query = new SelectQuery($users);
		$schema = $query->select($query->id, $query->name)->projection();

		foreach ([true, false] as $syncEach) {
			$harness->exec("UPDATE users SET name = 'Original'");
			$session = new Session($harness->commandExecutor);
			$objects = [(object) ['id' => 1, 'name' => 'Alice'], (object) ['id' => 1, 'name' => 'Bob']];
			foreach ($objects as $object) {
				$session->update($object, $schema);
				if ($syncEach) {
					$session->sync($object);
				}
			}
			$session->flush();

			self::assertSame(['name' => 'Bob'], $harness->fetchRow('SELECT name FROM users WHERE id = 1'));
		}
	}

	public function testFlushRejectsConflictingWritableObjectsBeforeChangingTheDatabase(): void
	{
		$harness = SqliteMemoryHarness::create();
		$harness->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
		$harness->exec("INSERT INTO users VALUES (1, 'Original')");
		[$users] = $this->flatRegistry();
		$session = new Session($harness->commandExecutor);
		$query = $harness->database->query($users);
		$query->select($query->id, $query->name)->to(stdClass::class)->writable($session);
		$first = $query->fetchOne(1);
		$second = $query->fetchOne(1);
		$first->name = 'Alice';
		$second->name = 'Bob';

		try {
			$session->flush();
			self::fail('Expected conflicting edits to be rejected.');
		} catch (SyncException $exception) {
			self::assertStringContainsString('multiple values', $exception->getMessage());
		}
		self::assertSame(['name' => 'Original'], $harness->fetchRow('SELECT name FROM users WHERE id = 1'));

		$second->name = 'Alice';
		$session->flush();
		self::assertSame(['name' => 'Alice'], $harness->fetchRow('SELECT name FROM users WHERE id = 1'));
	}

	public function testFlushAdoptsStagedGraphAndRetriesGeneratedKeysAfterTransactionFailure(): void
	{
		$harness = SqliteMemoryHarness::create();
		$harness->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
		$harness->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT)');
		$harness->exec('CREATE TABLE user_post (user_id INTEGER, post_id INTEGER, UNIQUE(user_id, post_id))');
		[$users] = $this->m2mRegistry();
		$executor = new class ($harness->commandExecutor) implements CommandExecutorInterface, TransactionalCommandExecutorInterface {
			private bool $fail = true;

			public function __construct(private CommandExecutorInterface $inner)
			{
			}

			public function execute(CommandInterface $command): CommandResult
			{
				return $this->inner->execute($command);
			}

			public function transaction(callable $callback): mixed
			{
				if (! $this->inner instanceof TransactionalCommandExecutorInterface) {
					throw new LogicException('Expected a transactional test executor.');
				}

				return $this->inner->transaction(function () use ($callback): mixed {
					$result = $callback();
					if ($this->fail) {
						$this->fail = false;

						throw new LogicException('failure before commit');
					}

					return $result;
				});
			}
		};
		$session = new Session($executor);
		$post = (object) ['title' => 'New post'];
		$owner = (object) ['id' => 1, 'name' => 'Ada', 'posts' => [$post]];
		$query = new SelectQuery($users);
		$query->select($query->id, $query->name);
		$query->posts->select('id', 'title');
		$session->create($post); // The child inherits its schema when the owner is attached.
		$session->create($owner, $query->projection());

		try {
			$session->flush();
			self::fail('Expected the first transaction to roll back.');
		} catch (LogicException $exception) {
			self::assertSame('failure before commit', $exception->getMessage());
		}
		self::assertNull($harness->fetchRow('SELECT id FROM users'));
		self::assertNull($harness->fetchRow('SELECT id FROM posts'));
		self::assertFalse($session->getRepresentations()->get($post)->requireSingleRecord()->hasKey());

		self::assertCount(3, $session->flush()->getCommandResults());
		self::assertSame(['title' => 'New post'], $harness->fetchRow('SELECT title FROM posts WHERE id = 1'));
		self::assertSame(['user_id' => 1, 'post_id' => 1], $harness->fetchRow('SELECT * FROM user_post'));
		self::assertFalse($session->getContext()->getIntents()->has($post));
		self::assertSame([], $session->flush()->getCommandResults());
	}

	public function testFlushDoesNotRecreateChildWithExplicitSchemaWhenOwnerWasStagedFirst(): void
	{
		$harness = SqliteMemoryHarness::create();
		$harness->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, profile_id INTEGER, name TEXT)');
		$harness->exec('CREATE TABLE profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
		[$users, $profiles] = $this->profileRegistry();
		$session = new Session($harness->commandExecutor);
		$profile = (object) ['name' => 'New profile'];
		$owner = (object) ['id' => 1, 'name' => 'Ada', 'profile' => $profile];
		$query = new SelectQuery($users);
		$query->select($query->id, $query->name);
		$query->profile->select('name');
		$profileQuery = new SelectQuery($profiles);
		$session->create($owner, $query->projection());
		$session->create($profile, $profileQuery->select($profileQuery->name)->projection());

		self::assertCount(2, $session->flush()->getCommandResults());
		self::assertSame(['total' => 1], $harness->fetchRow('SELECT COUNT(*) AS total FROM profiles'));
		self::assertSame(['profile_id' => 1], $harness->fetchRow('SELECT profile_id FROM users WHERE id = 1'));
	}

	public function testFlushUsesNestedUpdateIntentIdentityWhenChildHasNoReadableKey(): void
	{
		$harness = SqliteMemoryHarness::create();
		$harness->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, profile_id INTEGER, name TEXT)');
		$harness->exec('CREATE TABLE profiles (id INTEGER PRIMARY KEY, name TEXT)');
		$harness->exec("INSERT INTO profiles VALUES (10, 'Old')");
		[$users] = $this->profileRegistry();
		$session = new Session($harness->commandExecutor);
		$profile = (object) ['name' => 'Updated profile'];
		$owner = (object) ['id' => 1, 'name' => 'Ada', 'profile' => $profile];
		$query = new SelectQuery($users);
		$query->select($query->id, $query->name);
		$query->profile->select('name');
		$session->update($profile)->identity(['id' => 10]);
		$session->create($owner, $query->projection());

		$session->flush();

		self::assertSame(['name' => 'Updated profile'], $harness->fetchRow('SELECT name FROM profiles WHERE id = 10'));
		self::assertSame(['profile_id' => 10], $harness->fetchRow('SELECT profile_id FROM users WHERE id = 1'));
	}

	public function testFlushRejectsUnresolvedPendingIntentInsteadOfSilentlyIgnoringIt(): void
	{
		$harness = SqliteMemoryHarness::create();
		$session = new Session($harness->commandExecutor);
		$object = (object) ['name' => 'Missing schema'];
		$session->create($object);

		$this->expectException(SyncException::class);
		$this->expectExceptionMessage('root RepresentationSchema');

		try {
			$session->flush();
		} finally {
			self::assertTrue($session->getContext()->getIntents()->has($object));
		}
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

		$user = $query->to(stdClass::class)->writable($session)->fetchOne();
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
