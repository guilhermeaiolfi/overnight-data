<?php

declare(strict_types=1);

namespace ON\Data\Definition\Relation;

final class BuiltInRelationTypes
{
	public const DEFAULT = HasOneRelation::class;

	public const HAS_MANY = HasManyRelation::class;

	public const HAS_ONE = HasOneRelation::class;

	public const BELONGS_TO = BelongsToRelation::class;

	/**
	 * @return class-string<RelationInterface>
	 */
	public static function default(): string
	{
		return self::DEFAULT;
	}

	/**
	 * @return class-string<RelationInterface>
	 */
	public static function hasMany(): string
	{
		return self::HAS_MANY;
	}

	/**
	 * @return class-string<RelationInterface>
	 */
	public static function hasOne(): string
	{
		return self::HAS_ONE;
	}

	/**
	 * @return class-string<RelationInterface>
	 */
	public static function belongsTo(): string
	{
		return self::BELONGS_TO;
	}
}
