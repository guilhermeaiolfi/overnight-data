<?php

declare(strict_types=1);

namespace ON\Data\Definition\Relation;

use ON\Data\Query\Relation\Loader\FirstOfManyLoader;

/**
 * Read-only derived view of a has-many association: one ordered child per parent.
 *
 * Loading is owned by {@see FirstOfManyLoader}. There is no persistence planner —
 * mutations belong on the underlying has-many (or child collection), not on this view.
 */
class FirstOfManyRelation extends HasManyRelation
{
	protected static function definitionDefaults(): array
	{
		return array_replace(parent::definitionDefaults(), [
			'loader' => FirstOfManyLoader::class,
			'persistencePlanner' => null,
		]);
	}

	public function getCardinality(): RelationCardinality
	{
		return RelationCardinality::SINGLE;
	}
}
