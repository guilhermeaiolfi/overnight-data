<?php

declare(strict_types=1);

namespace ON\Data\Definition\Relation;

use ON\Data\ORM\Relation\Persistence\BelongsToPersistencePlanner;
use ON\Data\Query\Relation\Loader\BelongsToLoader;

// TODO: I need to really think about it to make sure that's the right behavior.
class BelongsToRelation extends HasOneRelation
{
	protected static function definitionDefaults(): array
	{
		return array_replace(parent::definitionDefaults(), [
			'nullable' => true,
			'loader' => BelongsToLoader::class,
			'persistencePlanner' => BelongsToPersistencePlanner::class,
		]);
	}
}
