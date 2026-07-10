<?php

declare(strict_types=1);

namespace ON\Data\Query\QueryFunction;

use ON\Data\Database\DatabasePlatformInterface;
use ON\Data\Query\Expression\ValueExpressionInterface;

interface FunctionCompilationContextInterface
{
	public function platform(): DatabasePlatformInterface;

	public function compile(ValueExpressionInterface $expression): CompiledExpression;

	/**
	 * @param list<mixed> $parameters
	 */
	public function sql(string $sql, array $parameters = []): CompiledExpression;

	public function quoteIdentifier(string $identifier): string;
}
