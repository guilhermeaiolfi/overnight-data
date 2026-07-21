<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\State\Query;

use InvalidArgumentException;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Representation\Schema\Query\QueryRepresentationIdentityPlanner;
use ON\Data\ORM\Representation\Schema\Query\QueryRepresentationPlan;
use ON\Data\ORM\Representation\Schema\Query\QueryRepresentationSchemaCompiler;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use ON\Data\ORM\Representation\Schema\RepresentationSource;
use ON\Data\ORM\Representation\Sync\AdoptionPolicy;
use ON\Data\ORM\Representation\Sync\RepresentationAdoptionContext;
use ON\Data\ORM\Representation\Sync\RepresentationAdoptionEngine;
use ON\Data\ORM\Representation\Sync\RepresentationReader;
use ON\Data\ORM\Session;
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
		$identities = $this->identityPlanner->plan($query, $sources);

		return new QueryRepresentationPlan($schema, $sources, $identities);
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
		if ($compilation->hasNonRootSources()) {
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
