<?php

declare(strict_types=1);

namespace ON\Data\Query;

use ON\Data\Query\Expression\ExpressionOperandNormalizer;
use ON\Data\Query\Expression\FunctionCallExpression;
use ON\Data\Query\Expression\WindowFunction;
use ON\Data\Query\Expression\WindowFunctionExpression;
use ON\Data\Query\QueryFunction\InvalidQueryFunctionException;
use ON\Data\Query\QueryFunction\QueryFunctionInterface;
use ReflectionClass;
use ReflectionException;

final class ExpressionFunctionFactory
{
	public function rowNumber(): WindowFunctionExpression
	{
		return new WindowFunctionExpression(WindowFunction::ROW_NUMBER);
	}

	public function rank(): WindowFunctionExpression
	{
		return new WindowFunctionExpression(WindowFunction::RANK);
	}

	public function denseRank(): WindowFunctionExpression
	{
		return new WindowFunctionExpression(WindowFunction::DENSE_RANK);
	}

	/**
	 * @param class-string<QueryFunctionInterface> $function
	 */
	public function call(
		string $function,
		mixed ...$arguments,
	): FunctionCallExpression {
		$this->assertValidFunctionClass($function);

		$normalized = array_map(
			static fn (mixed $argument) => ExpressionOperandNormalizer::normalize($argument, 'function'),
			$arguments,
		);

		return new FunctionCallExpression($function, $normalized);
	}

	/**
	 * @param class-string $function
	 */
	private function assertValidFunctionClass(string $function): void
	{
		if (! class_exists($function)) {
			throw InvalidQueryFunctionException::classMissing($function);
		}

		if (! is_a($function, QueryFunctionInterface::class, true)) {
			throw InvalidQueryFunctionException::mustImplement($function);
		}

		try {
			$reflection = new ReflectionClass($function);
		} catch (ReflectionException) {
			throw InvalidQueryFunctionException::notInstantiable($function);
		}

		if (! $reflection->isInstantiable()) {
			throw InvalidQueryFunctionException::notInstantiable($function);
		}

		$constructor = $reflection->getConstructor();

		if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
			throw InvalidQueryFunctionException::notInstantiable($function);
		}
	}
}
