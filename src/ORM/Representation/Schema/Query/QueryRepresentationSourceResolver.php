<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Schema\Query;

use InvalidArgumentException;
use ON\Data\ORM\Representation\Schema\Shape\RepresentationSourceResolverInterface;
use ON\Data\ORM\Representation\Schema\Shape\ResolvedRepresentationSource;
use ON\Data\ORM\Exception\StateException;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\SelectQuery;
/**
 * Resolves query-side projection sources (root SelectQuery or RelationRef) to
 * collection identity and source path for schema assembly.
 *
 * Exists as the SelectQuery-specific RepresentationSourceResolverInterface; it
 * intentionally does not interpret aliases or build field schemas.
 */
final class QueryRepresentationSourceResolver implements RepresentationSourceResolverInterface
{
	public function __construct(
		private SelectQuery $query,
	) {
	}

	public function resolve(object $source): ResolvedRepresentationSource
	{
		if (! $source instanceof QuerySourceInterface) {
			throw new InvalidArgumentException('Query projection source resolver requires a query source.');
		}

		if ($source === $this->query) {
			return new ResolvedRepresentationSource($this->query->getCollection(), sourcePath: []);
		}

		if ($source instanceof RelationRef && $source->getQuery() === $this->query) {
			return new ResolvedRepresentationSource($source->getCollection(), sourcePath: $source->getPath());
		}

		throw new StateException('Cannot resolve projection source because it does not belong to this query.');
	}
}
