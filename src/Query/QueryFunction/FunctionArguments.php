<?php

declare(strict_types=1);

namespace ON\Data\Query\QueryFunction;

use ON\Data\Query\Expression\LiteralExpression;
use ON\Data\Query\Expression\ValueExpressionInterface;

final class FunctionArguments
{
	/**
	 * @param list<ValueExpressionInterface> $arguments
	 */
	public function __construct(
		private readonly array $arguments,
	) {
	}

	public function count(): int
	{
		return count($this->arguments);
	}

	public function has(int $index): bool
	{
		return array_key_exists($index, $this->arguments);
	}

	public function expression(int $index): ValueExpressionInterface
	{
		if (! $this->has($index)) {
			throw FunctionArgumentException::missing($index, $this->count());
		}

		return $this->arguments[$index];
	}

	public function literal(int $index): mixed
	{
		$expression = $this->expression($index);

		if (! $expression instanceof LiteralExpression) {
			throw FunctionArgumentException::expectedLiteral($index, $expression::class);
		}

		return $expression->getValue();
	}

	/**
	 * @return list<ValueExpressionInterface>
	 */
	public function all(): array
	{
		return $this->arguments;
	}
}
