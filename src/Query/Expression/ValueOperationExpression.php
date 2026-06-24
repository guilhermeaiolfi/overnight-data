<?php

declare(strict_types=1);

namespace ON\Data\Query\Expression;

use InvalidArgumentException;

final class ValueOperationExpression extends AbstractAggregateableExpression
{
	/**
	 * @var non-empty-list<ValueExpressionInterface>
	 */
	private readonly array $arguments;

	/**
	 * @param non-empty-list<ValueExpressionInterface> $arguments
	 */
	public function __construct(
		private readonly ValueOperation $operation,
		array $arguments,
	) {
		$this->arguments = array_values($arguments);

		if ($this->arguments === []) {
			throw new InvalidArgumentException('Value operations require at least one argument.');
		}

		foreach ($this->arguments as $argument) {
			if (! $argument instanceof ValueExpressionInterface) {
				throw new InvalidArgumentException('Value-operation arguments must be value expressions.');
			}
		}

		$count = count($this->arguments);

		if (match ($this->operation) {
			ValueOperation::UPPER, ValueOperation::LOWER => $count !== 1,
			ValueOperation::CONCAT, ValueOperation::COALESCE, ValueOperation::ADD => $count < 2,
		}) {
			throw new InvalidArgumentException(sprintf(
				'Value operation %s received an invalid argument count of %d.',
				$this->operation->name,
				$count,
			));
		}
	}

	public function getOperation(): ValueOperation
	{
		return $this->operation;
	}

	/**
	 * @return non-empty-list<ValueExpressionInterface>
	 */
	public function getArguments(): array
	{
		return $this->arguments;
	}
}
