<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\FieldTypeRegistry;
use function ON\Data\Mapper\map;
use ON\Data\Mapper\Mapping;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\Fixture\GatewayIdentityMapper;

final class MappingTest extends TestCase
{
	protected function tearDown(): void
	{
		Mapping::resetDefaultGateway();
	}

	public function testGetDefaultGatewayReusesOneBuiltInInstance(): void
	{
		$first = Mapping::getDefaultGateway();
		$second = Mapping::getDefaultGateway();

		self::assertSame($first, $second);
	}

	public function testSetDefaultGatewayReplacesRuntimeUsedByDefaultHolder(): void
	{
		$gateway = new ConversionGateway(FieldTypeRegistry::createDefault());

		Mapping::setDefaultGateway($gateway);

		self::assertSame($gateway, Mapping::getDefaultGateway());
	}

	public function testResetDefaultGatewayRestoresLazyBuiltInBehavior(): void
	{
		$gateway = new ConversionGateway(FieldTypeRegistry::createDefault());

		Mapping::setDefaultGateway($gateway);
		Mapping::resetDefaultGateway();

		self::assertNotSame($gateway, Mapping::getDefaultGateway());
		self::assertSame(Mapping::getDefaultGateway(), Mapping::getDefaultGateway());
	}

	public function testMapUsesTheConfiguredDefaultGateway(): void
	{
		$gateway = new ConversionGateway(FieldTypeRegistry::createDefault());
		$gateway->getMappers()->register(GatewayIdentityMapper::class);
		Mapping::setDefaultGateway($gateway);

		$result = map('source')->using(GatewayIdentityMapper::class)->to(stdClass::class);

		self::assertSame(spl_object_id($gateway), $result->gatewayId);
	}

	public function testExplicitGatewayOverridesTheDefault(): void
	{
		$defaultGateway = new ConversionGateway(FieldTypeRegistry::createDefault());
		$defaultGateway->getMappers()->register(GatewayIdentityMapper::class);
		$explicitGateway = new ConversionGateway(FieldTypeRegistry::createDefault());
		$explicitGateway->getMappers()->register(GatewayIdentityMapper::class);
		Mapping::setDefaultGateway($defaultGateway);

		$result = map('source', null, $explicitGateway)->using(GatewayIdentityMapper::class)->to(stdClass::class);

		self::assertSame(spl_object_id($explicitGateway), $result->gatewayId);
		self::assertNotSame(spl_object_id($defaultGateway), $result->gatewayId);
	}
}
