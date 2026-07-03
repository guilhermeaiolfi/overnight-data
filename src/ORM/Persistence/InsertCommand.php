<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

final class InsertCommand implements CommandInterface
{
	/**
	 * @param array<string, mixed> $values
	 */
	public function __construct(
		private string $collectionName,
		private array $values,
	) {
	}

	public function getCollectionName(): string
	{
		return $this->collectionName;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getValues(): array
	{
		return $this->values;
	}
}
