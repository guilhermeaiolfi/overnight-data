<?php

declare(strict_types=1);

namespace ON\Data\Database\Sql;

/** @internal */
final readonly class CompiledQuery extends SqlFragment
{
	/**
	 * @param list<mixed> $params
	 * @param list<SqlResultColumn> $columns
	 */
	public function __construct(string $sql, array $params, private array $columns)
	{
		parent::__construct($sql, $params);
	}

	/** @return list<SqlResultColumn> */
	public function columns(): array
	{
		return $this->columns;
	}
}
