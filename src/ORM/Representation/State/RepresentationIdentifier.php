<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\State;

use ON\Data\Key;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Representation\Sync\ObjectRepresentationReader;
use ON\Data\ORM\Representation\Sync\RepresentationReader;
use ON\Data\ORM\Representation\Sync\RepresentationSourceReader;
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
		$this->attachObject($representation, $schema, $key, $session, $visited, $reader ?? new RepresentationReader());
	}

	/** @param array<int, true> $visited */
	private function attachObject(
		object $representation,
		RepresentationSchema $schema,
		?Key $rootKey,
		SessionContext $session,
		array &$visited,
		RepresentationReader $operation,
	): void {
		$id = spl_object_id($representation);
		if (isset($visited[$id])) {
			return;
		}
		$visited[$id] = true;
		$reader = $operation->withObject($representation);

		$representations = $session->getRepresentations();
		$state = $representations->get($representation);
		if ($state === null) {
			$state = $this->bind($reader, $schema, $rootKey, $session);
			foreach ($state->getUniqueRecords() as $record) {
				$session->getRecords()->add($record);
			}
			$representations->add($representation, $state);
		}

		foreach ($state->getSchema()->getRelations() as $relation) {
			foreach ($reader->readRelatedObjects($relation, $this->relationError(...)) as $child) {
				$this->attachObject($child, $relation->getRelatedSchema(), null, $session, $visited, $operation);
			}
		}
	}

	private function bind(
		ObjectRepresentationReader $reader,
		RepresentationSchema $schema,
		?Key $rootKey,
		SessionContext $session,
	): RepresentationState {
		$sources = $schema->getSources();
		$bound = [];
		if ($sources === []) {
			if ($schema->getRelations() === []) {
				throw new StateException('Cannot identify representation with an empty schema.');
			}
			$key = $rootKey ?? throw StateException::writableResultMissingIdentity($schema->getCollectionName(), $schema->getCollection()->getPrimaryKey());
			$bound[''] = $this->cleanRecord($session, $key, $key->getValues());
		}

		foreach ($sources as $source) {
			[$reads, $values] = RepresentationSourceReader::read($source, $reader->readPath(...));
			$key = RepresentationSourceReader::resolveKey($source, $reads, $source->isRoot() ? $rootKey : null)
				?? throw StateException::writableResultMissingIdentity($source->getCollection()->getName(), $source->getCollection()->getPrimaryKey());
			$bound[$source->getPathKey()] = $this->cleanRecord($session, $key, $values);
		}

		return RepresentationState::fromRecords($schema, $bound);
	}

	/** @param array<string, mixed> $values */
	private function cleanRecord(SessionContext $session, Key $key, array $values): RecordState
	{
		return $session->getRecords()->getActive($key, sprintf(
			"Cannot build projection representation for collection '%s' because key '%s' is already tracked as removed.",
			$key->getCollection()->getName(),
			$key->getDebugString(),
		)) ?? RecordState::clean($key, $values);
	}

	private function relationError(string $message): StateException
	{
		return new StateException(rtrim($message, '.') . ' during graph identification.');
	}
}
