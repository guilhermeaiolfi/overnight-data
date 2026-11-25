<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Sync;

use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Representation\State\RepresentationState;
use ON\Data\ORM\SessionContext;
use WeakMap;

final class RepresentationSyncer
{
	public function __construct(
		private ?ScalarRepresentationSynchronizer $scalarSynchronizer = null,
		private ?RelationRepresentationSynchronizer $relationSynchronizer = null,
		?RepresentationReader $reader = null,
	) {
		$this->scalarSynchronizer ??= new ScalarRepresentationSynchronizer($reader);
		$this->relationSynchronizer ??= new RelationRepresentationSynchronizer($reader);
	}

	public function sync(
		SessionContext $context,
		?object $representation = null,
	): SyncResult {
		$representations = $context->getRepresentations();
		$relations = $context->getRelations();
		$states = $representations;
		if ($representation !== null) {
			$state = $representations->get($representation);
			if (! $state instanceof RepresentationState) {
				throw new SyncException('Cannot synchronize an untracked representation object.');
			}

			$states = new WeakMap();
			$states[$representation] = $state;
		}

		$snapshots = new WeakMap();
		$syncPlans = $this->scalarSynchronizer->sync($states, $snapshots);
		$relationChanges = $this->relationSynchronizer->sync($states, $relations, $representations, $snapshots);

		return new SyncResult($syncPlans, $relationChanges);
	}
}
