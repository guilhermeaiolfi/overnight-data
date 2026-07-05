<?php

declare(strict_types=1);

namespace Tests\ON\Data\Query;

use ON\Data\Database\QueryExecutorInterface;
use ON\Data\Definition\Registry;
use ON\Data\ORM\Session;
use ON\Data\Query\Exception\ObjectExportException;
use ON\Data\Query\Result\ObjectResultMaterializer;
use ON\Data\Query\SelectQuery;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\Support\RecordingCommandExecutor;

final class ObjectResultExportTest extends TestCase
{
	public function testFetchAllReturnsArraysByDefault(): void
	{
		$query = new SelectQuery(
			$this->makeRegistry()->getCollection('users'),
			new ObjectExportRecordingExecutor(),
		);

		$rows = $query->fetchAll();

		self::assertSame([['id' => 1, 'name' => 'Guilherme']], $rows);
		self::assertIsArray($rows[0]);
	}

	public function testToStdClassFetchAllReturnsStdClassObjects(): void
	{
		$query = new SelectQuery(
			$this->makeRegistry()->getCollection('users'),
			new ObjectExportRecordingExecutor(),
		);

		$rows = $query->to(stdClass::class)->fetchAll();

		self::assertCount(1, $rows);
		self::assertInstanceOf(stdClass::class, $rows[0]);
		self::assertSame(1, $rows[0]->id);
		self::assertSame('Guilherme', $rows[0]->name);
	}

	public function testToStdClassFetchOneReturnsStdClassOrNull(): void
	{
		$query = new SelectQuery(
			$this->makeRegistry()->getCollection('users'),
			new ObjectExportRecordingExecutor(),
		);

		$row = $query->to(stdClass::class)->fetchOne();

		self::assertInstanceOf(stdClass::class, $row);
		self::assertSame(1, $row->id);
		self::assertSame('Guilherme', $row->name);
	}

	public function testToStdClassFetchOneReturnsNullWhenNoRow(): void
	{
		$query = new SelectQuery(
			$this->makeRegistry()->getCollection('users'),
			new ObjectExportRecordingExecutor(fetchOneRow: null),
		);

		self::assertNull($query->to(stdClass::class)->fetchOne());
	}

	public function testScalarFieldsBecomePublicProperties(): void
	{
		$materializer = new ObjectResultMaterializer();

		$object = $materializer->materialize([
			'id' => 42,
			'name' => 'Ada',
			'active' => true,
			'score' => 9.5,
		], stdClass::class);

		self::assertSame(42, $object->id);
		self::assertSame('Ada', $object->name);
		self::assertTrue($object->active);
		self::assertSame(9.5, $object->score);
	}

	public function testNestedAssociativeArraysBecomeNestedStdClassObjects(): void
	{
		$materializer = new ObjectResultMaterializer();

		$object = $materializer->materialize([
			'id' => 1,
			'profile' => [
				'label' => 'Primary',
				'meta' => [
					'verified' => true,
				],
			],
		], stdClass::class);

		self::assertInstanceOf(stdClass::class, $object->profile);
		self::assertSame('Primary', $object->profile->label);
		self::assertInstanceOf(stdClass::class, $object->profile->meta);
		self::assertTrue($object->profile->meta->verified);
	}

	public function testRelationListArraysBecomeArraysOfStdClassObjects(): void
	{
		$materializer = new ObjectResultMaterializer();

		$object = $materializer->materialize([
			'id' => 1,
			'name' => 'Guilherme',
			'posts' => [
				['id' => 10, 'title' => 'Hello'],
				['id' => 11, 'title' => 'World'],
			],
		], stdClass::class);

		self::assertIsArray($object->posts);
		self::assertCount(2, $object->posts);
		self::assertInstanceOf(stdClass::class, $object->posts[0]);
		self::assertSame(10, $object->posts[0]->id);
		self::assertSame('Hello', $object->posts[0]->title);
		self::assertInstanceOf(stdClass::class, $object->posts[1]);
		self::assertSame(11, $object->posts[1]->id);
	}

	public function testScalarListsRemainArrays(): void
	{
		$materializer = new ObjectResultMaterializer();

		$object = $materializer->materialize([
			'id' => 1,
			'tags' => ['php', 'orm'],
			'counts' => [1, 2, 3],
		], stdClass::class);

		self::assertSame(['php', 'orm'], $object->tags);
		self::assertSame([1, 2, 3], $object->counts);
	}

	public function testNullValuesArePreserved(): void
	{
		$materializer = new ObjectResultMaterializer();

		$object = $materializer->materialize([
			'id' => 1,
			'name' => null,
			'posts' => null,
		], stdClass::class);

		self::assertNull($object->name);
		self::assertNull($object->posts);
	}

	public function testExistingObjectsAreUnchanged(): void
	{
		$materializer = new ObjectResultMaterializer();
		$existing = new stdClass();
		$existing->label = 'kept';

		$object = $materializer->materialize([
			'profile' => $existing,
		], stdClass::class);

		self::assertSame($existing, $object->profile);
	}

	public function testUnsupportedClassPassedToToThrowsClearException(): void
	{
		$query = new SelectQuery(
			$this->makeRegistry()->getCollection('users'),
			new ObjectExportRecordingExecutor(),
		);

		$this->expectException(ObjectExportException::class);
		$this->expectExceptionMessage('Object export currently supports stdClass only');

		$query->to('App\\User');
	}

	public function testToStdClassFetchAllDoesNotRequireOrmSession(): void
	{
		$executor = new ObjectExportRecordingExecutor(
			fetchAllRows: [[
				'id' => 1,
				'name' => 'Guilherme',
				'posts' => [
					['id' => 10, 'title' => 'Hello'],
				],
			]],
		);
		$query = new SelectQuery($this->makeRegistry()->getCollection('users'), $executor);

		$rows = $query->to(stdClass::class)->fetchAll();

		self::assertInstanceOf(stdClass::class, $rows[0]);
		self::assertSame(stdClass::class, $query->getResultClass());
		self::assertInstanceOf(stdClass::class, $rows[0]->posts[0]);
	}

	public function testToStdClassMaterializesAfterExecutorFetchWithoutBindingSideEffects(): void
	{
		$executor = new ObjectExportRecordingExecutor();
		$query = new SelectQuery($this->makeRegistry()->getCollection('users'), $executor);

		$query->to(stdClass::class)->fetchAll();

		self::assertSame([['id' => 1, 'name' => 'Guilherme']], $executor->lastFetchAllResult);
	}

	public function testCopyPreservesResultClass(): void
	{
		$query = new SelectQuery($this->makeRegistry()->getCollection('users'));
		$query->to(stdClass::class);

		self::assertSame(stdClass::class, $query->copy()->getResultClass());
	}

	public function testMaterializeAllConvertsEachRow(): void
	{
		$materializer = new ObjectResultMaterializer();

		$rows = $materializer->materializeAll([
			['id' => 1],
			['id' => 2],
		], stdClass::class);

		self::assertCount(2, $rows);
		self::assertInstanceOf(stdClass::class, $rows[0]);
		self::assertInstanceOf(stdClass::class, $rows[1]);
		self::assertSame(1, $rows[0]->id);
		self::assertSame(2, $rows[1]->id);
	}

	public function testIterateReturnsArraysByDefault(): void
	{
		$query = new SelectQuery(
			$this->makeRegistry()->getCollection('users'),
			new ObjectExportRecordingExecutor(),
		);

		$rows = iterator_to_array($query->iterate(), false);

		self::assertSame([['id' => 1, 'name' => 'Guilherme']], $rows);
		self::assertIsArray($rows[0]);
	}

	public function testToStdClassIterateReturnsStdClassObjects(): void
	{
		$query = new SelectQuery(
			$this->makeRegistry()->getCollection('users'),
			new ObjectExportRecordingExecutor(),
		);

		$rows = iterator_to_array($query->to(stdClass::class)->iterate(), false);

		self::assertCount(1, $rows);
		self::assertInstanceOf(stdClass::class, $rows[0]);
		self::assertSame(1, $rows[0]->id);
		self::assertSame('Guilherme', $rows[0]->name);
	}

	public function testToStdClassIterateConvertsNestedAssociativeArrays(): void
	{
		$executor = new ObjectExportRecordingExecutor(
			fetchAllRows: [[
				'id' => 1,
				'profile' => [
					'label' => 'Primary',
					'meta' => ['verified' => true],
				],
			]],
		);
		$query = new SelectQuery($this->makeRegistry()->getCollection('users'), $executor);

		$row = $query->to(stdClass::class)->iterate()->current();

		self::assertInstanceOf(stdClass::class, $row);
		self::assertInstanceOf(stdClass::class, $row->profile);
		self::assertSame('Primary', $row->profile->label);
		self::assertInstanceOf(stdClass::class, $row->profile->meta);
		self::assertTrue($row->profile->meta->verified);
	}

	public function testToStdClassIterateConvertsRelationListArrays(): void
	{
		$executor = new ObjectExportRecordingExecutor(
			fetchAllRows: [[
				'id' => 1,
				'name' => 'Guilherme',
				'posts' => [
					['id' => 10, 'title' => 'Hello'],
					['id' => 11, 'title' => 'World'],
				],
			]],
		);
		$query = new SelectQuery($this->makeRegistry()->getCollection('users'), $executor);

		$row = $query->to(stdClass::class)->iterate()->current();

		self::assertInstanceOf(stdClass::class, $row);
		self::assertIsArray($row->posts);
		self::assertInstanceOf(stdClass::class, $row->posts[0]);
		self::assertSame(10, $row->posts[0]->id);
		self::assertInstanceOf(stdClass::class, $row->posts[1]);
		self::assertSame(11, $row->posts[1]->id);
	}

	public function testToStdClassIterateIsLazy(): void
	{
		$executor = new LazyIterateRecordingExecutor([
			['id' => 1, 'name' => 'First'],
			['id' => 2, 'name' => 'Second'],
			['id' => 3, 'name' => 'Third'],
		]);
		$query = new SelectQuery($this->makeRegistry()->getCollection('users'), $executor);

		$iterator = $query->to(stdClass::class)->iterate();
		$first = $iterator->current();

		self::assertInstanceOf(stdClass::class, $first);
		self::assertSame(1, $first->id);
		self::assertSame(1, $executor->iteratedRows);
	}

	public function testMutableIterateThrowsClearException(): void
	{
		$query = new SelectQuery(
			$this->makeRegistry()->getCollection('users'),
			new ObjectExportRecordingExecutor(),
		);

		$this->expectException(ObjectExportException::class);
		$this->expectExceptionMessage('Mutable object export is not supported by iterate(); use fetchAll() or fetchOne().');

		$query->to(stdClass::class)->mutable(new Session(new RecordingCommandExecutor()))->iterate();
	}

	private function makeRegistry(): Registry
	{
		$registry = new Registry();

		$registry->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();

		return $registry;
	}
}

final class ObjectExportRecordingExecutor implements QueryExecutorInterface
{
	/**
	 * @var list<array<string, mixed>>|null
	 */
	public ?array $lastFetchAllResult = null;

	/**
	 * @param list<array<string, mixed>> $fetchAllRows
	 */
	public function __construct(
		private readonly array $fetchAllRows = [['id' => 1, 'name' => 'Guilherme']],
		private readonly ?array $fetchOneRow = ['id' => 1, 'name' => 'Guilherme'],
	) {
	}

	public function fetchAll(SelectQuery $query): array
	{
		$this->lastFetchAllResult = $this->fetchAllRows;

		return $this->fetchAllRows;
	}

	public function fetchOne(SelectQuery $query): ?array
	{
		return $this->fetchOneRow;
	}

	public function iterate(SelectQuery $query): iterable
	{
		yield from $this->fetchAllRows;
	}
}

final class LazyIterateRecordingExecutor implements QueryExecutorInterface
{
	public int $iteratedRows = 0;

	/**
	 * @param list<array<string, mixed>> $rows
	 */
	public function __construct(
		private readonly array $rows,
	) {
	}

	public function fetchAll(SelectQuery $query): array
	{
		return $this->rows;
	}

	public function fetchOne(SelectQuery $query): ?array
	{
		return $this->rows[0] ?? null;
	}

	public function iterate(SelectQuery $query): iterable
	{
		foreach ($this->rows as $row) {
			$this->iteratedRows++;

			yield $row;
		}
	}
}
