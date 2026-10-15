<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use ON\Data\Definition\Registry;
use ON\Data\Mapper\ConversionGateway;
use function ON\Data\Mapper\map;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\MappingOptions;
use ON\Data\Mapper\MappingRuntime;
use ON\Data\Mapper\Resolution\BranchNodeResolution;
use ON\Data\Mapper\Resolution\BranchNodeResolutionInterface;
use ON\Data\Mapper\Resolution\LeafNodeResolutionInterface;
use ON\Data\Mapper\Resolver\DefinitionNodeResolver;
use ON\Data\Mapper\Resolver\GenericNodeResolver;
use ON\Data\Mapper\Resolver\NodeResolverInterface;
use ON\Data\Mapper\Resolver\ReflectionPropertyNodeResolver;
use ON\Data\Mapper\Support\BranchTargetInferrer;
use ON\Data\Mapper\Support\DefinitionArgumentLocator;
use ON\Data\Mapper\Support\MappingNodePropertyFinder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeakReference;

final class MappingRuntimeSharedInstanceTest extends TestCase
{
	protected function setUp(): void
	{
		SharedInstanceProbeService::reset();
		RuntimeSharedServiceResolver::reset();
	}

	public function testSameRuntimeReturnsSameSharedInstance(): void
	{
		$runtime = new MappingRuntime(ConversionGateway::createDefault()->getMapperManager());

		$first = $runtime->getSharedInstance(SharedInstanceProbeService::class);
		$second = $runtime->getSharedInstance(SharedInstanceProbeService::class);

		self::assertSame($first, $second);
		self::assertSame(1, SharedInstanceProbeService::$constructions);
	}

	public function testDifferentSharedClassesReceiveDistinctInstances(): void
	{
		$runtime = new MappingRuntime(ConversionGateway::createDefault()->getMapperManager());

		self::assertNotSame(
			$runtime->getSharedInstance(SharedInstanceProbeService::class),
			$runtime->getSharedInstance(SharedInstanceOtherService::class),
		);
	}

	public function testSeparateTopLevelRuntimeObjectsReceiveDifferentInstances(): void
	{
		$gateway = ConversionGateway::createDefault();
		$first = new MappingRuntime($gateway->getMapperManager());
		$second = new MappingRuntime($gateway->getMapperManager());

		self::assertNotSame(
			$first->getSharedInstance(SharedInstanceProbeService::class),
			$second->getSharedInstance(SharedInstanceProbeService::class),
		);
	}

	public function testCollectionSiblingsShareOneOperationRuntimeInstance(): void
	{
		$gateway = ConversionGateway::createDefault();

		map([['id' => 1], ['id' => 2]], null, $gateway)
			->collection()
			->resolver(RuntimeSharedServiceResolver::class)
			->to([]);

		self::assertSame(['0.id', '1.id'], RuntimeSharedServiceResolver::$paths);
		self::assertCount(1, array_unique(RuntimeSharedServiceResolver::$runtimeIds));
		self::assertCount(1, array_unique(RuntimeSharedServiceResolver::$serviceIds));
		self::assertSame(1, SharedInstanceProbeService::$constructions);
	}

	public function testRecursiveBranchesShareOneOperationRuntimeInstance(): void
	{
		$gateway = ConversionGateway::createDefault();

		map(['child' => ['grandchild' => ['id' => 1]]], null, $gateway)
			->resolver(RuntimeSharedBranchResolver::class)
			->resolver(RuntimeSharedServiceResolver::class)
			->to([]);

		self::assertContains('child.grandchild.id', RuntimeSharedServiceResolver::$paths);
		self::assertCount(1, array_unique(RuntimeSharedServiceResolver::$runtimeIds));
		self::assertCount(1, array_unique(RuntimeSharedServiceResolver::$serviceIds));
		self::assertSame(1, SharedInstanceProbeService::$constructions);
	}

	public function testSeparateTopLevelMappingsReceiveDifferentSharedInstances(): void
	{
		$gateway = ConversionGateway::createDefault();

		map([['id' => 1]], null, $gateway)
			->collection()
			->resolver(RuntimeSharedServiceResolver::class)
			->to([]);
		$firstServiceIds = RuntimeSharedServiceResolver::$serviceIds;

		RuntimeSharedServiceResolver::reset();

		map([['id' => 2]], null, $gateway)
			->collection()
			->resolver(RuntimeSharedServiceResolver::class)
			->to([]);
		$secondServiceIds = RuntimeSharedServiceResolver::$serviceIds;

		self::assertNotSame($firstServiceIds[0] ?? null, $secondServiceIds[0] ?? null);
	}

	public function testEmptyCollectionsInstantiateNoUnusedSharedServices(): void
	{
		$gateway = ConversionGateway::createDefault();

		self::assertSame(
			[],
			map([], null, $gateway)
				->collection()
				->resolver(RuntimeSharedServiceResolver::class)
				->to([]),
		);
		self::assertSame(0, SharedInstanceProbeService::$constructions);
	}

	#[DataProvider('builtinResolvers')]
	public function testRetainedResolverDoesNotRetainOperationHelpers(string $resolverClass, mixed $target): void
	{
		$registry = new Registry();
		$registry->collection('children')->primaryKey('id')->field('id', 'int')->end()->field('parent_id', 'int')->end();
		$parents = $registry->collection('parents')->primaryKey('id')->field('id', 'int')->end();
		$parents->hasMany('child', 'children')->innerKey('id')->outerKey('parent_id');
		$gateway = ConversionGateway::createDefault();
		$runtime = new MappingRuntime($gateway->getMapperManager());
		$options = (new MappingOptions($gateway))->withArguments([$parents]);
		$node = MappingNode::root(['child' => []], $target, $options)->createChildNode('child', []);
		$resolver = new $resolverClass();
		self::assertInstanceOf(BranchNodeResolutionInterface::class, $resolver->resolve($node, $runtime));
		$helpers = [];
		foreach ([BranchTargetInferrer::class, DefinitionArgumentLocator::class, MappingNodePropertyFinder::class] as $helperClass) {
			$helpers[$helperClass] = WeakReference::create($runtime->getSharedInstance($helperClass));
		}

		unset($runtime);
		gc_collect_cycles();

		foreach ($helpers as $helperClass => $helper) {
			self::assertNull($helper->get(), $helperClass);
		}
	}

	/** @return iterable<string, array{class-string<NodeResolverInterface>, mixed}> */
	public static function builtinResolvers(): iterable
	{
		yield 'definition' => [DefinitionNodeResolver::class, []];
		yield 'reflection' => [ReflectionPropertyNodeResolver::class, SharedHelperTarget::class];
		yield 'generic' => [GenericNodeResolver::class, []];
	}
}

final class SharedHelperTarget
{
	public array $child = [];
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
	public static array $runtimeIds = [];

	/**
	 * @var list<int>
	 */
	public static array $serviceIds = [];

	/**
	 * @var list<string>
	 */
	public static array $paths = [];

	public static function reset(): void
	{
		self::$runtimeIds = [];
		self::$serviceIds = [];
		self::$paths = [];
	}

	public function resolve(
		MappingNode $node,
		MappingRuntime $runtime,
	): LeafNodeResolutionInterface|BranchNodeResolutionInterface|null {
		self::$paths[] = $node->getPath();
		self::$runtimeIds[] = spl_object_id($runtime);
		self::$serviceIds[] = spl_object_id(
			$runtime->getSharedInstance(SharedInstanceProbeService::class),
		);

		return null;
	}
}

final class RuntimeSharedBranchResolver implements NodeResolverInterface
{
	public function resolve(
		MappingNode $node,
		MappingRuntime $runtime,
	): LeafNodeResolutionInterface|BranchNodeResolutionInterface|null {
		if ($node->getName() === 'child' || $node->getName() === 'grandchild') {
			return BranchNodeResolution::named(
				name: (string) $node->getName(),
				target: [],
				arguments: [],
			);
		}

		return null;
	}
}
