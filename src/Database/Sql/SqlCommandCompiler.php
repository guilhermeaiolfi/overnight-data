<?php

declare(strict_types=1);

namespace ON\Data\Database\Sql;

use ON\Data\Database\Sql\Dialect\DialectInterface;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Field\FieldInterface;
use ON\Data\ORM\Exception\InvalidCommandException;
use ON\Data\ORM\Persistence\CommandValueResolver;
use ON\Data\ORM\Persistence\DeleteCommand;
use ON\Data\ORM\Persistence\InsertCommand;
use ON\Data\ORM\Persistence\UpdateCommand;

/** @internal */
final readonly class SqlCommandCompiler
{
	public function __construct(
		private DialectInterface $dialect,
		private string $tablePrefix,
		private CommandValueResolver $resolver,
	) {
	}

	/** @param list<FieldInterface> $returning */
	public function insert(InsertCommand $command, array $returning): SqlFragment
	{
		$collection = $command->getCollection();
		$values = $this->mapFieldValuesToColumns($collection, $this->resolver->readyValues($command, 'values'));
		$sql = $this->dialect->compileInsert(
			$this->table($collection),
			array_keys($values),
			array_fill(0, count($values), '?'),
			$this->quoteColumns($returning),
		);

		return new SqlFragment($sql, array_values($values));
	}

	public function update(UpdateCommand $command): SqlFragment
	{
		$collection = $command->getCollection();
		$changes = $this->mapFieldValuesToColumns($collection, $this->resolver->readyValues($command, 'changes'));
		if ($changes === []) {
			throw new InvalidCommandException('Update command changes cannot be empty.');
		}

		$assignments = array_map(static fn (string $column): string => $column . ' = ?', array_keys($changes));
		$where = $this->identity($collection, $this->resolver->readyValues($command, 'identity'));

		return new SqlFragment(
			'UPDATE ' . $this->table($collection) . ' SET ' . implode(', ', $assignments) . ' WHERE ' . $where->sql(),
			[...array_values($changes), ...$where->parameters()],
		);
	}

	public function delete(DeleteCommand $command): SqlFragment
	{
		$collection = $command->getCollection();
		$where = $this->identity($collection, $this->resolver->readyValues($command, 'identity'));

		return new SqlFragment(
			'DELETE FROM ' . $this->table($collection) . ' WHERE ' . $where->sql(),
			$where->parameters(),
		);
	}

	/**
	 * @param non-empty-list<FieldInterface> $fields
	 * @param array<string, mixed> $identity
	 */
	public function selectReadback(CollectionInterface $collection, array $fields, array $identity): SqlFragment
	{
		$where = $this->identity($collection, $identity);
		$limit = $this->dialect->compileLimitOffset(1, null, false);

		return new SqlFragment(
			'SELECT ' . implode(', ', $this->quoteColumns($fields))
				. ' FROM ' . $this->table($collection) . ' WHERE ' . $where->sql() . ' ' . $limit->sql(),
			[...$where->parameters(), ...$limit->parameters()],
		);
	}

	/** @param array<string, mixed> $identity */
	private function identity(CollectionInterface $collection, iterable $identity): SqlFragment
	{
		$conditions = [];
		$params = [];
		foreach ($this->mapFieldValuesToColumns($collection, $identity) as $column => $value) {
			if ($value === null) {
				$conditions[] = $column . ' IS NULL';
			} else {
				$conditions[] = $column . ' = ?';
				$params[] = $value;
			}
		}

		if ($conditions === []) {
			throw new InvalidCommandException('Persistence command identity cannot be empty.');
		}

		return SqlFragment::withParameters(implode(' AND ', $conditions), $params);
	}

	private function table(CollectionInterface $collection): string
	{
		$table = $collection->getTable();

		return $this->dialect->quoteTable($table !== '' ? $table : $collection->getName(), $this->tablePrefix);
	}

	/**
	 * @param list<FieldInterface> $fields
	 * @return list<string>
	 */
	private function quoteColumns(array $fields): array
	{
		return array_map(
			fn (FieldInterface $field): string => $this->dialect->quoteIdentifier($field->getColumn()),
			$fields,
		);
	}

	/**
	 * @param iterable<string, mixed> $values
	 * @return array<string, mixed>
	 */
	private function mapFieldValuesToColumns(CollectionInterface $collection, iterable $values): array
	{
		$mapped = [];
		foreach ($values as $fieldName => $value) {
			$field = CommandValueResolver::requireField($collection, (string) $fieldName);
			$mapped[$this->dialect->quoteIdentifier($field->getColumn())] = $value;
		}

		return $mapped;
	}
}
