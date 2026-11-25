<?php

declare(strict_types=1);

namespace ON\Data\Definition\Relation;

use ON\Data\Definition\DefinitionInterface;
use ON\Data\Definition\Field\FieldInterface;
use ON\Data\ORM\Relation\Persistence\BelongsToPersistencePlanner;
use ON\Data\Query\Relation\Loader\BelongsToLoader;

class BelongsToRelation extends AbstractRelation
{
	protected static function definitionDefaults(): array
	{
		return array_replace(parent::definitionDefaults(), [
			'nullable' => true,
			'loader' => BelongsToLoader::class,
			'persistencePlanner' => BelongsToPersistencePlanner::class,
		]);
	}

	public function end(): DefinitionInterface
	{
		$this->generateField();

		return parent::end();
	}

	public function generateField(): ?FieldInterface
	{
		$parentCollection = $this->requireCollectionParent(static::class);
		$innerKeys = (array) $this->get('inner_keys');
		$outerKeys = (array) $this->get('outer_keys');

		if (count($innerKeys) !== 1 || count($outerKeys) !== 1) {
			return null;
		}

		$innerField = $this->getInnerField();
		$type = $this->getOuterField()->getType();
		$field = $parentCollection->field($innerField->getName());
		$field
			->setGeneratedFromRelation($parentCollection->getName())
			->type($type);

		return $field;
	}

	protected function initializeRuntimeState(): void
	{
		parent::initializeRuntimeState();
		$this->requireCollectionParent(static::class);
	}
}
