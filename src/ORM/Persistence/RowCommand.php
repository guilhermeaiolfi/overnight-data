<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\ORM\Record\ValueRef;

/** @internal Base for the built-in row mutations. */
abstract class RowCommand implements CommandInterface
{
	private readonly ExpectedAffectedRows $expectedAffectedRows;

	/** @param array<string, array<string, mixed>> $slots */
	protected function __construct(
		private readonly CollectionInterface $collection,
		public readonly string $operation,
		protected array $slots,
		?ExpectedAffectedRows $expectedAffectedRows,
	) {
		$this->expectedAffectedRows = $expectedAffectedRows ?? ExpectedAffectedRows::exactly(1);
	}

	public function getCollection(): CollectionInterface
	{
		return $this->collection;
	}

	public function getExpectedAffectedRows(): ExpectedAffectedRows
	{
		return $this->expectedAffectedRows;
	}

	/** @return array<string, array<string, mixed>> */
	public function getValueSlots(): array
	{
		return $this->slots;
	}

	public function resolveValueRefs(): bool
	{
		$changed = false;
		foreach ($this->slots as $slot => $values) {
			foreach ($values as $field => $value) {
				if ($value instanceof ValueRef && $value->isResolved()) {
					$this->slots[$slot][$field] = $value->resolve();
					$changed = true;
				}
			}
		}

		return $changed;
	}
}
