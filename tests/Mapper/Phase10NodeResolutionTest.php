<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use ON\Data\Definition\Registry;
use ON\Data\Mapper\FieldMap;
use function ON\Data\Mapper\map;
use ON\Data\Mapper\Mapper\MapperInterface;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\MappingRuntime;
use ON\Data\Mapper\Representation\WireRepresentation;
use ON\Data\Mapper\Resolution\BranchNodeResolution;
use ON\Data\Mapper\Resolution\BranchNodeResolutionInterface;
use ON\Data\Mapper\Resolution\LeafNodeResolution;
use ON\Data\Mapper\Resolution\LeafNodeResolutionInterface;
use ON\Data\Mapper\Resolver\NodeResolverInterface;
use PHPUnit\Framework\TestCase;
use stdClass;

final class Phase10NodeResolutionTest extends TestCase
{
	public function testHybridBranchDispatchMapsArrayObjectAndArrayLevels(): void
	{
		$object = new stdClass();
		$object->items = [
			['name' => 'Ada'],
		];

		$result = map([
			'child' => $object,
		])->to([]);

		self::assertSame(['name' => 'Ada'], $result['child']['items'][0]);
	}

	public function testExplicitRootMapperOverrideDoesNotLeakIntoObjectChild(): void
	{
		$object = new stdClass();
		$object->id = 10;

		$result = map(['child' => $object])
			->mapper(RecordingRootArrayMapper::class)
			->to([]);

		self::assertSame(['child' => ['id' => 10]], $result);
		self::assertSame([''], RecordingRootArrayMapper::$paths);
	}

	public function testFieldMapArrayLeafIsConvertedAsJsonInsteadOfMappedAsBranch(): void
	{
		$result = map([
			'settings' => ['theme' => 'dark'],
		])
			->as(WireRepresentation::class)
			->fieldMap(FieldMap::fromArray(['settings' => 'json']))
			->to([]);

		self::assertSame(['settings' => '{"theme":"dark"}'], $result);
	}

	public function testDefinitionArrayLeafIsConvertedAsJsonInsteadOfMappedAsBranch(): void
	{
		$registry = new Registry();
		$definition = $registry->collection('profiles');
		$definition->field('settings', 'json');

		$result = map([
			'settings' => ['theme' => 'dark'],
		])
			->as(WireRepresentation::class)
			->args($definition)
			->to([]);

		self::assertSame(['settings' => '{"theme":"dark"}'], $result);
	}

	public function testCustomNodeResolverCanReturnLeafOrBranchBeforeBuiltIns(): void
	{
		$result = map([
			'payload' => ['theme' => 'dark'],
			'child' => ['name' => 'Ada'],
		])
			->as(WireRepresentation::class)
			->resolver(Phase10CustomNodeResolver::class)
			->to([]);

		self::assertSame('{"theme":"dark"}', $result['payload']);
		self::assertInstanceOf(stdClass::class, $result['child']);
		self::assertSame('Ada', $result['child']->name);
	}
}

final class RecordingRootArrayMapper implements MapperInterface
{
	/**
	 * @var list<string>
	 */
	public static array $paths = [];

	public function __construct()
	{
		self::$paths = [];
	}

	public function map(MappingRuntime $runtime): mixed
	{
		$node = $runtime->getMappingNode();
		self::$paths[] = $node->getPath();

		foreach ($runtime->getSource() as $name => $value) {
			$runtime->write(name: $name, value: $value);
		}

		return $runtime->getResult();
	}

	public static function canMap(
		mixed $source,
		MappingContext $context,
	): bool {
		return is_array($source);
	}
}

final class Phase10CustomNodeResolver implements NodeResolverInterface
{
	public function resolve(
		MappingNode $node,
		MappingRuntime $runtime,
	): LeafNodeResolutionInterface|BranchNodeResolutionInterface|null {
		if ($node->getName() === 'payload') {
			return LeafNodeResolution::named('payload', 'json');
		}

		if ($node->getName() === 'child') {
			return BranchNodeResolution::named('child', stdClass::class, []);
		}

		return null;
	}
}
