<?php

declare(strict_types=1);

namespace ON\Data\Definition\Relation;

use ON\Data\Query\Relation\Loader\HasManyLoader;

class HasManyRelation extends AbstractRelation
{
	protected static function definitionDefaults(): array
	{
		return array_replace(parent::definitionDefaults(), [
			'loader' => HasManyLoader::class,
		]);
	}

	public function getCardinality(): string
	{
		return 'many';
	}
}
