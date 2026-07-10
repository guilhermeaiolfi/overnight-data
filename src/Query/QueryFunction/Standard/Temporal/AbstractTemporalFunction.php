<?php

declare(strict_types=1);

namespace ON\Data\Query\QueryFunction\Standard\Temporal;

use ON\Data\Database\DatabaseFamily;
use ON\Data\Query\QueryFunction\CompiledExpression;
use ON\Data\Query\QueryFunction\FunctionArgumentException;
use ON\Data\Query\QueryFunction\FunctionArguments;
use ON\Data\Query\QueryFunction\FunctionCompilationContextInterface;
use ON\Data\Query\QueryFunction\QueryFunctionInterface;
use ON\Data\Query\QueryFunction\UnsupportedQueryFunctionException;

/**
 * @internal
 */
abstract class AbstractTemporalFunction implements QueryFunctionInterface
{
	final public function compile(
		FunctionCompilationContextInterface $context,
		FunctionArguments $arguments,
	): CompiledExpression {
		if ($arguments->count() !== 1) {
			throw FunctionArgumentException::arity(static::class, 1, $arguments->count());
		}

		$value = $context->compile($arguments->expression(0));
		$sql = $this->compileSql($context->platform()->family(), $value->getSql());

		return $context->sql($sql, $value->getParameters());
	}

	abstract protected function compileSql(DatabaseFamily $family, string $argumentSql): string;

	protected function unsupported(DatabaseFamily $family): never
	{
		throw UnsupportedQueryFunctionException::forPlatform(static::class, $family);
	}
}
