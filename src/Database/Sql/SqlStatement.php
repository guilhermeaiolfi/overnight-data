<?php

declare(strict_types=1);

namespace ON\Data\Database\Sql;

/** @internal */
readonly class SqlStatement
{
	/** @param list<mixed> $params */
	public function __construct(
		public string $sql,
		public array $params,
	) {
	}
}
