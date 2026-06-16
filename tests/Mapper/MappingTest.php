<?php

declare(strict_types=1);

namespace Tests\ON\Data\Mapper;

use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\FieldTypeRegistry;
use ON\Data\Mapper\Mapping;
use PHPUnit\Framework\TestCase;

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
}
