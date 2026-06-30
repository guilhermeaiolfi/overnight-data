<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

use LogicException;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Result\Parser\AbstractNode;

final class OwnedBranchPlan
{
	private ?QuerySourceInterface $source = null;

	/**
	 * @var array<string, true>
	 */
	private array $fieldMap = [];

	/**
	 * @var list<string>
	 */
	private array $fieldOrder = [];

	private ?AbstractNode $node = null;

	/**
	 * @param list<string> $path
	 */
	public function __construct(
		private readonly array $path,
		private readonly CollectionInterface $collection,
	) {
	}

	/**
	 * @return list<string>
	 */
	public function getPath(): array
	{
		return $this->path;
	}

	public function getCollection(): CollectionInterface
	{
		return $this->collection;
	}

	public function setSource(QuerySourceInterface $source): void
	{
		$this->source = $source;
	}

	public function getSource(): QuerySourceInterface
	{
		return $this->source ?? throw new LogicException('Owned branch-plan source is not configured.');
	}

	public function requireFields(array $fieldNames): array
	{
		$added = [];

		foreach ($fieldNames as $fieldName) {
			if (isset($this->fieldMap[$fieldName])) {
				$added[] = $fieldName;

				continue;
			}

			$this->fieldMap[$fieldName] = true;
			$this->fieldOrder[] = $fieldName;
			$added[] = $fieldName;
		}

		return $added;
	}

	/**
	 * @return list<string>
	 */
	public function getNodeColumns(): array
	{
		return $this->fieldOrder;
	}

	public function setNode(AbstractNode $node): void
	{
		$this->node = $node;
	}

	public function getNode(): AbstractNode
	{
		return $this->node ?? throw new LogicException('Owned branch-plan parser node is not configured.');
	}
}
