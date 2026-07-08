<?php

declare(strict_types=1);

namespace ON\Data\Definition\Relation;

use ON\Data\ORM\Relation\Persistence\HasManyPersistencePlanner;
use ON\Data\Query\Relation\Loader\HasManyLoader;

class HasManyRelation extends AbstractRelation
{
	protected static function definitionDefaults(): array
	{
		return array_replace(parent::definitionDefaults(), [
			'loader' => HasManyLoader::class,
			'persistencePlanner' => HasManyPersistencePlanner::class,
		]);
	}

	public function getCardinality(): RelationCardinality
	{
		return RelationCardinality::MANY;
	}
}
