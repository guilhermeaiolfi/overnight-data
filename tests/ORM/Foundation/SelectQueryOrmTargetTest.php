<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Foundation;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Session;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\Selection\SelectionTag;
use ON\Data\Query\SelectQuery;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\Smoke\Support\SqliteMemoryHarness;

#[RequiresPhpExtension('pdo_sqlite')]
final class SelectQueryOrmTargetTest extends TestCase
{
	public function testSelectQueryRemainsTheReadQueryApi(): void
	{
		self::assertTrue(class_exists(SelectQuery::class));
		self::assertFalse(class_exists('ON\\Data\\ORM\\EntityQuery'));
	}

	public function testNoWithApiIsIntroducedForRelationLoading(): void
	{
		self::assertFalse(method_exists(SelectQuery::class, 'with'));
		self::assertFalse(method_exists(RelationRef::class, 'with'));
	}

	public function testClassTargetWithoutExplicitSelectUsesDefaultRootScalarFields(): void
	{
		[$harness, $users] = $this->usersHarness();

		$user = $harness->database->query($users)->to(SelectQueryOrmTargetUser::class)->fetchOne();

		self::assertInstanceOf(SelectQueryOrmTargetUser::class, $user);
		self::assertSame(1, $user->id);
		self::assertSame('Ada', $user->name);
		self::assertSame('ada@example.test', $user->email);
	}

	public function testStdClassTargetWithoutExplicitSelectUsesDefaultRootScalarFields(): void
	{
		[$harness, $users] = $this->usersHarness();

		$user = $harness->database->query($users)->to(stdClass::class)->fetchOne();

		self::assertInstanceOf(stdClass::class, $user);
		self::assertSame(1, $user->id);
		self::assertSame('Ada', $user->name);
		self::assertSame('ada@example.test', $user->email);
	}

	public function testDefaultArrayResultWithoutExplicitSelectUsesDefaultRootScalarFields(): void
	{
		[$harness, $users] = $this->usersHarness();

		$user = $harness->database->query($users)->fetchOne();

		self::assertIsArray($user);
		self::assertSame(
			['id' => 1, 'name' => 'Ada', 'email' => 'ada@example.test'],
			$user,
		);
	}

	public function testImplicitDefaultSelectionAppliesOnlyToRootCollection(): void
	{
		[$harness, $users] = $this->usersHarness();

		$user = $harness->database->query($users)->to(stdClass::class)->fetchOne();

		self::assertInstanceOf(stdClass::class, $user);
		self::assertFalse(property_exists($user, 'posts'));
	}

	public function testToTargetDoesNotAutoLoadRelations(): void
	{
		[$harness, $users] = $this->usersHarness();

		$user = $harness->database->query($users)->to(SelectQueryOrmTargetUser::class)->fetchOne();

		self::assertInstanceOf(SelectQueryOrmTargetUser::class, $user);
		self::assertFalse(property_exists($user, 'posts'));
	}

	public function testExplicitSelectDisablesDefaultRootFieldSelection(): void
	{
		[$harness, $users] = $this->usersHarness();
		$query = $harness->database->query($users);
		$query->select($query->id);

		$user = $query->to(stdClass::class)->fetchOne();

		self::assertInstanceOf(stdClass::class, $user);
		self::assertSame(1, $user->id);
		self::assertFalse(property_exists($user, 'name'));
		self::assertFalse(property_exists($user, 'email'));
	}

	public function testHiddenIdentityFieldsDoNotLeakIntoMappedRepresentation(): void
	{
		[$harness, $users] = $this->usersHarness();
		$session = new Session($harness->commandExecutor);
		$query = $harness->database->query($users);
		$query->select($query->name);

		$user = $query->to(stdClass::class)->mutable($session)->fetchOne();

		self::assertInstanceOf(stdClass::class, $user);
		self::assertSame('Ada', $user->name);
		self::assertFalse(property_exists($user, 'id'));

		foreach ($query->getSelections()->getByTag(SelectionTag::INTERNAL) as $selection) {
			self::assertFalse(property_exists($user, $selection->getSelectionKey()));
		}
	}

	public function testDirectSelectedFieldsCanHaveWritableLineage(): void
	{
		[$harness, $users] = $this->usersHarness();
		$session = new Session($harness->commandExecutor);
		$query = $harness->database->query($users);
		$query->select($query->id, $query->name->as('userName'));

		$user = $query->to(stdClass::class)->mutable($session)->fetchOne();
		self::assertInstanceOf(stdClass::class, $user);

		$user->userName = 'Ada Lovelace';
		$session->sync($user);
		$session->flush();

		self::assertSame(['name' => 'Ada Lovelace'], $harness->fetchRow('SELECT name FROM users WHERE id = 1'));
	}

	public function testExpressionSelectedValuesAreReadOnlyByDefault(): void
	{
		[$harness, $users] = $this->usersHarness();
		$session = new Session($harness->commandExecutor);
		$query = $harness->database->query($users);
		$query->select($query->id, $query->name->upper()->as('upperName'));

		$user = $query->to(stdClass::class)->mutable($session)->fetchOne();
		self::assertInstanceOf(stdClass::class, $user);
		self::assertSame(1, $user->id);
		self::assertSame('ADA', $user->upperName);

		$user->upperName = 'CHANGED';
		$session->sync($user);
		$result = $session->flush();

		self::assertSame([], $result->getCommandResults());
		self::assertSame(
			['name' => 'Ada'],
			$harness->fetchRow('SELECT name FROM users WHERE id = 1'),
		);
	}

	public function testPartialExplicitSelectionsDoNotOverwriteMissingFieldsDuringSync(): void
	{
		[$harness, $users] = $this->usersHarness();
		$session = new Session($harness->commandExecutor);
		$query = $harness->database->query($users);
		$query->select($query->id, $query->name);

		$user = $query->to(stdClass::class)->mutable($session)->fetchOne();
		self::assertInstanceOf(stdClass::class, $user);

		$user->name = 'Ada Lovelace';
		$session->sync($user);
		$session->flush();

		self::assertSame(
			['name' => 'Ada Lovelace', 'email' => 'ada@example.test'],
			$harness->fetchRow('SELECT name, email FROM users WHERE id = 1'),
		);
	}

	public function testDefaultSelectedRootRepresentationIsNormalWritableRootCase(): void
	{
		[$harness, $users] = $this->usersHarness();
		$session = new Session($harness->commandExecutor);

		$user = $harness->database->query($users)->to(stdClass::class)->mutable($session)->fetchOne();
		self::assertInstanceOf(stdClass::class, $user);

		$user->name = 'Ada Lovelace';
		$session->sync($user);
		$result = $session->flush();

		self::assertCount(1, $result->getCommandResults());
		self::assertSame(['name' => 'Ada Lovelace'], $harness->fetchRow('SELECT name FROM users WHERE id = 1'));
	}

	public function testPartialClassRepresentationsAreProjectionLikeUnlessTrackedFieldByField(): void
	{
		[$harness, $users] = $this->usersHarness();
		$query = $harness->database->query($users);
		$query->select($query->id, $query->name);

		$user = $query->to(SelectQueryOrmPartialUser::class)->fetchOne();

		self::assertInstanceOf(SelectQueryOrmPartialUser::class, $user);
		self::assertSame(1, $user->id);
		self::assertSame('Ada', $user->name);
		self::assertNull($user->email);
	}

	/**
	 * @return array{SqliteMemoryHarness, CollectionInterface}
	 */
	private function usersHarness(): array
	{
		$harness = SqliteMemoryHarness::create();
		$harness->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)');
		$harness->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, user_id INTEGER, title TEXT)');
		$harness->exec("INSERT INTO users (id, name, email) VALUES (1, 'Ada', 'ada@example.test')");
		$harness->exec("INSERT INTO posts (id, user_id, title) VALUES (10, 1, 'Hello')");

		$registry = new Registry();
		$registry->collection('users')
			->table('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end()
			->field('email', 'string')->end()
			->hasMany('posts', 'posts')
				->innerKey('id')
				->outerKey('user_id')
				->end();
		$registry->collection('posts')
			->table('posts')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('user_id', 'int')->end()
			->field('title', 'string')->end();

		return [$harness, $registry->getCollection('users')];
	}
}

final class SelectQueryOrmTargetUser
{
	public int $id;

	public string $name;

	public string $email;
}

final class SelectQueryOrmPartialUser
{
	public ?int $id = null;

	public ?string $name = null;

	public ?string $email = null;
}
