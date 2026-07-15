<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Schema\Query;

use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use ON\Data\ORM\Representation\Schema\Shape\RepresentationSource;
use ON\Data\ORM\Representation\State\Query\QueryRepresentationStateBuilder;

/**
 * Query compilation result pairing the public RepresentationSchema with the
 * compiled structural RepresentationSource entries and QueryRepresentationIdentityColumns
 * needed to adopt flat mutable query rows.
 *
 * Exists because mutable SelectQuery export must pass identity metadata to
 * QueryRepresentationStateBuilder separately from the user-visible schema.
 */
final class QueryRepresentationPlan
{
	/** @var list<RepresentationSource> */
	private array $sources;

	/**
	 * @param list<RepresentationSource> $sources
	 */
	public function __construct(
		private RepresentationSchema $schema,
		array $sources,
		private QueryRepresentationIdentityColumns $identityColumns,
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

	public function getIdentityColumns(): QueryRepresentationIdentityColumns
	{
		return $this->identityColumns;
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
