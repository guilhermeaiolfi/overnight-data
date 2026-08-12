<?php

declare(strict_types=1);

namespace Tests\ON\Data\Query;

use ON\Data\Database\QueryExecutorInterface;
use ON\Data\Definition\Registry;
use ON\Data\Query\SelectQuery;
use PHPUnit\Framework\TestCase;

/**
 * Without relation loads, LoadRuntime still assembles when output names differ
 * from field names (renames / flat related fields). Stub executors return SQL
 * aliases as Cycle would after emission: root-owned columns use the output name.
 */
final class RootLoadLocalProjectionTest extends TestCase
{
	public function testRootRenamedOwnFieldUsesPlaceSelectionKey(): void
	{
		$query = new SelectQuery(
			$this->makeRegistry()->getCollection('users'),
			new RootLoadLocalExecutor([
				'id' => 1,
				'displayName' => 'Ada',
			]),
		);
		$query->select($query->id, $query->name->as('displayName'));

		self::assertSame([[
			'id' => 1,
			'displayName' => 'Ada',
		]], $query->fetchAll());
	}

	public function testRootFlatRelatedFieldUsesPlaceSelectionKey(): void
	{
		$query = new SelectQuery(
			$this->makeRegistry()->getCollection('users'),
			new RootLoadLocalExecutor([
				'id' => 1,
				'companyName' => 'Acme',
			]),
		);
		$query->select($query->id, $query->company->name->as('companyName'));

		self::assertSame([[
			'id' => 1,
			'companyName' => 'Acme',
		]], $query->fetchAll());
	}

	private function makeRegistry(): Registry
	{
		$registry = new Registry();

		$registry->collection('companies')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();

		$registry->collection('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('company_id', 'int')->end()
			->field('name', 'string')->end();

		$registry->getCollection('users')
			->belongsTo('company', 'companies')
			->innerKey('company_id')
			->outerKey('id');

		return $registry;
	}
}

final class RootLoadLocalExecutor implements QueryExecutorInterface
{
	/**
	 * @param array<string, mixed> $row
	 */
	public function __construct(
		private readonly array $row,
	) {
	}

	public function fetchAll(SelectQuery $query): array
	{
		return [$this->row];
	}

	public function fetchOne(SelectQuery $query): ?array
	{
		return $this->row;
	}

	public function iterate(SelectQuery $query): iterable
	{
		yield $this->row;
	}
}
