<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\State\Query;

use InvalidArgumentException;
use ON\Data\Key;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Representation\Schema\Query\QueryRepresentationPlan;
use ON\Data\ORM\Representation\Sync\AdoptionPolicy;
use ON\Data\ORM\Representation\Sync\RepresentationAdoptionContext;
use ON\Data\ORM\Representation\Sync\RepresentationAdoptionEngine;
use ON\Data\ORM\Session;
use ON\Data\Query\Result\WritablePreparation;
use ON\Data\Query\Result\WritableResultHandler;
use ON\Data\Query\SelectQuery;

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
		return new QueryRepresentationPlan($query->projection());
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
		$schema = $compilation->getSchema();
		if (! $compilation->getIdentities()->getIdentity([], $sourceRow) instanceof Key) {
			throw StateException::writableResultMissingIdentity(
				$schema->getCollection()->getName(),
				$schema->getCollection()->getPrimaryKey(),
			);
		}

		$this->adoptionEngine->attach(
			$object,
			new RepresentationAdoptionContext(
				schema: $schema,
				policy: AdoptionPolicy::Hydrate,
				identities: $compilation->getIdentities(),
				sourceRow: $sourceRow,
			),
			$this->session->getRecords(),
			$this->session->getRepresentations(),
			$this->session->getRelations(),
		);
	}
}
