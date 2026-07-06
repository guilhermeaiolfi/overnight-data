<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Query;

use ON\Data\Database\QueryExecutorInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Persistence\CommandResult;
use ON\Data\ORM\Persistence\InsertCommand;
use ON\Data\ORM\Persistence\UpdateCommand;
use ON\Data\ORM\Session;
use ON\Data\Query\SelectQuery;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\Support\RecordingCommandExecutor;

final class MutableQueryExportPersistenceTest extends TestCase
{
	public function testMutableScalarEditFlushesUpdate(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$executor = new RecordingCommandExecutor();
		$session = new Session($executor);
		$query = new SelectQuery($users, new ScalarUserQueryExecutor());

		$user = $query->to(stdClass::class)->mutable($session)->fetchOne();
		self::assertInstanceOf(stdClass::class, $user);

		$user->name = 'Ada Lovelace';
		$session->sync($user);
		$session->flush();

		self::assertCount(1, $executor->getCommands());
		$command = $executor->getCommands()[0];
		if (! $command instanceof UpdateCommand) {
			self::fail('Expected an update command.');
		}

		self::assertSame($users, $command->getCollection());
		self::assertSame(['id' => 1], $command->getIdentity());
		self::assertSame(['name' => 'Ada Lovelace'], $command->getChanges());
	}

	public function testMutableRelatedObjectScalarEditFlushesUpdate(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$posts = $registry->getCollection('posts');
		$executor = new RecordingCommandExecutor();
		$session = new Session($executor);
		$query = new SelectQuery($users, new UserWithPostsQueryExecutor());
		$query->posts->fields('id', 'title');

		$user = $query->to(stdClass::class)->mutable($session)->fetchOne();
		self::assertInstanceOf(stdClass::class, $user);
		self::assertIsArray($user->posts);
		self::assertCount(1, $user->posts);

		$user->posts[0]->title = 'Updated';
		$session->sync($user);
		$session->flush();

		self::assertCount(1, $executor->getCommands());
		$command = $executor->getCommands()[0];
		if (! $command instanceof UpdateCommand) {
			self::fail('Expected an update command.');
		}

		self::assertSame($posts, $command->getCollection());
		self::assertSame(['id' => 10], $command->getIdentity());
		self::assertSame('Updated', $command->getChanges()['title']);
	}

	public function testMutableRelationAddFlushesInsertWithForeignKeyPropagation(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$posts = $registry->getCollection('posts');
		$executor = new RecordingCommandExecutor(new CommandResult(1, ['id' => 20]));
		$session = new Session($executor);
		$query = new SelectQuery($users, new UserWithEmptyPostsQueryExecutor());
		$query->posts->fields('title');

		$user = $query->to(stdClass::class)->mutable($session)->fetchOne();
		self::assertInstanceOf(stdClass::class, $user);
		self::assertSame([], $user->posts);

		$newPost = new stdClass();
		$newPost->id = null;
		$newPost->title = 'New post';
		$user->posts[] = $newPost;

		$session->sync($user);
		$session->flush();

		self::assertCount(1, $executor->getCommands());
		$command = $executor->getCommands()[0];
		if (! $command instanceof InsertCommand) {
			self::fail('Expected an insert command.');
		}

		self::assertSame($posts, $command->getCollection());
		self::assertSame('New post', $command->getValues()['title']);
		self::assertSame(1, $command->getValues()['user_id']);
	}

	private function makeRegistry(): Registry
	{
		$registry = new Registry();

		$registry->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();

		$registry->collection('posts')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('title', 'string')->end()
			->field('user_id', 'int')->end();

		$registry->getCollection('users')
			->hasMany('posts', 'posts')
			->innerKey('id')
			->outerKey('user_id');

		return $registry;
	}
}

final class ScalarUserQueryExecutor implements QueryExecutorInterface
{
	public function fetchAll(SelectQuery $query): array
	{
		return [['id' => 1, 'name' => 'Ada']];
	}

	public function fetchOne(SelectQuery $query): ?array
	{
		return ['id' => 1, 'name' => 'Ada'];
	}

	public function iterate(SelectQuery $query): iterable
	{
		yield from $this->fetchAll($query);
	}
}

final class UserWithPostsQueryExecutor implements QueryExecutorInterface
{
	public function fetchAll(SelectQuery $query): array
	{
		return match ($query->getCollection()->getName()) {
			'users' => [['id' => 1, 'name' => 'Ada']],
			'posts' => [['id' => 10, 'title' => 'Hello', 'user_id' => 1]],
			default => [],
		};
	}

	public function fetchOne(SelectQuery $query): ?array
	{
		if ($query->getCollection()->getName() !== 'users') {
			return null;
		}

		return ['id' => 1, 'name' => 'Ada'];
	}

	public function iterate(SelectQuery $query): iterable
	{
		yield from $this->fetchAll($query);
	}
}

final class UserWithEmptyPostsQueryExecutor implements QueryExecutorInterface
{
	public function fetchAll(SelectQuery $query): array
	{
		return match ($query->getCollection()->getName()) {
			'users' => [['id' => 1, 'name' => 'Ada']],
			'posts' => [],
			default => [],
		};
	}

	public function fetchOne(SelectQuery $query): ?array
	{
		if ($query->getCollection()->getName() !== 'users') {
			return null;
		}

		return ['id' => 1, 'name' => 'Ada'];
	}

	public function iterate(SelectQuery $query): iterable
	{
		yield from $this->fetchAll($query);
	}
}
