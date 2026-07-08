<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Definition\Relation\AbstractRelation;
use ON\Data\Definition\Relation\RelationCardinality;
use ON\Data\Query\Relation\Loader\HasManyLoader;

class CustomViewRelation extends AbstractRelation
{
	protected static function definitionDefaults(): array
	{
		return array_replace(parent::definitionDefaults(), [
			'loader' => HasManyLoader::class,
		]);
	}

	public function getCardinality(): RelationCardinality
	{
		return RelationCardinality::MANY;
	}
}
