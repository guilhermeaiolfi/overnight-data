<?php

declare(strict_types=1);

namespace ON\Data\Database;

use ON\Data\Definition\DefinitionInterface;
use ON\Data\Query\SelectQuery;

final class Database
{
	public function __construct(
		private readonly QueryExecutorInterface $executor,
	) {
	}

	public function query(DefinitionInterface $source): SelectQuery
	{
		return new SelectQuery($source, $this->executor);
	}
}
