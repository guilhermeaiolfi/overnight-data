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
final class EmbeddedNode extends AbstractNode
{
	protected function push(array &$data): void
	{
		if ($this->parent === null) {
			throw new ParserException('Unable to register embedded data because the parent node is missing.');
		}

		$this->parent->mount(
			$this->container ?? throw new ParserException('Unable to mount embedded data because its container name is undefined.'),
			$this->getParentReferenceIndex(),
			self::LAST_REFERENCE,
			$data,
		);
	}
}
