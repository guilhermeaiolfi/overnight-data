<?php

declare(strict_types=1);

namespace ON\Data\Query\Exception;

use InvalidArgumentException;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\SelectQuery;

final class RelationQueryException extends InvalidArgumentException
{
	public static function foreignRelation(RelationRef $relation, SelectQuery $query): self
	{
		return new self(sprintf(
			'Relation "%s" belongs to a different query than the one calling relatedQuery() on source "%s".',
			implode('.', $relation->getPath()),
			$query->getSourceName(),
		));
	}

	public static function multiHopRelation(RelationRef $relation): self
	{
		return new self(sprintf(
			'relatedQuery() does not support implicit multi-hop relation refs such as "%s"; call relatedQuery() on each hop explicitly.',
			implode('.', $relation->getPath()),
		));
	}

	public static function firstOfManyUnsupported(RelationRef $relation): self
	{
		return new self(sprintf(
			'relatedQuery() does not support first-of-many relation "%s" until ordered predicate semantics are defined.',
			implode('.', $relation->getPath()),
		));
	}

	public static function unsupportedRelation(RelationRef $relation): self
	{
		return new self(sprintf(
			'relatedQuery() cannot plan relation "%s" of type %s.',
			implode('.', $relation->getPath()),
			$relation->getDefinition()::class,
		));
	}
}
