<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use ON\Data\Definition\Registry;
use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\MappingRuntime;
use ON\Data\Mapper\Resolution\BranchNodeResolutionInterface;
use ON\Data\Mapper\Resolution\LeafNodeResolutionInterface;
use ON\Data\Mapper\Resolver\DefinitionNodeResolver;
use ON\Data\Mapper\Resolver\GenericNodeResolver;
use ON\Data\Mapper\Resolver\NodeResolverInterface;
use ON\Data\Mapper\Resolver\ReflectionPropertyNodeResolver;
use ON\Data\Mapper\Support\BranchTargetInferrer;
use ON\Data\Mapper\Support\DefinitionArgumentLocator;
use ON\Data\Mapper\Support\MappingNodePropertyFinder;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Tests\ON\Data\Fixture\PropertyContextFixture;

final class MappingRuntimeSharedInstanceTest extends TestCase
{
	protected function setUp(): void
	{
		SharedInstanceProbeService::reset();
		RuntimeSharedServiceResolver::$serviceIds = [];
	}

	public function testSameRuntimeReturnsSameSharedInstance(): void
	{
		$runtime = $this->runtime();

		$first = $runtime->getSharedInstance(SharedInstanceProbeService::class);
		$second = $runtime->getSharedInstance(SharedInstanceProbeService::class);

		self::assertSame($first, $second);
	}

	public function testDifferentSharedClassesReceiveDistinctInstances(): void
	{
		$runtime = $this->runtime();

		self::assertNotSame(
			$runtime->getSharedInstance(SharedInstanceProbeService::class),
			$runtime->getSharedInstance(SharedInstanceOtherService::class),
		);
	}

	public function testSharedInstancesAreConstructedLazilyOnlyOncePerOwningRuntime(): void
	{
		$runtime = $this->runtime();

		self::assertSame(0, SharedInstanceProbeService::$constructions);

		$runtime->getSharedInstance(SharedInstanceProbeService::class);
		$runtime->getSharedInstance(SharedInstanceProbeService::class);

		self::assertSame(1, SharedInstanceProbeService::$constructions);
	}

	public function testIndependentTopLevelRuntimesReceiveDifferentInstances(): void
	{
		$first = $this->runtime();
		$second = $this->runtime();

		self::assertNotSame(
			$first->getSharedInstance(SharedInstanceProbeService::class),
			$second->getSharedInstance(SharedInstanceProbeService::class),
		);
	}

	public function testDirectCollectionItemRuntimeDelegatesSharedLookupToParentRuntime(): void
	{
		$collectionRuntime = $this->runtime(source: [['id' => 1]], target: [], collection: true);
		$itemNode = $collectionRuntime->getMappingNode()
			->createChildNode('0', ['id' => 1])
			->forMapping(target: [], arguments: [], collection: false, preserveComponentOverrides: true);
		$itemRuntime = $this->runtimeForNode($itemNode, $collectionRuntime);

		$parentInstance = $collectionRuntime->getSharedInstance(SharedInstanceProbeService::class);
		$itemInstance = $itemRuntime->getSharedInstance(SharedInstanceProbeService::class);

		self::assertSame($parentInstance, $itemInstance);
		self::assertSame([], $this->sharedInstances($itemRuntime));
	}

	public function testRecursiveBranchRuntimeGetsIndependentSharedInstanceStore(): void
	{
		$rootRuntime = $this->runtime(source: ['child' => ['id' => 1]], target: []);
		$branchNode = $rootRuntime->getMappingNode()
			->createChildNode('child', ['id' => 1])
			->forMapping(target: [], arguments: [], collection: false);
		$branchRuntime = $this->runtimeForNode($branchNode);

		self::assertNotSame(
			$rootRuntime->getSharedInstance(SharedInstanceProbeService::class),
			$branchRuntime->getSharedInstance(SharedInstanceProbeService::class),
		);
	}

	public function testNestedCollectionItemsShareTheirNestedCollectionRuntimeStore(): void
	{
		$nestedCollectionNode = MappingNode::root(
			source: [['id' => 1], ['id' => 2]],
			target: [],
			context: $this->context(collection: true),
		);
		$nestedCollectionRuntime = $this->runtimeForNode($nestedCollectionNode);
		$itemNode = $nestedCollectionNode
			->createChildNode('0', ['id' => 1])
			->forMapping(target: [], arguments: [], collection: false, preserveComponentOverrides: true);
		$itemRuntime = $this->runtimeForNode($itemNode, $nestedCollectionRuntime);

		self::assertSame(
			$nestedCollectionRuntime->getSharedInstance(SharedInstanceProbeService::class),
			$itemRuntime->getSharedInstance(SharedInstanceProbeService::class),
		);
	}

	public function testEmptyCollectionDoesNotConstructUnusedSharedInstances(): void
	{
		$runtime = $this->runtime(source: [], target: [], collection: true);

		self::assertSame([], $runtime->map());
		self::assertSame(0, SharedInstanceProbeService::$constructions);
		self::assertSame([], $this->sharedInstances($runtime));
	}

	public function testCustomZeroArgumentResolverCanUseRuntimeSharedService(): void
	{
		$resolver = new RuntimeSharedServiceResolver();
		$runtime = $this->runtime();
		$node = $runtime->getMappingNode()->createChildNode('name', 'Ada');

		self::assertNull($resolver->resolve($node, $runtime));
		self::assertCount(1, RuntimeSharedServiceResolver::$serviceIds);
		self::assertArrayHasKey(
			SharedInstanceProbeService::class,
			$this->sharedInstances($runtime),
		);
	}

	public function testInjectedBranchTargetInferrerOverridesRuntimeDefault(): void
	{
		$resolver = new GenericNodeResolver(new BranchTargetInferrer());
		$runtime = $this->runtime(source: ['child' => ['id' => 1]], target: []);
		$node = $runtime->getMappingNode()->createChildNode('child', ['id' => 1]);

		self::assertInstanceOf(BranchNodeResolutionInterface::class, $resolver->resolve($node, $runtime));
		self::assertArrayNotHasKey(
			BranchTargetInferrer::class,
			$this->sharedInstances($runtime),
		);
	}

	public function testInjectedDefinitionArgumentLocatorOverridesRuntimeDefault(): void
	{
		$registry = new Registry();
		$definition = $registry->collection('users');
		$definition->field('id', 'int');
		$resolver = new DefinitionNodeResolver(new DefinitionArgumentLocator());
		$node = $this->node('id', '42', [$definition]);
		$runtime = $this->runtimeForNode($this->rootNode(arguments: [$definition]));

		self::assertSame('int', $resolver->resolve($node, $runtime)?->getType());
		self::assertArrayNotHasKey(
			DefinitionArgumentLocator::class,
			$this->sharedInstances($runtime),
		);
	}

	public function testScalarReflectionFieldsDoNotConstructBranchTargetInferrer(): void
	{
		$resolver = new ReflectionPropertyNodeResolver();
		$node = $this->propertyNode('name', 'Ada');
		$runtime = $this->runtimeForNode($this->rootNode(source: $node->getParentSource(), target: [], context: $node->getContext()));

		self::assertInstanceOf(LeafNodeResolutionInterface::class, $resolver->resolve($node, $runtime));
		self::assertArrayHasKey(
			MappingNodePropertyFinder::class,
			$this->sharedInstances($runtime),
		);
		self::assertArrayNotHasKey(
			BranchTargetInferrer::class,
			$this->sharedInstances($runtime),
		);
	}

	public function testReflectionResolverDoesNotCacheNegativeResultsForValueSensitiveBranchPaths(): void
	{
		$resolver = new ReflectionPropertyNodeResolver();
		$nullNode = $this->propertyNode('profile', 'not-structural');
		$branchNode = $this->propertyNode('profile', ['name' => 'Ada']);
		$runtime = $this->runtimeForNode(
			$this->rootNode(
				source: $nullNode->getParentSource(),
				target: [],
				context: $nullNode->getContext(),
			),
		);

		self::assertNull($resolver->resolve($nullNode, $runtime));
		self::assertInstanceOf(
			BranchNodeResolutionInterface::class,
			$resolver->resolve($branchNode, $runtime),
		);
	}

	public function testDefinitionFieldsDoNotConstructBranchTargetInferrer(): void
	{
		$registry = new Registry();
		$definition = $registry->collection('users');
		$definition->field('id', 'int');
		$resolver = new DefinitionNodeResolver();
		$node = $this->node('id', '42', [$definition]);
		$runtime = $this->runtimeForNode($this->rootNode(arguments: [$definition]));

		self::assertSame('int', $resolver->resolve($node, $runtime)?->getType());
		self::assertArrayHasKey(
			DefinitionArgumentLocator::class,
			$this->sharedInstances($runtime),
		);
		self::assertArrayNotHasKey(
			BranchTargetInferrer::class,
			$this->sharedInstances($runtime),
		);
	}

	public function testDefinitionRelationsConstructSharedInferrerAndFinder(): void
	{
		$registry = new Registry();
		$definition = $registry->collection('users');
		$definition->hasMany('children', 'users');
		$resolver = new DefinitionNodeResolver();
		$runtime = $this->runtime(source: ['children' => [['id' => 1]]], target: [], arguments: [$definition]);
		$node = $runtime->getMappingNode()->createChildNode('children', [['id' => 1]]);

		self::assertInstanceOf(BranchNodeResolutionInterface::class, $resolver->resolve($node, $runtime));
		self::assertArrayHasKey(
			BranchTargetInferrer::class,
			$this->sharedInstances($runtime),
		);
		self::assertArrayHasKey(
			MappingNodePropertyFinder::class,
			$this->sharedInstances($runtime),
		);
	}

	public function testGenericStructuralResolutionReusesOneInferrerAcrossMultipleNodes(): void
	{
		$resolver = new GenericNodeResolver();
		$runtime = $this->runtime(source: ['first' => ['id' => 1], 'second' => ['id' => 2]], target: []);
		$firstNode = $runtime->getMappingNode()->createChildNode('first', ['id' => 1]);
		$secondNode = $runtime->getMappingNode()->createChildNode('second', ['id' => 2]);

		self::assertInstanceOf(BranchNodeResolutionInterface::class, $resolver->resolve($firstNode, $runtime));
		$sharedInferrer = $this->sharedInstances($runtime)[BranchTargetInferrer::class] ?? null;
		self::assertInstanceOf(BranchTargetInferrer::class, $sharedInferrer);
		self::assertInstanceOf(BranchNodeResolutionInterface::class, $resolver->resolve($secondNode, $runtime));
		self::assertSame(
			$sharedInferrer,
			$this->sharedInstances($runtime)[BranchTargetInferrer::class] ?? null,
		);
	}

	public function testReflectionResolverAndDefaultBranchInferenceShareRuntimePropertyFinder(): void
	{
		$resolver = new ReflectionPropertyNodeResolver();
		$node = $this->propertyNode('profile', ['name' => 'Ada']);
		$runtime = $this->runtimeForNode($this->rootNode(source: $node->getParentSource(), target: [], context: $node->getContext()));

		self::assertInstanceOf(BranchNodeResolutionInterface::class, $resolver->resolve($node, $runtime));

		$sharedInstances = $this->sharedInstances($runtime);
		self::assertArrayHasKey(MappingNodePropertyFinder::class, $sharedInstances);
		self::assertArrayHasKey(BranchTargetInferrer::class, $sharedInstances);
		self::assertCount(2, $sharedInstances);
	}

	private function runtime(
		mixed $source = [],
		mixed $target = [],
		array $arguments = [],
		bool $collection = false,
	): MappingRuntime {
		return $this->runtimeForNode(
			$this->rootNode(
				source: $source,
				target: $target,
				arguments: $arguments,
				context: $this->context(arguments: $arguments, collection: $collection),
			),
		);
	}

	private function runtimeForNode(
		MappingNode $node,
		?MappingRuntime $parent = null,
	): MappingRuntime {
		return new MappingRuntime(
			mapperManager: $node->getContext()->getGateway()->getMapperManager(),
			mappingNode: $node,
			parentMappingRuntime: $parent,
		);
	}

	private function rootNode(
		mixed $source = [],
		mixed $target = [],
		array $arguments = [],
		?MappingContext $context = null,
	): MappingNode {
		return MappingNode::root(
			source: $source,
			target: $target,
			context: $context ?? $this->context(arguments: $arguments),
		);
	}

	private function node(
		string $name,
		mixed $value,
		array $arguments = [],
	): MappingNode {
		return $this->rootNode(arguments: $arguments)
			->createChildNode($name, $value);
	}

	private function propertyNode(string $name, mixed $value): MappingNode
	{
		return MappingNode::root(
			source: (new ReflectionClass(PropertyContextFixture::class))
				->newInstanceWithoutConstructor(),
			target: [],
			context: $this->context(),
		)->createChildNode($name, $value);
	}

	private function context(array $arguments = [], bool $collection = false): MappingContext
	{
		$context = new MappingContext(ConversionGateway::createDefault());
		$context = $context->withArguments($arguments);

		return $collection ? $context->asCollection() : $context;
	}

	/**
	 * @return array<class-string, object>
	 */
	private function sharedInstances(MappingRuntime $runtime): array
	{
		return $this->sharedInstancesProperty()->getValue($runtime);
	}

	private function sharedInstancesProperty(): ReflectionProperty
	{
		return new ReflectionProperty(MappingRuntime::class, 'sharedInstances');
	}
}

final class SharedInstanceProbeService
{
	public static int $constructions = 0;

	public function __construct()
	{
		self::$constructions++;
	}

	public static function reset(): void
	{
		self::$constructions = 0;
	}
}

final class SharedInstanceOtherService
{
}

final class RuntimeSharedServiceResolver implements NodeResolverInterface
{
	/**
	 * @var list<int>
	 */
	public static array $serviceIds = [];

	public function resolve(
		MappingNode $node,
		MappingRuntime $runtime,
	): LeafNodeResolutionInterface|BranchNodeResolutionInterface|null {
		self::$serviceIds[] = spl_object_id(
			$runtime->getSharedInstance(SharedInstanceProbeService::class),
		);

		return null;
	}
}
