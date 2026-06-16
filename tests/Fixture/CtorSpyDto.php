<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use RuntimeException;

class CtorSpyDto
{
	public static int $constructorCalls = 0;

	public int $id;

	public string $name = 'Anonymous';

	public function __construct()
	{
		++self::$constructorCalls;

		throw new RuntimeException('Constructor should not be called.');
	}
}
