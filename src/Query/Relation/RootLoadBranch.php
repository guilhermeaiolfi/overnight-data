<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

use LogicException;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Query\Result\Parser\RootNode;
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

	public function createNode(): RootNode
	{
		$identities = $this->getCollection()->getPrimaryKey();
		$node = new RootNode($this->getRemapColumns(), $identities);
		$this->setNode($node);

		return $node;
	}

	public function getRootNode(): RootNode
	{
		$node = $this->getNode();

		if (! $node instanceof RootNode) {
			throw new LogicException('Load branch parser node is not a root node.');
		}

		return $node;
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 */
	public function parseRows(array $rows): void
	{
		$node = $this->getRootNode();

		foreach ($rows as $row) {
			$node->parseRow($row);
		}
	}
}
