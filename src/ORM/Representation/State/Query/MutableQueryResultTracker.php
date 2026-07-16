<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\State\Query;

use InvalidArgumentException;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Representation\Schema\Query\QueryRepresentationPlan;
use ON\Data\ORM\Representation\Schema\Query\QueryRepresentationSchemaCompiler;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use ON\Data\ORM\Representation\Sync\AdoptionRecordResolver;
use ON\Data\ORM\Representation\Sync\RepresentationReader;
use ON\Data\ORM\Session;
use ON\Data\Query\Result\MutablePreparation;
use ON\Data\Query\Result\MutableResultHandler;
use ON\Data\Query\SelectQuery;
use RuntimeException;

/**
 * Mutable query export bridge: compiles projections ({@see prepare()}) and routes
 * results into Session tracking ({@see track()}).
 *
 * Stateless for preparations — the plan token is owned by the caller (SelectQuery
 * holds it for the duration of one fetch).
 */
final class MutableQueryResultTracker implements MutableResultHandler
{
	private RepresentationReader $reader;

	private QueryRepresentationSchemaCompiler $compiler;

	private AdoptionRecordResolver $recordResolver;

	public function __construct(
		private readonly Session $session,
		private ?QueryRepresentationStateBuilder $stateBuilder = null,
		?RepresentationReader $reader = null,
		?QueryRepresentationSchemaCompiler $compiler = null,
		?AdoptionRecordResolver $recordResolver = null,
	) {
		$this->stateBuilder ??= new QueryRepresentationStateBuilder();
		$this->reader = $reader ?? new RepresentationReader();
		$this->compiler = $compiler ?? new QueryRepresentationSchemaCompiler();
		$this->recordResolver = $recordResolver ?? new AdoptionRecordResolver();
	}

	public function prepare(SelectQuery $query): MutablePreparation
	{
		return $this->compiler->compileResult($query);
	}

	public function track(
		SelectQuery $query,
		MutablePreparation $preparation,
		array $rawRows,
		array $objects,
	): void {
		if (! $preparation instanceof QueryRepresentationPlan) {
			throw new InvalidArgumentException(sprintf(
				'MutableQueryResultTracker requires %s; %s was provided.',
				QueryRepresentationPlan::class,
				$preparation::class,
			));
		}

		$this->trackAll($preparation, $objects, $rawRows);
	}

	/**
	 * @param list<object> $objects
	 * @param list<array<string, mixed>> $sourceRows
	 */
	public function trackAll(
		QueryRepresentationPlan $compilation,
		array $objects,
		array $sourceRows,
	): void {
		foreach ($objects as $index => $object) {
			$this->trackObject($object, $compilation, $sourceRows[$index] ?? []);
		}
	}

	/**
	 * @param array<string, mixed> $sourceRow
	 */
	public function trackOne(
		QueryRepresentationPlan $compilation,
		object $object,
		array $sourceRow,
	): void {
		$this->trackObject($object, $compilation, $sourceRow);
	}

	/**
	 * @param array<string, mixed> $sourceRow
	 */
	private function trackObject(
		object $object,
		QueryRepresentationPlan $compilation,
		array $sourceRow,
	): void {
		if ($compilation->hasNonRootSources()) {
			$state = $this->stateBuilder->build(
				$object,
				$compilation,
				$sourceRow,
				$this->session->getRecords(),
			);
			$this->session->adopt($object, $state);
			$this->session->sync($object);

			return;
		}

		$schema = $compilation->getSchema();

		if ($this->hasReadableRootPrimaryKey($object, $schema)) {
			// Query hydrate must stay clean. update() is PATCH and would dirty present fields.
			$this->adoptLoadedExisting($object, $schema);
			$this->adoptLoadedRelatedObjects($object, $schema);
			$this->session->sync($object);

			return;
		}

		$this->session->sync($object, $schema);
	}

	private function adoptLoadedExisting(object $object, RepresentationSchema $schema): void
	{
		if ($this->session->getRepresentations()->has($object)) {
			return;
		}

		$record = $this->recordResolver->resolveClean(
			$object,
			$schema,
			$this->session->getRecords(),
			true,
		);
		$this->session->adoptRecord($object, $schema, $record);
	}

	private function hasReadableRootPrimaryKey(object $representation, RepresentationSchema $schema): bool
	{
		$collection = $schema->getCollection();
		$pathsByField = [];

		foreach ($schema->getFields() as $fieldSchema) {
			if ($fieldSchema->getCollectionName() === $collection->getName()) {
				$pathsByField[$fieldSchema->getFieldName()] = $fieldSchema->getPath();
			}
		}

		foreach ($collection->getPrimaryKey() as $fieldName) {
			if (! array_key_exists($fieldName, $pathsByField)) {
				return false;
			}

			try {
				$value = $this->reader->readPath($representation, $pathsByField[$fieldName]);
			} catch (SyncException) {
				return false;
			}

			if ($value === null) {
				return false;
			}
		}

		return true;
	}

	private function adoptLoadedRelatedObjects(
		object $object,
		RepresentationSchema $schema,
	): void {
		foreach ($schema->getRelations() as $relation) {
			if ($relation->isMany()) {
				foreach ($this->reader->readItems($object, $relation, static fn (string $message) => new RuntimeException($message)) as $item) {
					$this->adoptLoadedExisting($item, $relation->getRelatedSchema());
					$this->adoptLoadedRelatedObjects($item, $relation->getRelatedSchema());
				}

				continue;
			}

			if ($relation->isSingle()) {
				$target = $this->reader->readTarget($object, $relation, static fn (string $message) => new RuntimeException($message));
				if ($target !== null) {
					$this->adoptLoadedExisting($target, $relation->getRelatedSchema());
					$this->adoptLoadedRelatedObjects($target, $relation->getRelatedSchema());
				}
			}
		}
	}
}
