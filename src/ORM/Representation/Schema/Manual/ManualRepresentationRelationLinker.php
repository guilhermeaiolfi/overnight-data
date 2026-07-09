<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Schema\Manual;

use ON\Data\Definition\Relation\RelationCardinality;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Relation\ToManyRelationState;
use ON\Data\ORM\Relation\ToOneRelationState;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use ON\Data\ORM\Session;

/**
 * Applies manual representation relation targets to relation state stores.
 */
final class ManualRepresentationRelationLinker
{
	public function __construct(
		private Session $session,
	) {
	}

	public function applyTarget(
		RecordState $owner,
		string $relationName,
		RelationCardinality $cardinality,
		RepresentationSchema $relatedSchema,
		object $target,
	): void {
		if ($cardinality->isMany()) {
			$this->toManyRelation($owner, $relationName, $relatedSchema)->add($target);

			return;
		}

		$this->toOneRelation($owner, $relationName, $relatedSchema)->set($target);
	}

	private function toManyRelation(
		RecordState $owner,
		string $relationName,
		RepresentationSchema $relatedSchema,
	): ToManyRelationState {
		$relation = $this->session->getToManyRelations()->get($owner, $relationName);
		if (! $relation instanceof ToManyRelationState) {
			$relation = new ToManyRelationState($owner, $relationName, $relatedSchema);
			$this->session->getToManyRelations()->add($relation);
		}

		return $relation;
	}

	private function toOneRelation(
		RecordState $owner,
		string $relationName,
		RepresentationSchema $relatedSchema,
	): ToOneRelationState {
		$relation = $this->session->getToOneRelations()->get($owner, $relationName);
		if (! $relation instanceof ToOneRelationState) {
			$relation = new ToOneRelationState($owner, $relationName, $relatedSchema);
			$this->session->getToOneRelations()->add($relation);
		}

		return $relation;
	}
}
