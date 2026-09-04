<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Schema\Query;

use ON\Data\Query\Result\WritablePreparation;
use ON\Data\Schema\RepresentationSchema;
use ON\Data\Schema\RepresentationSource;

/**
 * Writable prepare() result: schema and the query-scoped
 * {@see QuerySourceIdentities} map (schema paths for the whole fetch).
 *
 * Sources are always {@see RepresentationSchema::getSources()} — not a second list.
 *
 * Also serves as the {@see WritablePreparation} token so SelectQuery can reuse the
 * prepared place schema without a second compile.
 */
final class QueryRepresentationPlan implements WritablePreparation
{
	private QuerySourceIdentities $identities;

	public function __construct(
		private RepresentationSchema $schema,
		?QuerySourceIdentities $identities = null,
	) {
		$this->identities = $identities ?? new QuerySourceIdentities($schema->getSources());
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
		return $this->schema->getSources();
	}

	/**
	 * One identity map for the prepared query root; reuse across all tracked rows.
	 */
	public function getIdentities(): QuerySourceIdentities
	{
		return $this->identities;
	}
}
