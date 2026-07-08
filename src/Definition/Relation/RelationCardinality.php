<?php

declare(strict_types=1);

namespace ON\Data\Definition\Relation;

enum RelationCardinality
{
	case SINGLE;
	case MANY;

	public function isMany(): bool
	{
		return $this === self::MANY;
	}

	public function isSingle(): bool
	{
		return $this === self::SINGLE;
	}
}
