<?php

declare(strict_types=1);

namespace ON\Data\Query\Result\Parser;

/**
 * Adapted from Cycle ORM parser code.
 *
 * Upstream commit:
 * a7a1db351df8037ff7a1196e19688bfc7d35c63e
 *
 * Original source licensed under the MIT License.
 */
abstract class OutputNode extends AbstractNode
{
	/**
	 * @var list<array<string, mixed>>
	 */
	protected array $result = [];

	/**
	 * @return list<array<string, mixed>>
	 */
	public function getResult(): array
	{
		return $this->result;
	}

	public function __destruct()
	{
		$this->result = [];

		parent::__destruct();
	}

	protected function push(array &$data): void
	{
		$this->result[] = &$data;
	}
}
