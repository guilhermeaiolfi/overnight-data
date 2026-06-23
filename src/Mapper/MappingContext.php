<?php

declare(strict_types=1);

namespace ON\Data\Mapper;

use ON\Data\Mapper\Resolution\BranchNodeResolutionInterface;
use ON\Data\Mapper\Resolution\LeafNodeResolution;
use ON\Data\Mapper\Resolution\LeafNodeResolutionInterface;
use ON\Data\Mapper\Resolver\NodeResolverInterface;
use ON\Data\Mapper\Writer\WriterInterface;

final class MappingContext
{
	private readonly MappingNode $node;

	private mixed $result;

	/**
	 * @param list<NodeResolverInterface> $resolvers
	 */
	public function __construct(
		private readonly MappingRuntime $runtime,
		MappingNode $node,
		private readonly WriterInterface $writer,
		private readonly array $resolvers,
		private readonly bool $conversionEnabled,
	) {
		$this->result = $this->writer->createTarget(
			node: $node,
		);

		$this->node = $node->withTarget(
			$this->result,
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

	public function write(
		string|int $name,
		mixed $value,
	): void {
		$child = $this->node->createChildNode(
			name: $name,
			value: $value,
		);

		$resolution = $this->resolveNode($child);

		if ($resolution instanceof BranchNodeResolutionInterface) {
			$mappedValue = $value === null
				? null
				: $this->runtime->mapNode(
					$child->forMapping(
						target: $resolution->getTarget(),
						arguments: $resolution->getArguments(),
						collection: $resolution->isCollection(),
					),
				);
		} else {
			$mappedValue = $this->conversionEnabled
				? $this->runtime->convert(
					value: $value,
					leaf: $resolution,
					node: $child,
				)
				: $value;
		}

		$this->result = $this->writer->write(
			target: $this->result,
			name: $resolution->getName(),
			value: $mappedValue,
			node: $child,
		);
	}

	public function getResult(): mixed
	{
		return $this->result;
	}

	private function resolveNode(
		MappingNode $node,
	): LeafNodeResolutionInterface|BranchNodeResolutionInterface {
		foreach ($this->resolvers as $resolver) {
			$resolution = $resolver->resolve(
				node: $node,
				runtime: $this->runtime,
			);

			if ($resolution !== null) {
				return $resolution;
			}
		}

		return LeafNodeResolution::passthrough((string) $node->getName());
	}
}
