<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\State\Query;

use Closure;
use InvalidArgumentException;
use ON\Data\Key;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Relation\ToManyRelationState;
use ON\Data\ORM\Relation\ToOneRelationState;
use ON\Data\ORM\Representation\Schema\Query\QueryRepresentationPlan;
use ON\Data\ORM\Representation\State\CleanRepresentationBinder;
use ON\Data\ORM\Representation\State\RepresentationState;
use ON\Data\ORM\Representation\Sync\ObjectRepresentationReader;
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
	/** @var Closure(): RepresentationReader */
	private Closure $newReader;

	/** @param (Closure(): RepresentationReader)|null $newReader */
	public function __construct(private SessionContext $session, ?Closure $newReader = null)
	{
		$this->newReader = $newReader ?? static fn (): RepresentationReader => new RepresentationReader();
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

		$operation = ($this->newReader)();
		$binder = new CleanRepresentationBinder($this->session);
		foreach ($objects as $index => $object) {
			$row = $rawRows[$index] ?? [];
			$key = $root === null ? null : RepresentationSourceReader::keyFromRow($root, $row);
			if ($key === null) {
				throw StateException::writableResultMissingIdentity($schema->getCollectionName(), $schema->getCollection()->getPrimaryKey());
			}
			$visited = [];
			$this->hydrateObject($object, $schema, $row, $operation, $binder, $visited, $key);
		}
		$binder->commit();
	}

	/** @param array<string, mixed> $sourceRow */
	public function hydrate(
		object $representation,
		RepresentationSchema $schema,
		array $sourceRow,
		?Key $rootKey = null,
	): RepresentationState {
		$visited = [];
		$binder = new CleanRepresentationBinder($this->session);
		$state = $this->hydrateObject($representation, $schema, $sourceRow, ($this->newReader)(), $binder, $visited, $rootKey);
		$binder->commit();

		return $state;
	}

	/**
	 * @param array<string, mixed> $sourceRow
	 * @param array<int, true> $visited
	 */
	private function hydrateObject(
		object $representation,
		RepresentationSchema $schema,
		array $sourceRow,
		RepresentationReader $operation,
		CleanRepresentationBinder $binder,
		array &$visited,
		?Key $rootKey = null,
	): RepresentationState {
		$reader = $operation->withObject($representation);
		$state = $binder->getRepresentation($representation);
		if ($state === null) {
			$state = $this->bind($reader, $schema, $sourceRow, $binder, $rootKey);
			$binder->stageRepresentation($representation, $state);
		}

		$id = spl_object_id($representation);
		if (isset($visited[$id])) {
			return $state;
		}
		$visited[$id] = true;

		foreach ($state->getSchema()->getRelations() as $relation) {
			$owner = $state->requireRootRecord();
			$name = $relation->getRelationName();
			$childSchema = $relation->getRelatedSchema();
			$rawChildren = $sourceRow[$relation->getPath()] ?? null;

			if ($relation->isMany()) {
				$items = $reader->readItems($relation, $this->relationError(...))->getValue();
				foreach ($items as $index => $item) {
					$childRow = is_array($rawChildren) ? ($rawChildren[$index] ?? null) : null;
					$this->hydrateObject($item, $childSchema, $this->childRow($childRow), $operation, $binder, $visited);
				}
				if (! $binder->hasRelation($owner, $name)) {
					$binder->stageRelation(match ($relation->getLoadKnowledge()) {
						RelationLoadKnowledge::Full => ToManyRelationState::full($owner, $name, $childSchema, $items),
						RelationLoadKnowledge::Partial => ToManyRelationState::partial($owner, $name, $childSchema, $items),
						RelationLoadKnowledge::Unknown => new ToManyRelationState($owner, $name, $childSchema, $items),
					});
				}

				continue;
			}

			$target = $reader->readTarget($relation, $this->relationError(...))->getValue();
			if ($target !== null) {
				$this->hydrateObject($target, $childSchema, $this->childRow($rawChildren), $operation, $binder, $visited);
			}
			if (! $binder->hasRelation($owner, $name)) {
				$binder->stageRelation(new ToOneRelationState($owner, $name, $childSchema, $target));
			}
		}

		return $state;
	}

	/** @param array<string, mixed> $sourceRow */
	private function bind(
		ObjectRepresentationReader $reader,
		RepresentationSchema $schema,
		array $sourceRow,
		CleanRepresentationBinder $binder,
		?Key $rootKey,
	): RepresentationState {
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

		$bound = $binder->bind($schema, $readPath, $rootKey);

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
