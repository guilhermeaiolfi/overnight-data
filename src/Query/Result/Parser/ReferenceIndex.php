<?php

declare(strict_types=1);

namespace ON\Data\Query\Result\Parser;

/**
 * Adapted from Cycle ORM parser code.
 *
 * Upstream commit:
 * a7a1db351df8037ff7a1196e19688bfc7d35c63e
 *
 * Original source licensed under the MIT License.
 */
final class ReferenceIndex
{
	/**
	 * @var non-empty-list<string>
	 */
	private array $fields;

	/**
	 * @var array<string, mixed>
	 */
	private array $data = [];

	/**
	 * @var array<string, array<string, scalar>>
	 */
	private array $distinctReferenceValues = [];

	/**
	 * @var array<string, scalar>|null
	 */
	private ?array $lastReferenceValues = null;

	/**
	 * @param non-empty-list<string> $fields
	 */
	public function __construct(array $fields)
	{
		foreach ($fields as $field) {
			if (! is_string($field) || $field === '') {
				throw new ParserException('Reference index fields must be non-empty strings.');
			}
		}

		$this->fields = array_values($fields);
	}

	/**
	 * @return non-empty-list<string>
	 */
	public function getFields(): array
	{
		return $this->fields;
	}

	/**
	 * @param array<string, mixed> $record
	 */
	public function add(array &$record): void
	{
		$rawValues = [];
		$encodedValues = [];

		foreach ($this->fields as $field) {
			if (! array_key_exists($field, $record)) {
				throw new ParserException(sprintf('Configured reference field `%s` is missing from the parsed record.', $field));
			}

			$value = $record[$field];

			if ($value === null) {
				return;
			}

			if (! is_scalar($value)) {
				throw new ParserException(sprintf('Reference field `%s` must contain a scalar value, `%s` given.', $field, get_debug_type($value)));
			}

			$rawValues[$field] = $value;
			$encodedValues[] = $this->encodeIndexValue($value);
		}

		$bucket = &$this->data;

		foreach ($encodedValues as $encodedValue) {
			if (! array_key_exists($encodedValue, $bucket)) {
				$bucket[$encodedValue] = [];
			}

			$bucket = &$bucket[$encodedValue];
		}

		$bucket[] = &$record;

		$compositeKey = $this->composeEncodedKey($encodedValues);
		$this->distinctReferenceValues[$compositeKey] = $rawValues;
		$this->lastReferenceValues = $rawValues;
	}

	/**
	 * @return list<array<string, scalar>>
	 */
	public function getReferenceValues(): array
	{
		return array_values($this->distinctReferenceValues);
	}

	/**
	 * @return array<string, scalar>|null
	 */
	public function getLastReferenceValues(): ?array
	{
		return $this->lastReferenceValues;
	}

	/**
	 * @param array<string, scalar> $values
	 * @return array<int, array<string, mixed>>
	 */
	public function getRecords(array $values): array
	{
		return $this->getRecordsByValues($this->orderedValuesFromNamedValues($values));
	}

	/**
	 * @param array<string, scalar> $values
	 */
	public function getRecordCount(array $values): int
	{
		return $this->getRecordCountByValues($this->orderedValuesFromNamedValues($values));
	}

	/**
	 * @param list<scalar> $values
	 * @return array<int, array<string, mixed>>
	 */
	public function getRecordsByValues(array $values): array
	{
		$bucket = &$this->getBucket($values);
		$records = [];

		foreach ($bucket as &$record) {
			$records[] = &$record;
		}
		unset($record);

		return $records;
	}

	/**
	 * @param list<scalar> $values
	 */
	public function getRecordCountByValues(array $values): int
	{
		$bucket = &$this->getBucket($values);

		return count($bucket);
	}

	/**
	 * @param array<string, scalar> $values
	 * @return list<scalar>
	 */
	private function orderedValuesFromNamedValues(array $values): array
	{
		$orderedValues = [];

		foreach ($this->fields as $field) {
			if (! array_key_exists($field, $values)) {
				throw new ParserException(sprintf('Reference values are missing the configured field `%s`.', $field));
			}

			$orderedValues[] = $values[$field];
		}

		if (count($values) !== count($this->fields)) {
			throw new ParserException('Reference values must match the configured reference field list exactly.');
		}

		return $orderedValues;
	}

	/**
	 * @param list<scalar> $values
	 * @return array<int, array<string, mixed>>
	 */
	private function &getBucket(array $values): array
	{
		if (count($values) !== count($this->fields)) {
			throw new ParserException('Reference value count does not match the configured reference field count.');
		}

		$bucket = &$this->data;

		foreach ($values as $position => $value) {
			$encodedValue = $this->encodeIndexValue($value);

			if (! array_key_exists($encodedValue, $bucket)) {
				throw new ParserException(sprintf(
					'Undefined reference for field `%s` and value `%s`.',
					$this->fields[$position],
					(string) $value,
				));
			}

			$bucket = &$bucket[$encodedValue];
		}

		return $bucket;
	}

	/**
	 * @param list<string> $encodedValues
	 */
	private function composeEncodedKey(array $encodedValues): string
	{
		$key = '';

		foreach ($encodedValues as $encodedValue) {
			$key .= strlen($encodedValue) . ':' . $encodedValue . ';';
		}

		return $key;
	}

	private function encodeIndexValue(mixed $value): string
	{
		return match (true) {
			is_int($value) => 'i:' . $value,
			is_string($value) => 's:' . strlen($value) . ':' . $value,
			is_float($value) => 'f:' . serialize($value),
			is_bool($value) => 'b:' . ($value ? '1' : '0'),
			default => throw new ParserException(sprintf('Non-scalar identity or reference value of type `%s` is not supported.', get_debug_type($value))),
		};
	}
}
