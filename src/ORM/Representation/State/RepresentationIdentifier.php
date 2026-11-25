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
	public function __construct(private RepresentationReader $reader)
	{
	}

	public function attach(object $representation, RepresentationSchema $schema, Key $key, SessionContext $session): void
	{
		if ($schema->getCollectionName() !== $key->getCollection()->getName()) {
			throw new StateException('Cannot identify representation with a schema for a different collection.');
		}
		$visited = [];
		$this->attachObject($representation, $schema, $key, $session, $visited, $this->reader->forTraversal());
	}

	/** @param array<int, true> $visited */
	private function attachObject(
		object $representation,
		RepresentationSchema $schema,
		?Key $rootKey,
		SessionContext $session,
		array &$visited,
		RepresentationReader $reader,
	): void {
		$id = spl_object_id($representation);
		if (isset($visited[$id])) {
			return;
		}
		$visited[$id] = true;

		$representations = $session->getRepresentations();
		$state = $representations->get($representation);
		if ($state === null) {
			if ($schema->getSources() === [] && $schema->getRelations() === []) {
				throw new StateException('Cannot identify representation with an empty schema.');
			}
			$records = CleanRepresentationBinder::bind($schema, $session->getRecords(), $reader->readPass($representation), $rootKey);
			$state = RepresentationState::fromRecords($schema, $records);
			$session->attachRepresentation($representation, $state);
		}

		foreach ($state->getSchema()->getRelations() as $relation) {
			foreach ($reader->readRelatedObjects($representation, $relation, $this->relationError(...)) as $child) {
				$this->attachObject($child, $relation->getRelatedSchema(), null, $session, $visited, $reader);
			}
		}
	}

	private function relationError(string $message): StateException
	{
		return new StateException(rtrim($message, '.') . ' during graph identification.');
	}
}
