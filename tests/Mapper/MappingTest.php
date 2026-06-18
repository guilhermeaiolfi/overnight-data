<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use ON\Data\Mapper\ConversionGateway;
use function ON\Data\Mapper\map;
use ON\Data\Mapper\Mapping;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\Fixture\GatewayAwareWriter;

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
		$gateway = ConversionGateway::createDefault();

		Mapping::setDefaultGateway($gateway);

		self::assertSame($gateway, Mapping::getDefaultGateway());
	}

	public function testResetDefaultGatewayRestoresLazyBuiltInBehavior(): void
	{
		$gateway = ConversionGateway::createDefault();

		Mapping::setDefaultGateway($gateway);
		Mapping::resetDefaultGateway();

		self::assertNotSame($gateway, Mapping::getDefaultGateway());
		self::assertSame(Mapping::getDefaultGateway(), Mapping::getDefaultGateway());
	}

	public function testMapUsesConfiguredDefaultGateway(): void
	{
		$gateway = ConversionGateway::createDefault();
		$gateway->getMapperManager()->setConstructor(
			static fn (string $component, ConversionGateway $runtime): object => $component === GatewayAwareWriter::class
				? new GatewayAwareWriter($runtime)
				: new $component(),
		);
		Mapping::setDefaultGateway($gateway);

		$result = map(['id' => 10])->writer(GatewayAwareWriter::class)->to(stdClass::class);

		self::assertSame(spl_object_id($gateway), $result->gatewayId);
	}

	public function testExplicitGatewayOverridesDefault(): void
	{
		$defaultGateway = ConversionGateway::createDefault();
		$defaultGateway->getMapperManager()->setConstructor(
			static fn (string $component, ConversionGateway $runtime): object => $component === GatewayAwareWriter::class
				? new GatewayAwareWriter($runtime)
				: new $component(),
		);
		$explicitGateway = ConversionGateway::createDefault();
		$explicitGateway->getMapperManager()->setConstructor(
			static fn (string $component, ConversionGateway $runtime): object => $component === GatewayAwareWriter::class
				? new GatewayAwareWriter($runtime)
				: new $component(),
		);
		Mapping::setDefaultGateway($defaultGateway);

		$result = map(['id' => 10], null, $explicitGateway)->writer(GatewayAwareWriter::class)->to(stdClass::class);

		self::assertSame(spl_object_id($explicitGateway), $result->gatewayId);
		self::assertNotSame(spl_object_id($defaultGateway), $result->gatewayId);
	}
}
