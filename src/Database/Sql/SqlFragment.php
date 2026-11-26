<?php

declare(strict_types=1);

namespace ON\Data\Database\Sql;

/** @internal */
readonly class SqlFragment
{
	/** @param list<mixed> $params */
	public function __construct(
		public string $sql,
		public array $params,
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
			array_push($params, ...$part->params);
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
		return $this->params;
	}
}
