<?php

declare(strict_types=1);

namespace ON\Data\ORM\Relation;

use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;

final class ToOneRelationState implements RelationStateInterface
{
	private ?RelationTarget $baselineTarget;
	private ?RelationTarget $target;

	public function __construct(
		private readonly RecordState $owner,
		private readonly string $relationName,
		private readonly RepresentationSchema $relatedSchema,
		?object $target = null,
	) {
		if (trim($relationName) === '') {
			throw new StateException('To-one relation name cannot be empty.');
		}

		$normalized = $target === null ? null : RelationTarget::from($target);
		$this->baselineTarget = $normalized;
		$this->target = $normalized;
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
		return $this->target?->toObject();
	}

	public function getTargetRelation(): ?RelationTarget
	{
		return $this->target;
	}

	public function getBaselineTarget(): ?object
	{
		return $this->baselineTarget?->toObject();
	}

	public function set(?object $target): void
	{
		$normalized = $target === null ? null : RelationTarget::from($target);
		if ($this->sameTarget($this->target, $normalized)) {
			return;
		}

		$this->target = $normalized;
	}

	public function setTarget(?RelationTarget $target): void
	{
		if ($this->sameTarget($this->target, $target)) {
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
		return ! $this->sameTarget($this->baselineTarget, $this->target);
	}

	public function clearChanges(): void
	{
		$this->baselineTarget = $this->target;
	}

	private function sameTarget(?RelationTarget $left, ?RelationTarget $right): bool
	{
		if ($left === null && $right === null) {
			return true;
		}

		if ($left === null || $right === null) {
			return false;
		}

		return $left->equals($right);
	}
}
