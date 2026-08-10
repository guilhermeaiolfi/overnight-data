<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Schema\Query;

use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use ON\Data\ORM\Representation\Schema\RepresentationSource;
use ON\Data\Query\Relation\RelationSelection;
use ON\Data\Query\Result\WritablePreparation;

/**
 * Writable prepare() result: schema, sources, and the query-scoped
 * {@see QuerySourceIdentities} map (locators for the whole fetch).
 *
 * Also serves as the {@see WritablePreparation} token so SelectQuery can reuse the
 * prepared place schema without a second compile.
 */
final class QueryRepresentationPlan implements WritablePreparation
{
	/** @var list<RepresentationSource> */
	private array $sources;

	/** @var array<string, QuerySourceIdentities> */
	private array $relationIdentities = [];

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

	/**
	 * @param list<string> $path
	 */
	private function pathKey(array $path): string
	{
		return RelationSelection::pathKey(array_values($path));
	}
}
