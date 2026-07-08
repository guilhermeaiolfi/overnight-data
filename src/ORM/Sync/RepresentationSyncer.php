<?php

declare(strict_types=1);

namespace ON\Data\ORM\Sync;

use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\SessionContext;
use ON\Data\ORM\State\RepresentationState;
use ON\Data\ORM\State\RepresentationStateStore;

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
		$toManyRelations = $context->getToManyRelations();
		$toOneRelations = $context->getToOneRelations();
		$syncRepresentations = $representations;
		if ($representation !== null) {
			$state = $representations->get($representation);
			if (! $state instanceof RepresentationState) {
				throw new SyncException('Cannot synchronize an untracked representation object.');
			}

			$syncRepresentations = new RepresentationStateStore();
			$syncRepresentations->add($representation, $state);
		}

		$syncPlans = $this->scalarSynchronizer->sync($syncRepresentations, $records);
		$relationChanges = $this->relationSynchronizer->sync($syncRepresentations, $toManyRelations, $toOneRelations, $representations);

		return new SyncResult($syncPlans, $relationChanges);
	}
}
