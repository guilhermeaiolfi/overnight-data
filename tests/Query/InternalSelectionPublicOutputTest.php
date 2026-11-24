<?php

declare(strict_types=1);

namespace Tests\ON\Data\Query;

use ON\Data\Database\QueryExecutorInterface;
use ON\Data\Definition\Registry;
use ON\Data\Query\SelectQuery;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\Support\StubQueryRowProjector;

final class InternalSelectionPublicOutputTest extends TestCase
{
	public function testImplicitIdentityColumnsNeverLeakIntoFetchAllOutput(): void
	{
		$query = $this->queryWithImplicitIdentity();

		$rows = $query->fetchAll();

		self::assertSame([['displayName' => 'Ada']], $rows);
		self::assertArrayNotHasKey('id', $rows[0]);
		self::assertArrayNotHasKey('c0', $rows[0]);
		self::assertArrayNotHasKey('c1', $rows[0]);
	}

	public function testImplicitIdentityColumnsNeverLeakIntoFetchOneOutput(): void
	{
		$query = $this->queryWithImplicitIdentity();

		$row = $query->fetchOne();

		self::assertSame(['displayName' => 'Ada'], $row);
		self::assertArrayNotHasKey('id', $row);
	}

	public function testImplicitIdentityColumnsNeverLeakIntoToStdClassOutput(): void
	{
		$query = $this->queryWithImplicitIdentity();

		$row = $query->to(stdClass::class)->fetchOne();

		self::assertInstanceOf(stdClass::class, $row);
		self::assertSame('Ada', $row->displayName);
		self::assertFalse(property_exists($row, 'id'));
		self::assertFalse(property_exists($row, 'c0'));
	}

	public function testUserAliasStartingWithInternalPrefixIsPreserved(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$query = new SelectQuery($users, new InternalSelectionPublicOutputExecutor());
		$query->select($query->id, $query->name->as('__od.foo'));

		$row = $query->fetchOne();

		self::assertSame(['id' => 1, '__od.foo' => 'Ada'], $row);
	}

	public function testPublicAliasLookingLikeLoadKeyIsPreserved(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$query = new SelectQuery($users, new InternalSelectionPublicOutputExecutor());
		$query->select($query->id, $query->name->as('l_custom_name'));

		$row = $query->fetchOne();

		self::assertSame(['id' => 1, 'l_custom_name' => 'Ada'], $row);
	}

	public function testPublicAliasMatchingColumnIndexSqlAliasIsPreserved(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$query = new SelectQuery($users, new InternalSelectionPublicOutputExecutor());
		$query->select($query->id, $query->name->as('c0'));

		$row = $query->fetchOne();

		self::assertSame(['id' => 1, 'c0' => 'Ada'], $row);
	}

	private function queryWithImplicitIdentity(): SelectQuery
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$query = new SelectQuery($users, new InternalSelectionPublicOutputExecutor());
		$query->select($query->name->as('displayName'));

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
		return StubQueryRowProjector::project($query, [
			'id' => 1,
			'name' => 'Ada',
		]);
	}

	public function iterate(SelectQuery $query): iterable
	{
		yield from $this->fetchAll($query);
	}
}
