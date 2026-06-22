<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use ON\Data\Mapper\ConversionGateway;
use function ON\Data\Mapper\map;
use ON\Data\Mapper\Mapper\MapperInterface;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\MappingRuntime;
use ON\Data\Mapper\Resolution\BranchNodeResolution;
use ON\Data\Mapper\Resolution\BranchNodeResolutionInterface;
use ON\Data\Mapper\Resolution\LeafNodeResolution;
use ON\Data\Mapper\Resolution\LeafNodeResolutionInterface;
use ON\Data\Mapper\Resolver\NodeResolverInterface;
use ON\Data\Mapper\Writer\WriterInterface;
use PHPUnit\Framework\TestCase;
use stdClass;

final class MappingRuntimeCacheTest extends TestCase
{
	protected function setUp(): void
	{
		RuntimeCacheSpyWriter::reset();
		RuntimeCacheSpyResolver::reset();
		RuntimeCachePrecedenceResolver::reset();
		RuntimeCacheBranchResolver::reset();
		RuntimeCacheCollectionBranchResolver::reset();
		RuntimeCacheArrayMapper::reset();
		RuntimeCacheObjectMapper::reset();
	}

	public function testWriterSelectionOccursOnceForMultipleDirectCollectionItems(): void
	{
		$gateway = ConversionGateway::createDefault();
		$gateway->getMapperManager()->prepend(RuntimeCacheSpyWriter::class);

		$result = map([['id' => 1], ['id' => 2]], null, $gateway)
			->collection()
			->to([]);

		self::assertSame([['id' => 1], ['id' => 2]], $result);
		self::assertSame(1, RuntimeCacheSpyWriter::$canWriteCalls);
		self::assertSame(2, RuntimeCacheSpyWriter::$createTargetCalls);
	}

	public function testResolverComponentsAreConstructedOncePerCollectionFrame(): void
	{
		$gateway = ConversionGateway::createDefault();

		$result = map([['id' => 1], ['id' => 2]], null, $gateway)
			->collection()
			->resolver(RuntimeCacheSpyResolver::class)
			->to([]);

		self::assertSame([['id' => 1], ['id' => 2]], $result);
		self::assertSame(1, RuntimeCacheSpyResolver::$constructions);
		self::assertSame(['0.id', '1.id'], RuntimeCacheSpyResolver::$resolvedPaths);
	}

	public function testResolverOrderAndExplicitResolverPrecedenceRemainUnchanged(): void
	{
		$gateway = ConversionGateway::createDefault();

		$result = map([['id' => 1], ['id' => 2]], null, $gateway)
			->collection()
			->resolver(RuntimeCachePrecedenceResolver::class)
			->to([]);

		self::assertSame([['custom_id' => 1], ['custom_id' => 2]], $result);
		self::assertSame(['0.id', '1.id'], RuntimeCachePrecedenceResolver::$resolvedPaths);
	}

	public function testMixedObjectArrayCollectionsStillSelectMapperPerItem(): void
	{
		$gateway = ConversionGateway::createDefault();
		$gateway->getMapperManager()->prepend(RuntimeCacheArrayMapper::class);
		$gateway->getMapperManager()->prepend(RuntimeCacheObjectMapper::class);

		$result = map([['id' => 1], (object) ['id' => 2], ['id' => 3]], null, $gateway)
			->collection()
			->to([]);

		self::assertSame([
			['kind' => 'array'],
			['kind' => 'object'],
			['kind' => 'array'],
		], $result);
		self::assertSame(3, RuntimeCacheObjectMapper::$canMapCalls);
		self::assertSame(2, RuntimeCacheArrayMapper::$canMapCalls);
		self::assertSame(2, RuntimeCacheArrayMapper::$mapCalls);
		self::assertSame(1, RuntimeCacheObjectMapper::$mapCalls);
	}

	public function testNestedCollectionGetsSeparateCacheFromParentCollection(): void
	{
		$gateway = ConversionGateway::createDefault();
		$gateway->getMapperManager()->prepend(RuntimeCacheSpyWriter::class);

		$result = map([
			['children' => [['id' => 1], ['id' => 2]]],
			['children' => [['id' => 3]]],
		], null, $gateway)
			->collection()
			->resolver(RuntimeCacheCollectionBranchResolver::class)
			->to([]);

		self::assertSame([
			['children' => [['id' => 1], ['id' => 2]]],
			['children' => [['id' => 3]]],
		], $result);
		self::assertSame(3, RuntimeCacheSpyWriter::$canWriteCalls);
		self::assertSame(3, RuntimeCacheCollectionBranchResolver::$constructions);
	}

	public function testBranchesWithDifferentTargetsDoNotInheritParentCollectionCache(): void
	{
		$gateway = ConversionGateway::createDefault();
		$gateway->getMapperManager()->prepend(RuntimeCacheSpyWriter::class);

		$result = map([
			['child' => ['id' => 1]],
			['child' => ['id' => 2]],
		], null, $gateway)
			->collection()
			->resolver(RuntimeCacheBranchResolver::class)
			->to([]);

		self::assertSame(1, $result[0]['child']->id);
		self::assertSame(2, $result[1]['child']->id);
		self::assertSame(3, RuntimeCacheSpyWriter::$canWriteCalls);
	}

	public function testEmptyCollectionsDoNotEagerlyResolveWriterOrConstructResolvers(): void
	{
		$gateway = ConversionGateway::createDefault();
		$gateway->getMapperManager()->prepend(RuntimeCacheSpyWriter::class);

		$result = map([], null, $gateway)
			->collection()
			->resolver(RuntimeCacheSpyResolver::class)
			->to([]);

		self::assertSame([], $result);
		self::assertSame(0, RuntimeCacheSpyWriter::$canWriteCalls);
		self::assertSame(0, RuntimeCacheSpyResolver::$constructions);
	}

	public function testNonCollectionMappingBehaviorRemainsUnchanged(): void
	{
		$gateway = ConversionGateway::createDefault();
		$gateway->getMapperManager()->prepend(RuntimeCacheSpyWriter::class);

		$result = map(['id' => 1], null, $gateway)
			->resolver(RuntimeCacheSpyResolver::class)
			->to([]);

		self::assertSame(['id' => 1], $result);
		self::assertSame(1, RuntimeCacheSpyWriter::$canWriteCalls);
		self::assertSame(1, RuntimeCacheSpyWriter::$createTargetCalls);
		self::assertSame(1, RuntimeCacheSpyResolver::$constructions);
	}
}

final class RuntimeCacheSpyWriter implements WriterInterface
{
	public static int $canWriteCalls = 0;

	public static int $createTargetCalls = 0;

	public static function reset(): void
	{
		self::$canWriteCalls = 0;
		self::$createTargetCalls = 0;
	}

	public static function canWrite(
		mixed $target,
		MappingContext $context,
	): bool {
		self::$canWriteCalls++;

		return is_array($target);
	}

	public function createTarget(MappingNode $node): array
	{
		self::$createTargetCalls++;

		return [];
	}

	public function write(
		mixed $target,
		string|int $name,
		mixed $value,
		MappingNode $node,
	): array {
		$target[$name] = $value;

		return $target;
	}
}

final class RuntimeCacheSpyResolver implements NodeResolverInterface
{
	public static int $constructions = 0;

	/**
	 * @var list<string>
	 */
	public static array $resolvedPaths = [];

	public function __construct()
	{
		self::$constructions++;
	}

	public static function reset(): void
	{
		self::$constructions = 0;
		self::$resolvedPaths = [];
	}

	public function resolve(
		MappingNode $node,
	): LeafNodeResolutionInterface|BranchNodeResolutionInterface|null {
		self::$resolvedPaths[] = $node->getPath();

		return null;
	}
}

final class RuntimeCachePrecedenceResolver implements NodeResolverInterface
{
	/**
	 * @var list<string>
	 */
	public static array $resolvedPaths = [];

	public static function reset(): void
	{
		self::$resolvedPaths = [];
	}

	public function resolve(
		MappingNode $node,
	): LeafNodeResolutionInterface|BranchNodeResolutionInterface|null {
		self::$resolvedPaths[] = $node->getPath();

		if ($node->getName() === 'id') {
			return LeafNodeResolution::passthrough('custom_id');
		}

		return null;
	}
}

final class RuntimeCacheBranchResolver implements NodeResolverInterface
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

	public function resolve(
		MappingNode $node,
	): LeafNodeResolutionInterface|BranchNodeResolutionInterface|null {
		if ($node->getName() === 'child') {
			return BranchNodeResolution::named(
				name: 'child',
				target: stdClass::class,
				arguments: ['branch'],
			);
		}

		return null;
	}
}

final class RuntimeCacheCollectionBranchResolver implements NodeResolverInterface
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

	public function resolve(
		MappingNode $node,
	): LeafNodeResolutionInterface|BranchNodeResolutionInterface|null {
		if ($node->getName() === 'children') {
			return BranchNodeResolution::named(
				name: 'children',
				target: [],
				arguments: [],
				collection: true,
			);
		}

		return null;
	}
}

final class RuntimeCacheArrayMapper implements MapperInterface
{
	public static int $canMapCalls = 0;

	public static int $mapCalls = 0;

	public static function reset(): void
	{
		self::$canMapCalls = 0;
		self::$mapCalls = 0;
	}

	public static function canMap(
		mixed $source,
		MappingContext $context,
	): bool {
		self::$canMapCalls++;

		return is_array($source);
	}

	public function map(MappingRuntime $runtime): mixed
	{
		self::$mapCalls++;

		return ['kind' => 'array'];
	}
}

final class RuntimeCacheObjectMapper implements MapperInterface
{
	public static int $canMapCalls = 0;

	public static int $mapCalls = 0;

	public static function reset(): void
	{
		self::$canMapCalls = 0;
		self::$mapCalls = 0;
	}

	public static function canMap(
		mixed $source,
		MappingContext $context,
	): bool {
		self::$canMapCalls++;

		return is_object($source);
	}

	public function map(MappingRuntime $runtime): mixed
	{
		self::$mapCalls++;

		return ['kind' => 'object'];
	}
}
