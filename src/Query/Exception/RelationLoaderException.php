<?php

declare(strict_types=1);

namespace ON\Data\Query\Exception;

use InvalidArgumentException;
use ON\Data\Query\Relation\Loader\LoaderInterface;
use ON\Data\Query\Relation\RelationRef;

final class RelationLoaderException extends InvalidArgumentException
{
	public static function invalidLoader(RelationRef $relation, string $loader): self
	{
		return new self(sprintf(
			'Relation "%s" has invalid loader "%s". Expected a class implementing %s.',
			implode('.', $relation->getPath()),
			$loader,
			LoaderInterface::class,
		));
	}

	public static function invalidLoaderClass(RelationRef $relation, string $loader, string $reason): self
	{
		return new self(sprintf(
			'Relation "%s" has invalid loader "%s": %s',
			implode('.', $relation->getPath()),
			$loader,
			$reason,
		));
	}

	public static function nestedJoinNotSupported(RelationRef $relation): self
	{
		return new self(sprintf(
			'Nested relation path "%s" is not supported by flat relation joins yet.',
			implode('.', $relation->getPath()),
		));
	}

	public static function relationWhereNotSupported(RelationRef $relation): self
	{
		return new self(sprintf(
			'Relation "%s" has conditions that are not supported by flat relation joins.',
			implode('.', $relation->getPath()),
		));
	}

	public static function relationOrderByNotSupported(RelationRef $relation): self
	{
		return new self(sprintf(
			'Relation "%s" has ordering that is not supported by flat relation joins.',
			implode('.', $relation->getPath()),
		));
	}

	public static function relationKeysIncomplete(RelationRef $relation): self
	{
		return new self(sprintf(
			'Relation "%s" cannot be joined because its key lists are incomplete.',
			implode('.', $relation->getPath()),
		));
	}

	public static function loadingNotImplemented(RelationRef $relation): self
	{
		return new self(sprintf(
			'Relation loading is not implemented for relation "%s".',
			implode('.', $relation->getPath()),
		));
	}

	public static function joinedLoadingNotImplemented(RelationRef $relation): self
	{
		return new self(sprintf(
			'Joined relation loading is not implemented for relation "%s".',
			implode('.', $relation->getPath()),
		));
	}

	public static function firstOfManyJoinNotSupported(RelationRef $relation): self
	{
		return new self(sprintf(
			'Relation "%s" cannot use JOIN loading because FirstOfMany must choose one ordered child per parent. Use separate-query loading instead.',
			implode('.', $relation->getPath()),
		));
	}

	public static function firstOfManyOrderRequired(RelationRef $relation): self
	{
		return new self(sprintf(
			'Relation "%s" cannot be loaded because FirstOfMany requires deterministic orderBy metadata.',
			implode('.', $relation->getPath()),
		));
	}

	public static function throughWhereNotSupported(RelationRef $relation): self
	{
		return new self(sprintf(
			'Relation "%s" has through conditions that are not supported by flat relation joins.',
			implode('.', $relation->getPath()),
		));
	}

	public static function missingThrough(RelationRef $relation): self
	{
		return new self(sprintf(
			'Relation "%s" is missing through metadata.',
			implode('.', $relation->getPath()),
		));
	}

	public static function malformedThrough(RelationRef $relation, string $reason): self
	{
		return new self(sprintf(
			'Relation "%s" %s',
			implode('.', $relation->getPath()),
			$reason,
		));
	}
}
