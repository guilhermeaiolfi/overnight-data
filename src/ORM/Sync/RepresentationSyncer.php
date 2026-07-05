<?php

declare(strict_types=1);

namespace ON\Data\ORM\Sync;

use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Relation\RelatedCollectionMap;
use ON\Data\ORM\Relation\RelatedReferenceMap;
use ON\Data\ORM\State\RecordStateMap;
use ON\Data\ORM\State\TrackedRepresentation;
use ON\Data\ORM\State\TrackedRepresentationMap;

final class RepresentationSyncer
{
	public function __construct(
		private ?ScalarRepresentationSynchronizer $scalarSynchronizer = null,
		private ?RelationRepresentationSynchronizer $relationSynchronizer = null,
	) {
		$this->scalarSynchronizer ??= new ScalarRepresentationSynchronizer();
		$this->relationSynchronizer ??= new RelationRepresentationSynchronizer();
	}

	public function sync(
		TrackedRepresentationMap $representations,
		RecordStateMap $records,
		RelatedCollectionMap $relations,
		RelatedReferenceMap $references,
		?object $representation = null,
	): SyncResult {
		$syncRepresentations = $representations;
		if ($representation !== null) {
			$tracked = $representations->get($representation);
			if (! $tracked instanceof TrackedRepresentation) {
				throw new SyncException('Cannot synchronize an untracked representation object.');
			}

			$syncRepresentations = new TrackedRepresentationMap();
			$syncRepresentations->add($tracked);
		}

		$syncPlans = $this->scalarSynchronizer->sync($syncRepresentations, $records);
		$relationChanges = $this->relationSynchronizer->sync($syncRepresentations, $relations, $references, $representations);

		return new SyncResult($syncPlans, $relationChanges);
	}
}
