<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\Attribute\MapFrom;

final class TargetAuthorDto
{
	#[MapFrom('full_name')]
	public string $displayName = '';
}
