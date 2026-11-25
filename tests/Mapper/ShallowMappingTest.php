<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use ON\Data\Mapper\FieldMap;
use function ON\Data\Mapper\map;
use ON\Data\Mapper\Mapper\ArrayMapperOptions;
use ON\Data\Mapper\Mapper\ObjectMapper;
use ON\Data\Mapper\Representation\WireRepresentation;
use PHPUnit\Framework\TestCase;
use stdClass;

final class ShallowMappingTest extends TestCase
{
	public function testPreservesNestedValuesFromBothMappersAcrossTargets(): void
	{
		$child = (object) ['name' => 'Ada'];
		$values = [
			'child' => $child,
			'items' => ['first.item' => $child],
		];

		foreach ([$values, (object) $values] as $source) {
			$mapping = map($source)->shallow();

			self::assertSame($values, $mapping->to([]));
			self::assertSame($values, get_object_vars($mapping->to(stdClass::class)));
			$dto = $mapping->to(ShallowTargetDto::class);
			self::assertInstanceOf(ShallowTargetDto::class, $dto);
			self::assertSame($child, $dto->child);
			self::assertSame($values['items'], $dto->items);
		}
	}

	public function testLiteralDottedObjectPropertiesWorkWithAndWithoutFastPath(): void
	{
		$source = (object) ['company.name' => 'Ada', 'company' => ['name' => 'Grace']];
		$mapping = map($source)->shallow();

		self::assertSame(get_object_vars($source), $mapping->to([]));
		self::assertSame(get_object_vars($source), $mapping->mapper(ObjectMapper::class)->to([]));
	}

	public function testArrayOptionsControlTopLevelDottedKeyExpansion(): void
	{
		$child = new stdClass();
		$source = ['company.owner' => $child, 'items' => ['first.item' => $child]];
		$mapping = map($source)->shallow();

		self::assertSame(['company' => ['owner' => $child], 'items' => $source['items']], $mapping->to([]));
		self::assertSame($source, $mapping->args(new ArrayMapperOptions(expandDottedKeys: false))->to([]));
	}

	public function testPreservesOneLevelOfCyclicObjectAndArraySources(): void
	{
		$object = new stdClass();
		$object->self = $object;
		$array = ['marker' => $object];
		$array['self'] = &$array;

		self::assertSame($object, map($object)->shallow()->to([])['self']);
		self::assertSame($object, map($object)->shallow()->to(stdClass::class)->self);
		self::assertSame($object, map($array)->shallow()->to([])['self']['self']['marker']);
		self::assertSame($object, map($array)->shallow()->to(stdClass::class)->self['self']['marker']);
	}

	public function testShallowStillAppliesTopLevelLeafConversions(): void
	{
		$source = (object) ['active' => 'false', 'nested' => ['active' => 'false']];

		$result = map($source)
			->from(WireRepresentation::class)
			->fieldMap(FieldMap::fromArray(['active' => 'bool', 'nested.active' => 'bool']))
			->shallow()
			->to([]);

		self::assertSame(['active' => false, 'nested' => ['active' => 'false']], $result);
	}

	public function testShallowMapsEachCollectionItem(): void
	{
		$child = new stdClass();
		$values = ['child' => $child];

		$result = map([$values, (object) $values])->collection()->shallow()->to([]);

		self::assertSame([$values, $values], $result);
	}

	public function testShallowRespectsExistingArrayTargets(): void
	{
		$child = new stdClass();

		self::assertSame(
			['default' => true, 'child' => $child],
			map((object) ['child' => $child])->shallow()->to(['default' => true]),
		);
	}

	public function testShallowModeIsImmutableAndCanBeDisabled(): void
	{
		$child = (object) ['name' => 'Ada'];
		$deep = map(['child' => $child]);
		$shallow = $deep->shallow();
		$expectedDeep = ['child' => ['name' => 'Ada']];

		self::assertSame($expectedDeep, $deep->to([]));
		self::assertSame($expectedDeep, $shallow->shallow(false)->to([]));
		self::assertSame(['child' => $child], $shallow->to([]));
	}
}

final class ShallowTargetDto
{
	/** @param array<string, stdClass> $items */
	public function __construct(public stdClass $child, public array $items)
	{
	}
}
