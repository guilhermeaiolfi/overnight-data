<?php

declare(strict_types=1);

namespace ON\Data\Query\Expression;

final class RawSqlExpression extends AbstractValueExpression
{
	/**
	 * @param list<mixed> $parameters
	 */
	public function __construct(
		private readonly string $sql,
		private readonly array $parameters = [],
	) {
	}

	public function getSql(): string
	{
		return $this->sql;
	}

	/**
	 * @return list<mixed>
	 */
	public function getParameters(): array
	{
		return $this->parameters;
	}
}
