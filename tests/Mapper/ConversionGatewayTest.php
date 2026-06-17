<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use ON\Data\Definition\Registry;
use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\Exception\ConversionException;
use ON\Data\Mapper\Exception\FieldTypeNotFoundException;
use ON\Data\Mapper\Exception\UnsupportedConversionException;
use ON\Data\Mapper\FieldContext;
use ON\Data\Mapper\FieldTypeRegistry;
use ON\Data\Mapper\Representation\PhpRepresentation;
use ON\Data\Mapper\Representation\StorageRepresentation;
use ON\Data\Mapper\Representation\WireRepresentation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\Fixture\CacheRepresentation;
use Tests\ON\Data\Fixture\CustomFieldType;
use Tests\ON\Data\Fixture\TrackingCustomFieldType;

final class ConversionGatewayTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();

		TrackingCustomFieldType::reset();
	}

	public function testStorageIntegerStringConvertsToPhpInteger(): void
	{
		$gateway = ConversionGateway::createDefault();
		$field = FieldContext::named('id', 'int');

		self::assertSame(10, $gateway->to(StorageRepresentation::class, '10', PhpRepresentation::class, $field));
	}

	public function testWireIntegerStringConvertsToPhpInteger(): void
	{
		$gateway = ConversionGateway::createDefault();
		$field = FieldContext::named('id', 'integer');

		self::assertSame(42, $gateway->to(WireRepresentation::class, '42', PhpRepresentation::class, $field));
	}

	#[DataProvider('supportedBooleanValues')]
	public function testSupportedBooleanInputsConvertDeterministically(mixed $input, bool $expected): void
	{
		$gateway = ConversionGateway::createDefault();
		$field = FieldContext::named('active', 'bool');

		self::assertSame($expected, $gateway->to(WireRepresentation::class, $input, PhpRepresentation::class, $field));
	}

	public static function supportedBooleanValues(): array
	{
		return [
			[true, true],
			[false, false],
			['1', true],
			['0', false],
			['true', true],
			['false', false],
			['TRUE', true],
			['False', false],
			['yes', true],
			['no', false],
			['on', true],
			['off', false],
			[' yes ', true],
			["\toff\n", false],
			[1, true],
			[0, false],
			[1.0, true],
			[0.0, false],
		];
	}

	#[DataProvider('ambiguousBooleanValues')]
	public function testAmbiguousBooleanStringsFail(string $input): void
	{
		$gateway = ConversionGateway::createDefault();
		$field = FieldContext::named('active', 'bool');

		$this->expectException(ConversionException::class);
		$gateway->to(WireRepresentation::class, $input, PhpRepresentation::class, $field);
	}

	public static function ambiguousBooleanValues(): array
	{
		return [
			['2'],
			[''],
			['-1'],
			['enabled'],
			['disabled'],
			['maybe'],
			['truthy'],
		];
	}

	#[DataProvider('successfulIntegerInputs')]
	public function testIntegerBoundaryValuesConvertSuccessfully(mixed $input, int $expected): void
	{
		$gateway = ConversionGateway::createDefault();

		self::assertSame(
			$expected,
			$gateway->to(StorageRepresentation::class, $input, PhpRepresentation::class, FieldContext::named('id', 'int')),
		);
	}

	public static function successfulIntegerInputs(): array
	{
		return [
			[(string) PHP_INT_MAX, PHP_INT_MAX],
			[(string) PHP_INT_MIN, PHP_INT_MIN],
			[PHP_INT_MAX, PHP_INT_MAX],
			[PHP_INT_MIN, PHP_INT_MIN],
		];
	}

	#[DataProvider('failingIntegerInputs')]
	public function testOutOfRangeOrLossyIntegerInputsFail(mixed $input): void
	{
		$gateway = ConversionGateway::createDefault();

		$this->expectException(ConversionException::class);
		$gateway->to(StorageRepresentation::class, $input, PhpRepresentation::class, FieldContext::named('id', 'int'));
	}

	public static function failingIntegerInputs(): array
	{
		return [
			[PHP_INT_MAX . '0'],
			['-' . PHP_INT_MAX . '0'],
			['999999999999999999999999999999'],
			['-999999999999999999999999999999'],
			['1e3'],
			[1.5],
			[INF],
			[-INF],
			[NAN],
		];
	}

	public function testPhpValuesConvertToStorage(): void
	{
		$gateway = ConversionGateway::createDefault();

		self::assertSame(7, $gateway->to(PhpRepresentation::class, 7, StorageRepresentation::class, FieldContext::named('id', 'int')));
		self::assertSame(3.5, $gateway->to(PhpRepresentation::class, 3.5, StorageRepresentation::class, FieldContext::named('price', 'float')));
		self::assertTrue($gateway->to(PhpRepresentation::class, true, StorageRepresentation::class, FieldContext::named('active', 'bool')));
	}

	#[DataProvider('successfulFloatInputs')]
	public function testFiniteFloatInputsConvertSuccessfully(mixed $input, float $expected): void
	{
		$gateway = ConversionGateway::createDefault();

		self::assertSame(
			$expected,
			$gateway->to(WireRepresentation::class, $input, PhpRepresentation::class, FieldContext::named('price', 'float')),
		);
	}

	public static function successfulFloatInputs(): array
	{
		return [
			[1, 1.0],
			[1.5, 1.5],
			['3.25', 3.25],
			['1e3', 1000.0],
		];
	}

	#[DataProvider('nonFiniteFloatInputs')]
	public function testNonFiniteFloatInputsFail(mixed $input): void
	{
		$gateway = ConversionGateway::createDefault();

		$this->expectException(ConversionException::class);
		$gateway->to(WireRepresentation::class, $input, PhpRepresentation::class, FieldContext::named('price', 'float'));
	}

	public static function nonFiniteFloatInputs(): array
	{
		return [
			[INF],
			[-INF],
			[NAN],
			['1e9999'],
			['-1e9999'],
		];
	}

	public function testPhpValuesConvertToWire(): void
	{
		$gateway = ConversionGateway::createDefault();

		self::assertSame('hello', $gateway->to(PhpRepresentation::class, 'hello', WireRepresentation::class, FieldContext::named('name', 'string')));
		self::assertSame(9, $gateway->to(PhpRepresentation::class, 9, WireRepresentation::class, FieldContext::named('id', 'primary')));
		self::assertFalse($gateway->to(PhpRepresentation::class, false, WireRepresentation::class, FieldContext::named('active', 'boolean')));
	}

	public function testSameRepresentationConversionReturnsOriginalValue(): void
	{
		$gateway = ConversionGateway::createDefault();
		$value = new stdClass();

		self::assertSame($value, $gateway->to(PhpRepresentation::class, $value, PhpRepresentation::class, FieldContext::named('payload', 'text')));
	}

	public function testSameValidRepresentationDoesNotRequireFieldTypeResolution(): void
	{
		$gateway = new ConversionGateway(new FieldTypeRegistry());
		$value = new stdClass();

		self::assertSame($value, $gateway->to(PhpRepresentation::class, $value, PhpRepresentation::class, FieldContext::named('payload', 'missing')));
	}

	public function testNullPassesThroughUnchanged(): void
	{
		$gateway = ConversionGateway::createDefault();

		self::assertNull($gateway->to(StorageRepresentation::class, null, PhpRepresentation::class, FieldContext::named('id', 'int')));
	}

	public function testUnknownRepresentationsFail(): void
	{
		$gateway = ConversionGateway::createDefault();

		$this->expectException(UnsupportedConversionException::class);
		$gateway->to(stdClass::class, '10', PhpRepresentation::class, FieldContext::named('id', 'int'));
	}

	public function testEqualInvalidRepresentationStillFailsValidation(): void
	{
		$gateway = ConversionGateway::createDefault();

		$this->expectException(UnsupportedConversionException::class);
		$gateway->to(stdClass::class, '10', stdClass::class, FieldContext::named('id', 'int'));
	}

	public function testCustomRepresentationIsAcceptedWithoutRegistration(): void
	{
		$gateway = $this->trackingGateway();

		self::assertSame(
			'php<payload>',
			$gateway->to(CacheRepresentation::class, 'payload', PhpRepresentation::class, FieldContext::named('payload', 'tracked')),
		);
	}

	public function testBuiltInFieldTypeRejectsValidCustomRepresentation(): void
	{
		$gateway = ConversionGateway::createDefault();

		$this->expectException(UnsupportedConversionException::class);
		$gateway->to(CacheRepresentation::class, '10', PhpRepresentation::class, FieldContext::named('id', 'int'));
	}

	public function testCustomRepresentationToPhpCallsOnlyToPhp(): void
	{
		$gateway = $this->trackingGateway();

		self::assertSame(
			'php<payload>',
			$gateway->to(CacheRepresentation::class, 'payload', PhpRepresentation::class, FieldContext::named('payload', 'tracked')),
		);
		self::assertSame(
			['toPhp:' . CacheRepresentation::class],
			TrackingCustomFieldType::calls(),
		);
	}

	public function testPhpToCustomRepresentationCallsOnlyFromPhp(): void
	{
		$gateway = $this->trackingGateway();

		self::assertSame(
			'cache<payload>',
			$gateway->to(PhpRepresentation::class, 'payload', CacheRepresentation::class, FieldContext::named('payload', 'tracked')),
		);
		self::assertSame(
			['fromPhp:' . CacheRepresentation::class],
			TrackingCustomFieldType::calls(),
		);
	}

	public function testCustomRepresentationToWireRoutesThroughPhp(): void
	{
		$gateway = $this->trackingGateway();

		self::assertSame(
			'wire<php<payload>>',
			$gateway->to(CacheRepresentation::class, 'payload', WireRepresentation::class, FieldContext::named('payload', 'tracked')),
		);
		self::assertSame(
			[
				'toPhp:' . CacheRepresentation::class,
				'fromPhp:' . WireRepresentation::class,
			],
			TrackingCustomFieldType::calls(),
		);
	}

	public function testWireToCustomRepresentationRoutesThroughPhp(): void
	{
		$gateway = $this->trackingGateway();

		self::assertSame(
			'cache<php-wire<payload>>',
			$gateway->to(WireRepresentation::class, 'payload', CacheRepresentation::class, FieldContext::named('payload', 'tracked')),
		);
		self::assertSame(
			[
				'toPhp:' . WireRepresentation::class,
				'fromPhp:' . CacheRepresentation::class,
			],
			TrackingCustomFieldType::calls(),
		);
	}

	public function testConversionErrorsRetainPreviousExceptions(): void
	{
		$gateway = ConversionGateway::createDefault();
		$field = FieldContext::named('active', 'bool');

		try {
			$gateway->to(WireRepresentation::class, 'maybe', PhpRepresentation::class, $field);
			self::fail('Expected conversion exception was not thrown.');
		} catch (ConversionException $exception) {
			self::assertNotNull($exception->getPrevious());
			self::assertStringContainsString("field 'active'", $exception->getMessage());
			self::assertStringContainsString('from ' . WireRepresentation::class, $exception->getMessage());
			self::assertStringContainsString('to ' . PhpRepresentation::class, $exception->getMessage());
		}
	}

	public function testUnknownFieldTypeResolutionIsExplicit(): void
	{
		$gateway = new ConversionGateway(new FieldTypeRegistry());

		$this->expectException(FieldTypeNotFoundException::class);
		$gateway->to(StorageRepresentation::class, '10', PhpRepresentation::class, FieldContext::named('id', 'int'));
	}

	public function testConversionDoesNotMutateRegistryDefinitions(): void
	{
		$registry = new Registry();
		$field = $registry->collection('users')->field('id', 'int')->nullable(true);
		$before = $registry->all();

		$gateway = ConversionGateway::createDefault();
		$result = $gateway->to(StorageRepresentation::class, '10', PhpRepresentation::class, FieldContext::fromField($field));

		self::assertSame(10, $result);
		self::assertSame($before, $registry->all());
	}

	public function testFieldContextFromFieldRetainsOnlyNameTypeAndNullability(): void
	{
		$registry = new Registry();
		$field = $registry->collection('users')->field('id', 'int')->nullable(true)->description('ignored in phase 1');
		$before = $registry->all();

		$context = FieldContext::fromField($field);

		self::assertSame('id', $context->getName());
		self::assertSame('int', $context->getType());
		self::assertTrue($context->isNullable());
		self::assertSame($before, $registry->all());
	}

	public function testRestoredDefinitionsBehaveIdentically(): void
	{
		$registry = new Registry();
		$registry->collection('users')->field('id', 'int')->nullable(true);
		$restored = new Registry($registry->all());

		$originalField = $registry->collection('users')->field('id');
		$restoredField = $restored->collection('users')->field('id');
		$gateway = new ConversionGateway(
			FieldTypeRegistry::createDefault()->register('custom', CustomFieldType::class),
		);

		self::assertSame(
			$gateway->to(StorageRepresentation::class, '15', PhpRepresentation::class, FieldContext::fromField($originalField)),
			$gateway->to(StorageRepresentation::class, '15', PhpRepresentation::class, FieldContext::fromField($restoredField)),
		);
	}

	private function trackingGateway(): ConversionGateway
	{
		return new ConversionGateway(
			FieldTypeRegistry::createDefault()->register('tracked', TrackingCustomFieldType::class),
		);
	}
}
