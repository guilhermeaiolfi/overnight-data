<?php

declare(strict_types=1);

namespace ON\Data\ORM\Relation;

use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Record\RecordState;

/**
 * Persistence-facing relation member: either a tracked representation object
 * (nested graphs) or a direct RecordState (flat projection creates).
 */
final class RelationTarget
{
	private function __construct(
		private readonly ?object $representation,
		private readonly ?RecordState $record,
	) {
	}

	public static function representation(object $representation): self
	{
		if ($representation instanceof RecordState) {
			throw new StateException('Use RelationTarget::record() for RecordState targets.');
		}

		return new self($representation, null);
	}

	public static function record(RecordState $record): self
	{
		return new self(null, $record);
	}

	/**
	 * Normalize a planner/sync item: RecordState → record target, anything else → representation.
	 */
	public static function from(object $item): self
	{
		if ($item instanceof self) {
			return $item;
		}

		if ($item instanceof RecordState) {
			return self::record($item);
		}

		return self::representation($item);
	}

	public function isRecord(): bool
	{
		return $this->record instanceof RecordState;
	}

	public function isRepresentation(): bool
	{
		return $this->representation !== null;
	}

	public function getRepresentation(): ?object
	{
		return $this->representation;
	}

	public function getRecord(): ?RecordState
	{
		return $this->record;
	}

	/**
	 * Object passed to legacy object-shaped APIs (representation, or the RecordState itself).
	 */
	public function toObject(): object
	{
		return $this->record ?? $this->representation;
	}

	/**
	 * Stable map key for add/remove deduplication within one relation state instance.
	 */
	public function identityKey(): string
	{
		if ($this->record instanceof RecordState) {
			$key = $this->record->getKey();
			if ($key !== null) {
				return 'k:' . $this->record->getCollection()->getName() . ':' . $key->getHash();
			}

			return 'r:' . spl_object_id($this->record);
		}

		return 'o:' . spl_object_id($this->representation);
	}

	public function equals(self $other): bool
	{
		return $this->identityKey() === $other->identityKey();
	}
}
