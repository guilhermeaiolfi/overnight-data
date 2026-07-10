<?php

declare(strict_types=1);

namespace ON\Data\Database\Cycle;

use Cycle\Database\DatabaseInterface;
use ON\Data\Database\DatabaseFamily;
use ON\Data\Database\DatabasePlatformInterface;

/**
 * @internal
 */
final class CycleDatabasePlatform implements DatabasePlatformInterface
{
	public function __construct(
		private readonly DatabaseInterface $database,
	) {
	}

	public function family(): DatabaseFamily
	{
		return DatabaseFamily::fromDriverType($this->database->getType());
	}

	public function nativeDriver(): mixed
	{
		return $this->database;
	}
}
