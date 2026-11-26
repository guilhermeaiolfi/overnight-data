<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Sync;

use ON\Data\Mapper\ConversionGateway;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Representation\State\RepresentationState;
use ON\Data\ORM\Representation\State\RepresentationStateStore;
use ON\Data\ORM\SessionContext;

final class RepresentationSyncer
{
	public function __construct(
		private ?ScalarRepresentationSynchronizer $scalarSynchronizer = null,
		private ?RelationRepresentationSynchronizer $relationSynchronizer = null,
		private readonly ?ConversionGateway $gateway = null,
	) {
		$this->scalarSynchronizer ??= new ScalarRepresentationSynchronizer($gateway);
		$this->relationSynchronizer ??= new RelationRepresentationSynchronizer($gateway);
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

		$operation = new RepresentationReader($this->gateway);
		$syncPlans = $this->scalarSynchronizer->sync($syncRepresentations, $operation);
		$relationChanges = $this->relationSynchronizer->sync($syncRepresentations, $relations, $representations, $operation);

		return new SyncResult($syncPlans, $relationChanges);
	}
}
