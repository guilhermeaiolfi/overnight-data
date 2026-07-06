<?php

declare(strict_types=1);

namespace ON\Data\ORM\Binding;

use InvalidArgumentException;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\SelectQuery;

final class QueryProjectionSourceResolver implements ProjectionSourceResolver
{
	public function __construct(
		private SelectQuery $query,
	) {
	}

	public function resolve(object $source): ProjectionSourceTarget
	{
		if (! $source instanceof QuerySourceInterface) {
			throw new InvalidArgumentException('Query projection source resolver requires a query source.');
		}

		if ($source === $this->query) {
			return new ProjectionSourceTarget($this->query->getCollection(), new RepresentationBinding());
		}

		if ($source instanceof RelationRef && $source->getQuery() === $this->query) {
			return new ProjectionSourceTarget($source->getCollection(), new RepresentationBinding());
		}

		throw new StateException('Cannot resolve projection source because it does not belong to this query.');
	}
}
