<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\FieldContext;
use ON\Data\Mapper\FieldConversionCoordinator;
use ON\Data\Mapper\FieldTypeRegistry;
use function ON\Data\Mapper\map;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\Representation\WireRepresentation;
use ON\Data\Mapper\Resolver\FieldResolverInterface;
use ON\Data\Mapper\Resolver\ReflectionPropertyFieldResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use stdClass;
use Tests\ON\Data\Fixture\CustomFieldType;
use Tests\ON\Data\Fixture\PropertyContextFixture;
use Tests\ON\Data\Fixture\UserInputDto;

final class FieldConversionCoordinatorTest extends TestCase
{
	public function testReflectionPropertyResolverUsesWalkerProperty(): void
	{
		$resolver = new ReflectionPropertyFieldResolver();

		$field = $resolver->resolve(
			$this->context(),
			'name',
			'name',
			'Ada',
			new ReflectionProperty(PropertyContextFixture::class, 'name'),
		);

		self::assertSame('name', $field?->getName());
		self::assertSame('string', $field?->getType());
		self::assertFalse($field?->isNullable() ?? true);
	}

	public function testReflectionPropertyResolverFallsBackToPreparedTargetProperty(): void
	{
		$resolver = new ReflectionPropertyFieldResolver();
		$target = (new ReflectionClass(UserInputDto::class))->newInstanceWithoutConstructor();

		$field = $resolver->resolve(
			$this->context()->withTarget($target),
			'user_score',
			'user_score',
			'3.5',
			null,
		);

		self::assertSame('score', $field?->getName());
		self::assertSame('float', $field?->getType());
	}

	#[DataProvider('unsupportedPropertyProvider')]
	public function testReflectionPropertyResolverReturnsNullForUnsupportedTypes(string $property): void
	{
		$resolver = new ReflectionPropertyFieldResolver();

		self::assertNull(
			$resolver->resolve(
				$this->context(),
				$property,
				$property,
				null,
				new ReflectionProperty(PropertyContextFixture::class, $property),
			),
		);
	}

	public static function unsupportedPropertyProvider(): array
	{
		return [
			['mixedValue'],
			['profile'],
			['unionValue'],
			['intersectionValue'],
			['untypedValue'],
		];
	}

	public function testCoordinatorLeavesValueUnchangedWithoutRepresentations(): void
	{
		$coordinator = new FieldConversionCoordinator($this->gateway(), [new ReflectionPropertyFieldResolver()]);
		$context = $this->context()->withPathSegment('age');
		$field = $coordinator->resolveField(
			$context,
			'age',
			'age',
			'42',
			new ReflectionProperty(PropertyContextFixture::class, 'age'),
		);

		self::assertSame('42', $coordinator->convertScalar('42', $field, $context));
	}

	public function testCoordinatorConvertsFromWireToPhp(): void
	{
		$coordinator = new FieldConversionCoordinator($this->gateway(), [new ReflectionPropertyFieldResolver()]);
		$context = $this->context()->withSourceRepresentation(WireRepresentation::class)->withPathSegment('age');
		$field = $coordinator->resolveField(
			$context,
			'age',
			'age',
			'42',
			new ReflectionProperty(PropertyContextFixture::class, 'age'),
		);

		self::assertSame(42, $coordinator->convertScalar('42', $field, $context));
	}

	public function testCoordinatorConvertsFromPhpToWire(): void
	{
		$coordinator = new FieldConversionCoordinator($this->gateway(), [new ReflectionPropertyFieldResolver()]);
		$context = $this->context()->withOutputRepresentation(WireRepresentation::class)->withPathSegment('name');
		$field = $coordinator->resolveField(
			$context,
			'name',
			'name',
			42,
			new ReflectionProperty(PropertyContextFixture::class, 'name'),
		);

		self::assertSame('42', $coordinator->convertScalar(42, $field, $context));
	}

	public function testCoordinatorLeavesUnresolvedValuesUnchanged(): void
	{
		$coordinator = new FieldConversionCoordinator($this->gateway(), []);

		self::assertSame(
			'42',
			$coordinator->convertScalar(
				'42',
				$coordinator->resolveField(
					$this->context()->withSourceRepresentation(WireRepresentation::class),
					'age',
					'age',
					'42',
					new stdClass(),
				),
				$this->context()->withSourceRepresentation(WireRepresentation::class),
			),
		);
	}

	public function testExplicitResolverCanOverrideBuiltInResolution(): void
	{
		$coordinator = new FieldConversionCoordinator(
			new ConversionGateway(FieldTypeRegistry::createDefault()->register('custom', CustomFieldType::class)),
			[
				new class () implements FieldResolverInterface {
					public function resolve(
						MappingContext $mapping,
						string $path,
						string|int $fieldName,
						mixed $value,
						mixed $extra = null,
					): ?FieldContext {
						return $fieldName === 'code'
							? FieldContext::named('code', 'custom')
							: null;
					}
				},
				new ReflectionPropertyFieldResolver(),
			],
		);
		$context = $this->context()->withSourceRepresentation(WireRepresentation::class)->withPathSegment('code');
		$field = $coordinator->resolveField($context, 'code', 'code', 'ada', null);

		self::assertSame('ADA', $coordinator->convertScalar('ada', $field, $context));
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

	private function gateway(): ConversionGateway
	{
		return ConversionGateway::createDefault();
	}

	private function context(): MappingContext
	{
		return new MappingContext($this->gateway());
	}
}
