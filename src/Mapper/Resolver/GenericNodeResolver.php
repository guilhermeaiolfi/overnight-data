<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Resolver;

use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\MappingRuntime;
use ON\Data\Mapper\Resolution\BranchNodeResolution;
use ON\Data\Mapper\Resolution\BranchNodeResolutionInterface;
use ON\Data\Mapper\Support\BranchTargetInferrer;
use stdClass;

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
		$value = $node->getValue();
		if (! BranchTargetInferrer::isStructuralValue($value)) {
			return null;
		}

		$inferrer = $this->getInferrer($runtime);

		$target = $inferrer->inferGenericTarget($node->getParentTarget());
		if ($target === null) {
			return null;
		}

		// Keep PHP list arrays as arrays under stdClass. Scalar lists pass through as
		// leaves; lists of structural items map as a collection of stdClass objects.
		if ($target === stdClass::class && is_array($value) && array_is_list($value)) {
			if ($value === [] || ! $this->isStructuralList($value)) {
				return null;
			}

			return BranchNodeResolution::named(
				name: (string) $node->getName(),
				target: $target,
				arguments: $node->getArguments(),
				collection: true,
			);
		}

		return BranchNodeResolution::named(
			name: (string) $node->getName(),
			target: $target,
			arguments: $node->getArguments(),
		);
	}

	/**
	 * @param list<mixed> $value
	 */
	private function isStructuralList(array $value): bool
	{
		foreach ($value as $item) {
			if (! BranchTargetInferrer::isStructuralValue($item)) {
				return false;
			}
		}

		return true;
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
