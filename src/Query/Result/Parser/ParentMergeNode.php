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
class ParentMergeNode extends AbstractMergeNode
{
	public function mergeInheritanceNodes(bool $includeDiscriminator = false): void
	{
		parent::mergeInheritanceNodes(false);
	}
}
