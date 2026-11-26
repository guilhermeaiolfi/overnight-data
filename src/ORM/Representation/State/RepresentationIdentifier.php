<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\State;

use ON\Data\Key;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Representation\Sync\RepresentationReader;
use ON\Data\ORM\SessionContext;
use ON\Data\Schema\RepresentationSchema;

/** Attaches supplied schemas and object graphs as clean existing references. */
final class RepresentationIdentifier
{
	public function attach(
		object $representation,
		RepresentationSchema $schema,
		Key $key,
		SessionContext $session,
		?RepresentationReader $reader = null,
	): void {
		if ($schema->getCollectionName() !== $key->getCollection()->getName()) {
			throw new StateException('Cannot identify representation with a schema for a different collection.');
		}
		$visited = [];
		$binder = new CleanRepresentationBinder($session);
		$this->attachObject($representation, $schema, $key, $binder, $visited, $reader ?? new RepresentationReader());
		$binder->commit();
	}

	/** @param array<int, true> $visited */
	private function attachObject(
		object $representation,
		RepresentationSchema $schema,
		?Key $rootKey,
		CleanRepresentationBinder $binder,
		array &$visited,
		RepresentationReader $operation,
	): void {
		$id = spl_object_id($representation);
		if (isset($visited[$id])) {
			return;
		}
		$visited[$id] = true;
		$reader = $operation->withObject($representation);

		$state = $binder->getRepresentation($representation);
		if ($state === null) {
			if ($schema->getSources() === [] && $schema->getRelations() === []) {
				throw new StateException('Cannot identify representation with an empty schema.');
			}
			$records = $binder->bind($schema, $reader->readPath(...), $rootKey);
			$state = RepresentationState::fromRecords($schema, $records);
			$binder->stageRepresentation($representation, $state);
		}

		foreach ($state->getSchema()->getRelations() as $relation) {
			foreach ($reader->readRelatedObjects($relation, $this->relationError(...)) as $child) {
				$this->attachObject($child, $relation->getRelatedSchema(), null, $binder, $visited, $operation);
			}
		}
	}

	private function relationError(string $message): StateException
	{
		return new StateException(rtrim($message, '.') . ' during graph identification.');
	}
}
