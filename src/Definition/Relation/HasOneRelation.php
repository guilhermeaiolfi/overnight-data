<?php

declare(strict_types=1);

namespace ON\Data\Definition\Relation;

use ON\Data\ORM\Relation\Persistence\HasOnePersistencePlanner;
use ON\Data\Query\Relation\Loader\HasOneLoader;

class HasOneRelation extends AbstractRelation
{
	protected static function definitionDefaults(): array
	{
		return array_replace(parent::definitionDefaults(), [
			'loader' => HasOneLoader::class,
			'persistencePlanner' => HasOnePersistencePlanner::class,
		]);
	}

	protected function initializeRuntimeState(): void
	{
		parent::initializeRuntimeState();
		$this->requireCollectionParent(static::class);
	}
}
