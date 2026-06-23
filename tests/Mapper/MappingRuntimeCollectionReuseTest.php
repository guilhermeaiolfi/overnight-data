<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\FieldTypeInterface;
use function ON\Data\Mapper\map;
use ON\Data\Mapper\Mapper\MapperInterface;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\MappingOptions;
use ON\Data\Mapper\MappingRuntime;
use ON\Data\Mapper\Representation\WireRepresentation;
use ON\Data\Mapper\Resolution\BranchNodeResolution;
use ON\Data\Mapper\Resolution\BranchNodeResolutionInterface;
use ON\Data\Mapper\Resolution\LeafNodeResolution;
use ON\Data\Mapper\Resolution\LeafNodeResolutionInterface;
use ON\Data\Mapper\Resolver\NodeResolverInterface;
use ON\Data\Mapper\Writer\WriterInterface;
use PHPUnit\Framework\TestCase;
use stdClass;

final class MappingRuntimeCollectionReuseTest extends TestCase
{
	protected function setUp(): void
	{
		RuntimeReuseSpyWriter::reset();
		RuntimeReuseSpyResolver::reset();
		RuntimeReusePrecedenceResolver::reset();
		RuntimeReuseBranchResolver::reset();
		RuntimeReuseCollectionBranchResolver::reset();
		RuntimeReuseArrayMapper::reset();
		RuntimeReuseObjectMapper::reset();
		RuntimeReuseCountingFieldType::reset();
	}

	public function testWriterSelectionOccursOnceForMultipleDirectCollectionItems(): void
	{
		$gateway = ConversionGateway::createDefault();
		$gateway->getMapperManager()->prepend(RuntimeReuseSpyWriter::class);

		$result = map([['id' => 1], ['id' => 2]], null, $gateway)
			->collection()
			->to([]);

		self::assertSame([['id' => 1], ['id' => 2]], $result);
		self::assertSame(1, RuntimeReuseSpyWriter::$canWriteCalls);
		self::assertSame(2, RuntimeReuseSpyWriter::$createTargetCalls);
	}

	public function testResolverComponentsAreConstructedOncePerCollectionFrame(): void
	{
		$gateway = ConversionGateway::createDefault();

		$result = map([['id' => 1], ['id' => 2]], null, $gateway)
			->collection()
			->resolver(RuntimeReuseSpyResolver::class)
			->to([]);

		self::assertSame([['id' => 1], ['id' => 2]], $result);
		self::assertSame(1, RuntimeReuseSpyResolver::$constructions);
		self::assertSame(['0.id', '1.id'], RuntimeReuseSpyResolver::$resolvedPaths);
	}

	public function testDirectCollectionItemsShareConversionCoordinatorPath(): void
	{
		$gateway = ConversionGateway::createDefault();
		$gateway->getMapperManager()->register(RuntimeReuseCountingFieldType::class);

		$result = map([['id' => '1'], ['id' => '2']], null, $gateway)
			->collection()
			->from(WireRepresentation::class)
			->resolver(RuntimeReuseTypedIdResolver::class)
			->to([]);

		self::assertSame([['id' => 'converted:1'], ['id' => 'converted:2']], $result);
		self::assertSame(2, RuntimeReuseCountingFieldType::$toPhpCalls);
	}

	public function testResolverOrderAndExplicitResolverPrecedenceRemainUnchanged(): void
	{
		$gateway = ConversionGateway::createDefault();

		$result = map([['id' => 1], ['id' => 2]], null, $gateway)
			->collection()
			->resolver(RuntimeReusePrecedenceResolver::class)
			->to([]);

		self::assertSame([['custom_id' => 1], ['custom_id' => 2]], $result);
		self::assertSame(['0.id', '1.id'], RuntimeReusePrecedenceResolver::$resolvedPaths);
	}

	public function testExplicitWriterOverrideRemainsAppliedToDirectCollectionItems(): void
	{
		$gateway = ConversionGateway::createDefault();

		$result = map([['id' => 1], ['id' => 2]], null, $gateway)
			->collection()
			->writer(RuntimeReuseSpyWriter::class)
			->to([]);

		self::assertSame([['id' => 1], ['id' => 2]], $result);
		self::assertSame(1, RuntimeReuseSpyWriter::$canWriteCalls);
		self::assertSame(2, RuntimeReuseSpyWriter::$createTargetCalls);
	}

	public function testMixedObjectArrayCollectionsStillSelectMapperPerItem(): void
	{
		$gateway = ConversionGateway::createDefault();
		$gateway->getMapperManager()->prepend(RuntimeReuseArrayMapper::class);
		$gateway->getMapperManager()->prepend(RuntimeReuseObjectMapper::class);

		$result = map([['id' => 1], (object) ['id' => 2], ['id' => 3]], null, $gateway)
			->collection()
			->to([]);

		self::assertSame([
			['kind' => 'array'],
			['kind' => 'object'],
			['kind' => 'array'],
		], $result);
		self::assertSame(3, RuntimeReuseObjectMapper::$canMapCalls);
		self::assertSame(2, RuntimeReuseArrayMapper::$canMapCalls);
		self::assertSame(2, RuntimeReuseArrayMapper::$mapCalls);
		self::assertSame(1, RuntimeReuseObjectMapper::$mapCalls);
	}

	public function testNestedCollectionGetsSeparateReuseFrameFromParentCollection(): void
	{
		$gateway = ConversionGateway::createDefault();
		$gateway->getMapperManager()->prepend(RuntimeReuseSpyWriter::class);

		$result = map([
			['children' => [['id' => 1], ['id' => 2]]],
			['children' => [['id' => 3]]],
		], null, $gateway)
			->collection()
			->resolver(RuntimeReuseCollectionBranchResolver::class)
			->to([]);

		self::assertSame([
			['children' => [['id' => 1], ['id' => 2]]],
			['children' => [['id' => 3]]],
		], $result);
		self::assertSame(3, RuntimeReuseSpyWriter::$canWriteCalls);
		self::assertSame(3, RuntimeReuseCollectionBranchResolver::$constructions);
	}

	public function testBranchesWithDifferentTargetsDoNotInheritParentCollectionComponents(): void
	{
		$gateway = ConversionGateway::createDefault();
		$gateway->getMapperManager()->prepend(RuntimeReuseSpyWriter::class);

		$result = map([
			['child' => ['id' => 1]],
			['child' => ['id' => 2]],
		], null, $gateway)
			->collection()
			->resolver(RuntimeReuseBranchResolver::class)
			->to([]);

		self::assertSame(1, $result[0]['child']->id);
		self::assertSame(2, $result[1]['child']->id);
		self::assertSame(3, RuntimeReuseSpyWriter::$canWriteCalls);
	}

	public function testEmptyCollectionsDoNotEagerlyResolveWriterOrConstructResolvers(): void
	{
		$gateway = ConversionGateway::createDefault();
		$gateway->getMapperManager()->prepend(RuntimeReuseSpyWriter::class);

		$result = map([], null, $gateway)
			->collection()
			->resolver(RuntimeReuseSpyResolver::class)
			->to([]);

		self::assertSame([], $result);
		self::assertSame(0, RuntimeReuseSpyWriter::$canWriteCalls);
		self::assertSame(0, RuntimeReuseSpyResolver::$constructions);
	}

	public function testNonCollectionMappingBehaviorRemainsUnchanged(): void
	{
		$gateway = ConversionGateway::createDefault();
		$gateway->getMapperManager()->prepend(RuntimeReuseSpyWriter::class);

		$result = map(['id' => 1], null, $gateway)
			->resolver(RuntimeReuseSpyResolver::class)
			->to([]);

		self::assertSame(['id' => 1], $result);
		self::assertSame(1, RuntimeReuseSpyWriter::$canWriteCalls);
		self::assertSame(1, RuntimeReuseSpyWriter::$createTargetCalls);
		self::assertSame(1, RuntimeReuseSpyResolver::$constructions);
	}
}

final class RuntimeReuseSpyWriter implements WriterInterface
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
		MappingOptions $options,
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

final class RuntimeReuseSpyResolver implements NodeResolverInterface
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
		MappingRuntime $runtime,
	): LeafNodeResolutionInterface|BranchNodeResolutionInterface|null {
		self::$resolvedPaths[] = $node->getPath();

		return null;
	}
}

final class RuntimeReusePrecedenceResolver implements NodeResolverInterface
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
		MappingRuntime $runtime,
	): LeafNodeResolutionInterface|BranchNodeResolutionInterface|null {
		self::$resolvedPaths[] = $node->getPath();

		if ($node->getName() === 'id') {
			return LeafNodeResolution::passthrough('custom_id');
		}

		return null;
	}
}

final class RuntimeReuseTypedIdResolver implements NodeResolverInterface
{
	public function resolve(
		MappingNode $node,
		MappingRuntime $runtime,
	): LeafNodeResolutionInterface|BranchNodeResolutionInterface|null {
		if ($node->getName() === 'id') {
			return LeafNodeResolution::named('id', 'runtime-reuse-counting');
		}

		return null;
	}
}

final class RuntimeReuseBranchResolver implements NodeResolverInterface
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
		MappingRuntime $runtime,
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

final class RuntimeReuseCollectionBranchResolver implements NodeResolverInterface
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

		return null;
	}
}

final class RuntimeReuseCountingFieldType implements FieldTypeInterface
{
	public static int $toPhpCalls = 0;

	public static function reset(): void
	{
		self::$toPhpCalls = 0;
	}

	public static function getNames(): array
	{
		return ['runtime-reuse-counting'];
	}

	public static function getStorageType(): string
	{
		return 'string';
	}

	public static function toPhp(
		mixed $value,
		LeafNodeResolutionInterface $field,
	): mixed {
		self::$toPhpCalls++;

		return 'converted:' . $value;
	}

	public static function fromPhp(
		mixed $value,
		LeafNodeResolutionInterface $field,
	): mixed {
		return $value;
	}
}

final class RuntimeReuseArrayMapper implements MapperInterface
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
		MappingOptions $options,
	): bool {
		self::$canMapCalls++;

		return is_array($source);
	}

	public function map(MappingContext $context): mixed
	{
		self::$mapCalls++;

		return ['kind' => 'array'];
	}
}

final class RuntimeReuseObjectMapper implements MapperInterface
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
		MappingOptions $options,
	): bool {
		self::$canMapCalls++;

		return is_object($source);
	}

	public function map(MappingContext $context): mixed
	{
		self::$mapCalls++;

		return ['kind' => 'object'];
	}
}
