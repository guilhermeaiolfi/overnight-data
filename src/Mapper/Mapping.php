<?php

declare(strict_types=1);

namespace ON\Data\Mapper;

/**
 * Holds the process-wide default {@see ConversionGateway} used by {@see map()} when none is passed.
 *
 * The default is ambient process state: {@see setDefaultGateway()} affects every later
 * {@see getDefaultGateway()} call in this PHP process until {@see resetDefaultGateway()}.
 * Prefer an explicit gateway in long-lived workers so request/tenant config cannot leak.
 */
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
