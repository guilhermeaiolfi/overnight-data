<?php

declare(strict_types=1);

namespace ON\Data\Definition\Relation;

use ON\Data\Query\Relation\Loader\FirstOfManyLoader;

class FirstOfManyRelation extends HasManyRelation
{
	protected static function definitionDefaults(): array
	{
		return array_replace(parent::definitionDefaults(), [
			'loader' => FirstOfManyLoader::class,
		]);
	}

	public function getCardinality(): RelationCardinality
	{
		return RelationCardinality::SINGLE;
	}
}
