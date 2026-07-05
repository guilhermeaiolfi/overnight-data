<?php

declare(strict_types=1);

namespace ON\Data\ORM\Relation;

use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\State\RecordState;

final class ToOneRelationStore
{
	/** @var array<string, ToOneRelationState> */
	private array $references = [];

	public function add(ToOneRelationState $reference): void
	{
		$key = $this->key($reference->getOwner(), $reference->getRelationName());
		if (isset($this->references[$key])) {
			if ($this->references[$key] === $reference) {
				return;
			}

			throw new StateException(sprintf(
				"To-one relation store already contains a different state for relation '%s'.",
				$reference->getRelationName()
			));
		}

		$this->references[$key] = $reference;
	}

	public function has(RecordState $owner, string $relationName): bool
	{
		return array_key_exists($this->key($owner, $relationName), $this->references);
	}

	public function get(RecordState $owner, string $relationName): ?ToOneRelationState
	{
		return $this->references[$this->key($owner, $relationName)] ?? null;
	}

	public function remove(RecordState $owner, string $relationName): void
	{
		unset($this->references[$this->key($owner, $relationName)]);
	}

	public function clear(): void
	{
		$this->references = [];
	}

	/**
	 * @return list<ToOneRelationState>
	 */
	public function getAll(): array
	{
		return array_values($this->references);
	}

	/**
	 * @return list<ToOneRelationState>
	 */
	public function getChanged(): array
	{
		return array_values(array_filter(
			$this->references,
			static fn (ToOneRelationState $reference): bool => $reference->hasChanges()
		));
	}

	private function key(RecordState $owner, string $relationName): string
	{
		if (trim($relationName) === '') {
			throw new StateException('To-one relation name cannot be empty.');
		}

		return $owner->getStateHash() . "\0" . $relationName;
	}
}
