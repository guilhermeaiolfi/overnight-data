<?php

declare(strict_types=1);

namespace ON\Data\ORM\Sync;

use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\SessionContext;
use ON\Data\ORM\State\RepresentationState;
use ON\Data\ORM\State\RepresentationStore;

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
		SessionContext $context,
		?object $representation = null,
	): SyncResult {
		$representations = $context->getRepresentations();
		$records = $context->getRecords();
		$relations = $context->getRelations();
		$references = $context->getReferences();
		$syncRepresentations = $representations;
		if ($representation !== null) {
			$state = $representations->get($representation);
			if (! $state instanceof RepresentationState) {
				throw new SyncException('Cannot synchronize an untracked representation object.');
			}

			$syncRepresentations = new RepresentationStore();
			$syncRepresentations->add($representation, $state);
		}

		$syncPlans = $this->scalarSynchronizer->sync($syncRepresentations, $records);
		$relationChanges = $this->relationSynchronizer->sync($syncRepresentations, $relations, $references, $representations);

		return new SyncResult($syncPlans, $relationChanges);
	}
}
