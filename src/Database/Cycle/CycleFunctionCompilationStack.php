<?php

declare(strict_types=1);

namespace ON\Data\Database\Cycle;

use ON\Data\Query\Expression\FunctionCallExpression;
use ON\Data\Query\QueryFunction\FunctionCompilationException;

/**
 * @internal
 */
final class CycleFunctionCompilationStack
{
	/**
	 * @var array<int, true>
	 */
	private array $compiling = [];

	public function enter(FunctionCallExpression $expression): void
	{
		$id = spl_object_id($expression);

		if (isset($this->compiling[$id])) {
			throw FunctionCompilationException::recursion($expression->getFunction());
		}

		$this->compiling[$id] = true;
	}

	public function leave(FunctionCallExpression $expression): void
	{
		unset($this->compiling[spl_object_id($expression)]);
	}

	public function contains(FunctionCallExpression $expression): bool
	{
		return isset($this->compiling[spl_object_id($expression)]);
	}
}
