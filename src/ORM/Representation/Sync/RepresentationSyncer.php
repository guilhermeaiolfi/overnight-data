<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Sync;

use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Representation\State\RepresentationState;
use ON\Data\ORM\Representation\State\RepresentationStateStore;
use ON\Data\ORM\SessionContext;

final class RepresentationSyncer
{
	private RepresentationReader $reader;

	public function __construct(
		private ?ScalarRepresentationSynchronizer $scalarSynchronizer = null,
		private ?RelationRepresentationSynchronizer $relationSynchronizer = null,
		?RepresentationReader $reader = null,
	) {
		$this->reader = $reader ?? new RepresentationReader();
		$this->scalarSynchronizer ??= new ScalarRepresentationSynchronizer($this->reader);
		$this->relationSynchronizer ??= new RelationRepresentationSynchronizer($this->reader);
	}

	public function sync(
		SessionContext $context,
		?object $representation = null,
	): SyncResult {
		$representations = $context->getRepresentations();
		$relations = $context->getRelations();
		$syncRepresentations = $representations;
		if ($representation !== null) {
			$state = $representations->get($representation);
			if (! $state instanceof RepresentationState) {
				throw new SyncException('Cannot synchronize an untracked representation object.');
			}

			$syncRepresentations = new RepresentationStateStore();
			$syncRepresentations->add($representation, $state);
		}

		$operation = $this->reader->forOperation();
		$syncPlans = $this->scalarSynchronizer->sync($syncRepresentations, $operation);
		$relationChanges = $this->relationSynchronizer->sync($syncRepresentations, $relations, $representations, $operation);

		return new SyncResult($syncPlans, $relationChanges);
	}
}
