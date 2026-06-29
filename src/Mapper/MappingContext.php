<?php

declare(strict_types=1);

namespace ON\Data\Mapper;

use ON\Data\Mapper\Attribute\Hidden;
use ON\Data\Mapper\Exception\MappingException;
use ON\Data\Mapper\Mapper\ArrayMapperOptions;
use ON\Data\Mapper\Resolution\BranchNodeResolutionInterface;
use ON\Data\Mapper\Resolution\LeafNodeResolution;
use ON\Data\Mapper\Resolution\LeafNodeResolutionInterface;
use ON\Data\Mapper\Resolution\ResolutionNodeInterface;
use ON\Data\Mapper\Resolver\CacheableNodeResolverInterface;
use ON\Data\Mapper\Resolver\NodeResolverInterface;
use ON\Data\Mapper\Support\ArrayPathExpander;
use ON\Data\Mapper\Writer\ObjectWriter;
use ON\Data\Mapper\Writer\WriterInterface;
use ReflectionObject;
use ReflectionProperty;
use stdClass;

final class MappingContext
{
	private readonly MappingNode $node;

	private mixed $result;

	private readonly ?string $resolutionShape;

	/**
	 * @var array<string, true>
	 */
	private array $preprocessedSourceNames = [];

	/**
	 * @param list<NodeResolverInterface> $resolvers
	 */
	public function __construct(
		private readonly MappingRuntime $runtime,
		MappingNode $node,
		private readonly WriterInterface $writer,
		private readonly array $resolvers,
		private readonly bool $conversionEnabled,
		private readonly ?NodeResolutionCache $resolutionCache = null,
		private readonly bool $enableConstructorHydration = false,
	) {
		[$this->result, $this->preprocessedSourceNames] = $this->bootstrapTarget($node);

		$this->node = $node->withTarget(
			$this->result,
		);

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

	public function write(
		string|int $name,
		mixed $value,
	): void {
		if (isset($this->preprocessedSourceNames[$this->sourceEntryKey($name)])) {
			return;
		}

		$cached = $this->resolutionShape !== null
			? $this->resolutionCache?->find(
				$this->resolutionShape,
				$name,
			)
			: null;

		if ($cached instanceof LeafNodeResolutionInterface) {
			$child = $this->node->createChildNode(
				name: $name,
				value: $value,
			);

			$this->writeResolved(
				child: $child,
				value: $value,
				resolution: $cached,
			);

			return;
		}

		$child = $this->node->createChildNode(
			name: $name,
			value: $value,
		);

		if ($cached === false || $this->resolutionShape === null) {
			$resolution = $this->resolveNode($child);
		} else {
			[$resolution, $cacheable] = $this->resolveNodeForCache($child);

			if ($cacheable && $resolution instanceof LeafNodeResolutionInterface) {
				$this->resolutionCache?->remember(
					$this->resolutionShape,
					$name,
					$resolution,
				);
			} else {
				$this->resolutionCache?->markDynamic(
					$this->resolutionShape,
					$name,
				);
			}
		}

		$this->writeResolved(
			child: $child,
			value: $value,
			resolution: $resolution,
		);
	}

	public function getResult(): mixed
	{
		return $this->result;
	}

	/**
	 * @return array{0: mixed, 1: array<string, true>}
	 */
	private function bootstrapTarget(MappingNode $node): array
	{
		if (! $this->enableConstructorHydration || ! $this->writer instanceof ObjectWriter) {
			return [$this->writer->createTarget($node), []];
		}

		if (! $this->writer->shouldUseConstructorHydration($node)) {
			return [$this->writer->createTarget($node), []];
		}

		$resolvedEntries = [];
		foreach ($this->sourceEntries($node) as [$name, $value]) {
			$child = $node->createChildNode($name, $value);
			$resolution = $this->resolveNode($child);

			$resolvedEntries[] = [
				'sourceName' => $name,
				'name' => $resolution->getName(),
				'value' => $this->resolveMappedValue($child, $value, $resolution),
			];
		}

		$prepared = $this->writer->createTargetUsingConstructor(
			node: $node,
			resolvedEntries: $resolvedEntries,
		);

		$result = $prepared['target'];
		$hydratedNode = $node->withTarget($result);
		$handled = [];

		foreach ($resolvedEntries as $entry) {
			$handled[$this->sourceEntryKey($entry['sourceName'])] = true;

			if (isset($prepared['consumed'][$entry['name']])) {
				continue;
			}

			$child = $hydratedNode->createChildNode($entry['sourceName'], $entry['value']);
			$result = $this->writer->write(
				target: $result,
				name: $entry['name'],
				value: $entry['value'],
				node: $child,
			);
		}

		return [$result, $handled];
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

	/**
	 * @return array{
	 *     0: LeafNodeResolutionInterface|BranchNodeResolutionInterface,
	 *     1: bool
	 * }
	 */
	private function resolveNodeForCache(
		MappingNode $node,
	): array {
		$cacheable = true;

		foreach ($this->resolvers as $resolver) {
			$resolution = $resolver->resolve(
				node: $node,
				runtime: $this->runtime,
			);

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
		$mappedValue = $this->resolveMappedValue($child, $value, $resolution);

		$this->result = $this->writer->write(
			target: $this->result,
			name: $resolution->getName(),
			value: $mappedValue,
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
			? $this->runtime->convert(
				value: $value,
				leaf: $resolution,
				node: $child,
			)
			: $value;
	}

	/**
	 * @return iterable<array{0: string|int, 1: mixed}>
	 */
	private function sourceEntries(MappingNode $node): iterable
	{
		$source = $node->getValue();
		if (is_array($source)) {
			$source = $this->shouldExpandDottedKeys($node)
				? (new ArrayPathExpander())->expand($source)
				: $source;

			foreach ($source as $name => $value) {
				yield [$name, $value];
			}

			return;
		}

		if (! is_object($source)) {
			return;
		}

		if ($source instanceof stdClass) {
			foreach (get_object_vars($source) as $name => $value) {
				yield [$name, $value];
			}

			return;
		}

		$reflection = new ReflectionObject($source);
		foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
			if (
				$property->isStatic()
				|| ! $property->isInitialized($source)
				|| $property->getAttributes(Hidden::class) !== []
			) {
				continue;
			}

			yield [$property->getName(), $property->getValue($source)];
		}
	}

	private function shouldExpandDottedKeys(MappingNode $node): bool
	{
		$options = [];

		foreach ($node->getArguments() as $argument) {
			if ($argument instanceof ArrayMapperOptions) {
				$options[] = $argument;
			}
		}

		if ($options === []) {
			return true;
		}

		if (count($options) > 1) {
			throw new MappingException('ArrayMapperOptions is ambiguous: mapping arguments contain multiple direct options.');
		}

		return $options[0]->getExpandDottedKeys();
	}

	private function sourceEntryKey(string|int $name): string
	{
		return (is_int($name) ? 'i:' : 's:') . (string) $name;
	}
}
