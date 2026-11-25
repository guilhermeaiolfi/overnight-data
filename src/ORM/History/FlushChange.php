<?php

declare(strict_types=1);

namespace ON\Data\ORM\History;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\ORM\Persistence\CommandInterface;
use ON\Data\ORM\Persistence\DeleteCommand;
use ON\Data\ORM\Persistence\InsertCommand;
use ON\Data\ORM\Persistence\UpdateCommand;

final readonly class FlushChange
{
	/**
	 * @internal Constructed from detached snapshots by the flush scheduler.
	 * @param array<string, mixed> $identity
	 * @param array<string, mixed> $before
	 * @param array<string, mixed> $after
	 * @param list<string> $changedFields
	 * @param array<string, list<string>> $unavailableFields
	 */
	public function __construct(
		private CollectionInterface $collection,
		public FlushChangeOperation $operation,
		public FlushChangeSource $source,
		public string $commandType,
		public int $affectedRows,
		public array $identity,
		public array $before,
		public array $after,
		public array $changedFields,
		public array $unavailableFields,
		public ?string $irreversibleReason,
	) {
	}

	public function getCollection(): CollectionInterface
	{
		return $this->collection;
	}

	public function isReversible(): bool
	{
		return $this->irreversibleReason === null;
	}

	/** @internal */
	public function inverse(): ?CommandInterface
	{
		if (! $this->isReversible()) {
			throw new HistoryRollbackException(sprintf(
				"Cannot reverse %s on collection '%s': %s",
				$this->operation->value,
				$this->collection->getName(),
				$this->irreversibleReason,
			));
		}
		if ($this->affectedRows === 0) {
			return null;
		}

		return match ($this->operation) {
			FlushChangeOperation::Insert => new DeleteCommand($this->collection, $this->identity),
			FlushChangeOperation::Update => new UpdateCommand($this->collection, $this->identity, $this->before),
			FlushChangeOperation::Delete => new InsertCommand($this->collection, $this->before),
			FlushChangeOperation::Custom => throw new HistoryRollbackException('Unsupported command: ' . $this->commandType),
		};
	}

	/** @return array<string, mixed> */
	public function toArray(): array
	{
		return [
			'operation' => $this->operation->value,
			'source' => $this->source->value,
			'commandType' => HistoryValue::forArray($this->commandType),
			'collection' => HistoryValue::forArray($this->collection->getName()),
			'affectedRows' => $this->affectedRows,
			'identity' => HistoryValue::forArray($this->identity),
			'before' => HistoryValue::forArray($this->before),
			'after' => HistoryValue::forArray($this->after),
			'changedFields' => HistoryValue::forArray($this->changedFields),
			'unavailableFields' => HistoryValue::forArray($this->unavailableFields),
			'reversible' => $this->isReversible(),
			'irreversibleReason' => HistoryValue::forArray($this->irreversibleReason),
		];
	}
}
