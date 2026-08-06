<?php

declare(strict_types=1);

namespace ON\Data\Query\Load;

use InvalidArgumentException;

/**
 * Fetch plan: collections/columns keyed by relation source path.
 *
 * Built from {@see SelectQuery} selections. Does not describe representation
 * placement — see {@see RepresentationSchema} / proposal 0003.
 */
final class LoadGraph
{
	/** @var array<string, LoadGraphNode> */
	private array $nodes = [];

	public function add(LoadGraphNode $node): void
	{
		$key = $node->getPathKey();

		if (isset($this->nodes[$key])) {
			throw new InvalidArgumentException(sprintf(
				'LoadGraph already has a node for source path "%s".',
				$key === '' ? '(root)' : $key,
			));
		}

		$this->nodes[$key] = $node;
	}

	public function has(array $path): bool
	{
		return isset($this->nodes[$this->pathKey($path)]);
	}

	public function get(array $path): ?LoadGraphNode
	{
		return $this->nodes[$this->pathKey($path)] ?? null;
	}

	/**
	 * @return list<LoadGraphNode>
	 */
	public function getNodes(): array
	{
		return array_values($this->nodes);
	}

	/**
	 * @param list<string> $path
	 */
	private function pathKey(array $path): string
	{
		return implode('.', array_values($path));
	}
}
