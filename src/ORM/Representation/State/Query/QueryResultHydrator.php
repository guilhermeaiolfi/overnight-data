<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\State\Query;

use InvalidArgumentException;
use ON\Data\Key;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Relation\ToManyRelationState;
use ON\Data\ORM\Relation\ToOneRelationState;
use ON\Data\ORM\Representation\Schema\Query\QueryRepresentationPlan;
use ON\Data\ORM\Representation\State\CleanRepresentationBinder;
use ON\Data\ORM\Representation\State\RepresentationState;
use ON\Data\ORM\Representation\Sync\PathReadResult;
use ON\Data\ORM\Representation\Sync\RepresentationReader;
use ON\Data\ORM\Representation\Sync\RepresentationSourceReader;
use ON\Data\ORM\SessionContext;
use ON\Data\Query\Result\WritablePreparation;
use ON\Data\Query\Result\WritableResultHandler;
use ON\Data\Query\SelectQuery;
use ON\Data\Schema\RelationLoadKnowledge;
use ON\Data\Schema\RepresentationSchema;

final readonly class QueryResultHydrator implements WritableResultHandler
{
	public function __construct(private SessionContext $session, private RepresentationReader $reader = new RepresentationReader())
	{
	}

	public function prepare(SelectQuery $query): QueryRepresentationPlan
	{
		return new QueryRepresentationPlan($query->projection());
	}

	public function track(SelectQuery $query, WritablePreparation $preparation, array $rawRows, array $objects): void
	{
		if (! $preparation instanceof QueryRepresentationPlan) {
			throw new InvalidArgumentException(sprintf('QueryResultHydrator requires %s; %s was provided.', QueryRepresentationPlan::class, $preparation::class));
		}

		$schema = $preparation->getFetchSchema();
		$root = null;
		foreach ($schema->getSources() as $source) {
			if ($source->isRoot()) {
				$root = $source;

				break;
			}
		}

		foreach ($objects as $index => $object) {
			$row = $rawRows[$index] ?? [];
			$key = $root === null ? null : RepresentationSourceReader::keyFromRow($root, $row);
			if ($key === null) {
				throw StateException::writableResultMissingIdentity($schema->getCollectionName(), $schema->getCollection()->getPrimaryKey());
			}
			$this->hydrate($object, $schema, $row, $key);
		}
	}

	/** @param array<string, mixed> $sourceRow */
	public function hydrate(
		object $representation,
		RepresentationSchema $schema,
		array $sourceRow,
		?Key $rootKey = null,
	): RepresentationState {
		$visited = [];

		return $this->hydrateObject($representation, $schema, $sourceRow, $this->reader->forTraversal(), $visited, $rootKey);
	}

	/**
	 * @param array<string, mixed> $sourceRow
	 * @param array<int, true> $visited
	 */
	private function hydrateObject(
		object $representation,
		RepresentationSchema $schema,
		array $sourceRow,
		RepresentationReader $reader,
		array &$visited,
		?Key $rootKey = null,
	): RepresentationState {
		$representations = $this->session->getRepresentations();
		$state = $representations->get($representation);
		if ($state === null) {
			$state = $this->bind($representation, $schema, $sourceRow, $rootKey, $reader);
			$this->session->attachRepresentation($representation, $state);
		}

		$id = spl_object_id($representation);
		if (isset($visited[$id])) {
			return $state;
		}
		$visited[$id] = true;

		$relations = $this->session->getRelations();
		foreach ($state->getSchema()->getRelations() as $relation) {
			$owner = $state->requireRootRecord();
			$name = $relation->getRelationName();
			$childSchema = $relation->getRelatedSchema();
			$rawChildren = $sourceRow[$relation->getPath()] ?? null;

			if ($relation->isMany()) {
				$items = $reader->readItems($representation, $relation, $this->relationError(...))->requireValue();
				foreach ($items as $index => $item) {
					$childRow = is_array($rawChildren) ? ($rawChildren[$index] ?? null) : null;
					$this->hydrateObject($item, $childSchema, $this->childRow($childRow), $reader, $visited);
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

			$target = $reader->readTarget($representation, $relation, $this->relationError(...))->requireValue();
			if ($target !== null) {
				$this->hydrateObject($target, $childSchema, $this->childRow($rawChildren), $reader, $visited);
			}
			if (! $relations->has($owner, $name)) {
				$relations->add(new ToOneRelationState($owner, $name, $childSchema, $target));
			}
		}

		return $state;
	}

	/** @param array<string, mixed> $sourceRow */
	private function bind(
		object $representation,
		RepresentationSchema $schema,
		array $sourceRow,
		?Key $rootKey,
		RepresentationReader $reader,
	): RepresentationState {
		$baselines = [];
		$readVisiblePath = $reader->readPass($representation);
		$readPath = static function (string $path) use ($readVisiblePath, $sourceRow, &$baselines): PathReadResult {
			$visible = $readVisiblePath($path);
			$loaded = array_key_exists($path, $sourceRow) ? PathReadResult::present($sourceRow[$path]) : $visible;
			// The shared record can already contain a pending edit. A new object starts from what it sees.
			if ($visible->isPresent()) {
				$baselines[$path] = $visible->requireValue();
			} elseif ($loaded->isPresent()) {
				$baselines[$path] = $loaded->requireValue();
			}

			return $loaded;
		};

		$bound = CleanRepresentationBinder::bind($schema, $this->session->getRecords(), $readPath, $rootKey);

		return RepresentationState::fromRecords($schema, $bound, $baselines);
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
