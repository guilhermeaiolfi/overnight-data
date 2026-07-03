<?php

declare(strict_types=1);

namespace ON\Data\ORM\State;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Key;
use ON\Data\ORM\Exception\StateException;

final class RecordFieldRef
{
	public function __construct(
		private CollectionInterface $collection,
		private string $fieldName,
		private ?Key $key = null,
	) {
		if ($fieldName === '') {
			throw new StateException('Record field reference field name cannot be empty.');
		}
	}

	public function getCollection(): CollectionInterface
	{
		return $this->collection;
	}

	public function getCollectionName(): string
	{
		return $this->collection->getName();
	}

	public function getFieldName(): string
	{
		return $this->fieldName;
	}

	public function getKey(): ?Key
	{
		return $this->key;
	}

	public function hasKey(): bool
	{
		return $this->key instanceof Key;
	}

	public function getRecordHash(): string
	{
		if (! $this->key instanceof Key) {
			throw new StateException(sprintf("Record field '%s.%s' does not have a record key.", $this->getCollectionName(), $this->fieldName));
		}

		return $this->key->getHash();
	}
}
