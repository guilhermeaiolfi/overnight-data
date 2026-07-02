<?php

declare(strict_types=1);

namespace ON\Data\Query\Expression;

use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Sort\Sort;

final class WindowFunctionExpression extends AbstractValueExpression
{
	public function __construct(
		private readonly WindowFunction $function,
		private readonly ?WindowSpec $window = null,
	) {
	}

	public function getFunction(): WindowFunction
	{
		return $this->function;
	}

	public function getWindow(): ?WindowSpec
	{
		return $this->window;
	}

	public function bindTo(QuerySourceInterface $target, ?QuerySourceInterface $from = null): self
	{
		$window = $this->window?->bindTo($target, from: $from);

		if ($window === $this->window) {
			return $this;
		}

		return new self($this->function, $window);
	}

	/**
	 * @param ValueExpressionInterface|list<ValueExpressionInterface>|null $partitionBy
	 * @param Sort|list<Sort>|null $orderBy
	 */
	public function over(
		ValueExpressionInterface|array|null $partitionBy = null,
		Sort|array|null $orderBy = null,
	): self {
		return new self(
			$this->function,
			new WindowSpec(
				$this->normalizeExpressions($partitionBy),
				$this->normalizeSorts($orderBy),
			),
		);
	}

	/**
	 * @param ValueExpressionInterface|list<ValueExpressionInterface>|null $expressions
	 * @return list<ValueExpressionInterface>
	 */
	private function normalizeExpressions(ValueExpressionInterface|array|null $expressions): array
	{
		if ($expressions === null) {
			return [];
		}

		return $expressions instanceof ValueExpressionInterface
			? [$expressions]
			: array_values($expressions);
	}

	/**
	 * @param Sort|list<Sort>|null $sorts
	 * @return list<Sort>
	 */
	private function normalizeSorts(Sort|array|null $sorts): array
	{
		if ($sorts === null) {
			return [];
		}

		return $sorts instanceof Sort
			? [$sorts]
			: array_values($sorts);
	}
}
