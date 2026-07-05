<?php

declare(strict_types=1);

namespace ON\Data\ORM\State;

use ON\Data\ORM\Exception\StateException;

final class ValueRef
{
	private function __construct(
		private RecordState $record,
		private string $field,
	) {
		if ($field === '') {
			throw new StateException('Value reference field name cannot be empty.');
		}
	}

	public static function field(RecordState $record, string $field): self
	{
		return new self($record, $field);
	}

	public function getRecord(): RecordState
	{
		return $this->record;
	}

	public function getField(): string
	{
		return $this->field;
	}

	public function isResolved(): bool
	{
		if (! $this->record->hasValue($this->field)) {
			return false;
		}

		$value = $this->record->getValue($this->field);

		return ! $value instanceof self && $value !== null;
	}

	public function resolve(): mixed
	{
		if (! $this->isResolved()) {
			throw new StateException(sprintf(
				"Value reference '%s.%s' on record '%s' is unresolved.",
				$this->record->getCollectionName(),
				$this->field,
				$this->record->getStateHash(),
			));
		}

		return $this->record->getValue($this->field);
	}

	public function equals(self $other): bool
	{
		return $this->record === $other->record
			&& $this->field === $other->field;
	}
}
