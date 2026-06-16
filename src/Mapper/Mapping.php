<?php

declare(strict_types=1);

namespace ON\Data\Mapper;

final class Mapping
{
	private static ?ConversionGateway $defaultGateway = null;

	public static function setDefaultGateway(ConversionGateway $gateway): void
	{
		self::$defaultGateway = $gateway;
	}

	public static function getDefaultGateway(): ConversionGateway
	{
		return self::$defaultGateway ??= ConversionGateway::createDefault();
	}

	public static function resetDefaultGateway(): void
	{
		self::$defaultGateway = null;
	}
}
