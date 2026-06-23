<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Resolver;

use BackedEnum;
use DateTimeInterface;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\MappingRuntime;
use ON\Data\Mapper\Representation\RepresentationInterface;
use ON\Data\Mapper\Resolution\BranchNodeResolution;
use ON\Data\Mapper\Resolution\BranchNodeResolutionInterface;
use ON\Data\Mapper\Support\BranchTargetInferrer;

final class GenericNodeResolver implements NodeResolverInterface
{
	public function __construct(
		private readonly ?BranchTargetInferrer $inferrer = null,
	) {
	}

	public function resolve(
		MappingNode $node,
		MappingRuntime $runtime,
	): ?BranchNodeResolutionInterface {
		if (! $this->mayBeStructuralValue($node->getValue())) {
			return null;
		}

		$inferrer = $this->inferrer
			?? $runtime->getSharedInstance(BranchTargetInferrer::class);

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

	private function mayBeStructuralValue(mixed $value): bool
	{
		if ($value === null) {
			return false;
		}

		if (is_array($value)) {
			return true;
		}

		return is_object($value)
			&& ! $value instanceof DateTimeInterface
			&& ! $value instanceof BackedEnum
			&& ! $value instanceof RepresentationInterface;
	}
}
