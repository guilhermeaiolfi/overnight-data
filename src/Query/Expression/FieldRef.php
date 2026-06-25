<?php

declare(strict_types=1);

namespace ON\Data\Query\Expression;

use ON\Data\Definition\Field\FieldInterface;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\SelectQuery;

final class FieldRef extends AbstractAggregateableExpression
{
	public function __construct(
		private readonly SelectQuery $query,
		private readonly FieldInterface $field,
		private readonly ?RelationRef $relation = null,
	) {
	}

	public function getQuery(): SelectQuery
	{
		return $this->query;
	}

	public function getField(): FieldInterface
	{
		return $this->field;
	}

	public function getRelation(): ?RelationRef
	{
		return $this->relation;
	}

	public function getName(): string
	{
		return $this->field->getName();
	}

	/**
	 * @return list<string>
	 */
	public function getPath(): array
	{
		$path = $this->relation?->getPath() ?? [];
		$path[] = $this->getName();

		return $path;
	}
}
