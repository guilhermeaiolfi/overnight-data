<?php

declare(strict_types=1);

namespace ON\Data\ORM\Relation\Persistence;

use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\ORM\Exception\RelationPersistenceException;
use ON\Data\ORM\Persistence\PersistenceContext;
use ON\Data\ORM\Record\RecordState;

final class TrackedRecordResolver
{
	public function resolve(
		PersistenceContext $context,
		RelationInterface $relation,
		object $representation,
		string $role,
	): RecordState {
		$tracked = $context->getRepresentations()->get($representation);
		if ($tracked === null) {
			throw new RelationPersistenceException(sprintf(
				"Relation '%s' %s item is not tracked.",
				$relation->getName(),
				$role,
			));
		}

		$record = $context->getRecords()->getFromRepresentation($tracked);
		if (! $record instanceof RecordState) {
			throw new RelationPersistenceException(sprintf(
				"Relation '%s' tracked %s item cannot be resolved to a record state.",
				$relation->getName(),
				$role,
			));
		}

		return $record;
	}
}
