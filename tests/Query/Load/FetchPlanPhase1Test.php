<?php

declare(strict_types=1);

namespace Tests\ON\Data\Query\Load;

use ON\Data\Database\QueryExecutorInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Session;
use ON\Data\Query\Relation\Loader\BelongsToLoader;
use ON\Data\Query\Relation\Loader\HasManyLoader;
use ON\Data\Query\SelectQuery;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\Support\RecordingCommandExecutor;

final class FetchPlanPhase1Test extends TestCase
{
	public function testReadFetchCompilesSchemaAndLoadGraphBeforeResults(): void
	{
		$users = new SelectQuery(
			$this->makeRegistry()->getCollection('users'),
			new FetchPlanPhase1Executor(),
		);

		$users->select($users->id, $users->name);
		$users->posts
			->select(
				$users->posts->id,
				$users->posts->author->name->as('authorName'),
			)
			->separate();

		self::assertNull($users->getFetchPlan());

		$rows = $users->fetchAll();
		$plan = $users->getFetchPlan();

		self::assertNotNull($plan);
		self::assertTrue($plan->getSchema()->hasField('id'));
		self::assertTrue($plan->getSchema()->hasRelation('posts'));
		self::assertSame(['id'], $plan->getLoadGraph()->get(['posts'])?->getFields());
		self::assertSame(['name'], $plan->getLoadGraph()->get(['posts', 'author'])?->getFields());
		self::assertSame('Ana', $rows[0]['posts'][0]['authorName']);
	}

	public function testWritablePrepareExposesLoadGraphOnPlanAndFetchPlan(): void
	{
		$session = new Session(new RecordingCommandExecutor());
		$users = new SelectQuery(
			$this->makeRegistry()->getCollection('users'),
			new FetchPlanPhase1Executor(),
		);

		$users->select($users->id, $users->name);
		$users->posts
			->select(
				$users->posts->id,
				$users->posts->author->name->as('authorName'),
			)
			->separate();

		$users->to(stdClass::class)->writable($session);
		$user = $users->fetchOne();
		$plan = $users->getFetchPlan();

		self::assertInstanceOf(stdClass::class, $user);
		self::assertNotNull($plan);
		self::assertSame(['name', 'id'], $plan->getLoadGraph()->get(['posts', 'author'])?->getFields());
		self::assertTrue($plan->getSchema()->getRelation('posts')->getRelatedSchema()->hasField('authorName'));
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

final class FetchPlanPhase1Executor implements QueryExecutorInterface
{
	public function fetchAll(SelectQuery $query): array
	{
		return match ($query->getCollection()->getName()) {
			'users' => [[
				'id' => 1,
				'name' => 'Ada',
			]],
			'posts' => [$this->postsRow($query)],
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
			'author__name' => 'Ana',
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
