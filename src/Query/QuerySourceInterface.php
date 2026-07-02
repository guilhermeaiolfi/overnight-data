<?php

declare(strict_types=1);

namespace ON\Data\Query;

use ON\Data\Query\Expression\StarExpression;
use ON\Data\Query\Expression\ValueExpressionInterface;

interface QuerySourceInterface
{
	public function getQuery(): SelectQuery;

	/**
	 * @return list<string>
	 */
	public function getPath(): array;

	public function field(string $name): ValueExpressionInterface;

	public function all(): StarExpression;
}
