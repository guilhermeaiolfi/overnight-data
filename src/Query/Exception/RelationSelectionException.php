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

	/**
	 * @param list<string> $path
	 */
	public static function hiddenLoadedRelation(array $path): self
	{
		return new self(sprintf(
			'Relation "%s" cannot request load=true with visible=false.',
			implode('.', $path),
		));
	}

	/**
	 * @param list<string> $path
	 */
	public static function hiddenTerminalRelation(array $path): self
	{
		return new self(sprintf(
			'Relation "%s" cannot be selected as a hidden terminal relation.',
			implode('.', $path),
		));
	}

	/**
	 * @param list<string> $path
	 */
	public static function unknownRelationOption(array $path, string $name): self
	{
		return new self(sprintf(
			'Relation "%s" does not support the "%s" selection option.',
			implode('.', $path),
			$name,
		));
	}

	/**
	 * @param list<string> $path
	 */
	public static function positionalRelationOption(array $path): self
	{
		return new self(sprintf(
			'Relation "%s" selection options must use named arguments.',
			implode('.', $path),
		));
	}

	/**
	 * @param list<string> $path
	 */
	public static function invalidRelationOptionType(array $path, string $name): self
	{
		return new self(sprintf(
			'Relation "%s" selection option "%s" must be a boolean.',
			implode('.', $path),
			$name,
		));
	}

	public static function ambiguousPromotion(string $parentPath, string $name): self
	{
		return new self(sprintf(
			'Promoted relation output "%s" is ambiguous under "%s".',
			$name,
			$parentPath,
		));
	}
}
