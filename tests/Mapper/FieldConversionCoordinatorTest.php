<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use DateTimeImmutable;
use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\FieldContext;
use ON\Data\Mapper\FieldConversionCoordinator;
use ON\Data\Mapper\FieldMap;
use function ON\Data\Mapper\map;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\Representation\StorageRepresentation;
use ON\Data\Mapper\Representation\WireRepresentation;
use ON\Data\Mapper\Resolver\FieldMapFieldResolver;
use ON\Data\Mapper\Resolver\FieldResolverInterface;
use ON\Data\Mapper\Resolver\ReflectionPropertyFieldResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use stdClass;
use Tests\ON\Data\Fixture\CustomFieldType;
use Tests\ON\Data\Fixture\InterfaceDateArticleDto;
use Tests\ON\Data\Fixture\IntStatusEnum;
use Tests\ON\Data\Fixture\PropertyContextFixture;
use Tests\ON\Data\Fixture\ReflectedArticleDto;
use Tests\ON\Data\Fixture\StatusEnum;
use Tests\ON\Data\Fixture\UserInputDto;

final class FieldConversionCoordinatorTest extends TestCase
{
	public function testReflectionPropertyResolverUsesWalkerProperty(): void
	{
		$resolver = new ReflectionPropertyFieldResolver();
		$node = MappingNode::root([], [], $this->context())
			->child('name', 'Ada', new ReflectionProperty(PropertyContextFixture::class, 'name'));

		$field = $resolver->resolve($node);

		self::assertSame('name', $field?->getName());
		self::assertSame('string', $field?->getType());
		self::assertFalse($field?->isNullable() ?? true);
	}

	public function testReflectionPropertyResolverFallsBackToPreparedTargetProperty(): void
	{
		$resolver = new ReflectionPropertyFieldResolver();
		$target = (new ReflectionClass(UserInputDto::class))->newInstanceWithoutConstructor();
		$node = MappingNode::root([], $target, $this->context())
			->withTarget($target)
			->child('user_score', '3.5');

		$field = $resolver->resolve($node);

		self::assertSame('score', $field?->getName());
		self::assertSame('float', $field?->getType());
	}

	public function testReflectionPropertyResolverPrefersTargetPropertyOverSourceProperty(): void
	{
		$resolver = new ReflectionPropertyFieldResolver();
		$target = (new ReflectionClass(UserInputDto::class))->newInstanceWithoutConstructor();
		$node = MappingNode::root([], $target, $this->context())
			->withTarget($target)
			->child('user_score', '3.5', new ReflectionProperty(PropertyContextFixture::class, 'name'));

		$field = $resolver->resolve($node);

		self::assertSame('score', $field?->getName());
		self::assertSame('float', $field?->getType());
	}

	public function testReflectionPropertyResolverUsesSourcePropertyAsFallback(): void
	{
		$resolver = new ReflectionPropertyFieldResolver();
		$node = MappingNode::root([], [], $this->context())
			->withTarget([])
			->child('name', 'Ada', new ReflectionProperty(PropertyContextFixture::class, 'name'));

		$field = $resolver->resolve($node);

		self::assertSame('name', $field?->getName());
		self::assertSame('string', $field?->getType());
	}

	public function testReflectionPropertyResolverInfersBackedEnumTypes(): void
	{
		$resolver = new ReflectionPropertyFieldResolver();

		$stringEnum = $resolver->resolve(
			MappingNode::root([], [], $this->context())
				->child('status', 'active', new ReflectionProperty(PropertyContextFixture::class, 'status')),
		);
		$intEnum = $resolver->resolve(
			MappingNode::root([], [], $this->context())
				->child('intStatus', 1, new ReflectionProperty(PropertyContextFixture::class, 'intStatus')),
		);
		$nullableEnum = $resolver->resolve(
			MappingNode::root([], [], $this->context())
				->child('nullableStatus', null, new ReflectionProperty(PropertyContextFixture::class, 'nullableStatus')),
		);

		self::assertSame(StatusEnum::class, $stringEnum?->getType());
		self::assertSame(IntStatusEnum::class, $intEnum?->getType());
		self::assertTrue($nullableEnum?->isNullable() ?? false);
	}

	public function testReflectionPropertyResolverInfersImmutableDatetimeTypes(): void
	{
		$resolver = new ReflectionPropertyFieldResolver();

		$immutable = $resolver->resolve(
			MappingNode::root([], [], $this->context())
				->child('publishedAt', '2026-06-18 13:45:12', new ReflectionProperty(PropertyContextFixture::class, 'publishedAt')),
		);
		$interface = $resolver->resolve(
			MappingNode::root([], [], $this->context())
				->child('publishedAtInterface', '2026-06-18 13:45:12', new ReflectionProperty(PropertyContextFixture::class, 'publishedAtInterface')),
		);
		$nullable = $resolver->resolve(
			MappingNode::root([], [], $this->context())
				->child('nullablePublishedAt', null, new ReflectionProperty(PropertyContextFixture::class, 'nullablePublishedAt')),
		);

		self::assertSame('datetime', $immutable?->getType());
		self::assertSame('datetime', $interface?->getType());
		self::assertTrue($nullable?->isNullable() ?? false);
	}

	#[DataProvider('unsupportedPropertyProvider')]
	public function testReflectionPropertyResolverReturnsNullForUnsupportedTypes(string $property): void
	{
		$resolver = new ReflectionPropertyFieldResolver();
		$node = MappingNode::root([], [], $this->context())
			->child($property, null, new ReflectionProperty(PropertyContextFixture::class, $property));

		self::assertNull($resolver->resolve($node));
	}

	public static function unsupportedPropertyProvider(): array
	{
		return [
			['mixedValue'],
			['profile'],
			['unionValue'],
			['intersectionValue'],
			['unitStatus'],
			['mutablePublishedAt'],
			['untypedValue'],
		];
	}

	public function testFieldMapResolverUsesFullPathAndIgnoresRuntimeIndexes(): void
	{
		$resolver = new FieldMapFieldResolver();
		$fieldMap = FieldMap::fromArray([
			'items.price' => 'decimal',
		]);
		$root = MappingNode::root([], [], $this->context()->withFieldMap($fieldMap));
		$node = $root->child('items', [])->withTarget([])->child(0, [])->withTarget([])->child('price', '12.50');

		$field = $resolver->resolve($node);

		self::assertSame('decimal', $field?->getType());
		self::assertSame('price', $field?->getName());
	}

	public function testFieldMapResolverReturnsNullWithoutStringNodeNameOrMap(): void
	{
		$resolver = new FieldMapFieldResolver();
		$withoutMap = MappingNode::root([], [], $this->context())->child('price', '12.50');
		$numericNode = MappingNode::root([], [], $this->context()->withFieldMap(FieldMap::fromArray(['id' => 'bigint'])))
			->child(0, ['id' => '1']);

		self::assertNull($resolver->resolve($withoutMap));
		self::assertNull($resolver->resolve($numericNode));
	}

	public function testCoordinatorLeavesValueUnchangedWithoutRepresentations(): void
	{
		$coordinator = new FieldConversionCoordinator($this->gateway(), [new ReflectionPropertyFieldResolver()]);
		$node = MappingNode::root([], [], $this->context())
			->child('age', '42', new ReflectionProperty(PropertyContextFixture::class, 'age'));
		$field = $coordinator->resolveField($node);

		self::assertSame('42', $coordinator->convertScalar('42', $field, $node));
	}

	public function testCoordinatorConvertsFromWireToPhp(): void
	{
		$coordinator = new FieldConversionCoordinator($this->gateway(), [new ReflectionPropertyFieldResolver()]);
		$node = MappingNode::root([], [], $this->context()->withSourceRepresentation(WireRepresentation::class))
			->child('age', '42', new ReflectionProperty(PropertyContextFixture::class, 'age'));
		$field = $coordinator->resolveField($node);

		self::assertSame(42, $coordinator->convertScalar('42', $field, $node));
	}

	public function testCoordinatorConvertsFromPhpToWire(): void
	{
		$coordinator = new FieldConversionCoordinator($this->gateway(), [new ReflectionPropertyFieldResolver()]);
		$node = MappingNode::root([], [], $this->context()->withOutputRepresentation(WireRepresentation::class))
			->child('name', 42, new ReflectionProperty(PropertyContextFixture::class, 'name'));
		$field = $coordinator->resolveField($node);

		self::assertSame('42', $coordinator->convertScalar(42, $field, $node));
	}

	public function testCoordinatorLeavesUnresolvedValuesUnchanged(): void
	{
		$coordinator = new FieldConversionCoordinator($this->gateway(), []);
		$node = MappingNode::root([], [], $this->context()->withSourceRepresentation(WireRepresentation::class))
			->child('age', '42');

		self::assertSame(
			'42',
			$coordinator->convertScalar(
				'42',
				$coordinator->resolveField($node),
				$node,
			),
		);
	}

	public function testExplicitResolverCanOverrideBuiltInResolution(): void
	{
		$gateway = new ConversionGateway();
		$gateway->getMapperManager()->register(CustomFieldType::class);

		$coordinator = new FieldConversionCoordinator(
			$gateway,
			[
				new class () implements FieldResolverInterface {
					public function resolve(MappingNode $node): ?FieldContext
					{
						return $node->getName() === 'code'
							? FieldContext::named('code', 'custom')
							: null;
					}
				},
				new ReflectionPropertyFieldResolver(),
			],
		);
		$node = MappingNode::root([], [], $this->context()->withSourceRepresentation(WireRepresentation::class))
			->child('code', 'ada');
		$field = $coordinator->resolveField($node);

		self::assertSame('ADA', $coordinator->convertScalar('ada', $field, $node));
	}

	public function testMapperRuntimeUsesSameConversionThroughDifferentCombinations(): void
	{
		$dto = map([
			'id' => '10',
			'name' => 123,
			'age' => '42',
			'active' => 'true',
			'user_score' => '3.5',
		])->from(WireRepresentation::class)->to(UserInputDto::class);

		$std = new stdClass();
		$std->id = '10';
		$std->name = 123;
		$std->age = '42';
		$std->active = 'true';
		$std->user_score = '3.5';
		$dtoFromStd = map($std)->from(WireRepresentation::class)->to(UserInputDto::class);

		self::assertSame($dto->id, $dtoFromStd->id);
		self::assertSame($dto->score, $dtoFromStd->score);
	}

	public function testReflectedEnumAndDatetimePropertiesConvertEndToEnd(): void
	{
		$dto = map([
			'status' => 'active',
			'priority' => 1,
			'publishedAt' => '2026-06-18 13:45:12',
		])
			->from(StorageRepresentation::class)
			->to(ReflectedArticleDto::class);

		$array = map($dto)
			->as(WireRepresentation::class)
			->to([]);

		self::assertSame(StatusEnum::Active, $dto->status);
		self::assertSame(IntStatusEnum::Published, $dto->priority);
		self::assertInstanceOf(DateTimeImmutable::class, $dto->publishedAt);
		self::assertSame('active', $array['status']);
		self::assertSame(1, $array['priority']);
		self::assertSame('2026-06-18T13:45:12+00:00', $array['publishedAt']);
	}

	public function testReflectedDatetimeInterfacePropertyAcceptsImmutableCanonicalValue(): void
	{
		$result = map([
			'publishedAt' => '2026-06-18 13:45:12',
		])
			->from(StorageRepresentation::class)
			->to(InterfaceDateArticleDto::class);

		self::assertInstanceOf(DateTimeImmutable::class, $result->publishedAt);
	}

	private function gateway(): ConversionGateway
	{
		return ConversionGateway::createDefault();
	}

	private function context(): MappingContext
	{
		return new MappingContext($this->gateway());
	}
}
