<?php

declare(strict_types=1);

namespace ON\Data\ORM\Relation;

use ON\Data\Definition\Relation\RelationCardinality;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;

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

	/**
	 * Return the existing relation state or create one matching $cardinality.
	 *
	 * @throws StateException when a tracked state has incompatible cardinality
	 */
	public function getOrCreate(
		RecordState $owner,
		string $relationName,
		RelationCardinality $cardinality,
		RepresentationSchema $relatedSchema,
	): ToManyRelationState|ToOneRelationState {
		$existing = $this->get($owner, $relationName);

		if ($cardinality->isMany()) {
			if ($existing === null) {
				return $this->createToMany($owner, $relationName, $relatedSchema);
			}

			if (! $existing instanceof ToManyRelationState) {
				throw $this->incompatibleCardinality($relationName);
			}

			return $existing;
		}

		if ($existing === null) {
			return $this->createToOne($owner, $relationName, $relatedSchema);
		}

		if (! $existing instanceof ToOneRelationState) {
			throw $this->incompatibleCardinality($relationName);
		}

		return $existing;
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

	private function createToMany(
		RecordState $owner,
		string $relationName,
		RepresentationSchema $childSchema,
	): ToManyRelationState {
		$state = new ToManyRelationState($owner, $relationName, $childSchema);
		$this->add($state);

		return $state;
	}

	private function createToOne(
		RecordState $owner,
		string $relationName,
		RepresentationSchema $relatedSchema,
	): ToOneRelationState {
		$state = new ToOneRelationState($owner, $relationName, $relatedSchema);
		$this->add($state);

		return $state;
	}

	private function incompatibleCardinality(string $relationName): StateException
	{
		return new StateException(sprintf(
			"Relation '%s' is already tracked with incompatible cardinality.",
			$relationName,
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
