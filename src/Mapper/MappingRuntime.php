<?php

declare(strict_types=1);

namespace ON\Data\Mapper;

use ON\Data\Mapper\Exception\MappingException;
use ON\Data\Mapper\Resolution\LeafNodeResolutionInterface;
use ON\Data\Mapper\Resolver\NodeResolverInterface;
use ON\Data\Mapper\Writer\WriterInterface;

final class MappingRuntime
{
	private ?FieldConversionCoordinator $converter = null;

	/**
	 * @var array<class-string, object>
	 */
	private array $sharedInstances = [];

	public function __construct(
		private readonly MapperManager $mapperManager,
	) {
	}

	public function getMapperManager(): MapperManager
	{
		return $this->mapperManager;
	}

	/**
	 * @template T of object
	 *
	 * @param class-string<T> $class
	 *
	 * @return T
	 */
	public function getSharedInstance(string $class): object
	{
		if (isset($this->sharedInstances[$class])) {
			/** @var T */
			return $this->sharedInstances[$class];
		}

		/** @var T $instance */
		$instance = new $class();
		$this->sharedInstances[$class] = $instance;

		return $instance;
	}

	public function mapNode(MappingNode $node): mixed
	{
		$node->assertNoObjectCycle();

		if ($node->isCollection()) {
			return $this->mapCollection($node);
		}

		return $this->mapSingle($node);
	}

	public function convert(
		mixed $value,
		LeafNodeResolutionInterface $leaf,
		MappingNode $node,
	): mixed {
		return $this->getConverter()->convert(
			value: $value,
			leaf: $leaf,
			node: $node,
		);
	}

	private function mapCollection(
		MappingNode $node,
	): array {
		$source = $node->getValue();

		if (! is_iterable($source)) {
			throw new MappingException('Collection mapping requires an iterable source.');
		}

		/** @var list<NodeResolverInterface>|null $resolvers */
		$resolvers = null;
		$writer = null;
		$conversionEnabled = null;
		$results = [];

		foreach ($source as $key => $item) {
			$itemNode = $node
				->createChildNode(
					name: (string) $key,
					value: $item,
				)
				->forMapping(
					target: $node->getTarget(),
					arguments: $node->getArguments(),
					collection: false,
					preserveComponentOverrides: true,
				);

			$options = $itemNode->getOptions();

			$writer ??= $this->mapperManager->resolveWriter(
				target: $itemNode->getTarget(),
				options: $options,
			);

			$resolvers ??= $this->mapperManager->createResolverChain(
				options: $options,
			);

			$conversionEnabled ??= $this->isConversionEnabled($options);

			$results[] = $this->mapSingle(
				node: $itemNode,
				writer: $writer,
				resolvers: $resolvers,
				conversionEnabled: $conversionEnabled,
			);
		}

		return $results;
	}

	/**
	 * @param list<NodeResolverInterface>|null $resolvers
	 */
	private function mapSingle(
		MappingNode $node,
		?WriterInterface $writer = null,
		?array $resolvers = null,
		?bool $conversionEnabled = null,
	): mixed {
		$node->assertNoObjectCycle();

		$options = $node->getOptions();
		$mapper = $this->mapperManager->resolveMapper(
			source: $node->getValue(),
			options: $options,
		);

		$context = new MappingContext(
			runtime: $this,
			node: $node,
			writer: $writer ?? $this->mapperManager->resolveWriter(
				target: $node->getTarget(),
				options: $options,
			),
			resolvers: $resolvers ?? $this->mapperManager->createResolverChain(
				options: $options,
			),
			conversionEnabled: $conversionEnabled ?? $this->isConversionEnabled($options),
		);

		return $mapper->map($context);
	}

	private function getConverter(): FieldConversionCoordinator
	{
		return $this->converter ??= $this->mapperManager->createFieldConversionCoordinator();
	}

	private function isConversionEnabled(
		MappingOptions $options,
	): bool {
		return $options->getSourceRepresentation() !== null
			|| $options->getOutputRepresentation() !== null;
	}
}
