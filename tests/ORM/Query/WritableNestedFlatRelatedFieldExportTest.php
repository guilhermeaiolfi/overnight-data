<?php

declare(strict_types=1);

namespace Tests\ON\Data\ORM\Query;

use ON\Data\Database\QueryExecutorInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Persistence\UpdateCommand;
use ON\Data\ORM\Session;
use ON\Data\Query\Relation\Loader\BelongsToLoader;
use ON\Data\Query\Relation\Loader\HasManyLoader;
use ON\Data\Query\SelectQuery;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\Support\RecordingCommandExecutor;

final class WritableNestedFlatRelatedFieldExportTest extends TestCase
{
	public function testWritableNestedFlatAuthorNameFlushesUpdateToAuthors(): void
	{
		$registry = $this->makeRegistry();
		$authors = $registry->getCollection('authors');
		$executor = new RecordingCommandExecutor();
		$session = new Session($executor);
		$query = new SelectQuery($registry->getCollection('users'), new NestedFlatWritableExecutor());

		$query->select($query->id, $query->name);
		$query->posts
			->select(
				$query->posts->id,
				$query->posts->author->name->as('authorName'),
			)
			->separate();

		$user = $query->to(stdClass::class)->writable($session)->fetchOne();

		self::assertInstanceOf(stdClass::class, $user);
		self::assertCount(1, $user->posts);
		$post = $user->posts[0];
		self::assertInstanceOf(stdClass::class, $post);
		self::assertSame(10, $post->id);
		self::assertSame('Ana', $post->authorName);
		self::assertFalse(property_exists($post, 'author'));
		self::assertFalse($this->hasInternalProperty($user));
		self::assertFalse($this->hasInternalProperty($post));

		$post->authorName = 'Grace';
		$session->sync($user);
		$session->flush();

		self::assertCount(1, $executor->getCommands());
		$command = $executor->getCommands()[0];
		self::assertInstanceOf(UpdateCommand::class, $command);
		self::assertSame($authors, $command->getCollection());
		self::assertSame(['id' => 7], $command->getIdentity());
		self::assertSame(['name' => 'Grace'], $command->getChanges());
	}

	private function hasInternalProperty(object $object): bool
	{
		foreach (get_object_vars($object) as $name => $_) {
			if (str_starts_with((string) $name, '_od_internal_')) {
				return true;
			}
		}

		return false;
	}

	private function makeRegistry(): Registry
	{
		$registry = new Registry();

		$authors = $registry->collection('authors');
		$authors->field('id', 'int');
		$authors->field('name', 'string');
		$authors->primaryKey('id');

		$posts = $registry->collection('posts');
		$posts->field('id', 'int');
		$posts->field('userId', 'int');
		$posts->field('authorId', 'int');
		$posts->field('title', 'string');
		$posts->primaryKey('id');
		$posts->belongsTo('author', 'authors')
			->innerKey('authorId')
			->outerKey('id')
			->loader(BelongsToLoader::class)
			->end();

		$users = $registry->collection('users');
		$users->field('id', 'int');
		$users->field('name', 'string');
		$users->primaryKey('id');
		$users->hasMany('posts', 'posts')
			->innerKey('id')
			->outerKey('userId')
			->loader(HasManyLoader::class)
			->end();

		return $registry;
	}
}

final class NestedFlatWritableExecutor implements QueryExecutorInterface
{
	public function fetchAll(SelectQuery $query): array
	{
		return match ($query->getCollection()->getName()) {
			'users' => [[
				'id' => 1,
				'name' => 'Ada',
			]],
			'posts' => [$this->postsRow($query)],
			default => [],
		};
	}

	public function fetchOne(SelectQuery $query): ?array
	{
		$rows = $this->fetchAll($query);

		return $rows[0] ?? null;
	}

	public function iterate(SelectQuery $query): iterable
	{
		return $this->fetchAll($query);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function postsRow(SelectQuery $query): array
	{
		$row = [
			'id' => 10,
			'userId' => 1,
			'authorId' => 7,
			'title' => 'Hello',
			'authorName' => 'Ana',
		];

		foreach ($query->getSelections()->getAll() as $selection) {
			$key = $selection->getSelectionKey();

			if (str_starts_with($key, '_od_internal_')) {
				$row[$key] = 7;
			}
		}

		return $row;
	}
}
