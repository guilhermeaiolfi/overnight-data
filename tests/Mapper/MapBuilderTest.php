<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use ON\Data\Definition\Registry;
use ON\Data\Mapper\Exception\InvalidMapTargetException;
use function ON\Data\Mapper\map;
use ON\Data\Mapper\Representation\WireRepresentation;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use stdClass;
use Tests\ON\Data\Fixture\GatewayIdentityMapper;
use Tests\ON\Data\Fixture\MapperTestState;

final class MapBuilderTest extends TestCase
{
	protected function setUp(): void
	{
		MapperTestState::reset();
	}

	public function testImmutableBuilderMethods(): void
	{
		$builder = map(['id' => 1]);
		$from = $builder->from(WireRepresentation::class);
		$as = $builder->as(WireRepresentation::class);
		$using = $builder->using(GatewayIdentityMapper::class, 'a');
		$args = $builder->args('b');
		$collection = $builder->collection();

		self::assertNotSame($builder, $from);
		self::assertNotSame($builder, $as);
		self::assertNotSame($builder, $using);
		self::assertNotSame($builder, $args);
		self::assertNotSame($builder, $collection);
		self::assertNull($this->property($builder, 'sourceRepresentation'));
		self::assertNull($this->property($builder, 'outputRepresentation'));
		self::assertNull($this->property($builder, 'mapperClass'));
		self::assertSame([], $this->property($builder, 'arguments'));
		self::assertFalse($this->property($builder, 'collection'));
	}

	public function testArrayToStdClass(): void
	{
		$result = map(['id' => 10, 'name' => 'Ada'])->to(stdClass::class);

		self::assertInstanceOf(stdClass::class, $result);
		self::assertSame(10, $result->id);
		self::assertSame('Ada', $result->name);
	}

	public function testStdClassToArray(): void
	{
		$source = new stdClass();
		$source->id = 10;
		$source->name = 'Ada';

		self::assertSame(['id' => 10, 'name' => 'Ada'], map($source)->toArray());
	}

	public function testCollectionMapping(): void
	{
		$result = map(
			[
				['id' => 1],
				['id' => 2],
			],
		)->collection()->to(stdClass::class);

		self::assertCount(2, $result);
		self::assertSame(1, $result[0]->id);
		self::assertSame(2, $result[1]->id);
	}

	public function testEmptyCollectionMapping(): void
	{
		self::assertSame([], map([])->collection()->to(stdClass::class));
	}

	public function testToJson(): void
	{
		$source = new stdClass();
		$source->id = 10;
		$source->name = 'Ada';

		self::assertSame('{"id":10,"name":"Ada"}', map($source)->toJson());
	}

	public function testRepresentationClassesAreRejectedByTo(): void
	{
		$this->expectException(InvalidMapTargetException::class);
		map(['id' => 10])->to(WireRepresentation::class);
	}

	public function testDefinitionsAndRegistryExportsRemainUnchanged(): void
	{
		$registry = new Registry();
		$registry->collection('users')->field('id', 'int')->nullable(true);
		$before = $registry->all();

		map(['id' => 10])->to(stdClass::class);

		self::assertSame($before, $registry->all());
	}

	private function property(object $object, string $property): mixed
	{
		$reflection = new ReflectionProperty($object, $property);

		return $reflection->getValue($object);
	}
}
