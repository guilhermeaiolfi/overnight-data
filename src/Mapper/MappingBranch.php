<?php

declare(strict_types=1);

namespace ON\Data\Mapper;

use ON\Data\Mapper\Resolution\BranchNodeResolutionInterface;
use ON\Data\Mapper\Resolution\LeafNodeResolution;
use ON\Data\Mapper\Resolution\LeafNodeResolutionInterface;
use ON\Data\Mapper\Resolution\ResolutionNodeInterface;
use ON\Data\Mapper\Resolver\CacheableNodeResolverInterface;
use ON\Data\Mapper\Resolver\NodeResolverInterface;
use ON\Data\Mapper\Writer\WriterInterface;
use ON\Data\Mapper\Writer\WriterStateInterface;

final class MappingBranch
{
	private readonly ?string $resolutionShape;

	private readonly WriterStateInterface $state;

	/**
	 * @param list<NodeResolverInterface> $resolvers
	 */
	public function __construct(
		private readonly MappingRuntime $runtime,
		private readonly MappingNode $node,
		private readonly WriterInterface $writer,
		private readonly array $resolvers,
		private readonly bool $conversionEnabled,
		private readonly ?NodeResolutionCache $resolutionCache = null,
	) {
		$this->state = $writer->createState($node);
		$this->resolutionShape = $this->resolutionCache?->createShapeKey(
			source: $this->node->getValue(),
			target: $this->node->getTarget(),
		);
	}

	public function getRuntime(): MappingRuntime
	{
		return $this->runtime;
	}

	public function getNode(): MappingNode
	{
		return $this->node;
	}

	public function getOptions(): MappingOptions
	{
		return $this->node->getOptions();
	}

	public function getSource(): mixed
	{
		return $this->node->getValue();
	}

	public function write(string|int $name, mixed $value): void
	{
		$cached = $this->resolutionShape !== null
			? $this->resolutionCache?->find($this->resolutionShape, $name)
			: null;

		if ($cached instanceof LeafNodeResolutionInterface) {
			$child = $this->node->createChildNode(name: $name, value: $value);
			$this->writeResolved($child, $value, $cached);

			return;
		}

		$child = $this->node->createChildNode(name: $name, value: $value);

		if ($cached === false || $this->resolutionShape === null) {
			$resolution = $this->resolveNode($child);
		} else {
			[$resolution, $cacheable] = $this->resolveNodeForCache($child);

			if ($cacheable && $resolution instanceof LeafNodeResolutionInterface) {
				$this->resolutionCache?->remember($this->resolutionShape, $name, $resolution);
			} else {
				$this->resolutionCache?->markDynamic($this->resolutionShape, $name);
			}
		}

		$this->writeResolved($child, $value, $resolution);
	}

	public function getResult(): mixed
	{
		return $this->writer->getResult($this->state, $this->node);
	}

	private function resolveNode(
		MappingNode $node,
	): LeafNodeResolutionInterface|BranchNodeResolutionInterface {
		foreach ($this->resolvers as $resolver) {
			$resolution = $resolver->resolve(node: $node, runtime: $this->runtime);

			if ($resolution !== null) {
				return $resolution;
			}
		}

		return LeafNodeResolution::passthrough((string) $node->getName());
	}

	/**
	 * @return array{
	 *     0: LeafNodeResolutionInterface|BranchNodeResolutionInterface,
	 *     1: bool
	 * }
	 */
	private function resolveNodeForCache(MappingNode $node): array
	{
		$cacheable = true;

		foreach ($this->resolvers as $resolver) {
			$resolution = $resolver->resolve(node: $node, runtime: $this->runtime);

			if ($cacheable) {
				$cacheable = $resolver instanceof CacheableNodeResolverInterface
					&& $resolver->isResolutionCacheable(
						node: $node,
						resolution: $resolution,
						runtime: $this->runtime,
					);
			}

			if ($resolution !== null) {
				return [$resolution, $cacheable];
			}
		}

		return [LeafNodeResolution::passthrough((string) $node->getName()), $cacheable];
	}

	private function writeResolved(
		MappingNode $child,
		mixed $value,
		ResolutionNodeInterface $resolution,
	): void {
		$this->writer->write(
			state: $this->state,
			name: $resolution->getName(),
			value: $this->resolveMappedValue($child, $value, $resolution),
			node: $child,
		);
	}

	private function resolveMappedValue(
		MappingNode $child,
		mixed $value,
		ResolutionNodeInterface $resolution,
	): mixed {
		if ($resolution instanceof BranchNodeResolutionInterface) {
			return $value === null
				? null
				: $this->runtime->mapNode(
					$child->forMapping(
						target: $resolution->getTarget(),
						arguments: $resolution->getArguments(),
						collection: $resolution->isCollection(),
					),
				);
		}

		return $this->conversionEnabled
			? $this->runtime->convert(value: $value, leaf: $resolution, node: $child)
			: $value;
	}
}
