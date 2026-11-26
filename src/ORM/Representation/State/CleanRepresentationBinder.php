<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\State;

use ON\Data\Key;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Record\RecordStateStore;
use ON\Data\ORM\Relation\RelationStateInterface;
use ON\Data\ORM\Relation\RelationStateStore;
use ON\Data\ORM\Representation\Sync\PathReadResult;
use ON\Data\ORM\Representation\Sync\RepresentationSourceReader;
use ON\Data\ORM\SessionContext;
use ON\Data\Schema\RepresentationSchema;
use SplObjectStorage;

/** @internal Stage clean bindings until the operation has validated every representation. */
final class CleanRepresentationBinder
{
	private RecordStateStore $records;
	/** @var SplObjectStorage<object, RepresentationState> */
	private SplObjectStorage $representations;
	private RelationStateStore $relations;

	public function __construct(private readonly SessionContext $session)
	{
		$this->records = new RecordStateStore();
		$this->representations = new SplObjectStorage();
		$this->relations = new RelationStateStore();
	}

	public function getRepresentation(object $representation): ?RepresentationState
	{
		return $this->representations[$representation] ?? $this->session->getRepresentations()->get($representation);
	}

	public function stageRepresentation(object $representation, RepresentationState $state): void
	{
		foreach ($state->getUniqueRecords() as $record) {
			$this->records->add($record);
		}
		$this->representations[$representation] = $state;
	}

	public function hasRelation(RecordState $owner, string $name): bool
	{
		return $this->relations->has($owner, $name) || $this->session->getRelations()->has($owner, $name);
	}

	public function stageRelation(RelationStateInterface $relation): void
	{
		$this->relations->add($relation);
	}

	public function commit(): void
	{
		foreach ($this->representations as $representation) {
			$this->session->attachRepresentation($representation, $this->representations[$representation]);
		}
		foreach ($this->relations->getAll() as $relation) {
			$this->session->getRelations()->add($relation);
		}
	}

	/**
	 * @param callable(string): PathReadResult<mixed> $read
	 * @return array<string, RecordState>
	 */
	public function bind(RepresentationSchema $schema, callable $read, ?Key $rootKey): array
	{
		$sources = $schema->getSources();
		if ($sources === [] && $schema->getRelations() !== []) {
			$key = $rootKey ?? throw StateException::writableResultMissingIdentity($schema->getCollectionName(), $schema->getCollection()->getPrimaryKey());

			return ['' => $this->bindClean($key, $key->getValues())];
		}

		$keys = [];
		$valuesByKey = [];
		foreach ($sources as $source) {
			[$reads, $values] = RepresentationSourceReader::read($source, $read);
			$key = RepresentationSourceReader::resolveKey($source, $reads, $source->isRoot() ? $rootKey : null)
				?? throw StateException::writableResultMissingIdentity($source->getCollection()->getName(), $source->getCollection()->getPrimaryKey());
			$keys[$source->getPathKey()] = $key;
			$hash = $key->getHash();
			if (isset($valuesByKey[$hash])) {
				foreach (array_intersect_key($values, $valuesByKey[$hash]) as $field => $value) {
					if ($value !== $valuesByKey[$hash][$field]) {
						throw new StateException(sprintf(
							"Sources for collection '%s' with key '%s' disagree on field '%s'.",
							$source->getCollection()->getName(),
							$key->getDebugString(),
							$field,
						));
					}
				}
				$valuesByKey[$hash] += $values;
			} else {
				$valuesByKey[$hash] = $values;
			}
		}

		// A self-join can expose the same row through several paths, each with different fields.
		$bound = [];
		$byKey = [];
		foreach ($keys as $path => $key) {
			$hash = $key->getHash();
			$bound[$path] = $byKey[$hash] ??= $this->bindClean($key, $valuesByKey[$hash]);
		}

		return $bound;
	}

	/** @param array<string, mixed> $values */
	private function bindClean(Key $key, array $values): RecordState
	{
		return $this->records->getByKey($key) ?? $this->session->getRecords()->bindClean($key, $values);
	}
}
