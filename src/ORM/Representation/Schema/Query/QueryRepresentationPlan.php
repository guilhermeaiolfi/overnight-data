<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Schema\Query;

use ON\Data\Query\Result\WritablePreparation;
use ON\Data\Schema\RepresentationSchema;
use ON\Data\Schema\RepresentationSource;

/**
 * Writable prepare() result: schema, sources, and the query-scoped
 * {@see QuerySourceIdentities} map (schema paths for the whole fetch).
 *
 * Also serves as the {@see WritablePreparation} token so SelectQuery can reuse the
 * prepared place schema without a second compile.
 */
final class QueryRepresentationPlan implements WritablePreparation
{
	/** @var list<RepresentationSource> */
	private array $sources;

	/**
	 * @param list<RepresentationSource> $sources
	 */
	public function __construct(
		private RepresentationSchema $schema,
		array $sources,
		private QuerySourceIdentities $identities,
	) {
		$this->sources = array_values($sources);
	}

	public function getSchema(): RepresentationSchema
	{
		return $this->schema;
	}

	public function getFetchSchema(): ?RepresentationSchema
	{
		return $this->schema;
	}

	/**
	 * @return list<RepresentationSource>
	 */
	public function getSources(): array
	{
		return $this->sources;
	}

	/**
	 * One identity map for the prepared query root; reuse across all tracked rows.
	 */
	public function getIdentities(): QuerySourceIdentities
	{
		return $this->identities;
	}
}
