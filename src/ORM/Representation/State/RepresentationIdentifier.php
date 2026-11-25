<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\State;

use ON\Data\Key;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Representation\Sync\RepresentationReader;
use ON\Data\ORM\Representation\Sync\RepresentationSourceReader;
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
		$this->attachObject($representation, $schema, $key, $session, $visited);
	}

	/** @param array<int, true> $visited */
	private function attachObject(
		object $representation,
		RepresentationSchema $schema,
		?Key $rootKey,
		SessionContext $session,
		array &$visited,
	): void {
		$id = spl_object_id($representation);
		if (isset($visited[$id])) {
			return;
		}
		$visited[$id] = true;

		$representations = $session->getRepresentations();
		$state = $representations->get($representation);
		if ($state === null) {
			$state = $this->bind($representation, $schema, $rootKey, $session);
			foreach ($state->getUniqueRecords() as $record) {
				$session->getRecords()->add($record);
			}
			$representations->add($representation, $state);
		}

		foreach ($state->getSchema()->getRelations() as $relation) {
			foreach ($this->reader->readRelatedObjects($representation, $relation, $this->relationError(...)) as $child) {
				$this->attachObject($child, $relation->getRelatedSchema(), null, $session, $visited);
			}
		}
	}

	private function bind(
		object $representation,
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

		$readPath = $this->reader->readPass($representation);
		foreach ($sources as $source) {
			[$reads, $values] = RepresentationSourceReader::read($source, $readPath);
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
		)) ?? RecordState::clean($key, array_filter($values, static fn (mixed $value): bool => $value !== null));
	}

	private function relationError(string $message): StateException
	{
		return new StateException(rtrim($message, '.') . ' during graph identification.');
	}
}
