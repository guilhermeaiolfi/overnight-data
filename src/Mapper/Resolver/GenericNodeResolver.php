<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Resolver;

use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\Resolution\BranchNodeResolution;
use ON\Data\Mapper\Resolution\BranchNodeResolutionInterface;
use ON\Data\Mapper\Support\BranchTargetInferrer;

final class GenericNodeResolver implements NodeResolverInterface
{
	public function __construct(
		private readonly ?BranchTargetInferrer $inferrer = null,
	) {
	}

	public function resolve(MappingNode $node): ?BranchNodeResolutionInterface
	{
		$inferrer = $this->inferrer ?? new BranchTargetInferrer();
		if (! $inferrer->isStructuralValue($node->getValue())) {
			return null;
		}

		$target = $inferrer->inferGenericTarget($node->getParentTarget());
		if ($target === null) {
			return null;
		}

		return BranchNodeResolution::named(
			name: (string) $node->getName(),
			target: $target,
			arguments: $node->getArguments(),
		);
	}
}
