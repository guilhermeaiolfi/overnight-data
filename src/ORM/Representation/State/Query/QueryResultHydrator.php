<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\State\Query;

use Closure;
use ON\Data\Key;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Record\RecordStateStore;
use ON\Data\ORM\Relation\ToManyRelationState;
use ON\Data\ORM\Relation\ToOneRelationState;
use ON\Data\ORM\Representation\Schema\Query\QuerySourceIdentities;
use ON\Data\ORM\Representation\State\RepresentationState;
use ON\Data\ORM\Representation\Sync\ObjectRepresentationReader;
use ON\Data\ORM\Representation\Sync\PathReadResult;
use ON\Data\ORM\Representation\Sync\RepresentationReader;
use ON\Data\ORM\Representation\Sync\RepresentationSourceReader;
use ON\Data\ORM\SessionContext;
use ON\Data\Schema\RelationLoadKnowledge;
use ON\Data\Schema\RepresentationSchema;

final class QueryResultHydrator
{
	/** @var Closure(): RepresentationReader */
	private readonly Closure $newReader;

	/** @param (Closure(): RepresentationReader)|null $newReader */
	public function __construct(?Closure $newReader = null)
	{
		$this->newReader = $newReader ?? static fn (): RepresentationReader => new RepresentationReader();
	}

	/** @param array<string, mixed> $sourceRow */
	public function hydrate(
		object $representation,
		RepresentationSchema $schema,
		array $sourceRow,
		QuerySourceIdentities $identities,
		SessionContext $session,
	): RepresentationState {
		$visited = [];

		return $this->hydrateObject($representation, $schema, $sourceRow, $identities, $session, $visited, ($this->newReader)());
	}

	/**
	 * @param array<string, mixed> $sourceRow
	 * @param array<int, true> $visited
	 */
	private function hydrateObject(
		object $representation,
		RepresentationSchema $schema,
		array $sourceRow,
		QuerySourceIdentities $identities,
		SessionContext $session,
		array &$visited,
		RepresentationReader $operation,
	): RepresentationState {
		$reader = $operation->withObject($representation);
		$representations = $session->getRepresentations();
		$state = $representations->get($representation);
		if ($state === null) {
			$state = $this->bind($reader, $schema, $sourceRow, $identities, $session->getRecords());
			foreach ($state->getUniqueRecords() as $record) {
				$session->getRecords()->add($record);
			}
			$representations->add($representation, $state);
		}

		$id = spl_object_id($representation);
		if (isset($visited[$id])) {
			return $state;
		}
		$visited[$id] = true;

		$relations = $session->getRelations();
		foreach ($state->getSchema()->getRelations() as $relation) {
			$owner = $state->requireRootRecord();
			$name = $relation->getRelationName();
			$childSchema = $relation->getRelatedSchema();
			$rawChildren = $sourceRow[$relation->getPath()] ?? null;
			$childIdentities = new QuerySourceIdentities($childSchema->getSources());

			if ($relation->isMany()) {
				$items = $reader->readItems($relation, $this->relationError(...))->getValue();
				foreach ($items as $index => $item) {
					$childRow = is_array($rawChildren) ? ($rawChildren[$index] ?? null) : null;
					$this->hydrateObject($item, $childSchema, $this->childRow($childRow), $childIdentities, $session, $visited, $operation);
				}
				if (! $relations->has($owner, $name)) {
					$relations->add(match ($relation->getLoadKnowledge()) {
						RelationLoadKnowledge::Full => ToManyRelationState::full($owner, $name, $childSchema, $items),
						RelationLoadKnowledge::Partial => ToManyRelationState::partial($owner, $name, $childSchema, $items),
						RelationLoadKnowledge::Unknown => new ToManyRelationState($owner, $name, $childSchema, $items),
					});
				}

				continue;
			}

			$target = $reader->readTarget($relation, $this->relationError(...))->getValue();
			if ($target !== null) {
				$this->hydrateObject($target, $childSchema, $this->childRow($rawChildren), $childIdentities, $session, $visited, $operation);
			}
			if (! $relations->has($owner, $name)) {
				$relations->add(new ToOneRelationState($owner, $name, $childSchema, $target));
			}
		}

		return $state;
	}

	/** @param array<string, mixed> $sourceRow */
	private function bind(
		ObjectRepresentationReader $reader,
		RepresentationSchema $schema,
		array $sourceRow,
		QuerySourceIdentities $identities,
		RecordStateStore $records,
	): RepresentationState {
		$sources = $schema->getSources();
		$bound = [];
		$baselines = [];
		$readPath = static function (string $path) use ($reader, $sourceRow, &$baselines): PathReadResult {
			$visible = $reader->readPath($path);
			$loaded = array_key_exists($path, $sourceRow) ? PathReadResult::present($sourceRow[$path]) : $visible;
			// The shared record can already contain a pending edit. A new object starts from what it sees.
			if ($visible->isPresent()) {
				$baselines[$path] = $visible->getValue();
			} elseif ($loaded->isPresent()) {
				$baselines[$path] = $loaded->getValue();
			}

			return $loaded;
		};

		if ($sources === [] && $schema->getRelations() !== []) {
			$key = $identities->getIdentity([], $sourceRow) ?? throw StateException::writableResultMissingIdentity(
				$schema->getCollectionName(),
				$schema->getCollection()->getPrimaryKey(),
			);
			$bound[''] = $this->cleanRecord($records, $key, $key->getValues());
		}

		foreach ($sources as $source) {
			[$reads, $values] = RepresentationSourceReader::read($source, $readPath);
			$key = RepresentationSourceReader::resolveKey($source, $reads, $identities->getIdentity($source->getPath(), $sourceRow))
				?? throw StateException::writableResultMissingIdentity($source->getCollection()->getName(), $source->getCollection()->getPrimaryKey());
			$bound[$source->getPathKey()] = $this->cleanRecord($records, $key, $values);
		}

		return RepresentationState::fromRecords($schema, $bound, $baselines);
	}

	/** @param array<string, mixed> $values */
	private function cleanRecord(RecordStateStore $records, Key $key, array $values): RecordState
	{
		return $records->getActive($key, sprintf(
			"Cannot build projection representation for collection '%s' because key '%s' is already tracked as removed.",
			$key->getCollection()->getName(),
			$key->getDebugString(),
		)) ?? RecordState::clean($key, $values);
	}

	/** @return array<string, mixed> */
	private function childRow(mixed $value): array
	{
		/** @var array<string, mixed> $row */
		$row = is_array($value) ? $value : [];

		return $row;
	}

	private function relationError(string $message): StateException
	{
		return new StateException(rtrim($message, '.') . ' during query hydration.');
	}
}
