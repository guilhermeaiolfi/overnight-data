<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\State\Query;

use InvalidArgumentException;
use ON\Data\Key;
use ON\Data\ORM\Representation\Schema\Query\QueryRepresentationPlan;
use ON\Data\ORM\Representation\Schema\Query\QuerySourceIdentities;
use ON\Data\ORM\Representation\Sync\AdoptionPolicy;
use ON\Data\ORM\Representation\Sync\RepresentationAdoptionContext;
use ON\Data\ORM\Representation\Sync\RepresentationAdoptionEngine;
use ON\Data\ORM\Session;
use ON\Data\Query\Result\WritablePreparation;
use ON\Data\Query\Result\WritableResultHandler;
use ON\Data\Query\SelectQuery;
use ON\Data\Schema\RepresentationSchema;

/**
 * Writable query export bridge: compiles projections ({@see prepare()}) and routes
 * assembled rows into Session tracking via {@see RepresentationAdoptionEngine}.
 *
 * Stateless for preparations — the plan token is owned by the caller (SelectQuery
 * holds it for the duration of one fetch).
 */
final class WritableQueryResultTracker implements WritableResultHandler
{
	private RepresentationAdoptionEngine $adoptionEngine;

	public function __construct(
		private readonly Session $session,
		?RepresentationAdoptionEngine $adoptionEngine = null,
	) {
		$this->adoptionEngine = $adoptionEngine ?? new RepresentationAdoptionEngine();
	}

	public function prepare(SelectQuery $query): WritablePreparation
	{
		$schema = $query->projection();
		$sources = $schema->getSources();
		$identities = new QuerySourceIdentities($sources);

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
		if ($this->canHydrateRoot($compilation, $object, $sourceRow)) {
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

		$this->session->sync($object, $compilation->getSchema());
	}

	/**
	 * @param array<string, mixed> $sourceRow
	 */
	private function canHydrateRoot(
		QueryRepresentationPlan $compilation,
		object $object,
		array $sourceRow,
	): bool {
		if ($compilation->getIdentities()->getIdentity([], $sourceRow) instanceof Key) {
			return true;
		}

		return $this->hasReadableRootPrimaryKey($object, $compilation->getSchema(), $sourceRow);
	}

	/**
	 * @param array<string, mixed> $sourceRow
	 */
	private function hasReadableRootPrimaryKey(
		object $object,
		RepresentationSchema $schema,
		array $sourceRow,
	): bool {
		$vars = get_object_vars($object);

		foreach ($schema->getCollection()->getPrimaryKey() as $fieldName) {
			$path = null;
			foreach ($schema->getFields() as $fieldSchema) {
				if ($fieldSchema->getFieldName() === $fieldName && $fieldSchema->isRootSource()) {
					$path = $fieldSchema->getPath();

					break;
				}
			}

			if ($path === null) {
				return false;
			}

			if (array_key_exists($path, $sourceRow) && $sourceRow[$path] !== null) {
				continue;
			}

			if (! array_key_exists($path, $vars) || $vars[$path] === null) {
				return false;
			}
		}

		return true;
	}
}
