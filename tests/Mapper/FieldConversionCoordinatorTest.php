<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use DateTimeImmutable;
use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\FieldConversionCoordinator;
use ON\Data\Mapper\FieldMap;
use function ON\Data\Mapper\map;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\MappingOptions;
use ON\Data\Mapper\MappingRuntime;
use ON\Data\Mapper\Representation\StorageRepresentation;
use ON\Data\Mapper\Representation\WireRepresentation;
use ON\Data\Mapper\Resolution\LeafNodeResolution;
use ON\Data\Mapper\Resolver\FieldMapNodeResolver;
use ON\Data\Mapper\Resolver\NodeResolverInterface;
use ON\Data\Mapper\Resolver\ReflectionPropertyNodeResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
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
	public function testReflectionPropertyResolverUsesMapperProperty(): void
	{
		$resolver = new ReflectionPropertyNodeResolver();
		$node = $this->sourceNode('name', 'Ada');

		$field = $resolver->resolve($node, $this->runtimeFor($node));

		self::assertSame('name', $field?->getName());
		self::assertSame('string', $field?->getType());
		self::assertFalse($field?->isNullable() ?? true);
	}

	public function testReflectionPropertyResolverFallsBackToPreparedTargetProperty(): void
	{
		$resolver = new ReflectionPropertyNodeResolver();
		$target = (new ReflectionClass(UserInputDto::class))->newInstanceWithoutConstructor();
		$node = MappingNode::root($this->source(), $target, $this->options())
			->withTarget($target)
			->createChildNode('user_score', '3.5');

		$field = $resolver->resolve($node, $this->runtimeFor($node));

		self::assertSame('score', $field?->getName());
		self::assertSame('float', $field?->getType());
	}

	public function testReflectionPropertyResolverPrefersTargetPropertyOverSourceProperty(): void
	{
		$resolver = new ReflectionPropertyNodeResolver();
		$target = (new ReflectionClass(UserInputDto::class))->newInstanceWithoutConstructor();
		$node = MappingNode::root($this->source(), $target, $this->options())
			->withTarget($target)
			->createChildNode('user_score', '3.5');

		$field = $resolver->resolve($node, $this->runtimeFor($node));

		self::assertSame('score', $field?->getName());
		self::assertSame('float', $field?->getType());
	}

	public function testReflectionPropertyResolverUsesSourcePropertyAsFallback(): void
	{
		$resolver = new ReflectionPropertyNodeResolver();
		$node = $this->sourceNode('name', 'Ada')->withTarget([]);

		$field = $resolver->resolve($node, $this->runtimeFor($node));

		self::assertSame('name', $field?->getName());
		self::assertSame('string', $field?->getType());
	}

	public function testReflectionPropertyResolverInfersBackedEnumTypes(): void
	{
		$resolver = new ReflectionPropertyNodeResolver();
		$stringNode = $this->sourceNode('status', 'active');
		$intNode = $this->sourceNode('intStatus', 1);
		$nullableNode = $this->sourceNode('nullableStatus', null);

		$stringEnum = $resolver->resolve($stringNode, $this->runtimeFor($stringNode));
		$intEnum = $resolver->resolve($intNode, $this->runtimeFor($intNode));
		$nullableEnum = $resolver->resolve($nullableNode, $this->runtimeFor($nullableNode));

		self::assertSame(StatusEnum::class, $stringEnum?->getType());
		self::assertSame(IntStatusEnum::class, $intEnum?->getType());
		self::assertTrue($nullableEnum?->isNullable() ?? false);
	}

	public function testReflectionPropertyResolverInfersImmutableDatetimeTypes(): void
	{
		$resolver = new ReflectionPropertyNodeResolver();
		$immutableNode = $this->sourceNode('publishedAt', '2026-06-18 13:45:12');
		$interfaceNode = $this->sourceNode('publishedAtInterface', '2026-06-18 13:45:12');
		$nullableNode = $this->sourceNode('nullablePublishedAt', null);

		$immutable = $resolver->resolve($immutableNode, $this->runtimeFor($immutableNode));
		$interface = $resolver->resolve($interfaceNode, $this->runtimeFor($interfaceNode));
		$nullable = $resolver->resolve($nullableNode, $this->runtimeFor($nullableNode));

		self::assertSame('datetime', $immutable?->getType());
		self::assertSame('datetime', $interface?->getType());
		self::assertTrue($nullable?->isNullable() ?? false);
	}

	#[DataProvider('unsupportedPropertyProvider')]
	public function testReflectionPropertyResolverReturnsNullForUnsupportedTypes(string $property): void
	{
		$resolver = new ReflectionPropertyNodeResolver();
		$node = $this->sourceNode($property, null);

		self::assertNull($resolver->resolve($node, $this->runtimeFor($node)));
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
		$resolver = new FieldMapNodeResolver();
		$fieldMap = FieldMap::fromArray([
			'items.price' => 'decimal',
		]);
		$root = MappingNode::root([], [], $this->options()->withFieldMap($fieldMap));
		$node = $root->createChildNode('items', [])->withTarget([])->createChildNode(0, [])->withTarget([])->createChildNode('price', '12.50');

		$field = $resolver->resolve($node, $this->runtimeFor($node));

		self::assertSame('decimal', $field?->getType());
		self::assertSame('price', $field?->getName());
	}

	public function testFieldMapResolverReturnsNullWithoutStringNodeNameOrMap(): void
	{
		$resolver = new FieldMapNodeResolver();
		$withoutMap = MappingNode::root([], [], $this->options())->createChildNode('price', '12.50');
		$numericNode = MappingNode::root([], [], $this->options()->withFieldMap(FieldMap::fromArray(['id' => 'bigint'])))
			->createChildNode(0, ['id' => '1']);

		self::assertNull($resolver->resolve($withoutMap, $this->runtimeFor($withoutMap)));
		self::assertNull($resolver->resolve($numericNode, $this->runtimeFor($numericNode)));
	}

	public function testCoordinatorLeavesValueUnchangedWithoutRepresentations(): void
	{
		$coordinator = new FieldConversionCoordinator($this->gateway());
		$node = $this->sourceNode('age', '42');
		$field = (new ReflectionPropertyNodeResolver())->resolve($node, $this->runtimeFor($node));

		self::assertSame('42', $coordinator->convert('42', $field, $node));
	}

	public function testCoordinatorConvertsFromWireToPhp(): void
	{
		$coordinator = new FieldConversionCoordinator($this->gateway());
		$node = $this->sourceNode('age', '42', $this->options()->withSourceRepresentation(WireRepresentation::class));
		$field = (new ReflectionPropertyNodeResolver())->resolve($node, $this->runtimeFor($node));

		self::assertSame(42, $coordinator->convert('42', $field, $node));
	}

	public function testCoordinatorConvertsFromPhpToWire(): void
	{
		$coordinator = new FieldConversionCoordinator($this->gateway());
		$node = $this->sourceNode('name', 42, $this->options()->withOutputRepresentation(WireRepresentation::class));
		$field = (new ReflectionPropertyNodeResolver())->resolve($node, $this->runtimeFor($node));

		self::assertSame('42', $coordinator->convert(42, $field, $node));
	}

	public function testCoordinatorLeavesPassthroughValuesUnchanged(): void
	{
		$coordinator = new FieldConversionCoordinator($this->gateway());
		$node = MappingNode::root([], [], $this->options()->withSourceRepresentation(WireRepresentation::class))
			->createChildNode('age', '42');

		self::assertSame(
			'42',
			$coordinator->convert(
				'42',
				LeafNodeResolution::passthrough('age'),
				$node,
			),
		);
	}

	public function testExplicitResolverCanOverrideBuiltInResolution(): void
	{
		$gateway = new ConversionGateway();
		$gateway->getMapperManager()->register(CustomFieldType::class);

		$coordinator = new FieldConversionCoordinator($gateway);
		$resolver = new class () implements NodeResolverInterface {
			public function resolve(
				MappingNode $node,
				MappingRuntime $runtime,
			): ?LeafNodeResolution {
				return $node->getName() === 'code'
					? LeafNodeResolution::named('code', 'custom')
					: null;
			}
		};
		$node = MappingNode::root([], [], $this->options()->withSourceRepresentation(WireRepresentation::class))
			->createChildNode('code', 'ada');
		$field = $resolver->resolve($node, $this->runtimeFor($node));

		self::assertSame('ADA', $coordinator->convert('ada', $field, $node));
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

	private function options(): MappingOptions
	{
		return new MappingOptions($this->gateway());
	}

	private function sourceNode(
		string $name,
		mixed $value,
		?MappingOptions $options = null,
		mixed $target = [],
	): MappingNode {
		return MappingNode::root(
			$this->source(),
			$target,
			$options ?? $this->options(),
		)->createChildNode($name, $value);
	}

	private function source(): PropertyContextFixture
	{
		return (new ReflectionClass(PropertyContextFixture::class))->newInstanceWithoutConstructor();
	}

	private function runtimeFor(MappingNode $node): MappingRuntime
	{
		return new MappingRuntime(
			mapperManager: $node->getOptions()->getGateway()->getMapperManager(),
		);
	}
}
