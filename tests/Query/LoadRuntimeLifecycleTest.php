<?php

declare(strict_types=1);

namespace Tests\ON\Data\Query;

use ON\Data\Database\QueryExecutorInterface;
use ON\Data\Definition\Registry;
use ON\Data\Query\Exception\LoadRuntimeException;
use ON\Data\Query\Relation\LoadRuntime;
use ON\Data\Query\Relation\Loader\AbstractLoader;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\Result\Parser\AbstractNode;
use ON\Data\Query\Result\Parser\CollectionNode;
use ON\Data\Query\SelectQuery;
use PHPUnit\Framework\TestCase;

final class LoadRuntimeLifecycleTest extends TestCase
{
	protected function tearDown(): void
	{
		LifecycleRecordingLoader::$events = [];
		LifecycleRecordingLoader::$referenceSnapshots = [];
		RepeatLoadLifecycleLoader::$loadCalls = 0;
	}

	public function testNamedNextPassRunsAfterParentRowsAreParsed(): void
	{
		$users = new SelectQuery($this->makeRegistry(LifecycleRecordingLoader::class)->getCollection('users'), new LifecycleExecutor());
		$users->select($users->posts);

		$users->fetchAll();

		self::assertSame(['register', 'load', 'loadData'], LifecycleRecordingLoader::$events);
		self::assertSame([['id' => 1]], LifecycleRecordingLoader::$referenceSnapshots[0]);
	}

	public function testDefaultNextPassRepeatsLoadOnTheSameBranch(): void
	{
		$users = new SelectQuery($this->makeRegistry(RepeatLoadLifecycleLoader::class)->getCollection('users'), new LifecycleExecutor());
		$users->select($users->posts);

		$users->fetchAll();

		self::assertSame(2, RepeatLoadLifecycleLoader::$loadCalls);
	}

	public function testInvalidScheduledMethodsAreRejected(): void
	{
		$users = new SelectQuery($this->makeRegistry(InvalidScheduledMethodLoader::class)->getCollection('users'), new LifecycleExecutor());
		$users->select($users->posts);

		$this->expectException(LoadRuntimeException::class);
		$users->fetchAll();
	}

	public function testNextPassFromRegisterIsRejected(): void
	{
		$users = new SelectQuery($this->makeRegistry(RegisterSchedulingLoader::class)->getCollection('users'), new LifecycleExecutor());
		$users->select($users->posts);

		$this->expectException(LoadRuntimeException::class);
		$users->fetchAll();
	}

	private function makeRegistry(string $loader): Registry
	{
		$registry = new Registry();

		$users = $registry->collection('users');
		$users->field('id', 'int');
		$users->field('name', 'string');
		$users->primaryKey('id');
		$users->hasMany('posts', 'posts')
			->innerKey('id')
			->outerKey('userId')
			->loader($loader)
			->end();

		$posts = $registry->collection('posts');
		$posts->field('id', 'int');
		$posts->field('userId', 'int');
		$posts->field('title', 'string');
		$posts->primaryKey('id');

		return $registry;
	}
}

final class LifecycleExecutor implements QueryExecutorInterface
{
	public function fetchAll(SelectQuery $query): array
	{
		return match ($query->getCollection()->getName()) {
			'users' => [['id' => 1, 'name' => 'Ada']],
			'posts' => [['id' => 10, 'userId' => 1, 'title' => 'Hello']],
			default => [],
		};
	}

	public function fetchOne(SelectQuery $query): ?array
	{
		return null;
	}

	public function iterate(SelectQuery $query): iterable
	{
		return [];
	}
}

abstract class LifecycleTestLoader extends AbstractLoader
{
	public function register(RelationRef $relation, LoadRuntime $runtime): AbstractNode
	{
		$identity = $runtime->requireBranchFields($relation->getCollection()->getPrimaryKey());
		$child = $runtime->requireBranchFields($this->relationKeys($relation, 'outer'));
		$parent = $runtime->requireParentFields($this->relationKeys($relation, 'inner'));

		return new CollectionNode(
			$runtime->getNodeColumns(),
			$identity,
			$child,
			$parent,
		);
	}

	protected function prepareSeparateQuery(RelationRef $relation, LoadRuntime $runtime): void
	{
		$query = new SelectQuery($relation->getCollection(), new LifecycleExecutor());
		$runtime->getParentNode()->linkNode($relation->getName(), $runtime->getNode());
		$runtime->setQueryContext($query, $query);
	}
}

final class LifecycleRecordingLoader extends LifecycleTestLoader
{
	/**
	 * @var list<string>
	 */
	public static array $events = [];

	/**
	 * @var list<list<array<string, scalar>>>
	 */
	public static array $referenceSnapshots = [];

	public function register(RelationRef $relation, LoadRuntime $runtime): AbstractNode
	{
		self::$events[] = 'register';

		return parent::register($relation, $runtime);
	}

	public function load(RelationRef $relation, LoadRuntime $runtime): void
	{
		self::$events[] = 'load';
		$this->prepareSeparateQuery($relation, $runtime);
		$runtime->nextPass('loadData');
	}

	public function loadData(RelationRef $relation, LoadRuntime $runtime): void
	{
		self::$events[] = 'loadData';
		self::$referenceSnapshots[] = $runtime->getReferenceValues();
	}
}

final class RepeatLoadLifecycleLoader extends LifecycleTestLoader
{
	public static int $loadCalls = 0;

	public function load(RelationRef $relation, LoadRuntime $runtime): void
	{
		self::$loadCalls++;

		if (self::$loadCalls === 1) {
			$this->prepareSeparateQuery($relation, $runtime);
			$runtime->nextPass();
		}
	}
}

final class InvalidScheduledMethodLoader extends LifecycleTestLoader
{
	public function load(RelationRef $relation, LoadRuntime $runtime): void
	{
		$this->prepareSeparateQuery($relation, $runtime);
		$runtime->nextPass('missingMethod');
	}
}

final class RegisterSchedulingLoader extends LifecycleTestLoader
{
	public function register(RelationRef $relation, LoadRuntime $runtime): AbstractNode
	{
		$node = parent::register($relation, $runtime);
		$runtime->nextPass('loadData');

		return $node;
	}

	public function load(RelationRef $relation, LoadRuntime $runtime): void
	{
		$this->prepareSeparateQuery($relation, $runtime);
	}

	public function loadData(RelationRef $relation, LoadRuntime $runtime): void
	{
	}
}
