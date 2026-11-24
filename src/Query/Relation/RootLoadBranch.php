<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Query\SelectQuery;

final class RootLoadBranch extends LoadBranch
{
	public function __construct(
		private readonly SelectQuery $query,
	) {
		$this->setQueryContext($query, $query, null);
	}

	public function getCollection(): CollectionInterface
	{
		return $this->query->getCollection();
	}

	public function getProjectionLevel(): SelectQuery
	{
		return $this->query;
	}
}
