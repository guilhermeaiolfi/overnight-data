<?php

declare(strict_types=1);

namespace ON\Data\ORM\Compiler\SelectQuery;

/**
 * Resolves query-side projection sources (root SelectQuery or RelationRef) to
 * collection identity and source path for schema assembly.
 *
 * Exists as the SelectQuery-specific ProjectionSourceResolverInterface; it
 * intentionally does not interpret aliases or build field schemas.
 */
use InvalidArgumentException;
use ON\Data\ORM\Compiler\ProjectionSourceResolverInterface;
use ON\Data\ORM\Compiler\ResolvedProjectionSource;
use ON\Data\ORM\Exception\StateException;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\SelectQuery;

final class QueryProjectionSourceResolver implements ProjectionSourceResolverInterface
{
	public function __construct(
		private SelectQuery $query,
	) {
	}

	public function resolve(object $source): ResolvedProjectionSource
	{
		if (! $source instanceof QuerySourceInterface) {
			throw new InvalidArgumentException('Query projection source resolver requires a query source.');
		}

		if ($source === $this->query) {
			return new ResolvedProjectionSource($this->query->getCollection(), sourcePath: []);
		}

		if ($source instanceof RelationRef && $source->getQuery() === $this->query) {
			return new ResolvedProjectionSource($source->getCollection(), sourcePath: $source->getPath());
		}

		throw new StateException('Cannot resolve projection source because it does not belong to this query.');
	}
}
