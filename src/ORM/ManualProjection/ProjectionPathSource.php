<?php

declare(strict_types=1);

namespace ON\Data\ORM\ManualProjection;

use ON\Data\Query\Expression\StarExpression;
use ON\Data\Query\Expression\ValueExpressionInterface;
use ON\Data\Query\SelectQuery;

final class ProjectionPathSource
{
	public function __construct(
		private ManualProjectionBuilder $builder,
		private SelectQuery $source,
	) {
	}

	public function field(string $name): ValueExpressionInterface
	{
		return $this->source->field($name);
	}

	public function all(): StarExpression
	{
		return $this->source->all();
	}

	public function end(): object
	{
		return $this->builder->end();
	}

	public function __get(string $name): mixed
	{
		return $this->source->__get($name);
	}
}
