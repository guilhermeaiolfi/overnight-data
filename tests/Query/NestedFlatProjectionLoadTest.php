<?php

declare(strict_types=1);

namespace Tests\ON\Data\Query;

use ON\Data\Database\QueryExecutorInterface;
use ON\Data\Definition\Registry;
use ON\Data\Query\Exception\RelationLoaderException;
use ON\Data\Query\Relation\Loader\BelongsToLoader;
use ON\Data\Query\Relation\Loader\HasManyLoader;
use ON\Data\Query\Selection\SelectionTag;
use ON\Data\Query\SelectQuery;
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

	public function testJoinedFlatRelatedNestedSelectionIsRejected(): void
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
			->join();

		$this->expectException(RelationLoaderException::class);
		$this->expectExceptionMessage('cannot use JOIN loading with flat related fields');
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

	public function testNestedFlatFieldIsRegisteredAsPublicSelectionOnPostsBranch(): void
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

		$users->fetchAll();

		$postsSelection = null;

		foreach ($users->getRelationSelections()->getAll() as $selection) {
			if ($selection->getPath() === ['posts']) {
				$postsSelection = $selection;

				break;
			}
		}

		self::assertNotNull($postsSelection);
		$publicKeys = array_map(
			static fn ($item) => $item->getSelectionKey(),
			$postsSelection->getSelections()->getByTag(SelectionTag::PUBLIC),
		);
		self::assertContains('id', $publicKeys);
		self::assertContains('authorName', $publicKeys);
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
				'title' => 'Hello',
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
