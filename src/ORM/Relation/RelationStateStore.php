<?php

declare(strict_types=1);

namespace ON\Data\ORM\Relation;

use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Record\RecordState;

final class RelationStateStore
{
	/** @var array<string, RelationStateInterface> */
	private array $states = [];

	public function add(RelationStateInterface $state): void
	{
		$key = $this->key($state->getOwner(), $state->getRelationName());
		if (isset($this->states[$key])) {
			if ($this->states[$key] === $state) {
				return;
			}

			throw new StateException(sprintf(
				"Relation '%s' is already tracked with a different relation state.",
				$state->getRelationName()
			));
		}

		$this->states[$key] = $state;
	}

	public function has(RecordState $owner, string $relationName): bool
	{
		return array_key_exists($this->key($owner, $relationName), $this->states);
	}

	public function get(RecordState $owner, string $relationName): ?RelationStateInterface
	{
		return $this->states[$this->key($owner, $relationName)] ?? null;
	}

	public function remove(RecordState $owner, string $relationName): void
	{
		unset($this->states[$this->key($owner, $relationName)]);
	}

	public function clear(): void
	{
		$this->states = [];
	}

	/** @return list<RelationStateInterface> */
	public function getAll(): array
	{
		return array_values($this->states);
	}

	/** @return list<RelationStateInterface> */
	public function getChanged(): array
	{
		return array_values(array_filter(
			$this->states,
			static fn (RelationStateInterface $state): bool => $state->hasChanges()
		));
	}

	private function key(RecordState $owner, string $relationName): string
	{
		if (trim($relationName) === '') {
			throw new StateException('Relation name cannot be empty.');
		}

		return $owner->getStateHash() . "\0" . $relationName;
	}
}
