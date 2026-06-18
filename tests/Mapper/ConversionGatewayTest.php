<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use ON\Data\Definition\Registry;
use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\Exception\ConversionException;
use ON\Data\Mapper\Exception\FieldTypeNotFoundException;
use ON\Data\Mapper\Exception\UnsupportedConversionException;
use ON\Data\Mapper\FieldContext;
use ON\Data\Mapper\Representation\PhpRepresentation;
use ON\Data\Mapper\Representation\StorageRepresentation;
use ON\Data\Mapper\Representation\WireRepresentation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\Fixture\ApiRepresentation;
use Tests\ON\Data\Fixture\CacheRepresentation;
use Tests\ON\Data\Fixture\CustomFieldType;
use Tests\ON\Data\Fixture\JsonApiRepresentation;
use Tests\ON\Data\Fixture\ReplacementTrackingWireCodec;
use Tests\ON\Data\Fixture\TrackingApiCodec;
use Tests\ON\Data\Fixture\TrackingCacheCodec;
use Tests\ON\Data\Fixture\TrackingCustomFieldType;
use Tests\ON\Data\Fixture\TrackingWireCodec;

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

		self::assertSame(
			10,
			$gateway->to(StorageRepresentation::class, '10', PhpRepresentation::class, FieldContext::named('id', 'int')),
		);
	}

	public function testWireIntegerStringConvertsToPhpInteger(): void
	{
		$gateway = ConversionGateway::createDefault();

		self::assertSame(
			42,
			$gateway->to(WireRepresentation::class, '42', PhpRepresentation::class, FieldContext::named('id', 'integer')),
		);
	}

	#[DataProvider('supportedBooleanValues')]
	public function testSupportedBooleanInputsConvertDeterministically(mixed $input, bool $expected): void
	{
		$gateway = ConversionGateway::createDefault();

		self::assertSame(
			$expected,
			$gateway->to(WireRepresentation::class, $input, PhpRepresentation::class, FieldContext::named('active', 'bool')),
		);
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

		$this->expectException(ConversionException::class);
		$gateway->to(WireRepresentation::class, $input, PhpRepresentation::class, FieldContext::named('active', 'bool'));
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

	public function testSameRepresentationConversionReturnsOriginalValueWithoutFieldTypeResolution(): void
	{
		$gateway = new ConversionGateway();
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

	public function testValidCustomRepresentationWorksWithBuiltInFieldTypeWithoutCodec(): void
	{
		$gateway = ConversionGateway::createDefault();

		self::assertSame(
			42,
			$gateway->to(CacheRepresentation::class, '42', PhpRepresentation::class, FieldContext::named('id', 'int')),
		);
	}

	public function testUnknownFieldTypeResolutionIsExplicit(): void
	{
		$gateway = new ConversionGateway();

		$this->expectException(FieldTypeNotFoundException::class);
		$gateway->to(StorageRepresentation::class, '10', PhpRepresentation::class, FieldContext::named('id', 'int'));
	}

	public function testCustomFieldTypeCanBeRegisteredThroughMapperManager(): void
	{
		$gateway = new ConversionGateway();
		$gateway->getMapperManager()->register(CustomFieldType::class);

		self::assertSame(
			'HELLO',
			$gateway->to(StorageRepresentation::class, 'hello', PhpRepresentation::class, FieldContext::named('code', 'custom')),
		);
	}

	public function testCustomRepresentationToPhpUsesExactCodecOnly(): void
	{
		$gateway = $this->trackingGateway();

		self::assertSame(
			'php<payload>',
			$gateway->to(CacheRepresentation::class, 'payload', PhpRepresentation::class, FieldContext::named('payload', 'tracked')),
		);
		self::assertSame(['cacheCodec:toPhp'], TrackingCustomFieldType::calls());
	}

	public function testPhpToCustomRepresentationUsesExactCodecOnly(): void
	{
		$gateway = $this->trackingGateway();

		self::assertSame(
			'cache<payload>',
			$gateway->to(PhpRepresentation::class, 'payload', CacheRepresentation::class, FieldContext::named('payload', 'tracked')),
		);
		self::assertSame(['cacheCodec:fromPhp'], TrackingCustomFieldType::calls());
	}

	public function testNonPhpToNonPhpRoutesThroughCanonicalPhp(): void
	{
		$gateway = $this->trackingGateway();

		self::assertSame(
			'wire<php<payload>>',
			$gateway->to(CacheRepresentation::class, 'payload', WireRepresentation::class, FieldContext::named('payload', 'tracked')),
		);
		self::assertSame(
			['cacheCodec:toPhp', 'wireCodec:fromPhp'],
			TrackingCustomFieldType::calls(),
		);
	}

	public function testInheritedRepresentationCodecFallsBackToNearestParent(): void
	{
		$gateway = $this->trackingGateway(registerApiCodec: true);

		self::assertSame(
			'api<payload>',
			$gateway->to(PhpRepresentation::class, 'payload', JsonApiRepresentation::class, FieldContext::named('payload', 'tracked')),
		);
		self::assertSame(['apiCodec:fromPhp'], TrackingCustomFieldType::calls());
	}

	public function testExactChildCodecOverridesParentCodec(): void
	{
		$gateway = $this->trackingGateway(registerApiCodec: true);
		$gateway->getMapperManager()->register(ReplacementTrackingWireCodec::class);

		self::assertSame(
			'api<payload>',
			$gateway->to(PhpRepresentation::class, 'payload', ApiRepresentation::class, FieldContext::named('payload', 'tracked')),
		);
		self::assertSame(['apiCodec:fromPhp'], TrackingCustomFieldType::calls());
	}

	public function testNoMatchingCodecFallsBackToFieldType(): void
	{
		$gateway = new ConversionGateway();
		$gateway->getMapperManager()->register(TrackingCustomFieldType::class);

		self::assertSame(
			'field-php<payload>',
			$gateway->to(ApiRepresentation::class, 'payload', PhpRepresentation::class, FieldContext::named('payload', 'tracked')),
		);
		self::assertSame(['fieldType:toPhp'], TrackingCustomFieldType::calls());
	}

	public function testCodecRegistrationOrderDoesNotRequireFieldTypeAliasRegistration(): void
	{
		$gateway = new ConversionGateway();
		$gateway->getMapperManager()->register(TrackingWireCodec::class);

		self::assertSame(
			TrackingWireCodec::class,
			$gateway->getMapperManager()->resolveFieldTypeCodec(TrackingCustomFieldType::class, WireRepresentation::class),
		);
	}

	public function testConversionErrorsRetainPreviousExceptions(): void
	{
		$gateway = ConversionGateway::createDefault();

		try {
			$gateway->to(WireRepresentation::class, 'maybe', PhpRepresentation::class, FieldContext::named('active', 'bool'));
			self::fail('Expected conversion exception was not thrown.');
		} catch (ConversionException $exception) {
			self::assertNotNull($exception->getPrevious());
			self::assertStringContainsString("field 'active'", $exception->getMessage());
			self::assertStringContainsString('from ' . WireRepresentation::class, $exception->getMessage());
			self::assertStringContainsString('to ' . PhpRepresentation::class, $exception->getMessage());
		}
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
		$registry->collection('users')->field('code', 'custom')->nullable(true);
		$restored = new Registry($registry->all());

		$originalField = $registry->collection('users')->field('code');
		$restoredField = $restored->collection('users')->field('code');
		$gateway = new ConversionGateway();
		$gateway->getMapperManager()->register(CustomFieldType::class);

		self::assertSame(
			$gateway->to(StorageRepresentation::class, 'hello', PhpRepresentation::class, FieldContext::fromField($originalField)),
			$gateway->to(StorageRepresentation::class, 'hello', PhpRepresentation::class, FieldContext::fromField($restoredField)),
		);
	}

	private function trackingGateway(bool $registerApiCodec = false): ConversionGateway
	{
		$gateway = new ConversionGateway();
		$gateway->getMapperManager()->register(TrackingCustomFieldType::class);
		$gateway->getMapperManager()->register(TrackingCacheCodec::class);
		$gateway->getMapperManager()->register(TrackingWireCodec::class);

		if ($registerApiCodec) {
			$gateway->getMapperManager()->register(TrackingApiCodec::class);
		}

		return $gateway;
	}
}
