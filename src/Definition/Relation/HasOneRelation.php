<?php

declare(strict_types=1);

namespace ON\Data\Definition\Relation;

use ON\Data\Definition\DefinitionInterface;
use ON\Data\Definition\Field\FieldInterface;

class HasOneRelation extends AbstractRelation
{
	protected static function definitionDefaults(): array
	{
		return array_replace(parent::definitionDefaults(), [
			'exclusive' => false,
		]);
	}

	public function exclusive(bool $exclusive): self
	{
		$this->set('exclusive', $exclusive);

		return $this;
	}

	public function isExclusive(): bool
	{
		return (bool) $this->get('exclusive');
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

	public function getLoader(): ?string
	{
		return parent::getLoader();
	}

	protected function initializeRuntimeState(): void
	{
		parent::initializeRuntimeState();
		$this->requireCollectionParent(static::class);
	}
}
