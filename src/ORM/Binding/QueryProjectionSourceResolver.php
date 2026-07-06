<?php

declare(strict_types=1);

namespace ON\Data\ORM\Binding;

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

	public function resolve(QuerySourceInterface $source): ProjectionSourceTarget
	{
		if ($source === $this->query) {
			return new ProjectionSourceTarget($this->query->getCollection(), new RepresentationBinding());
		}

		if ($source instanceof RelationRef && $source->getQuery() === $this->query) {
			return new ProjectionSourceTarget($source->getCollection(), new RepresentationBinding());
		}

		throw new StateException('Cannot resolve projection source because it does not belong to this query.');
	}
}
