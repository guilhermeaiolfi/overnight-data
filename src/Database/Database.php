<?php

declare(strict_types=1);

namespace ON\Data\Database;

use ON\Data\Database\Cycle\CycleDatabaseFactory;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Query\SelectQuery;

final class Database
{
	public function __construct(
		private readonly QueryExecutorInterface $executor,
	) {
	}

	public static function connect(ConnectionConfig $config): self
	{
		return (new CycleDatabaseFactory())->create($config);
	}

	public function query(CollectionInterface|SelectQuery $source): SelectQuery
	{
		return new SelectQuery($source, $this->executor);
	}
}
