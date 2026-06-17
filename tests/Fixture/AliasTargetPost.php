<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\Attribute\MapFrom;

final class AliasTargetPost
{
	#[MapFrom('post_title')]
	public string $heading;
}
