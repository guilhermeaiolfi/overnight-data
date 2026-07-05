<?php

declare(strict_types=1);

namespace Tests\ON\Data\Query;

use ON\Data\Database\QueryExecutorInterface;
use ON\Data\Definition\Registry;
use ON\Data\Query\Selection\SelectionTag;
use ON\Data\Query\SelectQuery;
use PHPUnit\Framework\TestCase;
use stdClass;

final class InternalSelectionPublicOutputTest extends TestCase
{
	public function testInternalSelectionsAreStrippedFromFetchAllArrayOutput(): void
	{
		$query = $this->queryWithInternalSelection();

		$rows = $query->fetchAll();

		self::assertSame([['id' => 1, 'name' => 'Ada']], $rows);
		self::assertArrayNotHasKey('_od_internal_1', $rows[0]);
	}

	public function testInternalSelectionsAreStrippedFromFetchOneArrayOutput(): void
	{
		$query = $this->queryWithInternalSelection();

		$row = $query->fetchOne();

		self::assertSame(['id' => 1, 'name' => 'Ada'], $row);
		self::assertArrayNotHasKey('_od_internal_1', $row);
	}

	public function testInternalSelectionsAreStrippedFromToStdClassOutput(): void
	{
		$query = $this->queryWithInternalSelection();

		$row = $query->to(stdClass::class)->fetchOne();

		self::assertInstanceOf(stdClass::class, $row);
		self::assertSame(1, $row->id);
		self::assertSame('Ada', $row->name);
		self::assertFalse(property_exists($row, '_od_internal_1'));
	}

	public function testInternalSelectionsAreStrippedFromIterateArrayOutput(): void
	{
		$query = $this->queryWithInternalSelection();

		$rows = iterator_to_array($query->iterate(), false);

		self::assertSame([['id' => 1, 'name' => 'Ada']], $rows);
		self::assertArrayNotHasKey('_od_internal_1', $rows[0]);
	}

	public function testInternalSelectionsAreStrippedFromToStdClassIterateOutput(): void
	{
		$query = $this->queryWithInternalSelection();

		$rows = iterator_to_array($query->to(stdClass::class)->iterate(), false);

		self::assertCount(1, $rows);
		self::assertInstanceOf(stdClass::class, $rows[0]);
		self::assertFalse(property_exists($rows[0], '_od_internal_1'));
	}

	public function testUserAliasStartingWithInternalPrefixIsNotStrippedUnlessTaggedInternal(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$query = new SelectQuery($users, new InternalSelectionPublicOutputExecutor());
		$query->select($query->id, $query->name->as('__od.foo'));

		$row = $query->fetchOne();

		self::assertSame(['id' => 1, '__od.foo' => 'Ada'], $row);
	}

	private function queryWithInternalSelection(): SelectQuery
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$query = new SelectQuery($users, new InternalSelectionPublicOutputExecutor());
		$query->select($query->id, $query->name);
		$query->getSelections()->add($query->id->as('_od_internal_1'), SelectionTag::INTERNAL);

		return $query;
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

final class InternalSelectionPublicOutputExecutor implements QueryExecutorInterface
{
	public function fetchAll(SelectQuery $query): array
	{
		return [$this->fetchOne($query) ?? []];
	}

	public function fetchOne(SelectQuery $query): ?array
	{
		$row = [
			'id' => 1,
			'name' => 'Ada',
		];

		if ($query->getSelections()->hasSelectionKey('__od.foo')) {
			$row['__od.foo'] = 'Ada';
			unset($row['name']);
		}

		foreach ($query->getSelections()->getByTag(SelectionTag::INTERNAL) as $selection) {
			$row[$selection->getSelectionKey()] = 99;
		}

		return $row;
	}

	public function iterate(SelectQuery $query): iterable
	{
		yield from $this->fetchAll($query);
	}
}
