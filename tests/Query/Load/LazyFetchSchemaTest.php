<?php

declare(strict_types=1);

namespace Tests\ON\Data\Query\Load;

use ON\Data\Database\QueryExecutorInterface;
use ON\Data\Definition\Registry;
use ON\Data\Query\SelectQuery;
use PHPUnit\Framework\TestCase;

/**
 * Plain reads without a primary key must not compile RepresentationSchema
 * (compiler calls getPrimaryKey()).
 */
final class LazyFetchSchemaTest extends TestCase
{
	public function testPlainReadWithoutPrimaryKeyReachesExecutor(): void
	{
		$registry = new Registry();
		$registry->collection('logs')
			->field('message', 'string')->end();

		$executor = new class () implements QueryExecutorInterface {
			public bool $called = false;

			public function fetchAll(SelectQuery $query): array
			{
				$this->called = true;

				return [['message' => 'ok']];
			}

			public function fetchOne(SelectQuery $query): ?array
			{
				$this->called = true;

				return ['message' => 'ok'];
			}

			public function iterate(SelectQuery $query): iterable
			{
				yield from $this->fetchAll($query);
			}
		};

		$query = new SelectQuery($registry->getCollection('logs'), $executor);
		$query->select($query->message);

		self::assertSame([['message' => 'ok']], $query->fetchAll());
		self::assertTrue($executor->called);
		self::assertNull($query->getFetchSchema());
		self::assertNull($query->getFetchLayout());
	}

	public function testRelationLoadStillCompilesFetchSchema(): void
	{
		$registry = new Registry();
		$registry->collection('authors')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();
		$registry->collection('posts')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('author_id', 'int')->end()
			->field('title', 'string')->end();
		$registry->getCollection('posts')
			->belongsTo('author', 'authors')
			->innerKey('author_id')
			->outerKey('id');

		$executor = new class () implements QueryExecutorInterface {
			public function fetchAll(SelectQuery $query): array
			{
				return [];
			}

			public function fetchOne(SelectQuery $query): ?array
			{
				return null;
			}

			public function iterate(SelectQuery $query): iterable
			{
				return [];
			}
		};

		$query = new SelectQuery($registry->getCollection('posts'), $executor);
		$query->select($query->id);
		$query->author->select($query->author->name)->separate();
		$query->fetchAll();

		self::assertNotNull($query->getFetchSchema());
		self::assertNotNull($query->getFetchLayout());
	}
}
