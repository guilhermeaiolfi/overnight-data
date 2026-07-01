<?php

declare(strict_types=1);

namespace ON\Data\Query\Exception;

use InvalidArgumentException;
use ON\Data\Query\Relation\LoadStrategy;
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
	public static function invalidRelationFieldsType(array $path): self
	{
		return new self(sprintf(
			'Relation "%s" selection option "fields" must be a list of non-empty field names.',
			implode('.', $path),
		));
	}

	/**
	 * @param list<string> $path
	 */
	public static function emptyRelationFields(array $path): self
	{
		return new self(sprintf(
			'Relation "%s" selection option "fields" must select at least one field.',
			implode('.', $path),
		));
	}

	/**
	 * @param list<string> $path
	 */
	public static function invalidRelationFieldName(array $path, mixed $value): self
	{
		return new self(sprintf(
			'Relation "%s" selection option "fields" contains invalid field name %s.',
			implode('.', $path),
			var_export($value, true),
		));
	}

	/**
	 * @param list<string> $path
	 */
	public static function unknownRelationField(array $path, string $name): self
	{
		return new self(sprintf(
			'Relation "%s" selection option "fields" references unknown field "%s".',
			implode('.', $path),
			$name,
		));
	}

	/**
	 * @param list<string> $path
	 */
	public static function invalidRelationFieldReference(array $path, string $name): self
	{
		return new self(sprintf(
			'Relation "%s" selection option "fields" cannot use field reference "%s" from a different relation path.',
			implode('.', $path),
			$name,
		));
	}

	/**
	 * @param list<string> $path
	 */
	public static function conflictingRelationStrategies(array $path, LoadStrategy $left, LoadStrategy $right): self
	{
		return new self(sprintf(
			'Relation "%s" cannot be selected with conflicting load strategies "%s" and "%s".',
			implode('.', $path),
			$left->name,
			$right->name,
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
