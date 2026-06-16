<?php

declare(strict_types=1);

namespace ON\Data\Definition\Collection;

use RuntimeException;

final class PrimaryKeyValue
{
	/**
	 * @param array<string, mixed> $values
	 */
	public function __construct(
		private CollectionInterface $collection,
		private array $values
	) {
	}

	public function getCollection(): CollectionInterface
	{
		return $this->collection;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getValues(): array
	{
		return $this->values;
	}

	public function getValue(string $fieldName): mixed
	{
		return $this->values[$fieldName] ?? null;
	}

	public function isComplete(): bool
	{
		$primaryKey = $this->collection->getPrimaryKey();

		foreach ($primaryKey->getFieldNames() as $fieldName) {
			if (! array_key_exists($fieldName, $this->values)) {
				return false;
			}
		}

		return true;
	}

	public function toUrlId(): string
	{
		$primaryKey = $this->collection->getPrimaryKey();

		if (! $primaryKey->isComposite()) {
			$fieldName = $primaryKey->getFieldNames()[0] ?? 'id';

			return (string) ($this->values[$fieldName] ?? '');
		}

		$ordered = [];
		foreach ($primaryKey->getFieldNames() as $fieldName) {
			$ordered[$fieldName] = $this->values[$fieldName] ?? null;
		}

		$json = json_encode($ordered, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($json === false) {
			throw new RuntimeException('Unable to encode composite primary key.');
		}

		return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
	}

	public static function fromUrlId(CollectionInterface $collection, string $id): self
	{
		return $collection->getPrimaryKey()->getValueFromUrlId($id);
	}
}
