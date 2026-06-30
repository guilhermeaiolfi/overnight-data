<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use LogicException;
use ON\Data\Definition\Registry;
use ON\Data\Mapper\FieldMap;
use function ON\Data\Mapper\map;
use ON\Data\Mapper\Mapper\MapperInterface;
use ON\Data\Mapper\MappingBranch;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\MappingOptions;
use ON\Data\Mapper\MappingRuntime;
use ON\Data\Mapper\Resolution\BranchNodeResolution;
use ON\Data\Mapper\Resolution\BranchNodeResolutionInterface;
use ON\Data\Mapper\Resolution\LeafNodeResolution;
use ON\Data\Mapper\Resolution\LeafNodeResolutionInterface;
use ON\Data\Mapper\Resolution\ResolutionNodeInterface;
use ON\Data\Mapper\Resolver\CacheableNodeResolverInterface;
use ON\Data\Mapper\Writer\ObjectWriterState;
use ON\Data\Mapper\Writer\WriterInterface;
use ON\Data\Mapper\Writer\WriterStateInterface;
use PHPUnit\Framework\TestCase;
use stdClass;

final class NodeResolutionCacheTest extends TestCase
{
	protected function setUp(): void
	{
		CacheProbeResolver::reset();
		CacheTrackingResolver::reset();
		CacheBarrierResolver::reset();
		CacheLateWinnerResolver::reset();
		CacheMissThenLeafResolver::reset();
		CacheBranchingResolver::reset();
		CacheTrackingWriter::reset();
	}

	public function testReflectionScalarLeafResolutionIsReusedAcrossDirectCollectionItems(): void
	{
		$result = map([
			['id' => 1],
			['id' => 2],
		])
			->collection()
			->resolver(CacheProbeResolver::class)
			->to(CacheTargetDto::class);

		self::assertSame(1, CacheProbeResolver::$resolveCalls);
		self::assertSame(1, CacheProbeResolver::$cacheabilityCalls);
		self::assertSame(1, $result[0]->id);
		self::assertSame(2, $result[1]->id);
	}

	public function testDefinitionLeafResolutionIsReusedAcrossDirectCollectionItems(): void
	{
		$registry = new Registry();
		$definition = $registry->collection('users');
		$definition->field('id', 'int');

		$result = map([
			['id' => 1],
			['id' => 2],
		])
			->collection()
			->args($definition)
			->resolver(CacheProbeResolver::class)
			->to([]);

		self::assertSame(1, CacheProbeResolver::$resolveCalls);
		self::assertSame(1, CacheProbeResolver::$cacheabilityCalls);
		self::assertSame([['id' => 1], ['id' => 2]], $result);
	}

	public function testFieldMapLeafResolutionIsReusedAcrossDirectCollectionItems(): void
	{
		$result = map([
			['id' => 1],
			['id' => 2],
		])
			->collection()
			->fieldMap(FieldMap::fromArray(['id' => 'int']))
			->resolver(CacheProbeResolver::class)
			->to([]);

		self::assertSame(1, CacheProbeResolver::$resolveCalls);
		self::assertSame(1, CacheProbeResolver::$cacheabilityCalls);
		self::assertSame([['id' => 1], ['id' => 2]], $result);
	}

	public function testCachedLeafReuseStillCreatesCurrentChildNodesAndWritesEachItem(): void
	{
		$result = map([
			['id' => 1],
			['id' => 2],
		])
			->collection()
			->resolver(CacheTrackingResolver::class)
			->writer(CacheTrackingWriter::class)
			->to(stdClass::class);

		self::assertSame(1, CacheTrackingResolver::$resolveCalls);
		self::assertSame(1, CacheTrackingResolver::$cacheabilityCalls);
		self::assertSame(['0.id', '1.id'], CacheTrackingWriter::$paths);
		self::assertSame([1, 2], CacheTrackingWriter::$values);
		self::assertNotSame($result[0], $result[1]);
	}

	public function testSeparateTopLevelCollectionsDoNotShareCachedLeafEntries(): void
	{
		map([['id' => 1], ['id' => 2]])
			->collection()
			->resolver(CacheTrackingResolver::class)
			->to([]);

		map([['id' => 3], ['id' => 4]])
			->collection()
			->resolver(CacheTrackingResolver::class)
			->to([]);

		self::assertSame(2, CacheTrackingResolver::$resolveCalls);
		self::assertSame(2, CacheTrackingResolver::$cacheabilityCalls);
	}

	public function testNestedCollectionsUseSeparateResolutionCaches(): void
	{
		$result = map([
			['children' => [['id' => 1], ['id' => 2]]],
			['children' => [['id' => 3]]],
		])
			->collection()
			->resolver(CacheBranchingResolver::class)
			->to([]);

		self::assertSame([
			['children' => [['id' => 1], ['id' => 2]]],
			['children' => [['id' => 3]]],
		], $result);
		self::assertSame(2, CacheBranchingResolver::$leafResolveCalls);
		self::assertSame(2, CacheBranchingResolver::$leafCacheabilityCalls);
	}

	public function testRecursiveNonCollectionBranchesDoNotInheritParentCollectionCache(): void
	{
		$result = map([
			['child' => ['id' => 1]],
			['child' => ['id' => 2]],
		])
			->collection()
			->resolver(CacheBranchingResolver::class)
			->to([]);

		self::assertSame([
			['child' => ['id' => 1]],
			['child' => ['id' => 2]],
		], $result);
		self::assertSame(2, CacheBranchingResolver::$leafResolveCalls);
		self::assertSame(0, CacheBranchingResolver::$leafCacheabilityCalls);
	}

	public function testNonCacheableBarrierPreventsLeafCachingAndLaterItemsStayDynamic(): void
	{
		$result = map([
			['id' => 1],
			['id' => 2],
		])
			->collection()
			->resolver(CacheBarrierResolver::class)
			->resolver(CacheTrackingResolver::class)
			->to([]);

		self::assertSame([['id' => 1], ['id' => 2]], $result);
		self::assertSame(2, CacheBarrierResolver::$resolveCalls);
		self::assertSame(1, CacheBarrierResolver::$cacheabilityCalls);
		self::assertSame(2, CacheTrackingResolver::$resolveCalls);
		self::assertSame(0, CacheTrackingResolver::$cacheabilityCalls);
	}

	public function testStableNullFromEarlierCacheableResolverStillAllowsLaterLeafToBeCached(): void
	{
		$result = map([
			['id' => 1],
			['id' => 2],
		])
			->collection()
			->resolver(CacheMissThenLeafResolver::class)
			->resolver(CacheTrackingResolver::class)
			->to([]);

		self::assertSame([['id' => 1], ['id' => 2]], $result);
		self::assertSame(1, CacheMissThenLeafResolver::$resolveCalls);
		self::assertSame(1, CacheMissThenLeafResolver::$cacheabilityCalls);
		self::assertSame(1, CacheTrackingResolver::$resolveCalls);
		self::assertSame(1, CacheTrackingResolver::$cacheabilityCalls);
	}

	public function testDynamicFieldsDoNotRepeatCacheabilityAnalysisAfterFirstItem(): void
	{
		$result = map([
			['id' => 1],
			['id' => 2],
			['id' => 3],
		])
			->collection()
			->resolver(CacheLateWinnerResolver::class)
			->to([]);

		self::assertSame([
			['id' => 1],
			['late_id' => 2],
			['late_id' => 3],
		], $result);
		self::assertSame(3, CacheLateWinnerResolver::$resolveCalls);
		self::assertSame(1, CacheLateWinnerResolver::$cacheabilityCalls);
	}

	public function testIntegerAndNumericStringFieldNamesDoNotShareOneCacheEntry(): void
	{
		$result = map([
			['ignored' => true],
			['ignored' => true],
		])
			->collection()
			->mapper(CacheDualFieldMapper::class)
			->resolver(CacheDualFieldResolver::class)
			->to([]);

		self::assertSame([
			['int-key' => 'int-value', 'string-key' => 'string-value'],
			['int-key' => 'int-value', 'string-key' => 'string-value'],
		], $result);
		self::assertSame([1, '1'], CacheDualFieldResolver::$resolvedNames);
	}
}

final class CacheTargetDto
{
	public int $id;
}

final class CacheProbeResolver implements CacheableNodeResolverInterface
{
	public static int $resolveCalls = 0;

	public static int $cacheabilityCalls = 0;

	public static function reset(): void
	{
		self::$resolveCalls = 0;
		self::$cacheabilityCalls = 0;
	}

	public function resolve(
		MappingNode $node,
		MappingRuntime $runtime,
	): LeafNodeResolutionInterface|BranchNodeResolutionInterface|null {
		self::$resolveCalls++;

		return null;
	}

	public function isResolutionCacheable(
		MappingNode $node,
		?ResolutionNodeInterface $resolution,
		MappingRuntime $runtime,
	): bool {
		self::$cacheabilityCalls++;

		return true;
	}
}

final class CacheTrackingResolver implements CacheableNodeResolverInterface
{
	public static int $resolveCalls = 0;

	public static int $cacheabilityCalls = 0;

	public static function reset(): void
	{
		self::$resolveCalls = 0;
		self::$cacheabilityCalls = 0;
	}

	public function resolve(
		MappingNode $node,
		MappingRuntime $runtime,
	): LeafNodeResolutionInterface|BranchNodeResolutionInterface|null {
		self::$resolveCalls++;

		if ($node->getName() !== 'id') {
			return null;
		}

		return LeafNodeResolution::passthrough('id');
	}

	public function isResolutionCacheable(
		MappingNode $node,
		?ResolutionNodeInterface $resolution,
		MappingRuntime $runtime,
	): bool {
		self::$cacheabilityCalls++;

		return $resolution instanceof LeafNodeResolutionInterface;
	}
}

final class CacheBarrierResolver implements CacheableNodeResolverInterface
{
	public static int $resolveCalls = 0;

	public static int $cacheabilityCalls = 0;

	public static function reset(): void
	{
		self::$resolveCalls = 0;
		self::$cacheabilityCalls = 0;
	}

	public function resolve(
		MappingNode $node,
		MappingRuntime $runtime,
	): LeafNodeResolutionInterface|BranchNodeResolutionInterface|null {
		self::$resolveCalls++;

		return null;
	}

	public function isResolutionCacheable(
		MappingNode $node,
		?ResolutionNodeInterface $resolution,
		MappingRuntime $runtime,
	): bool {
		self::$cacheabilityCalls++;

		return false;
	}
}

final class CacheLateWinnerResolver implements CacheableNodeResolverInterface
{
	public static int $resolveCalls = 0;

	public static int $cacheabilityCalls = 0;

	public static function reset(): void
	{
		self::$resolveCalls = 0;
		self::$cacheabilityCalls = 0;
	}

	public function resolve(
		MappingNode $node,
		MappingRuntime $runtime,
	): LeafNodeResolutionInterface|BranchNodeResolutionInterface|null {
		self::$resolveCalls++;

		if ($node->getName() === 'id' && $node->getValue() > 1) {
			return LeafNodeResolution::passthrough('late_id');
		}

		return null;
	}

	public function isResolutionCacheable(
		MappingNode $node,
		?ResolutionNodeInterface $resolution,
		MappingRuntime $runtime,
	): bool {
		self::$cacheabilityCalls++;

		return false;
	}
}

final class CacheMissThenLeafResolver implements CacheableNodeResolverInterface
{
	public static int $resolveCalls = 0;

	public static int $cacheabilityCalls = 0;

	public static function reset(): void
	{
		self::$resolveCalls = 0;
		self::$cacheabilityCalls = 0;
	}

	public function resolve(
		MappingNode $node,
		MappingRuntime $runtime,
	): LeafNodeResolutionInterface|BranchNodeResolutionInterface|null {
		self::$resolveCalls++;

		return null;
	}

	public function isResolutionCacheable(
		MappingNode $node,
		?ResolutionNodeInterface $resolution,
		MappingRuntime $runtime,
	): bool {
		self::$cacheabilityCalls++;

		return true;
	}
}

final class CacheBranchingResolver implements CacheableNodeResolverInterface
{
	public static int $leafResolveCalls = 0;

	public static int $leafCacheabilityCalls = 0;

	public static function reset(): void
	{
		self::$leafResolveCalls = 0;
		self::$leafCacheabilityCalls = 0;
	}

	public function resolve(
		MappingNode $node,
		MappingRuntime $runtime,
	): LeafNodeResolutionInterface|BranchNodeResolutionInterface|null {
		if ($node->getName() === 'children') {
			return BranchNodeResolution::named(
				name: 'children',
				target: [],
				arguments: [],
				collection: true,
			);
		}

		if ($node->getName() === 'child') {
			return BranchNodeResolution::named(
				name: 'child',
				target: [],
				arguments: [],
				collection: false,
			);
		}

		if ($node->getName() === 'id') {
			self::$leafResolveCalls++;

			return LeafNodeResolution::passthrough('id');
		}

		return null;
	}

	public function isResolutionCacheable(
		MappingNode $node,
		?ResolutionNodeInterface $resolution,
		MappingRuntime $runtime,
	): bool {
		if ($node->getName() === 'id') {
			self::$leafCacheabilityCalls++;
		}

		return $resolution instanceof LeafNodeResolutionInterface;
	}
}

final class CacheTrackingWriter implements WriterInterface
{
	/**
	 * @var list<string>
	 */
	public static array $paths = [];

	/**
	 * @var list<mixed>
	 */
	public static array $values = [];

	public static function reset(): void
	{
		self::$paths = [];
		self::$values = [];
	}

	public static function canWrite(
		mixed $target,
		MappingOptions $options,
	): bool {
		return $target === stdClass::class || $target instanceof stdClass;
	}

	public function createState(MappingNode $node): WriterStateInterface
	{
		$state = new ObjectWriterState();
		$state->target = new stdClass();

		return $state;
	}

	public function write(
		WriterStateInterface $state,
		string|int $name,
		mixed $value,
		MappingNode $node,
	): void {
		$state instanceof ObjectWriterState || throw new LogicException();
		$state->target instanceof stdClass || throw new LogicException();
		self::$paths[] = $node->getPath();
		self::$values[] = $value;
		$state->target->{$name} = $value;
	}

	public function getResult(
		WriterStateInterface $state,
		MappingNode $node,
	): stdClass {
		$state instanceof ObjectWriterState || throw new LogicException();
		$state->target instanceof stdClass || throw new LogicException();

		return $state->target;
	}
}

final class CacheDualFieldMapper implements MapperInterface
{
	public function map(MappingBranch $context): mixed
	{
		$context->write(1, 'int-value');
		$context->write('1', 'string-value');

		return $context->getResult();
	}

	public static function canMap(
		mixed $source,
		MappingOptions $options,
	): bool {
		return is_array($source);
	}
}

final class CacheDualFieldResolver implements CacheableNodeResolverInterface
{
	/**
	 * @var list<string|int>
	 */
	public static array $resolvedNames = [];

	public static function reset(): void
	{
		self::$resolvedNames = [];
	}

	public function resolve(
		MappingNode $node,
		MappingRuntime $runtime,
	): LeafNodeResolutionInterface|BranchNodeResolutionInterface|null {
		self::$resolvedNames[] = $node->getName();

		if ($node->getName() === 1) {
			return LeafNodeResolution::passthrough('int-key');
		}

		if ($node->getName() === '1') {
			return LeafNodeResolution::passthrough('string-key');
		}

		return null;
	}

	public function isResolutionCacheable(
		MappingNode $node,
		?ResolutionNodeInterface $resolution,
		MappingRuntime $runtime,
	): bool {
		return $resolution instanceof LeafNodeResolutionInterface;
	}
}
