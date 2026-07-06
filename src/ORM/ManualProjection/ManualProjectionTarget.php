<?php

declare(strict_types=1);

namespace ON\Data\ORM\ManualProjection;

use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationRelationCardinality;
use ON\Data\Query\Expression\StarExpression;
use ON\Data\Query\Expression\ValueExpressionInterface;
use ON\Data\Query\SelectQuery;

final class ManualProjectionTarget
{
	private ?SelectQuery $selectionSource = null;

	public function __construct(
		private ManualProjectionBuilder $builder,
		private RecordState $owner,
		private string $relationName,
		private RepresentationRelationCardinality $cardinality,
		private RepresentationBinding $relatedBinding,
		private RecordState $targetRecord,
		private object $targetObject,
		private bool $objectShaped,
	) {
	}

	public function getOwner(): RecordState
	{
		return $this->owner;
	}

	public function getRelationName(): string
	{
		return $this->relationName;
	}

	public function getCardinality(): RepresentationRelationCardinality
	{
		return $this->cardinality;
	}

	public function getRelatedBinding(): RepresentationBinding
	{
		return $this->relatedBinding;
	}

	public function getTargetRecord(): RecordState
	{
		return $this->targetRecord;
	}

	public function getTargetObject(): object
	{
		return $this->targetObject;
	}

	public function isObjectShaped(): bool
	{
		return $this->objectShaped;
	}

	public function field(string $name): ValueExpressionInterface
	{
		return $this->selectionSource()->field($name);
	}

	public function all(): StarExpression
	{
		return $this->selectionSource()->all();
	}

	public function end(): object
	{
		return $this->builder->finalizeObjectShapedTarget($this);
	}

	public function __get(string $name): mixed
	{
		return $this->selectionSource()->__get($name);
	}

	private function selectionSource(): SelectQuery
	{
		if ($this->selectionSource instanceof SelectQuery) {
			return $this->selectionSource;
		}

		$this->selectionSource = new SelectQuery($this->targetRecord->getCollection());
		$this->builder->rememberTargetSelectionSource($this, $this->selectionSource);

		return $this->selectionSource;
	}
}
