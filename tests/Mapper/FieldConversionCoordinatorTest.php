<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\FieldContext;
use ON\Data\Mapper\FieldContextResolverInterface;
use ON\Data\Mapper\FieldConversionCoordinator;
use ON\Data\Mapper\FieldTypeRegistry;
use function ON\Data\Mapper\map;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\ReflectionPropertyFieldContextResolver;
use ON\Data\Mapper\Representation\WireRepresentation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use stdClass;
use Tests\ON\Data\Fixture\CustomFieldType;
use Tests\ON\Data\Fixture\CustomResolverDto;
use Tests\ON\Data\Fixture\PropertyContextFixture;
use Tests\ON\Data\Fixture\UserInputDto;

final class FieldConversionCoordinatorTest extends TestCase
{
	public function testReflectionPropertyResolverSupportsPrimitiveProperties(): void
	{
		$resolver = new ReflectionPropertyFieldContextResolver();

		$field = $resolver->resolve(
			new ReflectionProperty(PropertyContextFixture::class, 'name'),
			$this->context(),
		);

		self::assertSame('name', $field?->getName());
		self::assertSame('string', $field?->getType());
		self::assertFalse($field?->isNullable() ?? true);
	}

	public function testReflectionPropertyResolverSupportsNullablePrimitives(): void
	{
		$resolver = new ReflectionPropertyFieldContextResolver();

		$field = $resolver->resolve(
			new ReflectionProperty(PropertyContextFixture::class, 'age'),
			$this->context(),
		);

		self::assertSame('age', $field?->getName());
		self::assertSame('int', $field?->getType());
		self::assertTrue($field?->isNullable() ?? false);
	}

	#[DataProvider('unsupportedPropertyProvider')]
	public function testReflectionPropertyResolverReturnsNullForUnsupportedProperties(string $property): void
	{
		$resolver = new ReflectionPropertyFieldContextResolver();

		self::assertNull(
			$resolver->resolve(
				new ReflectionProperty(PropertyContextFixture::class, $property),
				$this->context(),
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

	public function testCoordinatorConvertsInboundThroughGateway(): void
	{
		$gateway = ConversionGateway::createDefault();
		$coordinator = $gateway->getFieldConversionCoordinator();

		self::assertSame(
			42,
			$coordinator->convertInbound(
				'42',
				new ReflectionProperty(PropertyContextFixture::class, 'age'),
				$this->context()->withSourceRepresentation(WireRepresentation::class)->withPathSegment('age'),
			),
		);
	}

	public function testCoordinatorConvertsOutboundThroughGateway(): void
	{
		$gateway = ConversionGateway::createDefault();
		$coordinator = $gateway->getFieldConversionCoordinator();

		self::assertSame(
			'42',
			$coordinator->convertOutbound(
				42,
				new ReflectionProperty(PropertyContextFixture::class, 'name'),
				$this->context()->withOutputRepresentation(WireRepresentation::class)->withPathSegment('name'),
			),
		);
	}

	public function testCoordinatorDoesNothingWithoutRepresentations(): void
	{
		$gateway = ConversionGateway::createDefault();
		$coordinator = $gateway->getFieldConversionCoordinator();

		self::assertSame(
			'42',
			$coordinator->convertInbound(
				'42',
				new ReflectionProperty(PropertyContextFixture::class, 'age'),
				$this->context(),
			),
		);
		self::assertSame(
			42,
			$coordinator->convertOutbound(
				42,
				new ReflectionProperty(PropertyContextFixture::class, 'age'),
				$this->context(),
			),
		);
	}

	public function testCoordinatorReturnsOriginalValueWhenNoFieldContextResolves(): void
	{
		$coordinator = new FieldConversionCoordinator(new ConversionGateway(FieldTypeRegistry::createDefault()));

		self::assertSame(
			'42',
			$coordinator->convertInbound(
				'42',
				new stdClass(),
				$this->context()->withSourceRepresentation(WireRepresentation::class),
			),
		);
	}

	public function testCustomResolverRegistrationIsUsed(): void
	{
		$coordinator = new FieldConversionCoordinator(
			new ConversionGateway(FieldTypeRegistry::createDefault()),
		);
		$coordinator->addResolver(
			new class () implements FieldContextResolverInterface {
				public function resolve(mixed $source, MappingContext $context): ?FieldContext
				{
					return $source === 'custom' ? FieldContext::named('custom', 'int') : null;
				}
			},
		);

		self::assertSame(
			10,
			$coordinator->convertInbound(
				'10',
				'custom',
				$this->context()->withSourceRepresentation(WireRepresentation::class),
			),
		);
	}

	public function testResolverOrderUsesFirstMatch(): void
	{
		$coordinator = new FieldConversionCoordinator(
			new ConversionGateway(FieldTypeRegistry::createDefault()),
			[
				new class () implements FieldContextResolverInterface {
					public function resolve(mixed $source, MappingContext $context): ?FieldContext
					{
						return FieldContext::named('value', 'string');
					}
				},
				new class () implements FieldContextResolverInterface {
					public function resolve(mixed $source, MappingContext $context): ?FieldContext
					{
						return FieldContext::named('value', 'int');
					}
				},
			],
		);

		self::assertSame(
			'10',
			$coordinator->convertInbound(
				10,
				'ignored',
				$this->context()->withSourceRepresentation(WireRepresentation::class),
			),
		);
	}

	public function testTypedObjectMappersUseCoordinatorForCustomFieldTypes(): void
	{
		$gateway = new ConversionGateway(
			FieldTypeRegistry::createDefault()->register('custom', CustomFieldType::class),
		);
		$gateway->getFieldConversionCoordinator()->addResolver(
			new class () implements FieldContextResolverInterface {
				public function resolve(mixed $source, MappingContext $context): ?FieldContext
				{
					if (! $source instanceof ReflectionProperty || $source->getName() !== 'code') {
						return null;
					}

					return FieldContext::named('code', 'custom');
				}
			},
		);

		$inbound = map(['code' => 'ada'], null, $gateway)
			->from(WireRepresentation::class)
			->to(CustomResolverDto::class);

		$outbound = new CustomResolverDto();
		$outbound->code = 'ADA';

		$result = map($outbound, null, $gateway)
			->as(WireRepresentation::class)
			->toArray();

		self::assertSame('ADA', $inbound->code);
		self::assertSame('ada', $result['code']);
	}

	public function testStdClassMappingDoesNotInferScalarTypes(): void
	{
		$source = new stdClass();
		$source->age = '42';

		$result = map($source)->as(WireRepresentation::class)->toArray();

		self::assertSame('42', $result['age']);
	}

	public function testExistingPhase3ABehaviorRemainsUnchanged(): void
	{
		$result = map([
			'id' => '10',
			'name' => 123,
			'age' => '42',
			'active' => 'true',
			'user_score' => '3.5',
		])->from(WireRepresentation::class)->to(UserInputDto::class);

		self::assertSame(10, $result->id);
		self::assertSame('123', $result->name);
		self::assertSame(42, $result->age);
		self::assertTrue($result->active);
		self::assertSame(3.5, $result->score);
	}

	private function context(): MappingContext
	{
		$gateway = ConversionGateway::createDefault();

		return new MappingContext($gateway);
	}
}
