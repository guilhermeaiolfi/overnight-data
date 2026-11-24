<?php

declare(strict_types=1);

namespace ON\Data\Database\Sql\Dialect;

use ON\Data\Database\Sql\SqlFragment;

/** @internal */
abstract class AbstractDialect implements DialectInterface
{
	protected const OPEN_QUOTE = '"';
	protected const CLOSE_QUOTE = '"';
	protected const UNLIMITED = '';
	protected const DEFAULT_VALUES = 'DEFAULT VALUES';

	final public function quoteIdentifier(string $identifier): string
	{
		$close = static::CLOSE_QUOTE;

		return static::OPEN_QUOTE . str_replace($close, $close . $close, $identifier) . $close;
	}

	final public function quoteTable(string $table, string $prefix): string
	{
		$parts = explode('.', $table);
		$last = array_key_last($parts);
		$parts[$last] = $prefix . $parts[$last];

		return implode('.', array_map($this->quoteIdentifier(...), $parts));
	}

	public function compileSelectPrefix(?int $limit): SqlFragment
	{
		return SqlFragment::raw('SELECT');
	}

	public function compileLimitOffset(?int $limit, ?int $offset, bool $hasOrderBy, bool $nested = false): SqlFragment
	{
		$parts = [];
		$params = [];
		if ($limit !== null) {
			$parts[] = 'LIMIT ?';
			$params[] = $limit;
		} elseif ($offset !== null && static::UNLIMITED !== '') {
			$parts[] = static::UNLIMITED;
		}

		if ($offset !== null) {
			$parts[] = 'OFFSET ?';
			$params[] = $offset;
		}

		return SqlFragment::withParameters(implode(' ', $parts), $params);
	}

	public function compileConcat(array $sqlArguments): string
	{
		return '(' . implode(' || ', $sqlArguments) . ')';
	}

	public function compileInsert(string $table, array $columns, array $valuePlaceholders, array $returningColumns): string
	{
		$sql = 'INSERT INTO ' . $table . $this->insertColumns($columns) . ' ' . $this->insertValues($valuePlaceholders);
		if ($returningColumns !== [] && $this->supportsInsertReturning()) {
			$sql .= ' RETURNING ' . implode(', ', $returningColumns);
		}

		return $sql;
	}

	public function supportsInsertReturning(): bool
	{
		return true;
	}

	public function sqliteChangesQuery(): ?string
	{
		return null;
	}

	/** @param list<string> $columns */
	protected function insertColumns(array $columns): string
	{
		return $columns === [] ? '' : ' (' . implode(', ', $columns) . ')';
	}

	/** @param list<string> $values */
	protected function insertValues(array $values): string
	{
		return $values === [] ? static::DEFAULT_VALUES : 'VALUES (' . implode(', ', $values) . ')';
	}
}
