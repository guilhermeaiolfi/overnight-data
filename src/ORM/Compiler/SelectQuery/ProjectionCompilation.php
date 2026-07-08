<?php

declare(strict_types=1);

namespace ON\Data\ORM\Compiler\SelectQuery;

/**
 * Query compilation result pairing the public RepresentationSchema with the
 * compiled structural ProjectionSource entries and ProjectionIdentityColumns
 * needed to adopt flat mutable query rows.
 *
 * Exists because mutable SelectQuery export must pass identity metadata to
 * ProjectionRepresentationAdopter separately from the user-visible binding.
 */
use ON\Data\ORM\Compiler\ProjectionSource;
use ON\Data\ORM\State\RepresentationSchema;

final class ProjectionCompilation
{
	/** @var list<ProjectionSource> */
	private array $sources;

	/**
	 * @param list<ProjectionSource> $sources
	 */
	public function __construct(
		private RepresentationSchema $schema,
		array $sources,
		private ProjectionIdentityColumns $identityColumns,
	) {
		$this->sources = array_values($sources);
	}

	public function getSchema(): RepresentationSchema
	{
		return $this->schema;
	}

	/**
	 * @return list<ProjectionSource>
	 */
	public function getSources(): array
	{
		return $this->sources;
	}

	public function getIdentityColumns(): ProjectionIdentityColumns
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
