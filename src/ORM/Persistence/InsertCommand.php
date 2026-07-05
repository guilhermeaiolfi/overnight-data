<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

use ON\Data\Definition\Collection\CollectionInterface;

final class InsertCommand implements CommandInterface
{
	private ExpectedAffectedRows $expectedAffectedRows;

	/**
	 * @param array<string, mixed> $values
	 */
	public function __construct(
		private CollectionInterface $collection,
		private array $values,
		?ExpectedAffectedRows $expectedAffectedRows = null,
	) {
		$this->expectedAffectedRows = $expectedAffectedRows ?? ExpectedAffectedRows::exactly(1);
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

	/**
	 * @param array<string, mixed> $values
	 */
	public function setValues(array $values): void
	{
		$this->values = $values;
	}

	public function getExpectedAffectedRows(): ExpectedAffectedRows
	{
		return $this->expectedAffectedRows;
	}
}
