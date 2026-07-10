<?php

declare(strict_types=1);

namespace ON\Data\Query\Expression;

use InvalidArgumentException;
use ON\Data\Query\Condition\ConditionInterface;
use ON\Data\Query\SelectQuery;

/**
 * Shared operand normalization for expression and function factories.
 */
final class ExpressionOperandNormalizer
{
	public static function normalize(mixed $operand, string $context): ValueExpressionInterface
	{
		if ($operand instanceof AliasedExpression) {
			throw new InvalidArgumentException(sprintf('AliasedExpression cannot be used as a %s operand.', $context));
		}

		if ($operand instanceof StarExpression) {
			throw new InvalidArgumentException(sprintf('StarExpression cannot be used as a %s operand.', $context));
		}

		if ($operand instanceof ConditionInterface) {
			throw new InvalidArgumentException(sprintf('ConditionInterface cannot be used as a %s operand.', $context));
		}

		if ($operand instanceof SelectQuery) {
			return new SubqueryExpression($operand);
		}

		if ($operand instanceof ValueExpressionInterface) {
			return $operand;
		}

		return new LiteralExpression($operand);
	}

	public static function normalizeExpression(ValueExpressionInterface|SelectQuery $expression): ValueExpressionInterface
	{
		if ($expression instanceof AliasedExpression) {
			throw new InvalidArgumentException('AliasedExpression cannot be used as a comparison operand.');
		}

		if ($expression instanceof SelectQuery) {
			return new SubqueryExpression($expression);
		}

		return $expression;
	}
}
