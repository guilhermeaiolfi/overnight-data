<?php

declare(strict_types=1);

namespace ON\Data\Database;

interface DatabasePlatformInterface
{
	public function family(): DatabaseFamily;

	public function nativeDriver(): mixed;
}
