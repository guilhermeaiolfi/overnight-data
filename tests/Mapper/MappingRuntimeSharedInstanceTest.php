<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use ON\Data\Mapper\ConversionGateway;
use function ON\Data\Mapper\map;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\MappingRuntime;
use ON\Data\Mapper\Resolution\BranchNodeResolution;
use ON\Data\Mapper\Resolution\BranchNodeResolutionInterface;
use ON\Data\Mapper\Resolution\LeafNodeResolutionInterface;
use ON\Data\Mapper\Resolver\NodeResolverInterface;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

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

	public function testRuntimeSharedInstancesRemainInternalStateOnly(): void
	{
		$runtime = new MappingRuntime(ConversionGateway::createDefault()->getMapperManager());
		$runtime->getSharedInstance(SharedInstanceProbeService::class);

		self::assertArrayHasKey(
			SharedInstanceProbeService::class,
			$this->sharedInstancesProperty()->getValue($runtime),
		);
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
