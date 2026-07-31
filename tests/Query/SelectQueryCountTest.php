<?php

declare(strict_types=1);

namespace Tests\ON\Data\Query;

use ON\Data\Database\QueryExecutorInterface;
use ON\Data\Definition\Registry;
use ON\Data\Query\Exception\CountRequiresRootIdentityException;
use ON\Data\Query\Expression\AggregateExpression;
use ON\Data\Query\Expression\AggregateFunction;
use ON\Data\Query\Expression\AliasedExpression;
use function ON\Data\Query\query;
use ON\Data\Query\Result\WritablePreparation;
use ON\Data\Query\Result\WritableResultHandler;
use ON\Data\Query\SelectQuery;
use function ON\Data\Query\x;
use PHPUnit\Framework\TestCase;
use stdClass;

final class SelectQueryCountTest extends TestCase
{
	public function testCountUsesFetchOneWithAggregateAlias(): void
	{
		$executor = new CountRecordingExecutor(['__count' => 4]);
		$users = new SelectQuery($this->makeRegistry()->getCollection('users'), $executor);

		self::assertSame(4, $users->count());
		self::assertSame(['fetchOne'], $executor->calls);
		self::assertInstanceOf(SelectQuery::class, $executor->lastQuery);

		$selection = $executor->lastQuery->getSelections()->getAll()[0]->getExpression();
		self::assertInstanceOf(AliasedExpression::class, $selection);
		self::assertSame('__count', $selection->getAlias());
		self::assertInstanceOf(AggregateExpression::class, $selection->getExpression());
		self::assertSame(AggregateFunction::COUNT_DISTINCT, $selection->getExpression()->getFunction());
		self::assertTrue($executor->lastQuery->getRelationSelections()->isEmpty());
	}

	public function testCountDoesNotMutateReceiver(): void
	{
		$executor = new CountRecordingExecutor(['__count' => 1]);
		$users = new SelectQuery($this->makeRegistry()->getCollection('users'), $executor);
		$users->where(x()->eq($users->name, 'Ada'))->limit(2)->orderBy($users->name->asc());
		$users->posts->load();

		$condition = $users->getConditions()[0];
		$selections = $users->getSelections()->getAll();

		self::assertSame(1, $users->count());
		self::assertSame([$condition], $users->getConditions());
		self::assertSame($selections, $users->getSelections()->getAll());
		self::assertSame(2, $users->getLimit());
		self::assertCount(1, $users->getSorts());
		self::assertTrue($users->posts->isSelected());
	}

	public function testCountClearsProductModesOnExecutedQuery(): void
	{
		$executor = new CountRecordingExecutor(['__count' => 3]);
		$handler = new CountingWritableHandler();
		$users = new SelectQuery($this->makeRegistry()->getCollection('users'), $executor);
		$users->to(stdClass::class)->writable($handler);
		$users->posts->load();

		self::assertSame(3, $users->count());
		self::assertSame(stdClass::class, $users->getResultClass());
		self::assertSame($handler, $users->getWritableResultHandler());
		self::assertTrue($users->posts->isSelected());
		self::assertNull($executor->lastQuery?->getResultClass());
		self::assertNull($executor->lastQuery?->getWritableResultHandler());
		self::assertTrue($executor->lastQuery?->getRelationSelections()->isEmpty());
		self::assertSame(0, $handler->prepareCalls);
		self::assertSame(0, $handler->trackCalls);
	}

	public function testCountReturnsZeroWhenFetchOneIsEmpty(): void
	{
		$executor = new CountRecordingExecutor(null);

		self::assertSame(0, (new SelectQuery($this->makeRegistry()->getCollection('users'), $executor))->count());
	}

	public function testCountRejectsDerivedFromWithoutRootIdentityAndLeavesInnerUnchanged(): void
	{
		$executor = new CountRecordingExecutor(['__count' => 1]);
		$inner = query($this->makeRegistry()->getCollection('users'))
			->select(x()->literal(1)->as('marker'))
			->where(x()->eq(x()->literal(1), 1))
			->groupBy(x()->literal(1));
		$derived = $inner->as('derived_users');
		$outer = new SelectQuery($derived, $executor);

		try {
			$outer->count();
			self::fail('Expected CountRequiresRootIdentityException.');
		} catch (CountRequiresRootIdentityException $exception) {
			self::assertSame(
				'SelectQuery::count() requires a usable root identity. '
				. 'Count a collection-root query.',
				$exception->getMessage(),
			);
		}

		self::assertSame($derived, $outer->getFrom());
		self::assertSame($derived, $outer->copy()->getFrom());
		$marker = $inner->getSelections()->getAll()[0]->getExpression();
		self::assertInstanceOf(AliasedExpression::class, $marker);
		self::assertSame('marker', $marker->getAlias());
		self::assertCount(1, $inner->getConditions());
		self::assertCount(1, $inner->getGroups());
		self::assertSame('derived_users', $derived->getAlias());
		self::assertSame([], $executor->calls);
	}

	public function testGroupedCountWrapsDerivedSource(): void
	{
		$executor = new CountRecordingExecutor(['__count' => 2]);
		$users = new SelectQuery($this->makeRegistry()->getCollection('users'), $executor);
		$users->groupBy($users->name)->having(x()->gt($users->id->count(), 0));

		self::assertSame(2, $users->count());
		self::assertSame(['fetchOne'], $executor->calls);
		self::assertInstanceOf(SelectQuery::class, $executor->lastQuery);
		self::assertTrue($executor->lastQuery->isDerivedSource());
		self::assertSame('count_rows', $executor->lastQuery->getFrom()->getAlias());
	}

	private function makeRegistry(): Registry
	{
		$registry = new Registry();

		$users = $registry->collection('users');
		$users->field('id', 'int');
		$users->field('name', 'string');
		$users->hasMany('posts', 'posts')->innerKey('id')->outerKey('userId')->end();
		$users->primaryKey('id');

		$posts = $registry->collection('posts');
		$posts->field('id', 'int');
		$posts->field('userId', 'int');
		$posts->primaryKey('id');

		return $registry;
	}
}

final class CountRecordingExecutor implements QueryExecutorInterface
{
	/** @var list<string> */
	public array $calls = [];

	public ?SelectQuery $lastQuery = null;

	public function __construct(
		private readonly ?array $fetchOneRow,
	) {
	}

	public function fetchAll(SelectQuery $query): array
	{
		$this->calls[] = 'fetchAll';
		$this->lastQuery = $query;

		return $this->fetchOneRow === null ? [] : [$this->fetchOneRow];
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

		return [];
	}
}

final class CountingWritableHandler implements WritableResultHandler
{
	public int $prepareCalls = 0;

	public int $trackCalls = 0;

	public function prepare(SelectQuery $query): WritablePreparation
	{
		++$this->prepareCalls;

		return new class () implements WritablePreparation {};
	}

	public function track(
		SelectQuery $query,
		WritablePreparation $preparation,
		array $rawRows,
		array $objects,
	): void {
		++$this->trackCalls;
	}
}
