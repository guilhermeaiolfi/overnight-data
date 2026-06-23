<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Resolver;

use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\MappingRuntime;
use ON\Data\Mapper\Resolution\BranchNodeResolution;
use ON\Data\Mapper\Resolution\BranchNodeResolutionInterface;
use ON\Data\Mapper\Support\BranchTargetInferrer;

final class GenericNodeResolver implements NodeResolverInterface
{
	private ?BranchTargetInferrer $runtimeInferrer = null;

	public function __construct(
		private readonly ?BranchTargetInferrer $inferrer = null,
	) {
	}

	public function resolve(
		MappingNode $node,
		MappingRuntime $runtime,
	): ?BranchNodeResolutionInterface {
		if (! BranchTargetInferrer::isStructuralValue($node->getValue())) {
			return null;
		}

		$inferrer = $this->getInferrer($runtime);

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

	private function getInferrer(
		MappingRuntime $runtime,
	): BranchTargetInferrer {
		return $this->inferrer
			?? (
				$this->runtimeInferrer
				??= $runtime->getSharedInstance(
					BranchTargetInferrer::class,
				)
			);
	}
}
