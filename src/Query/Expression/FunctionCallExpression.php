<?php

declare(strict_types=1);

namespace ON\Data\Query\Expression;

use ON\Data\Query\QuerySourceInterface;

final class FunctionCallExpression extends AbstractAggregateableExpression
{
	/**
	 * @var list<ValueExpressionInterface>
	 */
	private readonly array $arguments;

	/**
	 * @param class-string $function
	 * @param list<ValueExpressionInterface> $arguments
	 */
	public function __construct(
		private readonly string $function,
		array $arguments,
	) {
		$this->arguments = array_values($arguments);
	}

	/**
	 * @return class-string
	 */
	public function getFunction(): string
	{
		return $this->function;
	}

	/**
	 * @return list<ValueExpressionInterface>
	 */
	public function getArguments(): array
	{
		return $this->arguments;
	}

	public function bindTo(QuerySourceInterface $target, ?QuerySourceInterface $from = null): self
	{
		$changed = false;
		$arguments = [];

		foreach ($this->arguments as $argument) {
			$bound = $argument->bindTo($target, from: $from);
			$changed = $changed || $bound !== $argument;
			$arguments[] = $bound;
		}

		if (! $changed) {
			return $this;
		}

		return new self($this->function, $arguments);
	}
}
