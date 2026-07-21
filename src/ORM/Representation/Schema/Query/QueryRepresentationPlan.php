<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Schema\Query;

use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use ON\Data\ORM\Representation\Schema\RepresentationSource;
use ON\Data\Query\Result\WritablePreparation;

/**
 * Writable prepare() result: schema, sources, and the query-scoped
 * {@see QuerySourceIdentities} map (locators for the whole fetch).
 *
 * Also serves as the {@see WritablePreparation} token so SelectQuery can hold the
 * plan without importing ORM adoption types into the query layer.
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

	/**
	 * @return list<RepresentationSource>
	 */
	public function getSources(): array
	{
		return $this->sources;
	}

	/**
	 * One identity map for the prepared query; reuse across all tracked rows.
	 */
	public function getIdentities(): QuerySourceIdentities
	{
		return $this->identities;
	}

	public function hasNonRootSources(): bool
	{
		foreach ($this->sources as $source) {
			if (! $source->isRoot()) {
				return true;
			}
		}

		return false;
	}
}
