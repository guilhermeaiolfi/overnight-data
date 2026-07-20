<?php

declare(strict_types=1);

namespace Tests\ON\Data\Query;

use Generator;
use InvalidArgumentException;
use ON\Data\Database\Exception\QueryNotExecutableException;
use ON\Data\Database\QueryExecutorInterface;
use ON\Data\Definition\Exception\InvalidPrimaryKeyException;
use ON\Data\Definition\Registry;
use ON\Data\Key;
use ON\Data\Query\Condition\ComparisonCondition;
use ON\Data\Query\Condition\ConditionInterface;
use ON\Data\Query\Condition\ConditionTag;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\Expression\LiteralExpression;
use function ON\Data\Query\query;
use ON\Data\Query\SelectQuery;
use function ON\Data\Query\x;
use PHPUnit\Framework\TestCase;
use Tests\ON\Data\Fixture\CustomRelation;

final class SelectQueryExecutionTest extends TestCase
{
	public function testQueryHelperCreatesAnUnboundQuery(): void
	{
		$query = query($this->makeRegistry()->getCollection('users'));

		self::assertFalse($query->isExecutable());
	}

	public function testDirectConstructionCanBindANeutralExecutor(): void
	{
		$definition = $this->makeRegistry()->getCollection('users');
		$executor = new RecordingExecutor();
		$query = new SelectQuery($definition, $executor);

		self::assertTrue($query->isExecutable());
		self::assertSame([['id' => 1]], $query->fetchAll());
		self::assertSame($query, $executor->lastQuery);
		self::assertSame(['fetchAll'], $executor->calls);
	}

	public function testExecutionMethodsDelegateTheSameQueryObjectAndReturnExecutorResults(): void
	{
		$definition = $this->makeRegistry()->getCollection('users');
		$executor = new RecordingExecutor(
			fetchAllRows: [['id' => 10], ['id' => 11]],
			fetchOneRow: ['id' => 12],
			iterateRows: [['id' => 13], ['id' => 14]],
		);
		$query = new SelectQuery($definition, $executor);

		self::assertSame([['id' => 10], ['id' => 11]], $query->fetchAll());
		self::assertSame($query, $executor->lastQuery);
		self::assertSame(['id' => 12], $query->fetchOne());
		self::assertSame($query, $executor->lastQuery);
		self::assertSame([['id' => 13], ['id' => 14]], iterator_to_array($query->iterate(), false));
		self::assertSame($query, $executor->lastQuery);
		self::assertSame(['fetchAll', 'fetchOne', 'iterate'], $executor->calls);
	}

	public function testFetchOneCanReturnNull(): void
	{
		$query = new SelectQuery(
			$this->makeRegistry()->getCollection('users'),
			new RecordingExecutor(fetchOneRow: null),
		);

		self::assertNull($query->fetchOne());
	}

	public function testFetchOneIdentityAppliesTemporaryPrimaryKeyConstraint(): void
	{
		$users = $this->makeRegistry()->getCollection('users');
		$executor = new RecordingExecutor(fetchOneRow: ['id' => 2, 'name' => 'Grace']);
		$query = new SelectQuery($users, $executor);
		$query->where(x()->eq($query->active, true));

		self::assertSame(['id' => 2, 'name' => 'Grace'], $query->fetchOne(2));

		self::assertCount(1, $executor->identityConditionsAtFetchOne);
		$condition = $executor->identityConditionsAtFetchOne[0];
		self::assertInstanceOf(ComparisonCondition::class, $condition);
		self::assertInstanceOf(FieldRef::class, $condition->getLeft());
		self::assertSame('id', $condition->getLeft()->getName());
		self::assertInstanceOf(LiteralExpression::class, $condition->getRight());
		self::assertSame(2, $condition->getRight()->getValue());

		self::assertSame([], $query->getConditionList()->getByTag(ConditionTag::IDENTITY));
		self::assertCount(1, $query->getConditionList()->getByTag(ConditionTag::USER));
	}

	public function testFetchOneIdentityAcceptsKeyAndCompositeValues(): void
	{
		$registry = new Registry();
		$postUser = $registry->collection('post_user')
			->primaryKey('post_id', 'user_id')
			->field('post_id', 'int')->end()
			->field('user_id', 'int')->end();

		$executor = new RecordingExecutor(fetchOneRow: ['post_id' => 1, 'user_id' => 2]);
		$query = new SelectQuery($postUser, $executor);

		self::assertSame(['post_id' => 1, 'user_id' => 2], $query->fetchOne([1, 2]));
		self::assertCount(2, $executor->identityConditionsAtFetchOne);

		$key = $postUser->getKey(['post_id' => 3, 'user_id' => 4]);
		$query->fetchOne($key);
		self::assertCount(2, $executor->identityConditionsAtFetchOne);
		self::assertSame([], $query->getConditionList()->getByTag(ConditionTag::IDENTITY));
	}

	public function testFetchOneIdentityRejectsDerivedAndNestedSources(): void
	{
		$users = $this->makeRegistry()->getCollection('users');
		$executor = new RecordingExecutor();

		$aliased = (new SelectQuery($users, $executor))->as('u');

		try {
			$aliased->fetchOne(1);
			self::fail('Expected aliased query identity fetch to be rejected.');
		} catch (InvalidArgumentException $exception) {
			self::assertStringContainsString('collection-root query', $exception->getMessage());
		}

		$innerQuery = new SelectQuery($users, $executor);
		$inner = $innerQuery->select($innerQuery->id)->as('inner_users');
		$nested = new SelectQuery($inner, $executor);

		try {
			$nested->fetchOne(1);
			self::fail('Expected nested query identity fetch to be rejected.');
		} catch (InvalidArgumentException $exception) {
			self::assertStringContainsString('collection-root query', $exception->getMessage());
		}
	}

	public function testFetchOneIdentityRejectsInvalidPrimaryKeyInput(): void
	{
		$users = $this->makeRegistry()->getCollection('users');
		$query = new SelectQuery($users, new RecordingExecutor());

		$this->expectException(InvalidPrimaryKeyException::class);
		$query->fetchOne(['id' => 1, 'extra' => 2]);
	}

	public function testFetchOneIdentityRejectsKeyFromAnotherCollection(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$posts = $registry->collection('posts')
			->primaryKey('id')
			->field('id', 'int')->end();

		$query = new SelectQuery($users, new RecordingExecutor());

		$this->expectException(InvalidPrimaryKeyException::class);
		$query->fetchOne(new Key($posts, ['id' => 1]));
	}

	public function testUnboundExecutionMethodsThrow(): void
	{
		$query = query($this->makeRegistry()->getCollection('users'));

		foreach ([
			static fn (SelectQuery $query): mixed => $query->fetchAll(),
			static fn (SelectQuery $query): mixed => $query->fetchOne(),
			static fn (SelectQuery $query): mixed => iterator_to_array($query->iterate(), false),
		] as $call) {
			try {
				$call($query);
				self::fail('Expected unbound execution to throw.');
			} catch (QueryNotExecutableException $exception) {
				self::assertSame(
					"Query for definition 'users' is not executable because no executor is bound.",
					$exception->getMessage(),
				);
			}
		}
	}

	public function testDetachReturnsTheSameQueryRemovesExecutionAndPreservesState(): void
	{
		$registry = $this->makeRegistry();
		$query = new SelectQuery($registry->getCollection('users'), new RecordingExecutor());
		$field = $query->id;
		$star = $query->star();

		$query
			->select($query->id, $query->name->as('display_name'))
			->where(x()->eq($query->active, true))
			->groupBy($query->name)
			->having(x()->gt($query->id->count(), 0))
			->orderBy($query->id->asc())
			->limit(5)
			->offset(10);

		$selections = $query->getSelections();
		$conditions = $query->getConditions();
		$groups = $query->getGroups();
		$having = $query->getHavingConditions();
		$sorts = $query->getSorts();

		$detached = $query->detach();

		self::assertSame($query, $detached);
		self::assertFalse($query->isExecutable());
		self::assertSame($field, $query->id);
		self::assertSame($star, $query->star());
		self::assertSame($selections, $query->getSelections());
		self::assertSame($selections->getAll(), $query->getSelections()->getAll());
		self::assertSame($conditions, $query->getConditions());
		self::assertSame($groups, $query->getGroups());
		self::assertSame($having, $query->getHavingConditions());
		self::assertSame($sorts, $query->getSorts());
		self::assertSame(5, $query->getLimit());
		self::assertSame(10, $query->getOffset());
	}

	private function makeRegistry(): Registry
	{
		$registry = new Registry();
		$users = $registry->collection('users');
		$users->primaryKey('id');
		$users->field('id', 'int');
		$users->field('name', 'string');
		$users->field('active', 'bool');
		$users->relation('posts', CustomRelation::class);

		return $registry;
	}
}

final class RecordingExecutor implements QueryExecutorInterface
{
	/**
	 * @var list<string>
	 */
	public array $calls = [];

	public ?SelectQuery $lastQuery = null;

	/**
	 * @var list<ConditionInterface>
	 */
	public array $identityConditionsAtFetchOne = [];

	/**
	 * @param list<array<string, mixed>> $fetchAllRows
	 * @param list<array<string, mixed>> $iterateRows
	 */
	public function __construct(
		private readonly array $fetchAllRows = [['id' => 1]],
		private readonly ?array $fetchOneRow = ['id' => 1],
		private readonly array $iterateRows = [['id' => 1]],
	) {
	}

	public function fetchAll(SelectQuery $query): array
	{
		$this->calls[] = 'fetchAll';
		$this->lastQuery = $query;

		return $this->fetchAllRows;
	}

	public function fetchOne(SelectQuery $query): ?array
	{
		$this->calls[] = 'fetchOne';
		$this->lastQuery = $query;
		$this->identityConditionsAtFetchOne = $query->getConditionList()->getConditionsByTag(ConditionTag::IDENTITY);

		return $this->fetchOneRow;
	}

	public function iterate(SelectQuery $query): iterable
	{
		$this->calls[] = 'iterate';
		$this->lastQuery = $query;

		yield from $this->generator();
	}

	/**
	 * @return Generator<int, array<string, mixed>>
	 */
	private function generator(): Generator
	{
		foreach ($this->iterateRows as $row) {
			yield $row;
		}
	}
}
