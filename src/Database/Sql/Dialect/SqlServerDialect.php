<?php

declare(strict_types=1);

namespace ON\Data\Database\Sql\Dialect;

use ON\Data\Database\DatabaseFamily;
use ON\Data\Database\Sql\SqlFragment;

/** @internal */
final class SqlServerDialect extends AbstractDialect
{
	protected const OPEN_QUOTE = '[';
	protected const CLOSE_QUOTE = ']';

	public function __construct(private readonly bool $returning = true)
	{
	}

	public function family(): DatabaseFamily
	{
		return DatabaseFamily::SqlServer;
	}

	public function compileSelectPrefix(?int $limit): SqlFragment
	{
		return $limit === 0 ? SqlFragment::withParameters('SELECT TOP (?)', [0]) : parent::compileSelectPrefix($limit);
	}

	public function compileLimitOffset(?int $limit, ?int $offset, bool $hasOrderBy, bool $nested = false): SqlFragment
	{
		if ($limit === 0) {
			return SqlFragment::raw('');
		}

		if ($limit === null && $offset === null && ! ($nested && $hasOrderBy)) {
			return SqlFragment::raw('');
		}

		$order = $hasOrderBy ? '' : 'ORDER BY (SELECT NULL) ';

		return $limit === null
			? SqlFragment::withParameters($order . 'OFFSET ? ROWS', [$offset ?? 0])
			: SqlFragment::withParameters($order . 'OFFSET ? ROWS FETCH NEXT ? ROWS ONLY', [$offset ?? 0, $limit]);
	}

	public function compileConcat(array $sqlArguments): string
	{
		return 'CONCAT(' . implode(', ', $sqlArguments) . ')';
	}

	public function compileInsert(string $table, array $columns, array $valuePlaceholders, array $returningColumns): string
	{
		$sql = 'INSERT INTO ' . $table . $this->insertColumns($columns);
		if ($returningColumns !== [] && $this->supportsInsertReturning()) {
			$sql .= ' OUTPUT ' . implode(', ', array_map(static fn (string $column): string => 'INSERTED.' . $column, $returningColumns));
		}

		return $sql . ' ' . $this->insertValues($valuePlaceholders);
	}

	public function supportsInsertReturning(): bool
	{
		return $this->returning;
	}
}
