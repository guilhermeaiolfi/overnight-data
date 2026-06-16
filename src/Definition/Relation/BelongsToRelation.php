<?php

declare(strict_types=1);

namespace ON\Data\Definition\Relation;

// TODO: I need to really think about it to make sure that's the right behavior.
class BelongsToRelation extends HasOneRelation
{
	protected static function definitionDefaults(): array
	{
		return array_replace(parent::definitionDefaults(), [
			'nullable' => true,
		]);
	}
}
