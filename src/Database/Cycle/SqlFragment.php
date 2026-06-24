<?php

declare(strict_types=1);

namespace ON\Data\Database\Cycle;

use Cycle\Database\Injection\Fragment;
use Cycle\Database\Injection\ParameterInterface;

/**
 * @internal
 */
final class SqlFragment
{
	/**
	 * @param list<ParameterInterface> $parameters
	 */
	public function __construct(
		private readonly string $sql,
		private readonly array $parameters = [],
	) {
	}

	public static function raw(string $sql): self
	{
		return new self($sql);
	}

	/**
	 * @param list<ParameterInterface> $parameters
	 */
	public static function withParameters(string $sql, array $parameters): self
	{
		return new self($sql, $parameters);
	}

	public function sql(): string
	{
		return $this->sql;
	}

	/**
	 * @return list<ParameterInterface>
	 */
	public function parameters(): array
	{
		return $this->parameters;
	}

	public function toCycleFragment(): Fragment
	{
		return new Fragment($this->sql, ...$this->parameters);
	}
}
