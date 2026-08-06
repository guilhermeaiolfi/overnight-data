<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Schema\Query;

use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use ON\Data\ORM\Representation\Schema\RepresentationSource;
use ON\Data\Query\Load\LoadGraph;
use ON\Data\Query\Result\WritablePreparation;

/**
 * Writable prepare() result: schema, sources, and the query-scoped
 * {@see QuerySourceIdentities} map (locators for the whole fetch).
 *
 * Also serves as the {@see WritablePreparation} token so SelectQuery can hold the
 * plan without importing ORM adoption types into the query layer.
 *
 * Phase 1 (proposal 0003): also carries the {@see LoadGraph} fetch snapshot built
 * after identity planning.
 */
final class QueryRepresentationPlan implements WritablePreparation
{
	/** @var list<RepresentationSource> */
	private array $sources;

	/** @var array<string, QuerySourceIdentities> */
	private array $relationIdentities = [];

	private ?LoadGraph $loadGraph = null;

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
	 * One identity map for the prepared query root; reuse across all tracked rows.
	 */
	public function getIdentities(): QuerySourceIdentities
	{
		return $this->identities;
	}

	public function setLoadGraph(LoadGraph $loadGraph): void
	{
		$this->loadGraph = $loadGraph;
	}

	public function getLoadGraph(): ?LoadGraph
	{
		return $this->loadGraph;
	}

	/**
	 * @param list<string> $relationPath
	 */
	public function setRelationIdentities(array $relationPath, QuerySourceIdentities $identities): void
	{
		$this->relationIdentities[$this->pathKey($relationPath)] = $identities;
	}

	/**
	 * @param list<string> $relationPath
	 */
	public function getRelationIdentities(array $relationPath): ?QuerySourceIdentities
	{
		return $this->relationIdentities[$this->pathKey($relationPath)] ?? null;
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

	/**
	 * @param list<string> $path
	 */
	private function pathKey(array $path): string
	{
		return json_encode(array_values($path), JSON_THROW_ON_ERROR);
	}
}
