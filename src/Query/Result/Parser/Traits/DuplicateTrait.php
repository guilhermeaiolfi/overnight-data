<?php

declare(strict_types=1);

namespace ON\Data\Query\Result\Parser\Traits;

use ON\Data\Query\Result\Parser\IndexValueEncoder;
use ON\Data\Query\Result\Parser\ParserException;

/**
 * Adapted from Cycle ORM parser code.
 *
 * Upstream commit:
 * a7a1db351df8037ff7a1196e19688bfc7d35c63e
 *
 * Original source licensed under the MIT License.
 */
trait DuplicateTrait
{
	/**
	 * @var list<string>
	 */
	protected array $identityFields = [];

	/**
	 * @var array<string, array<string, mixed>>
	 */
	protected array $duplicates = [];

	/**
	 * @param list<string> $fields
	 */
	protected function setIdentityFields(array $fields): void
	{
		$this->identityFields = $fields;
	}

	/**
	 * @param array<string, mixed> $record
	 */
	final protected function deduplicate(array &$record): bool
	{
		if ($this->identityFields === []) {
			return true;
		}

		$identityKey = $this->getIdentityKey($record);

		if (array_key_exists($identityKey, $this->duplicates)) {
			$record = $this->duplicates[$identityKey];

			return false;
		}

		$this->duplicates[$identityKey] = &$record;

		return true;
	}

	/**
	 * @param array<string, mixed> $record
	 */
	final protected function hasNullIdentityValue(array $record): bool
	{
		foreach ($this->identityFields as $field) {
			if (! array_key_exists($field, $record)) {
				throw new ParserException(sprintf('Configured identity field `%s` is missing from the parsed record.', $field));
			}

			if ($record[$field] === null) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $record
	 */
	private function getIdentityKey(array $record): string
	{
		$key = '';

		foreach ($this->identityFields as $field) {
			if (! array_key_exists($field, $record)) {
				throw new ParserException(sprintf('Configured identity field `%s` is missing from the parsed record.', $field));
			}

			$encodedValue = IndexValueEncoder::encodeIndexValue($record[$field]);
			$key .= strlen($encodedValue) . ':' . $encodedValue . ';';
		}

		return $key;
	}
}
