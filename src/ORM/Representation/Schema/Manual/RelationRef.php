<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Schema\Manual;

use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\Query\Exception\UnknownQueryFieldException;

/**
 * Navigable relation reference in the manual representation fluent API.
 *
 * Exists to mirror query relation refs for nested field selection and for
 * create()/existing()/tracked($relation) without depending on SelectQuery types.
 */
final class RelationRef
{
	public function __construct(
		private ManualRepresentationSourceInterface $owner,
		private string $relationName,
		private RelationInterface $definition,
	) {
	}

	public function getOwner(): ManualRepresentationSourceInterface
	{
		return $this->owner;
	}

	public function getName(): string
	{
		return $this->relationName;
	}

	public function getDefinition(): RelationInterface
	{
		return $this->definition;
	}

	/**
	 * @return list<string>
	 */
	public function getPath(): array
	{
		return [...$this->owner->getRelationPath(), $this->relationName];
	}

	public function field(string $name): PropertyRef
	{
		$collection = $this->definition->getCollection();
		if (! $collection->hasField($name)) {
			throw new UnknownQueryFieldException(sprintf(
				"Unknown field '%s' on relation '%s'.",
				$name,
				implode('.', $this->getPath())
			));
		}

		return new PropertyRef($this, $name);
	}

	public function __get(string $name): PropertyRef
	{
		return $this->field($name);
	}
}
