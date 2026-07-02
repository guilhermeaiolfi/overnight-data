<?php

declare(strict_types=1);

namespace ON\Data\Query;

use ON\Data\Query\Expression\WindowFunction;
use ON\Data\Query\Expression\WindowFunctionExpression;

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
}
