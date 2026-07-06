<?php

declare(strict_types=1);

namespace ON\Data\ORM\ManualProjection;

use ON\Data\ORM\Relation\RelationStateStore;
use ON\Data\ORM\Relation\ToManyRelationState;
use ON\Data\ORM\Relation\ToOneRelationState;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationRelationCardinality;

final class ManualProjectionRelationApplier
{
	/**
	 * @param RelationStateStore<ToManyRelationState> $toManyRelations
	 * @param RelationStateStore<ToOneRelationState> $toOneRelations
	 */
	public function __construct(
		private RelationStateStore $toManyRelations,
		private RelationStateStore $toOneRelations,
	) {
	}

	public function applyTarget(
		RecordState $owner,
		string $relationName,
		RepresentationRelationCardinality $cardinality,
		RepresentationBinding $relatedBinding,
		object $target,
	): void {
		if ($cardinality === RepresentationRelationCardinality::MANY) {
			$this->applyToManyTarget($owner, $relationName, $relatedBinding, $target);

			return;
		}

		$this->applyToOneTarget($owner, $relationName, $relatedBinding, $target);
	}

	private function applyToManyTarget(
		RecordState $owner,
		string $relationName,
		RepresentationBinding $relatedBinding,
		object $target,
	): void {
		$relation = $this->toManyRelations->get($owner, $relationName);
		if (! $relation instanceof ToManyRelationState) {
			$relation = new ToManyRelationState($owner, $relationName, $relatedBinding);
			$this->toManyRelations->add($relation);
		}

		$relation->add($target);
	}

	private function applyToOneTarget(
		RecordState $owner,
		string $relationName,
		RepresentationBinding $relatedBinding,
		object $target,
	): void {
		$relation = $this->toOneRelations->get($owner, $relationName);
		if (! $relation instanceof ToOneRelationState) {
			$relation = new ToOneRelationState($owner, $relationName, $relatedBinding);
			$this->toOneRelations->add($relation);
		}

		$relation->set($target);
	}
}
