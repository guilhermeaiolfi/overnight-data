<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

class ReadonlyUserDto
{
	public readonly int $id;

	public function __construct()
	{
		$this->id = 0;
	}
}
