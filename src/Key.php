<?php

declare(strict_types=1);

namespace ON\Data;

use JsonException;
use JsonSerializable;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Exception\CompositeKeyException;
use ON\Data\Definition\Exception\InvalidPrimaryKeyException;
use Stringable;

final readonly class Key implements Stringable, JsonSerializable
{
	private CollectionInterface $collection;

	/** @var non-empty-array<string, string|int|float|bool> */
	private array $values;

	/**
	 * @param non-empty-array<string, string|int|float|bool> $values
	 */
	public function __construct(
		CollectionInterface $collection,
		array $values,
	) {
		$this->collection = $collection;
		$primaryKey = $collection->getPrimaryKey();
		if (array_is_list($values)) {
			throw new InvalidPrimaryKeyException('Key values must use canonical field names.');
		}

		$canonicalValues = [];
		foreach ($primaryKey as $fieldName) {
			if (! array_key_exists($fieldName, $values)) {
				throw new InvalidPrimaryKeyException(
					sprintf("Missing primary key field '%s' for collection '%s'.", $fieldName, $collection->getName())
				);
			}

			$value = $values[$fieldName];
			if (! is_string($value) && ! is_int($value) && ! is_float($value) && ! is_bool($value)) {
				throw new InvalidPrimaryKeyException(
					sprintf("Primary key field '%s' for collection '%s' must be a scalar string|int|float|bool.", $fieldName, $collection->getName())
				);
			}

			$canonicalValues[$fieldName] = $value;
		}

		foreach (array_keys($values) as $fieldName) {
			if (! in_array($fieldName, $primaryKey, true)) {
				throw new InvalidPrimaryKeyException(
					sprintf("Unexpected primary key field '%s' for collection '%s'.", (string) $fieldName, $collection->getName())
				);
			}
		}

		$this->values = $canonicalValues;
	}

	public function getCollection(): CollectionInterface
	{
		return $this->collection;
	}

	public function getValue(): string|int|float|bool
	{
		if ($this->isComposite()) {
			throw new CompositeKeyException(
				sprintf("Collection '%s' uses a composite primary key.", $this->collection->getName())
			);
		}

		return array_values($this->values)[0];
	}

	/**
	 * @return non-empty-array<string, string|int|float|bool>
	 */
	public function getValues(): array
	{
		return $this->values;
	}

	public function getFieldValue(string $fieldName): string|int|float|bool
	{
		if (! array_key_exists($fieldName, $this->values)) {
			throw new InvalidPrimaryKeyException(
				sprintf("Field '%s' is not part of collection '%s' primary key.", $fieldName, $this->collection->getName())
			);
		}

		return $this->values[$fieldName];
	}

	public function isComposite(): bool
	{
		return count($this->values) > 1;
	}

	public function equals(self $other): bool
	{
		return $this->collection->getName() === $other->collection->getName()
			&& $this->values === $other->values;
	}

	public function getHash(): string
	{
		try {
			$json = json_encode(
				[
					'collection' => $this->collection->getName(),
					'values' => $this->values,
				],
				JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
			);
		} catch (JsonException $exception) {
			throw new InvalidPrimaryKeyException('Unable to encode primary key hash payload.', 0, $exception);
		}

		return 'k1:' . rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
	}

	public function getDebugString(): string
	{
		$parts = [];
		foreach ($this->values as $fieldName => $value) {
			try {
				$encoded = json_encode(
					$value,
					JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
				);
			} catch (JsonException $exception) {
				throw new InvalidPrimaryKeyException('Unable to encode primary key debug value.', 0, $exception);
			}

			$parts[] = sprintf('%s=%s', $fieldName, $encoded);
		}

		return sprintf('%s#%s', $this->collection->getName(), implode(',', $parts));
	}

	/**
	 * @return array{collection: string, values: non-empty-array<string, string|int|float|bool>}
	 */
	public function jsonSerialize(): array
	{
		return [
			'collection' => $this->collection->getName(),
			'values' => $this->values,
		];
	}

	public function __toString(): string
	{
		return $this->getHash();
	}
}
