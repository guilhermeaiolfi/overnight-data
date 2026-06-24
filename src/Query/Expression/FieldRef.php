<?php

declare(strict_types=1);

namespace ON\Data\Query\Expression;

use ON\Data\Definition\Field\FieldInterface;
use ON\Data\Query\SelectQuery;

final class FieldRef extends AbstractAggregateableExpression
{
	public function __construct(
		private readonly SelectQuery $query,
		private readonly FieldInterface $field,
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

	public function getName(): string
	{
		return $this->field->getName();
	}
}
