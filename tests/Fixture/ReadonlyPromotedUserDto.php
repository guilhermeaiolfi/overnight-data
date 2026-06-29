<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\Attribute\MapFrom;

final class ReadonlyPromotedUserDto
{
	public function __construct(
		public readonly int $id,
		public readonly string $name = 'Anonymous',
		public readonly ?string $nickname = null,
		public readonly int $age = 0,
		#[MapFrom('user_score')]
		public readonly float $score = 0.0,
	) {
	}
}
