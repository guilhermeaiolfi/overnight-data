<?php

declare(strict_types=1);

namespace ON\Data\ORM\Relation;

use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Record\RecordState;

/**
 * @template T of RelationChangeInterface
 */
final class RelationStateStore
{
	/** @var array<string, T> */
	private array $states = [];

	/**
	 * @param T $state
	 */
	public function add(RelationChangeInterface $state): void
	{
		$key = $this->key($state->getOwner(), $state->getRelationName());
		if (isset($this->states[$key])) {
			if ($this->states[$key] === $state) {
				return;
			}

			throw new StateException(sprintf(
				"Relation state store already contains a different state for relation '%s'.",
				$state->getRelationName()
			));
		}

		$this->states[$key] = $state;
	}

	public function has(RecordState $owner, string $relationName): bool
	{
		return array_key_exists($this->key($owner, $relationName), $this->states);
	}

	/**
	 * @return T|null
	 */
	public function get(RecordState $owner, string $relationName): ?RelationChangeInterface
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

	/**
	 * @return list<T>
	 */
	public function getAll(): array
	{
		return array_values($this->states);
	}

	/**
	 * @return list<T>
	 */
	public function getChanged(): array
	{
		return array_values(array_filter(
			$this->states,
			static fn (RelationChangeInterface $state): bool => $state->hasChanges()
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
