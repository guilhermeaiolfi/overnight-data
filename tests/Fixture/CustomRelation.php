<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Definition\Relation\AbstractRelation;
use ON\Data\Query\Relation\Loader\HasManyLoader;

final class CustomRelation extends AbstractRelation
{
	protected static function definitionDefaults(): array
	{
		return array_replace(parent::definitionDefaults(), [
			'loader' => HasManyLoader::class,
		]);
	}
}
