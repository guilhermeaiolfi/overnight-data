<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\Attribute\MapTo;

final class SourceAuthorDto
{
	#[MapTo('full_name')]
	public string $name = '';
}
