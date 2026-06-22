<?php

declare(strict_types=1);

namespace ON\Data\Mapper;

use ON\Data\Mapper\Exception\MappingException;
use ON\Data\Mapper\Resolution\BranchNodeResolutionInterface;
use ON\Data\Mapper\Resolution\LeafNodeResolution;
use ON\Data\Mapper\Resolution\LeafNodeResolutionInterface;
use ON\Data\Mapper\Resolver\NodeResolverInterface;
use ON\Data\Mapper\Writer\MappingWriter;
use ON\Data\Mapper\Writer\WriterInterface;

final class MappingRuntime
{
	private ?MappingWriter $mappingWriter = null;

	private ?WriterInterface $writer = null;

	/**
	 * @var list<NodeResolverInterface>|null
	 */
	private ?array $resolvers = null;

	private ?FieldConversionCoordinator $converter = null;

	public function __construct(
		private readonly MapperManager $mapperManager,
		private readonly MappingNode $mappingNode,
		private readonly ?self $parentMappingRuntime = null,
	) {
	}

	public function getMappingNode(): MappingNode
	{
		return $this->mappingNode;
	}

	public function getSource(): mixed
	{
		return $this->mappingNode->getValue();
	}

	public function getMapperManager(): MapperManager
	{
		return $this->mapperManager;
	}

	public function map(): mixed
	{
		$this->mappingNode->assertNoObjectCycle();

		if ($this->mappingNode->isCollection()) {
			return $this->mapCollection();
		}

		$mapper = $this->mapperManager->resolveMapper(
			source: $this->mappingNode->getValue(),
			context: $this->mappingNode->getContext(),
		);

		return $mapper->map($this);
	}

	public function mapNode(MappingNode $node): mixed
	{
		return (new self(
			mapperManager: $this->mapperManager,
			mappingNode: $node,
		))->map();
	}

	public function write(
		string|int $name,
		mixed $value,
	): void {
		$mappingWriter = $this->getMappingWriter();

		$child = $mappingWriter->createChildNode(
			name: $name,
			value: $value,
		);

		$resolution = $this->resolveNode($child);

		if ($resolution instanceof BranchNodeResolutionInterface) {
			$mappedValue = $value === null
				? null
				: $this->mapNode(
					$child->forMapping(
						target: $resolution->getTarget(),
						arguments: $resolution->getArguments(),
						collection: $resolution->isCollection(),
					),
				);
		} else {
			$mappedValue = $this->getConverter()->convert(
				value: $value,
				leaf: $resolution,
				node: $child,
			);
		}

		$mappingWriter->write(
			node: $child,
			name: $resolution->getName(),
			value: $mappedValue,
		);
	}

	public function getResult(): mixed
	{
		return $this->getMappingWriter()->getResult();
	}

	private function mapCollection(): array
	{
		$source = $this->mappingNode->getValue();

		if (! is_iterable($source)) {
			throw new MappingException('Collection mapping requires an iterable source.');
		}

		$results = [];

		foreach ($source as $key => $item) {
			$itemNode = $this->mappingNode
				->createChildNode(
					name: (string) $key,
					value: $item,
				)
				->forMapping(
					target: $this->mappingNode->getTarget(),
					arguments: $this->mappingNode->getArguments(),
					collection: false,
					preserveComponentOverrides: true,
				);

			$results[] = (new self(
				mapperManager: $this->mapperManager,
				mappingNode: $itemNode,
				parentMappingRuntime: $this,
			))->map();
		}

		return $results;
	}

	private function getMappingWriter(): MappingWriter
	{
		return $this->mappingWriter ??= new MappingWriter(
			mappingNode: $this->mappingNode,
			writer: $this->getWriter(),
		);
	}

	private function getWriter(): WriterInterface
	{
		if ($this->parentMappingRuntime !== null) {
			return $this->parentMappingRuntime->getWriter();
		}

		return $this->writer ??= $this->mapperManager->resolveWriter(
			target: $this->mappingNode->getTarget(),
			context: $this->mappingNode->getContext(),
		);
	}

	private function resolveNode(MappingNode $node): LeafNodeResolutionInterface|BranchNodeResolutionInterface
	{
		foreach ($this->getResolvers() as $resolver) {
			$resolution = $resolver->resolve($node);
			if ($resolution !== null) {
				return $resolution;
			}
		}

		return LeafNodeResolution::passthrough((string) $node->getName());
	}

	/**
	 * @return list<NodeResolverInterface>
	 */
	private function getResolvers(): array
	{
		if ($this->parentMappingRuntime !== null) {
			return $this->parentMappingRuntime->getResolvers();
		}

		return $this->resolvers ??= $this->mapperManager->createResolverChain(
			context: $this->mappingNode->getContext(),
		);
	}

	private function getConverter(): FieldConversionCoordinator
	{
		if ($this->parentMappingRuntime !== null) {
			return $this->parentMappingRuntime->getConverter();
		}

		return $this->converter ??= $this->mapperManager->createFieldConversionCoordinator();
	}
}
