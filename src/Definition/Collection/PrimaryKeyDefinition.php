<?php

declare(strict_types=1);

namespace ON\Data\Definition\Collection;

use InvalidArgumentException;
use ON\Data\Definition\Field\FieldInterface;

final class PrimaryKeyDefinition
{
	/**
	 * @param list<FieldInterface> $fields
	 */
	public function __construct(
		private CollectionInterface $collection,
		private array $fields
	) {
	}

	/**
	 * @return list<FieldInterface>
	 */
	public function getFields(): array
	{
		return $this->fields;
	}

	/**
	 * @return list<string>
	 */
	public function getFieldNames(): array
	{
		return array_map(static fn (FieldInterface $field): string => $field->getName(), $this->fields);
	}

	/**
	 * @return list<string>
	 */
	public function getColumns(): array
	{
		return array_map(static fn (FieldInterface $field): string => $field->getColumn(), $this->fields);
	}

	public function isComposite(): bool
	{
		return count($this->fields) > 1;
	}

	/**
	 * @param array<string, mixed> $input
	 */
	public function extract(array $input, bool $allowColumnNames = true): ?PrimaryKeyValue
	{
		$values = [];

		foreach ($this->fields as $field) {
			$name = $field->getName();
			if (array_key_exists($name, $input)) {
				$values[$name] = $input[$name];

				continue;
			}

			if ($allowColumnNames && array_key_exists($field->getColumn(), $input)) {
				$values[$name] = $input[$field->getColumn()];

				continue;
			}

			return null;
		}

		return new PrimaryKeyValue($this->collection, $values);
	}

	public function requireFromInput(array $input, string $context): PrimaryKeyValue
	{
		$value = $this->extract($input);
		if ($value !== null) {
			return $value;
		}

		$missing = $this->getMissingFieldNames($input);
		$fieldList = implode(', ', $missing);

		throw new InvalidArgumentException("{$context} requires primary key field(s): {$fieldList}.");
	}

	/**
	 * @return list<string>
	 */
	public function getMissingFieldNames(array $input): array
	{
		$missing = [];

		foreach ($this->fields as $field) {
			if (
				! array_key_exists($field->getName(), $input)
				&& ! array_key_exists($field->getColumn(), $input)
			) {
				$missing[] = $field->getName();
			}
		}

		return $missing;
	}

	public function getValueFromUrlId(string $id): PrimaryKeyValue
	{
		if (! $this->isComposite()) {
			$fieldName = $this->getFieldNames()[0] ?? 'id';

			return new PrimaryKeyValue($this->collection, [$fieldName => $id]);
		}

		$decoded = base64_decode(strtr($id, '-_', '+/') . str_repeat('=', (4 - strlen($id) % 4) % 4), true);
		if ($decoded === false) {
			throw new InvalidArgumentException('Invalid composite primary key encoding.');
		}

		$data = json_decode($decoded, true);
		if (! is_array($data)) {
			throw new InvalidArgumentException('Invalid composite primary key payload.');
		}

		$value = $this->extract($data, false);
		if ($value === null) {
			throw new InvalidArgumentException('Incomplete composite primary key payload.');
		}

		return $value;
	}

	public function getValue(PrimaryKeyValue|array|string|int|float $value): PrimaryKeyValue
	{
		if ($value instanceof PrimaryKeyValue) {
			return $value;
		}

		if (is_array($value)) {
			$identity = $this->extract($value, true);
			if ($identity !== null) {
				return $identity;
			}

			if (! $this->isComposite() && array_is_list($value) && count($value) === 1) {
				return new PrimaryKeyValue($this->collection, [$this->getFieldNames()[0] => $value[0]]);
			}

			throw new InvalidArgumentException('Invalid primary key value array.');
		}

		if ($this->isComposite()) {
			return $this->getValueFromUrlId((string) $value);
		}

		return new PrimaryKeyValue($this->collection, [$this->getFieldNames()[0] => $value]);
	}
}
