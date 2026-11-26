<?php

declare(strict_types=1);

namespace ON\Data\ORM\Relation;

use ON\Data\ORM\Record\RecordState;

/** Identity used for relation membership and pending changes. */
final class RelationMemberIdentity
{
	public static function key(object $item): string
	{
		if ($item instanceof RecordState) {
			$key = $item->getKey();

			return $key === null
				? 'r:' . spl_object_id($item)
				: 'k:' . $item->getCollection()->getName() . ':' . $key->getHash();
		}

		return 'o:' . spl_object_id($item);
	}

	public static function same(?object $left, ?object $right): bool
	{
		return $left === $right
			|| ($left instanceof RecordState && $right instanceof RecordState && self::key($left) === self::key($right));
	}
}
