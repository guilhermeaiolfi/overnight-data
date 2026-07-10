<?php

declare(strict_types=1);

namespace ON\Data\Query\QueryFunction;

interface QueryFunctionInterface
{
	public function compile(
		FunctionCompilationContextInterface $context,
		FunctionArguments $arguments,
	): CompiledExpression;
}
