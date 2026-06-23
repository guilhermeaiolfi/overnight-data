<?php

declare(strict_types=1);

namespace ON\Data\Query\Expression;

final class LiteralExpression extends AbstractValueExpression
{
	public function __construct(
		private readonly mixed $value,
	) {
	}

	public function getValue(): mixed
	{
		return $this->value;
	}
}
