<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\State\Query;

use InvalidArgumentException;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Representation\Schema\Query\QueryRepresentationIdentityPlanner;
use ON\Data\ORM\Representation\Schema\Query\QueryRepresentationPlan;
use ON\Data\ORM\Representation\Schema\Query\QueryRepresentationSchemaCompiler;
use ON\Data\ORM\Representation\Schema\RepresentationRelationSchema;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use ON\Data\ORM\Representation\Schema\RepresentationSource;
use ON\Data\ORM\Representation\Sync\AdoptionPolicy;
use ON\Data\ORM\Representation\Sync\RepresentationAdoptionContext;
use ON\Data\ORM\Representation\Sync\RepresentationAdoptionEngine;
use ON\Data\ORM\Representation\Sync\RepresentationReader;
use ON\Data\ORM\Session;
use ON\Data\Query\Load\FetchPlan;
use ON\Data\Query\Load\LoadGraphBuilder;
use ON\Data\Query\Relation\RelationSelection;
use ON\Data\Query\Result\WritablePreparation;
use ON\Data\Query\Result\WritableResultHandler;
use ON\Data\Query\SelectQuery;

/**
 * Writable query export bridge: compiles projections ({@see prepare()}) and routes
 * results into Session tracking via {@see RepresentationAdoptionEngine}.
 *
 * Stateless for preparations — the plan token is owned by the caller (SelectQuery
 * holds it for the duration of one fetch).
 */
final class WritableQueryResultTracker implements WritableResultHandler
{
	private RepresentationReader $reader;

	private QueryRepresentationSchemaCompiler $compiler;

	private QueryRepresentationIdentityPlanner $identityPlanner;

	private RepresentationAdoptionEngine $adoptionEngine;

	public function __construct(
		private readonly Session $session,
		?RepresentationReader $reader = null,
		?QueryRepresentationSchemaCompiler $compiler = null,
		?QueryRepresentationIdentityPlanner $identityPlanner = null,
		?RepresentationAdoptionEngine $adoptionEngine = null,
	) {
		$this->reader = $reader ?? new RepresentationReader();
		$this->compiler = $compiler ?? new QueryRepresentationSchemaCompiler();
		$this->identityPlanner = $identityPlanner ?? new QueryRepresentationIdentityPlanner();
		$this->adoptionEngine = $adoptionEngine ?? new RepresentationAdoptionEngine($this->reader);
	}

	public function prepare(SelectQuery $query): WritablePreparation
	{
		$schema = $this->compiler->compile($query);
		$sources = RepresentationSource::fromRepresentationSchema($schema);
		$identities = $this->identityPlanner->planIdentities($query, $sources, resetCounter: true);
		$plan = new QueryRepresentationPlan($schema, $sources, $identities);

		foreach ($query->getRelationSelections()->getAll() as $selection) {
			$this->planRelationLevel($plan, $schema, $selection);
		}

		// After INTERNAL identity selections are planned onto the query/relations.
		$plan->setFetchPlan(new FetchPlan(
			$schema,
			(new LoadGraphBuilder())->fromQuery($query),
		));

		return $plan;
	}

	public function track(
		SelectQuery $query,
		WritablePreparation $preparation,
		array $rawRows,
		array $objects,
	): void {
		if (! $preparation instanceof QueryRepresentationPlan) {
			throw new InvalidArgumentException(sprintf(
				'WritableQueryResultTracker requires %s; %s was provided.',
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
		$this->preAttachNestedFlatRelations($object, $compilation, $sourceRow);

		if (RepresentationSource::listHasNonRoot($compilation->getSources())) {
			$this->adoptionEngine->attach(
				$object,
				new RepresentationAdoptionContext(
					schema: $compilation->getSchema(),
					policy: AdoptionPolicy::Hydrate,
					identities: $compilation->getIdentities(),
					sourceRow: $sourceRow,
				),
				$this->session->getRecords(),
				$this->session->getRepresentations(),
			);
			$this->session->sync($object);

			return;
		}

		$schema = $compilation->getSchema();

		if ($this->hasReadableRootPrimaryKey($object, $schema)) {
			$this->adoptionEngine->attach(
				$object,
				new RepresentationAdoptionContext(
					schema: $schema,
					policy: AdoptionPolicy::Hydrate,
				),
				$this->session->getRecords(),
				$this->session->getRepresentations(),
			);
			$this->session->sync($object);

			return;
		}

		$this->session->sync($object, $schema);
	}

	/**
	 * Flat-attach nested relation items whose related schema has non-root sources
	 * (e.g. posts.authorName → authors) before graph attachment walks the root.
	 *
	 * @param array<string, mixed> $sourceRow
	 */
	private function preAttachNestedFlatRelations(
		object $object,
		QueryRepresentationPlan $compilation,
		array $sourceRow,
	): void {
		$this->preAttachNestedFlatRelationsAt(
			$object,
			$compilation->getSchema(),
			$compilation,
			$sourceRow,
			[],
		);
	}

	/**
	 * @param list<string> $relationPathPrefix
	 * @param array<string, mixed> $sourceRow
	 */
	private function preAttachNestedFlatRelationsAt(
		object $object,
		RepresentationSchema $schema,
		QueryRepresentationPlan $compilation,
		array $sourceRow,
		array $relationPathPrefix,
	): void {
		foreach ($schema->getRelations() as $relationSchema) {
			$path = [...$relationPathPrefix, $relationSchema->getPath()];
			$relatedSchema = $relationSchema->getRelatedSchema();
			$rawChildren = $sourceRow[$relationSchema->getPath()] ?? null;

			if (RepresentationAdoptionEngine::isFlatAttachment($relatedSchema)) {
				$identities = $compilation->getRelationIdentities($path);
				if ($identities === null) {
					continue;
				}

				$items = $relationSchema->isMany()
					? $this->reader->readItems($object, $relationSchema, static fn (string $message): StateException => new StateException($message))
					: array_values(array_filter([
						$this->reader->readTarget($object, $relationSchema, static fn (string $message): StateException => new StateException($message)),
					]));

				foreach ($items as $index => $item) {
					if ($this->session->getRepresentations()->has($item)) {
						continue;
					}

					$childRow = is_array($rawChildren)
						? ($relationSchema->isMany() ? ($rawChildren[$index] ?? []) : $rawChildren)
						: [];

					if (! is_array($childRow)) {
						$childRow = [];
					}

					$this->adoptionEngine->attach(
						$item,
						new RepresentationAdoptionContext(
							schema: $relatedSchema,
							policy: AdoptionPolicy::Hydrate,
							identities: $identities,
							sourceRow: $childRow,
						),
						$this->session->getRecords(),
						$this->session->getRepresentations(),
					);
				}
			}

			if ($relatedSchema->getRelations() === []) {
				continue;
			}

			$items = $relationSchema->isMany()
				? $this->reader->readItems($object, $relationSchema, static fn (string $message): StateException => new StateException($message))
				: array_values(array_filter([
					$this->reader->readTarget($object, $relationSchema, static fn (string $message): StateException => new StateException($message)),
				]));

			foreach ($items as $index => $item) {
				$childRow = is_array($rawChildren)
					? ($relationSchema->isMany() ? ($rawChildren[$index] ?? []) : $rawChildren)
					: [];

				if (! is_array($childRow)) {
					$childRow = [];
				}

				$this->preAttachNestedFlatRelationsAt(
					$item,
					$relatedSchema,
					$compilation,
					$childRow,
					$path,
				);
			}
		}
	}

	private function planRelationLevel(
		QueryRepresentationPlan $plan,
		RepresentationSchema $rootSchema,
		RelationSelection $selection,
	): void {
		if (! $selection->isLoaded()) {
			return;
		}

		$relatedSchema = $this->relatedSchemaAtPath($rootSchema, $selection->getPath());
		if (! $relatedSchema instanceof RepresentationSchema) {
			return;
		}

		$sources = RepresentationSource::fromRepresentationSchema($relatedSchema);
		if (! RepresentationSource::listHasNonRoot($sources)) {
			return;
		}

		$identities = $this->identityPlanner->planIdentities($selection->getRelationRef(), $sources);
		$plan->setRelationIdentities($selection->getPath(), $identities);
	}

	/**
	 * @param list<string> $path
	 */
	private function relatedSchemaAtPath(RepresentationSchema $schema, array $path): ?RepresentationSchema
	{
		$current = $schema;

		foreach ($path as $segment) {
			if (! $current->hasRelation($segment)) {
				return null;
			}

			$relation = $current->getRelation($segment);
			if (! $relation instanceof RepresentationRelationSchema) {
				return null;
			}

			$current = $relation->getRelatedSchema();
		}

		return $current;
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
}
