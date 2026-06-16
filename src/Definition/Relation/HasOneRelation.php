<?php

declare(strict_types=1);

namespace ON\Data\Definition\Relation;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Field\FieldInterface;

class HasOneRelation extends AbstractRelation
{
	protected bool $exclusive = false;

	public function exclusive(bool $exclusive): self
	{
		$this->exclusive = $exclusive;

		return $this;
	}

	public function isExclusive(): bool
	{
		return $this->exclusive;
	}

	public function end(): CollectionInterface
	{
		$this->generateField();

		return parent::end();
	}

	// creates the field into the parent collection
	public function generateField(): ?FieldInterface
	{
		if ($this->inner_keys === [] || $this->outer_keys === []) {
			return null;
		}

		if (count($this->inner_keys) !== 1 || count($this->outer_keys) !== 1) {
			return null;
		}

		$parentCollection = $this->parent;

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
		return $this->loader;
	}
}
