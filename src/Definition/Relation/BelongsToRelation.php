<?php

declare(strict_types=1);

namespace ON\Data\Definition\Relation;

use InvalidArgumentException;
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

		$innerName = $innerKeys[0];
		$outerField = $this->getOuterField();
		$innerField = $parentCollection->getField($innerName);
		if ($innerField === null) {
			return $parentCollection->field($innerName, $outerField->getType())
				->nullable($this->isNullable())
				->setGeneratedFromRelation($this->getName());
		}

		if ($innerField->getType() !== $outerField->getType()) {
			throw new InvalidArgumentException(sprintf(
				'Relation %1$s.%2$s expects %1$s.%3$s (%4$s) to match %5$s.%6$s (%7$s).',
				$parentCollection->getName(),
				$this->getName(),
				$innerName,
				$innerField->getType(),
				$this->getCollectionName(),
				$outerField->getName(),
				$outerField->getType(),
			));
		}

		return $innerField;
	}

	protected function initializeRuntimeState(): void
	{
		parent::initializeRuntimeState();
		$this->requireCollectionParent(static::class);
	}
}
