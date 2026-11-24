<?php

declare(strict_types=1);

namespace ON\Data\Database\Sql\Dialect;

use ON\Data\Database\DatabaseFamily;
use ON\Data\Database\Sql\SqlFragment;

/** @internal */
interface DialectInterface
{
	public function family(): DatabaseFamily;

	/** Quotes one identifier, including any literal dots. */
	public function quoteIdentifier(string $identifier): string;

	public function quoteTable(string $table, string $prefix): string;

	public function compileSelectPrefix(?int $limit): SqlFragment;

	public function compileLimitOffset(?int $limit, ?int $offset, bool $hasOrderBy, bool $nested = false): SqlFragment;

	/** @param list<string> $sqlArguments */
	public function compileConcat(array $sqlArguments): string;

	/**
	 * Table and column names are already quoted by the compiler.
	 *
	 * @param list<string> $columns
	 * @param list<string> $valuePlaceholders
	 * @param list<string> $returningColumns
	 */
	public function compileInsert(string $table, array $columns, array $valuePlaceholders, array $returningColumns): string;

	public function supportsInsertReturning(): bool;

	public function sqliteChangesQuery(): ?string;
}
