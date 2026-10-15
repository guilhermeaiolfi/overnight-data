<?php

declare(strict_types=1);

namespace Tests\ON\Data\Query;

use DateTimeImmutable;
use ON\Data\Database\Cycle\CycleQueryExecutor;
use ON\Data\Database\Exception\UnsupportedQueryException;
use ON\Data\Database\QueryExecutorInterface;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\Mapper\ConversionGateway;
use ON\Data\Query\Condition\ConditionTag;
use ON\Data\Query\Result\WritablePreparation;
use ON\Data\Query\Result\WritableResultHandler;
use ON\Data\Query\SelectQuery;
use ON\Data\Schema\RepresentationSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Tests\ON\Data\Smoke\Support\SqliteMemoryHarness;
use WeakReference;

final class SelectQueryFetchIsolationTest extends TestCase
{
	#[RequiresPhpExtension('pdo_sqlite')]
	public function testCopiedUnaliasedComputedSelectionKeepsExecutorValidation(): void
	{
		$query = $this->database()->database->query($this->users());
		$query->select($query->name->upper());
		$copy = $query->copy();

		$this->expectException(UnsupportedQueryException::class);
		$this->expectExceptionMessage('unaliased computed, aggregate, and subquery root selections are not supported');
		$copy->fetchAll();
	}

	#[RequiresPhpExtension('pdo_sqlite')]
	public function testChangingJoinToSeparateDoesNotReuseGeneratedJoin(): void
	{
		$harness = $this->database();
		$query = $harness->database->query($this->users());
		$query->select($query->id, $query->name)->orderBy($query->id->asc());
		$query->posts->select('id', 'title')->join();
		$selections = $query->getSelections()->getAll();

		self::assertSame([1, 2], array_column($query->fetchAll(), 'id'));
		$query->posts->separate();
		$rows = $query->fetchAll();

		self::assertSame([1, 2, 3], array_column($rows, 'id'));
		self::assertSame([], $rows[2]['posts']);
		self::assertSame([], $query->getJoins());
		self::assertSame($selections, $query->getSelections()->getAll());
		self::assertNotNull($query->getFetchSchema());
	}

	public function testCompletedFetchDoesNotRetainResultValues(): void
	{
		$executor = new class () implements QueryExecutorInterface {
			public ?WeakReference $value = null;

			public function fetchAll(SelectQuery $query): array
			{
				if ($query->getCollection()->getName() !== 'users') {
					return [];
				}

				$value = new DateTimeImmutable('2026-01-01');
				$this->value = WeakReference::create($value);

				return [['id' => 1, 'createdOn' => $value]];
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
		$query = new SelectQuery($this->users(), $executor);
		$query->select($query->id, $query->createdAt->as('createdOn'));
		$query->posts->select('id')->separate();
		$rows = $query->fetchAll();
		self::assertSame($executor->value->get(), $rows[0]['createdOn']);

		unset($rows);
		gc_collect_cycles();

		self::assertNull($executor->value->get());
		self::assertNotNull($query->getFetchSchema());
	}

	public function testFailedPreparedFetchLeavesBuilderUntouchedForRetry(): void
	{
		$executor = new class () implements QueryExecutorInterface {
			private bool $fail = true;

			public function fetchAll(SelectQuery $query): array
			{
				if ($this->fail) {
					$this->fail = false;

					throw new RuntimeException('Fetch failed after preparation.');
				}

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
		$query = new SelectQuery($this->users(), $executor);
		$query->select($query->id);
		$query->posts->select('id')->join();
		$selections = $query->getSelections()->getAll();

		try {
			$query->fetchAll();
			self::fail('Expected the first fetch to fail.');
		} catch (RuntimeException $exception) {
			self::assertSame('Fetch failed after preparation.', $exception->getMessage());
		}

		self::assertSame([], $query->getJoins());
		self::assertSame($selections, $query->getSelections()->getAll());
		self::assertNotNull($query->getFetchSchema());
		$query->posts->separate();
		self::assertSame([], $query->fetchAll());
	}

	#[DataProvider('nestedFetchOutcomes')]
	public function testNestedIdentityFetchKeepsOuterConstraint(bool $innerFails): void
	{
		$executor = new class ($innerFails) implements QueryExecutorInterface {
			private bool $nested = false;
			public ?array $innerResult = null;

			public function __construct(private readonly bool $innerFails)
			{
			}

			public function fetchOne(SelectQuery $query): ?array
			{
				if (! $this->nested) {
					$this->nested = true;

					try {
						$this->innerResult = $query->fetchOne(2);
					} catch (RuntimeException $exception) {
						if (! $this->innerFails) {
							throw $exception;
						}
					} finally {
						$this->nested = false;
					}
				} elseif ($this->innerFails) {
					throw new RuntimeException('Inner fetch failed.');
				}

				$identity = $query->getConditionList()->getConditionsByTag(ConditionTag::IDENTITY);

				return ['id' => $identity === [] ? null : $identity[0]->getRight()->getValue()];
			}

			public function fetchAll(SelectQuery $query): array
			{
				return [];
			}

			public function iterate(SelectQuery $query): iterable
			{
				return [];
			}
		};
		$query = new SelectQuery($this->users(), $executor);
		$query->where($query->id->gt(0));

		self::assertSame(['id' => 1], $query->fetchOne(1));
		self::assertSame($innerFails ? null : ['id' => 2], $executor->innerResult);
		self::assertSame([], $query->getConditionList()->getByTag(ConditionTag::IDENTITY));
		self::assertCount(1, $query->getConditionList()->getByTag(ConditionTag::USER));
	}

	/** @return iterable<string, array{bool}> */
	public static function nestedFetchOutcomes(): iterable
	{
		yield 'success' => [false];
		yield 'caught failure' => [true];
	}

	#[RequiresPhpExtension('pdo_sqlite')]
	public function testWritablePreparationAndTrackingShareTheExecutedQuery(): void
	{
		$handler = new class () implements WritableResultHandler {
			public ?SelectQuery $prepared = null;
			public ?SelectQuery $tracked = null;
			public ?WritablePreparation $preparation = null;
			public ?WritablePreparation $trackedPreparation = null;

			public function prepare(SelectQuery $query): WritablePreparation
			{
				$this->prepared = $query;

				return $this->preparation = new class ($query->projection()) implements WritablePreparation {
					public function __construct(private readonly RepresentationSchema $schema)
					{
					}

					public function getFetchSchema(): ?RepresentationSchema
					{
						return $this->schema;
					}
				};
			}

			public function track(SelectQuery $query, WritablePreparation $preparation, array $rawRows, array $objects): void
			{
				$this->tracked = $query;
				$this->trackedPreparation = $preparation;
			}
		};
		$harness = $this->database();
		$backend = new CycleQueryExecutor($harness->cycleDatabase, ConversionGateway::createDefault());
		$executedQuery = null;
		$executor = $this->createMock(QueryExecutorInterface::class);
		$executor->expects(self::once())->method('fetchAll')->willReturnCallback(
			static function (SelectQuery $query) use ($backend, &$executedQuery): array {
				$executedQuery = $query;

				return $backend->fetchAll($query);
			},
		);
		$query = new SelectQuery($this->users(), $executor);
		$query->select($query->id, $query->name->upper()->as('displayName'));
		$query->to(stdClass::class)->writable($handler);
		$objects = $query->fetchAll();

		self::assertSame('ONE', $objects[0]->displayName);
		self::assertNotSame($query, $executedQuery);
		self::assertSame($handler->prepared, $executedQuery);
		self::assertSame($handler->prepared, $handler->tracked);
		self::assertSame($handler->preparation, $handler->trackedPreparation);
		self::assertSame($handler->preparation->getFetchSchema(), $query->getFetchSchema());
		self::assertSame([], $query->getJoins());
	}

	private function users(): CollectionInterface
	{
		$registry = new Registry();
		$registry->collection('posts')->primaryKey('id')
			->field('id', 'int')->end()->field('user_id', 'int')->end()->field('title', 'string')->end();
		$users = $registry->collection('users')->primaryKey('id')
			->field('id', 'int')->end()->field('name', 'string')->end()->field('createdAt', 'datetime')->end();
		$users->hasMany('posts', 'posts')->innerKey('id')->outerKey('user_id');

		return $users;
	}

	private function database(): SqliteMemoryHarness
	{
		$harness = SqliteMemoryHarness::create();
		$harness->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
		$harness->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, user_id INTEGER, title TEXT)');
		$harness->exec("INSERT INTO users VALUES (1, 'One'), (2, 'Two'), (3, 'Three')");
		$harness->exec("INSERT INTO posts VALUES (10, 1, 'A'), (11, 1, 'B'), (12, 2, 'C')");

		return $harness;
	}
}
