<?php

declare(strict_types=1);

namespace Tests\ON\Data\Query;

use LogicException;
use ON\Data\Database\QueryExecutorInterface;
use ON\Data\Definition\Registry;
use ON\Data\Query\Exception\RelationLoaderException;
use ON\Data\Query\Relation\Loader\BelongsToLoader;
use ON\Data\Query\Relation\Loader\HasManyLoader;
use ON\Data\Query\SelectQuery;
use function ON\Data\Query\x;
use PHPUnit\Framework\TestCase;

final class NestedFlatProjectionLoadTest extends TestCase
{
	public function testSeparatePostsSelectCanFlatProjectAuthorNameOntoPostRows(): void
	{
		$users = new SelectQuery(
			$this->makeRegistry()->getCollection('users'),
			new NestedFlatProjectionExecutor(),
		);

		$users->select($users->id, $users->name);
		$users->posts
			->select(
				$users->posts->id,
				$users->posts->title->as('headline'),
				$users->posts->author->name->as('authorName'),
			)
			->separate();

		self::assertSame([[
			'id' => 1,
			'name' => 'Ada',
			'posts' => [[
				'id' => 10,
				'headline' => 'Hello',
				'authorName' => 'Ana',
			]],
		]], $users->fetchAll());
	}

	public function testFlatAuthorProjectionDoesNotCreateNestedAuthorContainer(): void
	{
		$users = new SelectQuery(
			$this->makeRegistry()->getCollection('users'),
			new NestedFlatProjectionExecutor(),
		);

		$users->posts
			->select(
				$users->posts->id,
				$users->posts->author->name->as('authorName'),
			)
			->separate();

		$rows = $users->fetchAll();
		$post = $rows[0]['posts'][0];

		self::assertArrayHasKey('authorName', $post);
		self::assertArrayNotHasKey('author', $post);
		self::assertSame('Ana', $post['authorName']);
	}

	public function testJoinedPostsSelectCanFlatProjectAuthorNameOntoPostRows(): void
	{
		$executor = new NestedJoinedFlatProjectionExecutor();
		$users = new SelectQuery(
			$this->makeRegistry()->getCollection('users'),
			$executor,
		);

		$users->select($users->id, $users->name);
		$users->posts
			->select(
				$users->posts->id,
				$users->posts->title->as('headline'),
				$users->posts->author->name->as('authorName'),
			)
			->join();

		$rows = $users->fetchAll();

		self::assertSame([[
			'id' => 1,
			'name' => 'Ada',
			'posts' => [[
				'id' => 10,
				'headline' => 'Hello',
				'authorName' => 'Ana',
			]],
		]], $rows);
		self::assertArrayNotHasKey('author', $rows[0]['posts'][0]);
	}

	public function testJoinedNonFieldExpressionNestedSelectionIsRejected(): void
	{
		$users = new SelectQuery(
			$this->makeRegistry()->getCollection('users'),
			new NestedFlatProjectionExecutor(),
		);

		$users->posts
			->select(
				$users->posts->id,
				x()->literal(1)->as('one'),
			)
			->join();

		$this->expectException(RelationLoaderException::class);
		$this->expectExceptionMessage('cannot use JOIN loading with non-field expressions');
		$users->fetchAll();
	}

	public function testBelongsToJoinAllowsSameLevelAliasSelect(): void
	{
		$users = new SelectQuery(
			$this->makeRegistry()->getCollection('users'),
			new NestedFlatProjectionExecutor(),
		);

		$users->posts->select('id', 'title')->separate();
		$users->posts->author->select(
			$users->posts->author->name->as('displayName'),
		);

		// BelongsTo defaults to JOIN; same-level aliases are allowed.
		$rows = $users->fetchAll();
		self::assertSame('Ada', $rows[0]['name']);
	}

	public function testNestedFlatFieldIsPlacedFromFetchSchema(): void
	{
		$users = new SelectQuery(
			$this->makeRegistry()->getCollection('users'),
			new NestedFlatProjectionExecutor(),
		);

		$users->posts
			->select(
				$users->posts->id,
				$users->posts->author->name->as('authorName'),
			)
			->separate();

		$rows = $users->fetchAll();
		$schema = $users->getFetchSchema();

		self::assertNotNull($schema);
		$postsSchema = $schema->getRelation('posts')->getRelatedSchema();
		self::assertTrue($postsSchema->hasField('authorName'));
		self::assertSame(['author'], $postsSchema->getField('authorName')->getSourcePath());
		self::assertSame('Ana', $rows[0]['posts'][0]['authorName']);
	}

	public function testFlatAuthorNameReusesLoadedSeparateAuthorDestination(): void
	{
		$executor = new NestedFlatReuseExecutor();
		$users = new SelectQuery(
			$this->makeRegistry()->getCollection('users'),
			$executor,
		);

		$users->select($users->id, $users->name);
		$users->posts
			->select(
				$users->posts->id,
				$users->posts->author->name->as('authorName'),
			)
			->separate();
		// Author attach exists; name is not public on author — flat should require it there.
		$users->posts->author->select($users->posts->author->id)->separate();

		$rows = $users->fetchAll();
		$post = $rows[0]['posts'][0];

		self::assertSame('Ana', $post['authorName']);
		self::assertSame(['id' => 7], $post['author']);

		foreach ($executor->queries as $query) {
			if ($query->getCollection()->getName() !== 'posts') {
				continue;
			}

			self::assertFalse(
				$query->getSelections()->hasSelectionKey('authorName'),
				'Flat must not JOIN author onto posts when an author destination exists',
			);
		}
	}

	public function testFlatAuthorNameStillWorksWhenAuthorDestinationOmitsName(): void
	{
		$executor = new NestedFlatReuseExecutor();
		$users = new SelectQuery(
			$this->makeRegistry()->getCollection('users'),
			$executor,
		);

		$users->posts
			->select(
				$users->posts->id,
				$users->posts->author->name->as('authorName'),
			)
			->separate();
		$users->posts->author->select('id')->separate();

		self::assertSame('Ana', $users->fetchAll()[0]['posts'][0]['authorName']);
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

final class NestedFlatProjectionExecutor implements QueryExecutorInterface
{
	public function fetchAll(SelectQuery $query): array
	{
		return match ($query->getCollection()->getName()) {
			'users' => [[
				'id' => 1,
				'name' => 'Ada',
			]],
			'posts' => [[
				'id' => 10,
				'userId' => 1,
				'authorId' => 7,
				'headline' => 'Hello',
				'authorName' => 'Ana',
			]],
			'authors' => [[
				'id' => 7,
				'name' => 'Ana',
			]],
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
}

/**
 * Single-query JOIN shape: root + joined posts columns (+ flat author) in one row.
 */
final class NestedJoinedFlatProjectionExecutor implements QueryExecutorInterface
{
	public function fetchAll(SelectQuery $query): array
	{
		if ($query->getCollection()->getName() !== 'users') {
			throw new LogicException('Joined flat fixture only serves the root users query.');
		}

		return [[
			'id' => 1,
			'name' => 'Ada',
			'posts.id' => 10,
			'posts.userId' => 1,
			'posts.headline' => 'Hello',
			'posts.authorName' => 'Ana',
		]];
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
}

/**
 * Posts rows intentionally omit a flat authorName column — reuse must read name from the author destination.
 */
final class NestedFlatReuseExecutor implements QueryExecutorInterface
{
	/** @var list<SelectQuery> */
	public array $queries = [];

	public function fetchAll(SelectQuery $query): array
	{
		$this->queries[] = $query;

		return match ($query->getCollection()->getName()) {
			'users' => [[
				'id' => 1,
				'name' => 'Ada',
			]],
			'posts' => [[
				'id' => 10,
				'userId' => 1,
				'authorId' => 7,
				'title' => 'Hello',
			]],
			'authors' => [[
				'id' => 7,
				'name' => 'Ana',
			]],
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
}
