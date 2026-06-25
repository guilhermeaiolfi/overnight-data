<?php

declare(strict_types=1);

namespace Tests\ON\Data\Query;

use Generator;
use ON\Data\Database\Exception\QueryNotExecutableException;
use ON\Data\Database\QueryExecutorInterface;
use ON\Data\Definition\Registry;
use function ON\Data\Query\query;
use ON\Data\Query\SelectQuery;
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
			->where(\ON\Data\Query\x()->eq($query->active, true))
			->groupBy($query->name)
			->having(\ON\Data\Query\x()->gt($query->id->count(), 0))
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
