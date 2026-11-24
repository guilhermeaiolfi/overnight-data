<?php

declare(strict_types=1);

namespace ON\Data\Database\Sql;

/** @internal */
final readonly class SqlFragment
{
	/** @param list<mixed> $parameters */
	private function __construct(
		private string $sql,
		private array $parameters,
	) {
	}

	public static function raw(string $sql): self
	{
		return new self($sql, []);
	}

	/** @param list<mixed> $parameters */
	public static function withParameters(string $sql, array $parameters): self
	{
		return new self($sql, $parameters);
	}

	/** @param list<self> $parts */
	public static function join(string $separator, array $parts): self
	{
		$sql = [];
		$params = [];
		foreach ($parts as $part) {
			$sql[] = $part->sql;
			array_push($params, ...$part->parameters);
		}

		return new self(implode($separator, $sql), $params);
	}

	public function sql(): string
	{
		return $this->sql;
	}

	/** @return list<mixed> */
	public function parameters(): array
	{
		return $this->parameters;
	}
}
