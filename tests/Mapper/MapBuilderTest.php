<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use ON\Data\Definition\Registry;
use ON\Data\Mapper\Exception\InvalidMapTargetException;
use ON\Data\Mapper\FieldMap;
use function ON\Data\Mapper\map;
use ON\Data\Mapper\Representation\WireRepresentation;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use stdClass;
use Tests\ON\Data\Fixture\SpyArrayMapper;
use Tests\ON\Data\Fixture\SpyArrayWriter;
use Tests\ON\Data\Fixture\SpyResolver;

final class MapBuilderTest extends TestCase
{
	public function testBuilderMethodsAreImmutable(): void
	{
		$builder = map(['id' => 1]);
		$from = $builder->from(WireRepresentation::class);
		$as = $builder->as(WireRepresentation::class);
		$mapper = $builder->mapper(SpyArrayMapper::class);
		$writer = $builder->writer(SpyArrayWriter::class);
		$resolver = $builder->resolver(SpyResolver::class);
		$args = $builder->args('b');
		$fieldMap = $builder->fieldMap(FieldMap::fromArray(['id' => 'bigint']));
		$collection = $builder->collection();

		self::assertNotSame($builder, $from);
		self::assertNotSame($builder, $as);
		self::assertNotSame($builder, $mapper);
		self::assertNotSame($builder, $writer);
		self::assertNotSame($builder, $resolver);
		self::assertNotSame($builder, $args);
		self::assertNotSame($builder, $fieldMap);
		self::assertNotSame($builder, $collection);
		self::assertNull($this->property($builder, 'sourceRepresentation'));
		self::assertNull($this->property($builder, 'outputRepresentation'));
		self::assertNull($this->property($builder, 'mapperClass'));
		self::assertNull($this->property($builder, 'writerClass'));
		self::assertSame([], $this->property($builder, 'resolverClasses'));
		self::assertSame([], $this->property($builder, 'arguments'));
		self::assertNull($this->property($builder, 'fieldMap'));
		self::assertFalse($this->property($builder, 'collection'));
	}

	public function testToArrayCanonicalPathIsToEmptyArray(): void
	{
		self::assertSame(['id' => 10, 'name' => 'Ada'], map(['id' => 10, 'name' => 'Ada'])->to([]));
	}

	public function testStdClassToArrayUsesToEmptyArray(): void
	{
		$source = new stdClass();
		$source->id = 10;
		$source->name = 'Ada';

		self::assertSame(['id' => 10, 'name' => 'Ada'], map($source)->to([]));
	}

	public function testCollectionMapping(): void
	{
		$result = map([['id' => 1], ['id' => 2]])->collection()->to(stdClass::class);

		self::assertCount(2, $result);
		self::assertSame(1, $result[0]->id);
		self::assertSame(2, $result[1]->id);
	}

	public function testToJsonMapsThroughArrayWriter(): void
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
