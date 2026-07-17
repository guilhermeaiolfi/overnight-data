<?php

declare(strict_types=1);

namespace ON\Data\ORM\Relation\Persistence;

use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\ORM\Exception\RelationPersistenceException;
use ON\Data\ORM\Persistence\PersistenceContext;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Relation\RelationTarget;

final class TrackedRecordResolver
{
	public function resolve(
		PersistenceContext $context,
		RelationInterface $relation,
		object $item,
		string $role,
	): RecordState {
		$target = RelationTarget::from($item);
		if ($target->isRecord()) {
			$record = $target->getRecord();
			assert($record instanceof RecordState);
			$this->assertRecordInStore($context, $relation, $record, $role);

			return $record;
		}

		$representation = $target->getRepresentation();
		assert($representation !== null);

		$tracked = $context->getRepresentations()->get($representation);
		if ($tracked === null) {
			throw new RelationPersistenceException(sprintf(
				"Relation '%s' %s item is not tracked.",
				$relation->getName(),
				$role,
			));
		}

		$record = $tracked->getSingleRecord();
		if (! $record instanceof RecordState) {
			throw new RelationPersistenceException(sprintf(
				"Relation '%s' tracked %s item cannot be resolved to a record state.",
				$relation->getName(),
				$role,
			));
		}

		return $record;
	}

	private function assertRecordInStore(
		PersistenceContext $context,
		RelationInterface $relation,
		RecordState $record,
		string $role,
	): void {
		$stored = $context->getRecords()->getByStateHash($record->getStateHash());
		if ($stored !== $record) {
			throw new RelationPersistenceException(sprintf(
				"Relation '%s' %s RecordState is not managed by the session.",
				$relation->getName(),
				$role,
			));
		}
	}
}
