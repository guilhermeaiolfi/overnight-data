<?php

declare(strict_types=1);

namespace ON\Data\Definition\Relation;

use ON\Data\Definition\DefinitionInterface;
use ON\Data\Definition\Field\FieldInterface;
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

	public function end(): DefinitionInterface
	{
		$this->generateField();

		return parent::end();
	}

	// creates the field into the parent collection
	public function generateField(): ?FieldInterface
	{
		$parentCollection = $this->requireCollectionParent(static::class);
		$innerKeys = (array) $this->get('inner_keys');
		$outerKeys = (array) $this->get('outer_keys');

		if ($innerKeys === [] || $outerKeys === []) {
			return null;
		}

		if (count($innerKeys) !== 1 || count($outerKeys) !== 1) {
			return null;
		}

		$innerField = $this->getInnerField();
		$outerField = $this->getOuterField();

		$type = $outerField->getType();

		// not necessarly we are creating this field, it could exist already
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
