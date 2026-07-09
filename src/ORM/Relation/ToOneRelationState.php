<?php

declare(strict_types=1);

namespace ON\Data\ORM\Relation;

use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;

final class ToOneRelationState implements RelationChangeInterface
{
	private ?object $baselineTarget;
	private ?object $target;

	public function __construct(
		private readonly RecordState $owner,
		private readonly string $relationName,
		private readonly RepresentationSchema $relatedSchema,
		?object $target = null,
	) {
		if (trim($relationName) === '') {
			throw new StateException('To-one relation name cannot be empty.');
		}

		$this->baselineTarget = $target;
		$this->target = $target;
	}

	public function getOwner(): RecordState
	{
		return $this->owner;
	}

	public function getRelationName(): string
	{
		return $this->relationName;
	}

	public function getRelatedSchema(): RepresentationSchema
	{
		return $this->relatedSchema;
	}

	public function getTarget(): ?object
	{
		return $this->target;
	}

	public function getBaselineTarget(): ?object
	{
		return $this->baselineTarget;
	}

	public function set(?object $target): void
	{
		if ($this->target === $target) {
			return;
		}

		$this->target = $target;
	}

	public function clear(): void
	{
		$this->set(null);
	}

	public function hasTarget(): bool
	{
		return $this->target !== null;
	}

	public function hasChanges(): bool
	{
		return $this->baselineTarget !== $this->target;
	}

	public function clearChanges(): void
	{
		$this->baselineTarget = $this->target;
	}
}
