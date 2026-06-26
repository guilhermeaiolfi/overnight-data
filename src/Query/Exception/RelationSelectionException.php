<?php

declare(strict_types=1);

namespace ON\Data\Query\Exception;

use InvalidArgumentException;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\SelectQuery;

final class RelationSelectionException extends InvalidArgumentException
{
	public static function foreignQueryRelation(RelationRef $relation, SelectQuery $owner): self
	{
		return new self(sprintf(
			'Relation "%s" belongs to a different SelectQuery than "%s".',
			implode('.', $relation->getPath()),
			$owner->getCollection()->getName(),
		));
	}

	public static function rootAliasCollision(string $name): self
	{
		return new self(sprintf(
			'Root result name "%s" collides with a selected relation container.',
			$name,
		));
	}

	public static function iterateNotSupported(): self
	{
		return new self(
			'Structured relation selection currently supports only fetchAll() and fetchOne(); iterate() is not supported.',
		);
	}
}
